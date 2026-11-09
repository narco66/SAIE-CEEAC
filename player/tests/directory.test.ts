import { test } from 'node:test'
import assert from 'node:assert/strict'
import { availableLetters, directoryEntries, initial } from '../src/directory.ts'

const floors = [
  { level: 1, label: '1er étage', directory: [{ name: 'Direction des ressources humaines', office: 'Aile B, 112', phone: '+241 01 00 00' }], rooms: [{ name: 'Salle Ogooué', capacity: 20 }] },
  { level: 0, label: 'Rez-de-chaussée', directory: [{ name: 'Accueil et protocole', office: null, phone: null }, { name: 'Économat', office: 'Bureau 4', phone: null }], rooms: [] },
]

test('annuaire trié et filtré par niveau et par initiale, accents ignorés (ORI-01)', () => {
  assert.deepEqual(directoryEntries(floors).map((e) => e.name), ['Accueil et protocole', 'Direction des ressources humaines', 'Économat', 'Salle Ogooué'])
  assert.deepEqual(directoryEntries(floors, { level: 1 }).map((e) => e.kind), ['structure', 'room'])
  assert.deepEqual(directoryEntries(floors, { letter: 'E' }).map((e) => e.name), ['Économat'])
  assert.equal(initial('Économat'), 'E')
  assert.deepEqual(availableLetters(floors), ['A', 'D', 'E', 'S'])
})

test('un niveau sans annuaire ni salle ne produit aucune entrée', () => {
  assert.deepEqual(directoryEntries([{ level: 2, label: '2e', rooms: [] }], { level: 2 }), [])
})
