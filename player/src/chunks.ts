/**
 * Découpage d'un téléchargement en blocs pour la reprise après interruption (PLY-04, PERF-01) : un média interrompu
 * reprend au premier bloc manquant au lieu de repartir de zéro. Module pur, sans accès au navigateur (testable sous Node).
 */

/** Taille d'un bloc : compromis entre nombre de requêtes et quantité retéléchargée après une coupure. */
export const CHUNK_SIZE = 8 * 1024 * 1024

export interface ChunkRange { index: number; start: number; end: number }

/** Blocs d'un fichier de `size` octets (bornes incluses, comme l'en-tête HTTP Range). */
export function chunkRanges(size: number, chunkSize = CHUNK_SIZE): ChunkRange[] {
  if (size <= 0) return []
  const ranges: ChunkRange[] = []
  for (let index = 0, start = 0; start < size; index++, start += chunkSize) {
    ranges.push({ index, start, end: Math.min(size, start + chunkSize) - 1 })
  }
  return ranges
}

/** Blocs restant à télécharger, sachant ceux déjà conservés. */
export function missingRanges(size: number, stored: Set<number>, chunkSize = CHUNK_SIZE): ChunkRange[] {
  return chunkRanges(size, chunkSize).filter((r) => !stored.has(r.index))
}

/** Un bloc reçu est accepté seulement s'il a exactement la longueur attendue. */
export function chunkComplete(range: ChunkRange, received: number): boolean {
  return received === range.end - range.start + 1
}
