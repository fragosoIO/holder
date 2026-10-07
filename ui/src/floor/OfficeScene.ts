import Phaser from 'phaser'
import clerkAtlasUrl from '../assets/floor/clerk.json?url'
import clerkUrl from '../assets/floor/clerk.png'
import dogAtlasUrl from '../assets/floor/dog.json?url'
import dogUrl from '../assets/floor/dog.png'
import mapUrl from '../assets/floor/office.json?url'
import tilesUrl from '../assets/floor/office.png'
import type { FloorAgent, FloorSnapshot } from '../types'
import { createDog, DOG_SPEED, tickDog, type DogMind } from './dog'
import { bubbleX, displayLines, initials, seat, smoking, startWalk, tickWalk, tintColor, type PlaceName, type Walk } from './present'

type Handlers = {
  open: (agent: FloorAgent) => void
  hover: (agent: FloorAgent | null) => void
}

let handlers: Handlers = {
  open: () => {},
  hover: () => {},
}

type ArrowDir = 'left' | 'right' | 'up' | 'down'

function arrowDir(key: string): ArrowDir | null {
  if (key === 'ArrowLeft') return 'left'
  if (key === 'ArrowRight') return 'right'
  if (key === 'ArrowUp') return 'up'
  if (key === 'ArrowDown') return 'down'
  return null
}

function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') return true
  return target.isContentEditable
}

export function setFloorHandlers(next: Handlers): void {
  handlers = next
}

type OfficeDog = {
  sprite: Phaser.GameObjects.Sprite
  bubble: Phaser.GameObjects.Text
  plate: Phaser.GameObjects.Graphics
  mind: DogMind
  points: { x: number; y: number }[]
  targetX: number
  targetY: number
}

type Actor = {
  agent: FloorAgent
  index: number
  sprite: Phaser.GameObjects.Sprite
  label: Phaser.GameObjects.Text
  bubble: Phaser.GameObjects.Text
  plate: Phaser.GameObjects.Graphics
  smoke: Phaser.GameObjects.Graphics
  targetX: number
  targetY: number
  walk: Walk
}

function isSnapshot(value: unknown): value is FloorSnapshot {
  return typeof value === 'object' && value !== null && 'agents' in value && Array.isArray(value.agents)
}

export class OfficeScene extends Phaser.Scene {
  private actors = new Map<string, Actor>()
  private spots = new Map<string, { x: number; y: number }>()
  private arrows: Record<ArrowDir, boolean> = { left: false, right: false, up: false, down: false }
  private drag: { x: number; y: number; scrollX: number; scrollY: number } | null = null
  private moved = false
  private mapWidth = 0
  private mapHeight = 0
  private boundHeight = 0
  private baseZoom = 1
  private framed = false
  private ready = false
  private dog: OfficeDog | null = null

  constructor() {
    super('office')
  }

  preload(): void {
    this.load.image('office-tiles', tilesUrl)
    this.load.tilemapTiledJSON('office-map', mapUrl)
    this.load.atlas('clerk', clerkUrl, clerkAtlasUrl)
    this.load.atlas('dog', dogUrl, dogAtlasUrl)
  }

  create(): void {
    const map = this.make.tilemap({ key: 'office-map' })
    const tiles = map.addTilesetImage('office', 'office-tiles')
    if (!tiles) throw new Error('Office tileset did not load.')
    const ground = map.createLayer('ground', tiles, 0, 0)
    const props = map.createLayer('props', tiles, 0, 0)
    if (!ground || !props) throw new Error('Office map is missing a room layer.')
    ground.setDepth(0)
    props.setDepth(1)
    for (const object of map.getObjectLayer('spots')?.objects ?? []) {
      if (object.name) this.spots.set(object.name, { x: object.x ?? 0, y: object.y ?? 0 })
    }
    this.mapWidth = map.widthInPixels
    this.mapHeight = map.heightInPixels
    this.boundHeight = this.mapHeight
    const camera = this.cameras.main
    this.fitCamera(true)
    this.scale.on('resize', this.onResize, this)
    window.addEventListener('keydown', this.onArrowDown)
    window.addEventListener('keyup', this.onArrowUp)
    this.events.once('shutdown', this.releaseKeys)
    this.input.on('pointerdown', (pointer: Phaser.Input.Pointer) => {
      this.drag = { x: pointer.x, y: pointer.y, scrollX: camera.scrollX, scrollY: camera.scrollY }
      this.moved = false
    })
    this.input.on('pointermove', (pointer: Phaser.Input.Pointer) => {
      if (!this.drag || !pointer.isDown) return
      const dx = pointer.x - this.drag.x
      const dy = pointer.y - this.drag.y
      if (Math.hypot(dx, dy) > 4) {
        this.moved = true
        this.framed = true
      }
      camera.scrollX = this.drag.scrollX - dx / camera.zoom
      camera.scrollY = this.drag.scrollY - dy / camera.zoom
    })
    this.input.on('wheel', (...args: unknown[]) => {
      const dy = typeof args[3] === 'number' ? args[3] : 0
      this.framed = true
      camera.setZoom(Phaser.Math.Clamp(camera.zoom - dy * 0.001, this.baseZoom, this.baseZoom * 2.5))
    })
    for (const dir of ['down', 'left', 'right', 'up']) {
      this.anims.create({
        key: `${dir}-walk`,
        frames: this.anims.generateFrameNames('clerk', {
          prefix: `${dir}-walk.`,
          start: 0,
          end: 3,
          zeroPad: 3,
        }),
        frameRate: 8,
        repeat: -1,
      })
    }
    for (const dir of ['down', 'left', 'right', 'up']) {
      this.anims.create({
        key: `dog-${dir}-walk`,
        frames: this.anims.generateFrameNames('dog', {
          prefix: `${dir}-walk.`,
          start: 0,
          end: 3,
          zeroPad: 3,
        }),
        frameRate: 8,
        repeat: -1,
      })
    }
    this.spawnDog()
    this.ready = true
    const initial = this.game.registry.get('snapshot')
    if (isSnapshot(initial)) this.apply(initial)
  }

  apply(snapshot: FloorSnapshot): void {
    if (!this.ready) {
      this.game.registry.set('snapshot', snapshot)
      return
    }
    const keep = new Set<string>()
    snapshot.agents.forEach((agent, index) => {
      keep.add(agent.id)
      const actor = this.actors.get(agent.id)
      if (!actor) {
        const walk = startWalk(null, agent.place)
        const point = this.point(walk.stops[0], index)
        this.actors.set(agent.id, this.spawn(agent, index, point, walk))
        return
      }
      if (actor.agent.place !== agent.place) actor.walk = startWalk(actor.agent.place, agent.place)
      actor.agent = agent
      actor.index = index
      const point = this.point(actor.walk.stops[0], index)
      actor.targetX = point.x
      actor.targetY = point.y
      actor.bubble.setText(displayLines(agent, actor.walk.stops[0]).join('\n'))
    })
    for (const [id, actor] of this.actors) {
      if (keep.has(id)) continue
      actor.sprite.destroy()
      actor.label.destroy()
      actor.bubble.destroy()
      actor.plate.destroy()
      actor.smoke.destroy()
      this.actors.delete(id)
    }
    const rows = snapshot.agents.length === 0 ? 0 : Math.floor((snapshot.agents.length - 1) / 12)
    this.boundHeight = this.mapHeight + rows * 64
    this.cameras.main.setBounds(0, 0, this.mapWidth, this.boundHeight)
  }

  update(_time: number, delta: number): void {
    const camera = this.cameras.main
    if (!isTypingTarget(document.activeElement)) {
      const pan = (400 * delta) / 1000
      if (this.arrows.left) camera.scrollX -= pan
      if (this.arrows.right) camera.scrollX += pan
      if (this.arrows.up) camera.scrollY -= pan
      if (this.arrows.down) camera.scrollY += pan
      if (this.arrows.left || this.arrows.right || this.arrows.up || this.arrows.down) this.framed = true
    }
    for (const actor of this.actors.values()) {
      let dx = actor.targetX - actor.sprite.x
      let dy = actor.targetY - actor.sprite.y
      let distance = Math.hypot(dx, dy)
      const before = actor.walk.stops[0]
      actor.walk = tickWalk(actor.walk, distance < 1, delta)
      if (actor.walk.stops[0] !== before) {
        const point = this.point(actor.walk.stops[0], actor.index)
        actor.targetX = point.x
        actor.targetY = point.y
        dx = actor.targetX - actor.sprite.x
        dy = actor.targetY - actor.sprite.y
        distance = Math.hypot(dx, dy)
        actor.bubble.setText(displayLines(actor.agent, actor.walk.stops[0]).join('\n'))
      }
      const arrived = distance < 1
      if (arrived) {
        actor.sprite.setPosition(actor.targetX, actor.targetY)
        actor.sprite.anims.stop()
        actor.sprite.setFrame(actor.agent.place === 'balcony' ? 'right' : 'down')
      } else {
        const step = Math.min(distance, (120 * delta) / 1000)
        actor.sprite.x += (dx / distance) * step
        actor.sprite.y += (dy / distance) * step
        const dir = Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up')
        actor.sprite.anims.play(`${dir}-walk`, true)
      }
      if (smoking(actor.agent.place, arrived)) this.drawSmoke(actor, _time)
      else actor.smoke.clear()
      actor.sprite.setDepth(actor.sprite.y)
      actor.label.setPosition(actor.sprite.x, actor.sprite.y + 2)
      const bubbleAnchor = bubbleX(actor.sprite.x, actor.bubble.width, this.mapWidth)
      actor.bubble.setPosition(bubbleAnchor, actor.sprite.y - 28)
      actor.plate.clear()
      actor.plate.fillStyle(0xfff7e8, 1)
      actor.plate.fillRoundedRect(
        bubbleAnchor - actor.bubble.width / 2 - 4,
        actor.sprite.y - 28 - actor.bubble.height - 2,
        actor.bubble.width + 8,
        actor.bubble.height + 6,
        4,
      )
      actor.plate.setDepth(actor.sprite.y + 1)
      actor.bubble.setDepth(actor.sprite.y + 2)
      actor.label.setDepth(actor.sprite.y + 2)
    }
    this.stepDog(delta)
  }

  private onArrowDown = (event: KeyboardEvent): void => {
    const dir = arrowDir(event.key)
    if (!dir) return
    if (isTypingTarget(event.target) || isTypingTarget(document.activeElement)) return
    event.preventDefault()
    this.arrows[dir] = true
  }

  private onArrowUp = (event: KeyboardEvent): void => {
    const dir = arrowDir(event.key)
    if (!dir) return
    this.arrows[dir] = false
  }

  private onResize = (): void => {
    this.fitCamera(!this.framed)
  }

  private fitCamera(recenter: boolean): void {
    const camera = this.cameras.main
    const ratio = window.devicePixelRatio || 1
    const viewWidth = camera.width / ratio
    const viewHeight = camera.height / ratio
    if (this.mapWidth === 0 || this.mapHeight === 0 || viewWidth === 0 || viewHeight === 0) return
    const contain = Math.min(viewWidth / this.mapWidth, viewHeight / this.mapHeight)
    this.baseZoom = contain * ratio
    camera.roundPixels = Math.abs(contain - Math.round(contain)) < 0.02
    if (recenter || camera.zoom < this.baseZoom - 0.001) camera.setZoom(this.baseZoom)
    camera.setBounds(0, 0, this.mapWidth, this.boundHeight || this.mapHeight)
    if (recenter) {
      const worldHeight = camera.height / this.baseZoom
      if (worldHeight >= this.mapHeight) {
        camera.centerOn(this.mapWidth / 2, this.mapHeight / 2)
      } else {
        // North bubbles start near y=56. Work desks end at y=640.
        // Frame that span and keep the view inside the map.
        const focusTop = 56
        const focusBottom = 640
        const pad = Math.max(0, (worldHeight - (focusBottom - focusTop)) / 2)
        const top = Math.min(Math.max(0, focusTop - pad), this.mapHeight - worldHeight)
        camera.centerOn(this.mapWidth / 2, top + worldHeight / 2)
      }
    }
  }

  private releaseKeys = (): void => {
    this.scale.off('resize', this.onResize, this)
    window.removeEventListener('keydown', this.onArrowDown)
    window.removeEventListener('keyup', this.onArrowUp)
    this.arrows.left = false
    this.arrows.right = false
    this.arrows.up = false
    this.arrows.down = false
  }

  private spawnDog(): void {
    const points = [...this.spots.values()]
    if (points.length === 0) return
    const mind = createDog(points.length, Math.random())
    const point = points[mind.target] ?? points[0]
    if (!point) return
    const sprite = this.add.sprite(point.x, point.y, 'dog', 'down').setOrigin(0.5, 1).setDepth(point.y)
    const bubble = this.add.text(point.x, point.y - 28, '', {
      fontFamily: 'monospace',
      fontSize: '11px',
      color: '#1c1915',
      align: 'center',
    }).setOrigin(0.5, 1).setDepth(point.y + 2).setVisible(false)
    this.dog = {
      sprite,
      bubble,
      plate: this.add.graphics(),
      mind,
      points,
      targetX: point.x,
      targetY: point.y,
    }
  }

  private stepDog(delta: number): void {
    const dog = this.dog
    if (!dog) return
    let dx = dog.targetX - dog.sprite.x
    let dy = dog.targetY - dog.sprite.y
    let distance = Math.hypot(dx, dy)
    const before = dog.mind.target
    dog.mind = tickDog(dog.mind, distance < 1, delta, dog.points.length, Math.random)
    if (dog.mind.target !== before) {
      const point = dog.points[dog.mind.target]
      if (point) {
        dog.targetX = point.x
        dog.targetY = point.y
        dx = dog.targetX - dog.sprite.x
        dy = dog.targetY - dog.sprite.y
        distance = Math.hypot(dx, dy)
      }
    }
    const arrived = distance < 1
    if (arrived) {
      dog.sprite.setPosition(dog.targetX, dog.targetY)
      dog.sprite.anims.stop()
      dog.sprite.setFrame('down')
    } else {
      const step = Math.min(distance, (DOG_SPEED * delta) / 1000)
      dog.sprite.x += (dx / distance) * step
      dog.sprite.y += (dy / distance) * step
      const dir = Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up')
      dog.sprite.anims.play(`dog-${dir}-walk`, true)
    }
    dog.sprite.setDepth(dog.sprite.y)
    const barking = dog.mind.bark
    dog.bubble.setVisible(barking !== null)
    dog.plate.setVisible(barking !== null)
    if (!barking) {
      dog.plate.clear()
      return
    }
    if (dog.bubble.text !== barking) dog.bubble.setText(barking)
    const anchor = bubbleX(dog.sprite.x, dog.bubble.width, this.mapWidth)
    dog.bubble.setPosition(anchor, dog.sprite.y - 28)
    dog.plate.clear()
    dog.plate.fillStyle(0xfff7e8, 1)
    dog.plate.fillRoundedRect(
      anchor - dog.bubble.width / 2 - 4,
      dog.sprite.y - 28 - dog.bubble.height - 2,
      dog.bubble.width + 8,
      dog.bubble.height + 6,
      4,
    )
    dog.plate.setDepth(dog.sprite.y + 1)
    dog.bubble.setDepth(dog.sprite.y + 2)
  }

  private drawSmoke(actor: Actor, time: number): void {
    const puff = actor.smoke
    puff.clear()
    const rise = (time % 1100) / 1100
    const x = actor.sprite.x + 8
    const y = actor.sprite.y - 18
    puff.fillStyle(0xf4f1ea, 1)
    puff.fillRect(x - 5, y, 8, 2)
    puff.fillStyle(0xe25a28, 1)
    puff.fillRect(x + 3, y, 3, 2)
    puff.fillStyle(0xf2f0ea, 0.9)
    puff.fillCircle(x + Math.sin(time / 220) * 3, y - 8 - rise * 18, 3.4)
    puff.fillStyle(0xd7d2c8, 0.55)
    puff.fillCircle(x + 5, y - 16 - rise * 8, 2.4)
    puff.setDepth(actor.sprite.y + 3)
  }

  private point(place: PlaceName, index: number): { x: number; y: number } {
    const { spot, extraY } = seat(index)
    const found = this.spots.get(`${place}-${spot}`)
    if (!found) throw new Error(`Missing spot ${place}-${spot}`)
    return { x: found.x, y: found.y + extraY }
  }

  private spawn(agent: FloorAgent, index: number, point: { x: number; y: number }, walk: Walk): Actor {
    const facing = agent.place === 'balcony' ? 'right' : 'down'
    const sprite = this.add.sprite(point.x, point.y, 'clerk', facing).setOrigin(0.5, 1).setTint(tintColor(agent.id)).setDepth(point.y)
    sprite.setInteractive({ useHandCursor: true })
    const label = this.add.text(point.x, point.y + 2, initials(agent.name), {
      fontFamily: 'monospace',
      fontSize: '10px',
      color: '#f4f1ea',
      stroke: '#2a2622',
      strokeThickness: 3,
    }).setOrigin(0.5, 0).setDepth(point.y + 2)
    const bubble = this.add.text(point.x, point.y - 28, displayLines(agent, walk.stops[0]).join('\n'), {
      fontFamily: 'monospace',
      fontSize: '11px',
      color: '#1c1915',
      align: 'center',
      wordWrap: { width: 90 },
    }).setOrigin(0.5, 1).setDepth(point.y + 2)
    bubble.setPosition(bubbleX(point.x, bubble.width, this.mapWidth), point.y - 28)
    const actor: Actor = {
      agent,
      index,
      sprite,
      label,
      bubble,
      plate: this.add.graphics(),
      smoke: this.add.graphics(),
      targetX: point.x,
      targetY: point.y,
      walk,
    }
    sprite.on('pointerup', () => {
      if (this.moved) return
      handlers.open(actor.agent)
    })
    sprite.on('pointerover', () => handlers.hover(actor.agent))
    sprite.on('pointerout', () => handlers.hover(null))
    return actor
  }
}
