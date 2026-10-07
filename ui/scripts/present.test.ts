import assert from 'node:assert/strict'
import test from 'node:test'
import {
  bubbleLines,
  bubbleX,
  COFFEE_DWELL_MS,
  cutLine,
  displayLines,
  initials,
  seat,
  smoking,
  startWalk,
  tickWalk,
  tintColor,
} from '../src/floor/present.ts'

test('cutLine keeps 21 code points and adds an ellipsis', () => {
  assert.equal(cutLine('short'), 'short')
  assert.equal(cutLine('a'.repeat(22)), 'a'.repeat(22))
  assert.equal(cutLine('a'.repeat(23)), `${'a'.repeat(21)}…`)
})

test('initials use the first and last word', () => {
  assert.equal(initials('Ada Lovelace'), 'AL')
  assert.equal(initials('Ada'), 'A')
  assert.equal(initials('  '), '?')
})

test('bubbleLines drops the second line when there is no task', () => {
  assert.deepEqual(bubbleLines({ step: 'At their desk', task: null }), ['At their desk'])
  assert.deepEqual(
    bubbleLines({ step: 'Editing src/Login.php', task: { title: 'Login page' } }),
    ['Login page', 'Editing src/Login.php'],
  )
})

test('bubbleX keeps the plate inside the floor', () => {
  assert.equal(bubbleX(48, 66, 1280), 73)
  assert.equal(bubbleX(640, 66, 1280), 665)
  assert.equal(bubbleX(1240, 66, 1280), 1211)
  assert.equal(bubbleX(48, 2000, 1280), 48)
})

test('bubble plates on neighboring desks do not overlap', () => {
  const width = 86
  const pad = 4
  const plateLeft = (anchor: number) => bubbleX(anchor, width, 1280) - width / 2 - pad
  const plateRight = (anchor: number) => plateLeft(anchor) + width + pad * 2
  assert.ok(plateLeft(48) >= 32)
  assert.ok(plateRight(48) <= plateLeft(144))
})

test('seat wraps every 12 agents and steps down 64 pixels', () => {
  assert.deepEqual(seat(0), { spot: 1, extraY: 0 })
  assert.deepEqual(seat(11), { spot: 12, extraY: 0 })
  assert.deepEqual(seat(12), { spot: 1, extraY: 64 })
  assert.deepEqual(seat(13), { spot: 2, extraY: 64 })
})

test('tintColor is stable', () => {
  assert.equal(tintColor('ada'), 0xd197d8)
  assert.equal(tintColor('ada'), tintColor('ada'))
})

test('the first sight of an agent puts them straight at that place', () => {
  assert.deepEqual(startWalk(null, 'work'), { stops: ['work'], dwellMs: 0 })
  assert.deepEqual(startWalk(null, 'balcony'), { stops: ['balcony'], dwellMs: 0 })
})

test('starting work stops at the coffee machine first', () => {
  assert.deepEqual(startWalk('balcony', 'work'), { stops: ['coffee', 'work'], dwellMs: 0 })
  assert.deepEqual(startWalk('desk', 'work'), { stops: ['coffee', 'work'], dwellMs: 0 })
})

test('leaving work skips the coffee machine', () => {
  assert.deepEqual(startWalk('work', 'balcony'), { stops: ['balcony'], dwellMs: 0 })
  assert.deepEqual(startWalk('work', 'desk'), { stops: ['desk'], dwellMs: 0 })
})

test('arrival at the coffee machine waits before the walk continues', () => {
  const armed = tickWalk({ stops: ['coffee', 'work'], dwellMs: 0 }, true, 16)
  assert.deepEqual(armed, { stops: ['coffee', 'work'], dwellMs: COFFEE_DWELL_MS })
  const waiting = tickWalk(armed, true, COFFEE_DWELL_MS - 1)
  assert.deepEqual(waiting.stops, ['coffee', 'work'])
  assert.ok(waiting.dwellMs > 0)
  assert.deepEqual(tickWalk(waiting, true, waiting.dwellMs), { stops: ['work'], dwellMs: 0 })
})

test('the coffee stop holds while the agent is still walking there', () => {
  const walk = { stops: ['coffee', 'work'] as const, dwellMs: 0 }
  assert.deepEqual(tickWalk(walk, false, 500), walk)
})

test('the bubble says Getting coffee only while they are at the machine', () => {
  const working = { step: 'Editing src/Login.php', task: { title: 'Login page' } }
  assert.deepEqual(displayLines(working, 'coffee'), ['Getting coffee'])
  assert.deepEqual(displayLines(working, 'work'), ['Login page', 'Editing src/Login.php'])
  assert.deepEqual(displayLines({ step: 'Smoking', task: null }, 'balcony'), ['Smoking'])
})

test('smoke shows only after an idle agent reaches the balcony', () => {
  assert.equal(smoking('balcony', true), true)
  assert.equal(smoking('balcony', false), false)
  assert.equal(smoking('work', true), false)
  assert.equal(smoking('coffee', true), false)
})
