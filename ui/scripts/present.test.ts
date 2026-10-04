import assert from 'node:assert/strict'
import test from 'node:test'
import { bubbleLines, cutLine, initials, seat, tintColor } from '../src/floor/present.ts'

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
