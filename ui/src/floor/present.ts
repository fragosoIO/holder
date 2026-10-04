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
