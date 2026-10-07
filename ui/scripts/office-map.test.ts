import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

type TileProperty = { name: string; value: unknown }
type TileDef = { id: number; properties?: TileProperty[] }
type TileLayer = { name: string; type: string; width: number; height: number; data: number[] }
type Spot = { name?: string; x?: number; y?: number }
type OfficeMap = {
  width: number
  height: number
  tilewidth: number
  tileheight: number
  tilesets: { firstgid: number; tiles?: TileDef[] }[]
  layers: ({ type: string; name: string; objects?: Spot[] } & Partial<TileLayer>)[]
}

const map = JSON.parse(
  readFileSync(join(dirname(fileURLToPath(import.meta.url)), '../src/assets/floor/office.json'), 'utf8'),
) as OfficeMap

const FURNITURE = new Set(['desk', 'chair', 'plant', 'monitor', 'window', 'shelf', 'lamp', 'rug', 'whiteboard', 'cabinet'])
const TALL = new Set(['desk', 'monitor', 'shelf', 'whiteboard', 'cabinet', 'plant', 'lamp', 'window', 'wall', 'coffee', 'railing'])

function kindOf(gid: number): string | null {
  if (gid === 0) return null
  for (const tileset of map.tilesets) {
    const id = gid - tileset.firstgid
    const tile = tileset.tiles?.find((entry) => entry.id === id)
    const kind = tile?.properties?.find((property) => property.name === 'kind')?.value
    if (typeof kind === 'string') return kind
  }
  return null
}

function tileLayers(): TileLayer[] {
  return map.layers.filter((layer): layer is TileLayer => layer.type === 'tilelayer' && Array.isArray(layer.data))
}

function spots(): Spot[] {
  const layer = map.layers.find((entry) => entry.name === 'spots')
  return layer?.objects ?? []
}

// Feet sit on the tile that ends at the spot. Spot y is that tile's bottom edge.
function cellOf(spot: Spot): { col: number; row: number } {
  return {
    col: Math.floor((spot.x ?? 0) / map.tilewidth),
    row: Math.floor(((spot.y ?? 0) - 1) / map.tileheight),
  }
}

function gidsAt(col: number, row: number): number[] {
  return tileLayers().map((layer) => layer.data[row * layer.width + col] ?? 0)
}

test('desk and work stand points each exist once', () => {
  const names = spots().map((spot) => spot.name)
  for (const place of ['desk', 'work']) {
    for (let index = 1; index <= 12; index += 1) {
      const name = `${place}-${index}`
      assert.equal(names.filter((entry) => entry === name).length, 1, name)
    }
  }
})

test('the ground uses more than four tiles and no tile covers 85% of it', () => {
  const ground = tileLayers().find((layer) => layer.name === 'ground')
  assert.ok(ground, 'ground layer')
  const used = new Set<number>()
  for (const layer of tileLayers()) {
    for (const gid of layer.data) if (gid !== 0) used.add(gid)
  }
  assert.ok(used.size > 4, `distinct tiles: ${used.size}`)
  const counts = new Map<number, number>()
  for (const gid of ground.data) counts.set(gid, (counts.get(gid) ?? 0) + 1)
  let top = 0
  for (const count of counts.values()) top = Math.max(top, count)
  assert.ok(top / ground.data.length < 0.85, `dominant share ${top}/${ground.data.length}`)
})

test('both desk and work bands have furniture that is not the main floor tile', () => {
  const ground = tileLayers().find((layer) => layer.name === 'ground')
  assert.ok(ground)
  const counts = new Map<number, number>()
  for (const gid of ground.data) counts.set(gid, (counts.get(gid) ?? 0) + 1)
  let dominant = 0
  let top = -1
  for (const [gid, count] of counts) {
    if (count > top) {
      dominant = gid
      top = count
    }
  }
  for (const place of ['desk', 'work']) {
    const kinds = new Set<string>()
    for (const spot of spots()) {
      if (!spot.name?.startsWith(`${place}-`)) continue
      const { col, row } = cellOf(spot)
      for (let y = row - 2; y <= row + 2; y += 1) {
        for (let x = col - 2; x <= col + 2; x += 1) {
          if (x < 0 || y < 0 || x >= map.width || y >= map.height) continue
          for (const gid of gidsAt(x, y)) {
            const kind = kindOf(gid)
            if (gid !== dominant && kind && FURNITURE.has(kind)) kinds.add(kind)
          }
        }
      }
    }
    for (const kind of ['desk', 'chair', 'plant']) {
      assert.ok(kinds.has(kind), `${place} band missing ${kind}`)
    }
  }
})

test('plants, monitors, and another prop kind are used more than once', () => {
  const counts = new Map<string, number>()
  for (const layer of tileLayers()) {
    for (const gid of layer.data) {
      const kind = kindOf(gid)
      if (!kind) continue
      counts.set(kind, (counts.get(kind) ?? 0) + 1)
    }
  }
  assert.ok((counts.get('plant') ?? 0) > 1)
  assert.ok((counts.get('monitor') ?? 0) > 1)
  const third = ['window', 'shelf', 'lamp', 'rug', 'whiteboard'].filter((kind) => (counts.get(kind) ?? 0) > 1)
  assert.ok(third.length >= 1, `third props: ${third.join(',')}`)
})

test('balcony and coffee stand points each exist once', () => {
  const names = spots().map((spot) => spot.name)
  for (const place of ['balcony', 'coffee']) {
    for (let index = 1; index <= 12; index += 1) {
      const name = `${place}-${index}`
      assert.equal(names.filter((entry) => entry === name).length, 1, name)
    }
  }
})

test('smokers stand on the balcony floor beside a railing', () => {
  const balcony = spots().filter((spot) => spot.name?.startsWith('balcony-'))
  assert.equal(balcony.length, 12)
  for (const spot of balcony) {
    const { col, row } = cellOf(spot)
    const under = gidsAt(col, row).map((gid) => kindOf(gid))
    assert.ok(under.includes('floor'), `${spot.name} is not on the floor`)
    let railing = false
    for (let y = row - 2; y <= row + 2; y += 1) {
      for (let x = col - 2; x <= col + 2; x += 1) {
        if (gidsAt(x, y).some((gid) => kindOf(gid) === 'railing')) railing = true
      }
    }
    assert.ok(railing, `${spot.name} has no railing nearby`)
  }
})

test('coffee spots stand next to the coffee machine and off the balcony', () => {
  const coffee = spots().filter((spot) => spot.name?.startsWith('coffee-'))
  const balconyCells = new Set(
    spots()
      .filter((spot) => spot.name?.startsWith('balcony-'))
      .map((spot) => {
        const cell = cellOf(spot)
        return `${cell.col},${cell.row}`
      }),
  )
  const machines: { col: number; row: number }[] = []
  for (let row = 0; row < map.height; row += 1) {
    for (let col = 0; col < map.width; col += 1) {
      if (gidsAt(col, row).some((gid) => kindOf(gid) === 'coffee')) machines.push({ col, row })
    }
  }
  assert.ok(machines.length >= 1, 'coffee machine missing')
  for (const spot of coffee) {
    const cell = cellOf(spot)
    assert.equal(balconyCells.has(`${cell.col},${cell.row}`), false, spot.name)
    const beside = machines.some((machine) => Math.abs(machine.col - cell.col) <= 1 && Math.abs(machine.row - cell.row) <= 1)
    assert.ok(beside, `${spot.name} is away from the coffee machine`)
  }
})

test('stand tiles are not covered by tall furniture', () => {
  for (const spot of spots()) {
    if (!spot.name) continue
    const { col, row } = cellOf(spot)
    for (const gid of gidsAt(col, row)) {
      const kind = kindOf(gid)
      assert.ok(!kind || !TALL.has(kind), `${spot.name} covered by ${kind}`)
    }
  }
})
