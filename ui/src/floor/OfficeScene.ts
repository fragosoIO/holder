import Phaser from 'phaser'
import clerkAtlasUrl from '../assets/floor/clerk.json?url'
import clerkUrl from '../assets/floor/clerk.png'
import mapUrl from '../assets/floor/office.json?url'
import tilesUrl from '../assets/floor/office.png'
import type { FloorAgent, FloorSnapshot } from '../types'
import { bubbleLines, initials, seat, tintColor } from './present'

type Handlers = {
  open: (agent: FloorAgent) => void
  hover: (agent: FloorAgent | null) => void
}

let handlers: Handlers = {
  open: () => {},
  hover: () => {},
}

export function setFloorHandlers(next: Handlers): void {
  handlers = next
}

type Actor = {
  agent: FloorAgent
  sprite: Phaser.GameObjects.Sprite
  label: Phaser.GameObjects.Text
  bubble: Phaser.GameObjects.Text
  plate: Phaser.GameObjects.Graphics
  targetX: number
  targetY: number
}

function isSnapshot(value: unknown): value is FloorSnapshot {
  return typeof value === 'object' && value !== null && 'agents' in value && Array.isArray(value.agents)
}

export class OfficeScene extends Phaser.Scene {
  private actors = new Map<string, Actor>()
  private spots = new Map<string, { x: number; y: number }>()
  private cursors: Phaser.Types.Input.Keyboard.CursorKeys | null = null
  private drag: { x: number; y: number; scrollX: number; scrollY: number } | null = null
  private moved = false
  private mapWidth = 0
  private mapHeight = 0
  private ready = false

  constructor() {
    super('office')
  }

  preload(): void {
    this.load.image('office-tiles', tilesUrl)
    this.load.tilemapTiledJSON('office-map', mapUrl)
    this.load.atlas('clerk', clerkUrl, clerkAtlasUrl)
  }

  create(): void {
    const map = this.make.tilemap({ key: 'office-map' })
    const tiles = map.addTilesetImage('office', 'office-tiles')
    if (!tiles) throw new Error('Office tileset did not load.')
    map.createLayer('ground', tiles, 0, 0)
    for (const object of map.getObjectLayer('spots')?.objects ?? []) {
      if (object.name) this.spots.set(object.name, { x: object.x ?? 0, y: object.y ?? 0 })
    }
    this.mapWidth = map.widthInPixels
    this.mapHeight = map.heightInPixels
    const camera = this.cameras.main
    camera.setZoom(1)
    camera.centerOn(this.mapWidth / 2, this.mapHeight / 2)
    camera.setBounds(0, 0, this.mapWidth, this.mapHeight)
    this.cursors = this.input.keyboard?.createCursorKeys() ?? null
    this.input.on('pointerdown', (pointer: Phaser.Input.Pointer) => {
      this.drag = { x: pointer.x, y: pointer.y, scrollX: camera.scrollX, scrollY: camera.scrollY }
      this.moved = false
    })
    this.input.on('pointermove', (pointer: Phaser.Input.Pointer) => {
      if (!this.drag || !pointer.isDown) return
      const dx = pointer.x - this.drag.x
      const dy = pointer.y - this.drag.y
      if (Math.hypot(dx, dy) > 4) this.moved = true
      camera.scrollX = this.drag.scrollX - dx / camera.zoom
      camera.scrollY = this.drag.scrollY - dy / camera.zoom
    })
    this.input.on('wheel', (...args: unknown[]) => {
      const dy = typeof args[3] === 'number' ? args[3] : 0
      camera.setZoom(Phaser.Math.Clamp(camera.zoom - dy * 0.001, 1, 2))
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
      const point = this.point(agent.place, index)
      const actor = this.actors.get(agent.id)
      if (!actor) {
        this.actors.set(agent.id, this.spawn(agent, point))
        return
      }
      actor.agent = agent
      actor.targetX = point.x
      actor.targetY = point.y
      actor.bubble.setText(bubbleLines(agent).join('\n'))
    })
    for (const [id, actor] of this.actors) {
      if (keep.has(id)) continue
      actor.sprite.destroy()
      actor.label.destroy()
      actor.bubble.destroy()
      actor.plate.destroy()
      this.actors.delete(id)
    }
    const rows = snapshot.agents.length === 0 ? 0 : Math.floor((snapshot.agents.length - 1) / 12)
    this.cameras.main.setBounds(0, 0, this.mapWidth, this.mapHeight + rows * 64)
  }

  update(_time: number, delta: number): void {
    const camera = this.cameras.main
    if (this.cursors) {
      const pan = (400 * delta) / 1000
      if (this.cursors.left.isDown) camera.scrollX -= pan
      if (this.cursors.right.isDown) camera.scrollX += pan
      if (this.cursors.up.isDown) camera.scrollY -= pan
      if (this.cursors.down.isDown) camera.scrollY += pan
    }
    for (const actor of this.actors.values()) {
      const dx = actor.targetX - actor.sprite.x
      const dy = actor.targetY - actor.sprite.y
      const distance = Math.hypot(dx, dy)
      if (distance < 1) {
        actor.sprite.setPosition(actor.targetX, actor.targetY)
        actor.sprite.anims.stop()
        actor.sprite.setFrame('down')
      } else {
        const step = Math.min(distance, (120 * delta) / 1000)
        actor.sprite.x += (dx / distance) * step
        actor.sprite.y += (dy / distance) * step
        const dir = Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'right' : 'left') : (dy > 0 ? 'down' : 'up')
        actor.sprite.anims.play(`${dir}-walk`, true)
      }
      actor.sprite.setDepth(actor.sprite.y)
      actor.label.setPosition(actor.sprite.x, actor.sprite.y + 2)
      actor.bubble.setPosition(actor.sprite.x, actor.sprite.y - 36)
      actor.plate.clear()
      actor.plate.fillStyle(0xfff7e8, 1)
      actor.plate.fillRoundedRect(
        actor.sprite.x - actor.bubble.width / 2 - 4,
        actor.sprite.y - 36 - actor.bubble.height - 2,
        actor.bubble.width + 8,
        actor.bubble.height + 6,
        4,
      )
      actor.plate.setDepth(actor.sprite.y + 1)
      actor.bubble.setDepth(actor.sprite.y + 2)
      actor.label.setDepth(actor.sprite.y + 2)
    }
  }

  private point(place: FloorAgent['place'], index: number): { x: number; y: number } {
    const { spot, extraY } = seat(index)
    const found = this.spots.get(`${place}-${spot}`)
    if (!found) throw new Error(`Missing spot ${place}-${spot}`)
    return { x: found.x, y: found.y + extraY }
  }

  private spawn(agent: FloorAgent, point: { x: number; y: number }): Actor {
    const sprite = this.add.sprite(point.x, point.y, 'clerk', 'down').setOrigin(0.5, 1).setTint(tintColor(agent.id))
    sprite.setInteractive({ useHandCursor: true })
    const label = this.add.text(point.x, point.y + 2, initials(agent.name), {
      fontFamily: 'monospace',
      fontSize: '10px',
      color: '#f5f5f4',
    }).setOrigin(0.5, 0)
    const bubble = this.add.text(point.x, point.y - 36, bubbleLines(agent).join('\n'), {
      fontFamily: 'monospace',
      fontSize: '12px',
      color: '#1c1915',
      align: 'center',
    }).setOrigin(0.5, 1)
    const actor: Actor = {
      agent,
      sprite,
      label,
      bubble,
      plate: this.add.graphics(),
      targetX: point.x,
      targetY: point.y,
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
