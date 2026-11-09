/**
 * Annuaire de la borne d'orientation tactile (ORI-01) : filtrage par niveau et par initiale, sans clavier.
 * Module pur, testable sous Node.
 */

export interface DirectoryFloor {
  level: number
  label: string
  directory?: { name: string; office: string | null; phone: string | null }[]
  rooms: { name: string; capacity: number }[]
  plan?: string | null
}

export interface DirectoryEntry { name: string; office: string | null; phone: string | null; level: number; floor: string; kind: 'structure' | 'room' }

/** Initiale normalisée (sans accent) servant au filtre alphabétique. */
export function initial(name: string): string {
  return name.normalize('NFD').replace(/[̀-ͯ]/g, '').trim().charAt(0).toUpperCase()
}

/** Entrées de l'annuaire (structures puis salles), triées alphabétiquement, filtrées par niveau et par initiale. */
export function directoryEntries(floors: DirectoryFloor[], filter: { level?: number | null; letter?: string | null } = {}): DirectoryEntry[] {
  const entries: DirectoryEntry[] = floors.flatMap((f) => [
    ...(f.directory ?? []).map((d) => ({ name: d.name, office: d.office, phone: d.phone, level: f.level, floor: f.label, kind: 'structure' as const })),
    ...f.rooms.map((r) => ({ name: r.name, office: null, phone: null, level: f.level, floor: f.label, kind: 'room' as const })),
  ])

  return entries
    .filter((e) => filter.level === undefined || filter.level === null || e.level === filter.level)
    .filter((e) => !filter.letter || initial(e.name) === filter.letter)
    .sort((a, b) => a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }))
}

/** Initiales présentes, pour n'afficher que des boutons utiles. */
export function availableLetters(floors: DirectoryFloor[]): string[] {
  return [...new Set(directoryEntries(floors).map((e) => initial(e.name)).filter(Boolean))].sort()
}
