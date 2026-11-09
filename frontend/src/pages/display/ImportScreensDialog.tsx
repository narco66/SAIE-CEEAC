import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError, fileUrl } from '../../lib/api'
import { Button, Field, Modal, Notice, Status } from '../../components/ui'

interface ImportRow { line: number; code: string; status: string; errors: string[] }
interface ImportReport { committed: boolean; summary: Record<string, number>; rows: ImportRow[] }

const ROW_STATUS: Record<string, [string, string]> = { created: ['Création', 'success'], updated: ['Mise à jour', 'blue'], unchanged: ['Inchangé', 'neutral'], invalid: ['Invalide', 'danger'] }

/**
 * Import en masse des écrans (§5.5) : modèle CSV, simulation sans écriture avec bilan ligne à ligne, puis confirmation
 * atomique (aucune écriture si une ligne est invalide).
 */
export default function ImportScreensDialog({ onClose }: { onClose: () => void }) {
  const client = useQueryClient()
  const [file, setFile] = useState<File | null>(null)
  const [report, setReport] = useState<ImportReport | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const send = async (commit: boolean) => {
    if (!file) return
    setBusy(true)
    setError(null)
    try {
      const form = new FormData()
      form.append('file', file)
      if (commit) form.append('commit', '1')
      const res = await api.post<{ data: ImportReport }>('/screens/import', form, false)
      setReport(res.data)
      if (res.data.committed) await client.invalidateQueries({ queryKey: ['screens'] })
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  const invalid = report?.summary.invalid ?? 0
  return (
    <Modal title="Importer des écrans" kicker="INVENTAIRE" wide onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>{report?.committed ? 'Fermer' : 'Annuler'}</Button>
      {!report?.committed && <Button variant="secondary" onClick={() => send(false)} busy={busy && !report} disabled={!file}>Simuler</Button>}
      {report && !report.committed && <Button icon="check" onClick={() => send(true)} busy={busy} disabled={invalid > 0}>Confirmer l’import</Button>}
    </>}>
      <p className="small">Fichier CSV en UTF-8 (séparateur « ; » ou « , ») ou classeur Excel (.xlsx, première feuille), 500 écrans au plus. Un écran est créé ou mis à jour d’après son code ; l’emplacement est désigné par les codes du bâtiment, du niveau et de l’emplacement.</p>
      <p className="small"><a className="link-text" href={fileUrl('/screens/import/template')}>Télécharger le modèle</a></p>
      <Field label="Fichier CSV ou Excel" required error={error?.field('file')}>
        <input type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setReport(null) }} />
      </Field>
      {error && <Notice tone="danger">{error.message}</Notice>}
      {report && (
        <>
          <div className="chip-row" style={{ margin: '12px 0' }}>
            {Object.entries(report.summary).filter(([k]) => k !== 'total').map(([k, n]) => <Status key={k} tone={ROW_STATUS[k]?.[1] ?? 'neutral'}>{n} · {ROW_STATUS[k]?.[0] ?? k}</Status>)}
          </div>
          {report.committed ? <Notice tone="success">Import enregistré : {report.summary.total} ligne(s) traitée(s), emplacements historisés.</Notice>
            : invalid > 0 ? <Notice tone="danger">{invalid} ligne(s) invalide(s) : corrigez le fichier. Rien n’a été écrit.</Notice>
              : <Notice tone="info">Simulation sans écriture : vérifiez le bilan puis confirmez.</Notice>}
          <div className="table-scroll" style={{ maxHeight: 320 }}>
            <table>
              <thead><tr><th>Ligne</th><th>Code</th><th>Résultat</th><th>Détail</th></tr></thead>
              <tbody>
                {report.rows.map((r) => (
                  <tr key={r.line}><td>{r.line}</td><td className="mono">{r.code}</td><td><Status tone={ROW_STATUS[r.status]?.[1] ?? 'neutral'}>{ROW_STATUS[r.status]?.[0] ?? r.status}</Status></td><td className="small">{r.errors.join(' ')}</td></tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </Modal>
  )
}
