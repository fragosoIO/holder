export const BARKS = ['Woof', 'Arf', 'Bork'] as const
export const BARK_HOLD_MS = 1000
export const BARK_GAP_MIN_MS = 4000
export const BARK_GAP_MAX_MS = 9000
export const DOG_SPEED = 70

export type DogMind = {
  target: number
  barkInMs: number
  bark: string | null
  barkLeftMs: number
}

export function barkGap(roll: number): number {
  const span = BARK_GAP_MAX_MS - BARK_GAP_MIN_MS
  return BARK_GAP_MIN_MS + Math.floor(roll * span)
}

export function createDog(spotCount: number, roll: number): DogMind {
  const count = Math.max(spotCount, 1)
  return {
    target: Math.min(count - 1, Math.floor(roll * count)),
    barkInMs: barkGap(roll),
    bark: null,
    barkLeftMs: 0,
  }
}

export function tickDog(
  mind: DogMind,
  arrived: boolean,
  deltaMs: number,
  spotCount: number,
  random: () => number,
): DogMind {
  let target = mind.target
  let barkInMs = mind.barkInMs - deltaMs
  let bark = mind.bark
  let barkLeftMs = mind.barkLeftMs
  if (arrived && spotCount > 1) {
    const step = 1 + Math.floor(random() * (spotCount - 1))
    target = (target + step) % spotCount
  }
  if (bark) {
    barkLeftMs -= deltaMs
    if (barkLeftMs <= 0) {
      bark = null
      barkLeftMs = 0
      barkInMs = barkGap(random())
    }
  } else if (barkInMs <= 0) {
    bark = BARKS[Math.floor(random() * BARKS.length) % BARKS.length] ?? BARKS[0]
    barkLeftMs = BARK_HOLD_MS
    barkInMs = 0
  }
  return { target, barkInMs, bark, barkLeftMs }
}
