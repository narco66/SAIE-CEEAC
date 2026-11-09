import { test } from 'node:test'
import assert from 'node:assert/strict'
import { chunkComplete, chunkRanges, missingRanges } from '../src/chunks.ts'

test('découpage en blocs aux bornes incluses, dernier bloc partiel (PLY-04)', () => {
  assert.deepEqual(chunkRanges(25, 10), [{ index: 0, start: 0, end: 9 }, { index: 1, start: 10, end: 19 }, { index: 2, start: 20, end: 24 }])
  assert.deepEqual(chunkRanges(0, 10), [])
  assert.deepEqual(chunkRanges(10, 10), [{ index: 0, start: 0, end: 9 }])
})

test('reprise : seuls les blocs non conservés sont redemandés', () => {
  assert.deepEqual(missingRanges(25, new Set([0, 2]), 10).map((r) => r.index), [1])
  assert.deepEqual(missingRanges(25, new Set([0, 1, 2]), 10), [])
})

test('un bloc tronqué est refusé', () => {
  assert.equal(chunkComplete({ index: 2, start: 20, end: 24 }, 5), true)
  assert.equal(chunkComplete({ index: 2, start: 20, end: 24 }, 4), false)
})
