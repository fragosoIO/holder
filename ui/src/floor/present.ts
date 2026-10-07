export function cutLine(line: string): string {
  const chars = Array.from(line)
  if (chars.length <= 22) return line
  return `${chars.slice(0, 21).join('')}…`
}

export function initials(name: string): string {
  const words = name.trim().split(/\s+/).filter((word) => word !== '')
  const first = words[0] ? Array.from(words[0])[0] : ''
  if (!first) return '?'
  if (words.length === 1) return first.toUpperCase()
  const last = Array.from(words[words.length - 1] ?? '')[0] ?? ''
  return `${first}${last}`.toUpperCase()
}

export function bubbleLines(agent: { step: string; task: { title: string } | null }): string[] {
  if (!agent.task) return [cutLine(agent.step)]
  return [cutLine(agent.task.title), cutLine(agent.step)]
}

export type PlaceName = 'desk' | 'work' | 'balcony' | 'coffee'

export type Walk = {
  stops: PlaceName[]
  dwellMs: number
}

export const COFFEE_DWELL_MS = 900

export function startWalk(from: PlaceName | null, to: PlaceName): Walk {
  if (from !== null && to === 'work' && from !== 'work') {
    return { stops: ['coffee', 'work'], dwellMs: 0 }
  }
  return { stops: [to], dwellMs: 0 }
}

export function tickWalk(walk: Walk, arrived: boolean, deltaMs: number): Walk {
  if (!arrived || walk.stops.length <= 1) {
    return walk.stops.length <= 1 ? { stops: walk.stops, dwellMs: 0 } : walk
  }
  if (walk.stops[0] !== 'coffee') return { stops: walk.stops.slice(1), dwellMs: 0 }
  if (walk.dwellMs <= 0) return { stops: walk.stops, dwellMs: COFFEE_DWELL_MS }
  const left = walk.dwellMs - deltaMs
  if (left > 0) return { stops: walk.stops, dwellMs: left }
  return { stops: walk.stops.slice(1), dwellMs: 0 }
}

export function displayLines(
  agent: { step: string; task: { title: string } | null },
  stop: PlaceName,
): string[] {
  if (stop === 'coffee') return [cutLine('Getting coffee')]
  return bubbleLines(agent)
}

export function smoking(place: PlaceName, arrived: boolean): boolean {
  return place === 'balcony' && arrived
}

// Plate center. The plate hangs 4px past the text and must stay out of the 32px wall band.
// It sits just left of the agent and grows right, so a full line does not cover the next desk.
export function bubbleX(anchorX: number, textWidth: number, mapWidth: number): number {
  const pad = 4
  const margin = 32
  if (textWidth + pad * 2 > mapWidth - margin * 2) return anchorX
  const half = textWidth / 2
  let left = Math.max(margin, anchorX - 12)
  const maxLeft = mapWidth - margin - textWidth - pad * 2
  if (left > maxLeft) left = Math.max(margin, maxLeft)
  return left + pad + half
}

export function seat(index: number): { spot: number; extraY: number } {
  return { spot: (index % 12) + 1, extraY: Math.floor(index / 12) * 64 }
}

export function tintColor(id: string): number {
  let hash = 0
  for (const char of id) hash = (Math.imul(hash, 31) + (char.codePointAt(0) ?? 0)) >>> 0
  return hsl(hash % 360, 0.45, 0.72)
}

function hsl(hue: number, saturation: number, lightness: number): number {
  const chroma = (1 - Math.abs(2 * lightness - 1)) * saturation
  const channel = hue / 60
  const x = chroma * (1 - Math.abs((channel % 2) - 1))
  const match = lightness - chroma / 2
  let red = 0
  let green = 0
  let blue = 0
  if (channel < 1) {
    red = chroma
    green = x
  } else if (channel < 2) {
    red = x
    green = chroma
  } else if (channel < 3) {
    green = chroma
    blue = x
  } else if (channel < 4) {
    green = x
    blue = chroma
  } else if (channel < 5) {
    red = x
    blue = chroma
  } else {
    red = chroma
    blue = x
  }
  const byte = (value: number) => Math.round((value + match) * 255)
  return (byte(red) << 16) | (byte(green) << 8) | byte(blue)
}
