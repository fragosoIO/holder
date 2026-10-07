import assert from 'node:assert/strict'
import test from 'node:test'
import { BARK_GAP_MIN_MS, BARK_HOLD_MS, BARKS, createDog, tickDog } from '../src/floor/dog.ts'

test('createDog starts on a spot and waits before the first bark', () => {
  assert.deepEqual(createDog(10, 0), { target: 0, barkInMs: BARK_GAP_MIN_MS, bark: null, barkLeftMs: 0 })
  const later = createDog(10, 0.95)
  assert.equal(later.target, 9)
  assert.equal(later.bark, null)
  assert.ok(later.barkInMs >= BARK_GAP_MIN_MS)
})

test('arriving sends the dog to a different spot', () => {
  const mind = { target: 0, barkInMs: 5000, bark: null, barkLeftMs: 0 }
  const next = tickDog(mind, true, 16, 12, () => 0)
  assert.equal(next.target, 1)
  assert.notEqual(next.target, mind.target)
})

test('the dog barks after the quiet gap and then goes quiet again', () => {
  let mind = { target: 3, barkInMs: 800, bark: null, barkLeftMs: 0 }
  mind = tickDog(mind, false, 400, 12, () => 0)
  assert.equal(mind.bark, null)
  mind = tickDog(mind, false, 400, 12, () => 0)
  assert.equal(mind.bark, BARKS[0])
  assert.equal(mind.barkLeftMs, BARK_HOLD_MS)
  mind = tickDog(mind, false, BARK_HOLD_MS, 12, () => 0)
  assert.equal(mind.bark, null)
  assert.equal(mind.barkInMs, BARK_GAP_MIN_MS)
})

test('which bark comes up depends on the roll', () => {
  const mind = { target: 0, barkInMs: 0, bark: null, barkLeftMs: 0 }
  assert.equal(tickDog(mind, false, 16, 4, () => 0.9).bark, BARKS[BARKS.length - 1])
})
