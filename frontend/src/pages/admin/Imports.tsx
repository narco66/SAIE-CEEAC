import { useRef, useState, type DragEvent } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, fileUrl, type ApiError } from '../../lib/api'
import { fmtDateTime } from '../../lib/format'
import { Button, EmptyState, ErrorState, Icon, Loading, Notice, PageHeading, Status, Tabs, useToast } from '../../components/ui'
import { asApiError } from './common'
import './admin.css'

interface ImportRow { line: number; key: string; status: 'created' | 'updated' | 'unchanged' | 'invalid'; errors: string[]; changes?: string[] }
interface ImportResult { summary: { created: number; updated: number; unchanged: number; invalid: number; duplicates: number; total: number; written: boolean }; rows: ImportRow[] }
interface ImportRun { id: string; type: string; file_name: string; status: string; summary: ImportResult['summary'] | null; creator: string | null; created_at: string | null }

const TYPE_LABEL: Record<string, string> = {
  sites: 'Sites', buildings: 'Bâtiments', floors: 'Niveaux', zones: 'Zones de diffusion', locations: 'Emplacements', structures: 'Structures', categories: 'Catégories',
  languages: 'Langues', service_types: 'Types de prestation', holidays: 'Jours non ouvrés', equipment: 'Équipements', rooms: 'Salles',
}
const ROW_STATUS: Record<string, [string, string]> = { created: ['Création', 'success'], updated: ['Mise à jour', 'blue'], unchanged: ['Inchangée', 'neutral'], invalid: ['Invalide', 'danger'] }
type Filter = 'all' | 'valid' | 'invalid' | 'duplicates'
const isDuplicate = (r: ImportRow) => r.errors.some((e) => e.startsWith('Doublon'))

/** V37 Imports CSV : modèle, simulation sans écriture, erreurs ligne par ligne, confirmation seulement sans ligne invalide. */
export default function Imports() {
  const client = useQueryClient()
  const toast = useToast()
  const history = useQuery({ queryKey: ['imports'], queryFn: ({ signal }) => api.get<{ data: ImportRun[]; types: string[] }>('/imports', undefined, signal) })
  const [type, setType] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [result, setResult] = useState<ImportResult | null>(null)
  const [simulatedFor, setSimulatedFor] = useState<string | null>(null)
  const [busy, setBusy] = useState<'simulate' | 'commit' | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const [filter, setFilter] = useState<Filter>('all')
  const [over, setOver] = useState(false)
  const input = useRef<HTMLInputElement>(null)

  const types = history.data?.types ?? []
  const current = type || types[0] || ''
  const signature = file ? `${current}|${file.name}|${file.size}|${file.lastModified}` : null
  const fresh = !!result && simulatedFor === signature && !result.summary.written

  const choose = (f: File | null) => {
    setFile(f)
    setResult(null)
    setError(null)
  }
  const run = async (mode: 'simulate' | 'commit') => {
    if (!file) return
    setBusy(mode)
    setError(null)
    const form = new FormData()
    form.append('type', current)
    form.append('file', file)
    try {
      const r = await api.upload<{ data: ImportResult }>(`/imports/${mode}`, form)
      setResult(r.data)
      setSimulatedFor(signature)
      if (mode === 'commit') {
        toast.success('Import confirmé', `${r.data.summary.created} création(s), ${r.data.summary.updated} mise(s) à jour`)
        await client.invalidateQueries({ queryKey: ['imports'] })
        await client.invalidateQueries({ queryKey: ['referentials'] })
      }
    } catch (e) {
      const err = asApiError(e)
      const details = err.details as Partial<ImportResult>
      if (details?.summary && details.rows) {
        setResult(details as ImportResult)
        setSimulatedFor(signature)
      }
      setError(err)
    } finally {
      setBusy(null)
    }
  }
  const onDrop = (e: DragEvent) => {
    e.preventDefault()
    setOver(false)
    if (e.dataTransfer.files[0]) choose(e.dataTransfer.files[0])
  }
  const exportErrors = () => {
    if (!result) return
    const esc = (v: string) => `"${v.replace(/"/g, '""')}"`
    const lines = ['ligne;cle;statut;erreurs', ...result.rows.filter((r) => r.status === 'invalid').map((r) => [r.line, esc(r.key), r.status, esc(r.errors.join(' | '))].join(';'))]
    const blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `erreurs-import-${current}-${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  }

  const rows = (result?.rows ?? []).filter((r) => filter === 'all' || (filter === 'valid' ? r.status !== 'invalid' : filter === 'invalid' ? r.status === 'invalid' : isDuplicate(r)))

  return (
    <>
      <PageHeading title="Imports" description="Chargement de référentiels par fichier CSV : simulation obligatoire, aucune suppression implicite, correspondance par code métier." />
      {history.isLoading ? <Loading /> : history.isError ? <ErrorState error={history.error} onRetry={() => history.refetch()} /> : (
        <>
          <section className="panel">
            <div className="panel-title"><div><h2>Nouvel import</h2><p>1. Choisir le type · 2. Télécharger le modèle · 3. Déposer le fichier · 4. Simuler · 5. Confirmer</p></div></div>
            <div className="panel-body" style={{ display: 'grid', gap: 14 }}>
              <div className="inline-form">
                <label className="field" htmlFor="imp-type">
                  <span>Type de référentiel</span>
                  <select id="imp-type" className="input" value={current} onChange={(e) => { setType(e.target.value); setResult(null); setError(null) }}>
                    {types.map((t) => <option key={t} value={t}>{TYPE_LABEL[t] ?? t}</option>)}
                  </select>
                </label>
                {current && <a className="button secondary" href={fileUrl(`/imports/template/${current}`)} download><Icon name="download" size={16} /><span>Modèle CSV</span></a>}
              </div>
              <div className={`dropzone ${over ? 'over' : ''}`} onDragOver={(e) => { e.preventDefault(); setOver(true) }} onDragLeave={() => setOver(false)} onDrop={onDrop}>
                <Icon name="upload" size={26} />
                {file ? <strong>{file.name} · {(file.size / 1024).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} Ko</strong> : <span>Déposez un fichier CSV (séparateur « ; » ou « , », UTF-8, 5 Mo maximum)</span>}
                <input ref={input} id="imp-file" type="file" accept=".csv,text/csv,text/plain" className="sr-only" onChange={(e) => choose(e.target.files?.[0] ?? null)} />
                <Button variant="secondary" size="small" onClick={() => input.current?.click()}>{file ? 'Changer de fichier' : 'Choisir un fichier'}</Button>
              </div>
              <div className="row-actions" style={{ justifyContent: 'flex-start' }}>
                <Button variant="secondary" icon="eye" onClick={() => run('simulate')} busy={busy === 'simulate'} disabled={!file || !!busy}>Simuler (aucune écriture)</Button>
                <Button icon="check" onClick={() => run('commit')} busy={busy === 'commit'} disabled={!fresh || result!.summary.invalid > 0 || !!busy}
                  title={!fresh ? 'Lancez d’abord une simulation sur ce fichier' : result!.summary.invalid > 0 ? 'Corrigez les lignes invalides avant de confirmer' : undefined}>Confirmer l’import</Button>
                {result && result.summary.invalid > 0 && <Button variant="secondary" icon="download" onClick={exportErrors}>Exporter les erreurs (CSV)</Button>}
              </div>
              {fresh && result!.summary.invalid > 0 && <Notice tone="warning">{result!.summary.invalid} ligne(s) invalide(s) : la confirmation reste désactivée tant que le fichier n’est pas corrigé et de nouveau simulé.</Notice>}
              {error && <Notice tone="danger">{error.message}{error.field('file') ? ` — ${error.field('file')}` : ''}</Notice>}
              {result?.summary.written && <Notice tone="success">Import enregistré : {result.summary.created} création(s), {result.summary.updated} mise(s) à jour, {result.summary.unchanged} ligne(s) inchangée(s).</Notice>}
            </div>
          </section>

          {result && (
            <section className="panel table-panel section-gap">
              <div className="panel-title"><div><h2>{result.summary.written ? 'Bilan de l’import' : 'Bilan de la simulation'}</h2><p>{result.summary.written ? 'Données écrites' : 'Aucune donnée n’a été écrite'} · {TYPE_LABEL[current] ?? current}</p></div></div>
              <div className="panel-body">
                <div className="import-summary">
                  {([['Lignes', result.summary.total], ['Créations', result.summary.created], ['Mises à jour', result.summary.updated], ['Inchangées', result.summary.unchanged], ['Invalides', result.summary.invalid], ['Doublons', result.summary.duplicates]] as [string, number][]).map(([l, v]) => (
                    <div className="metric-card" key={l}><span>{l}</span><strong className={l === 'Invalides' && v > 0 ? 'red-text' : ''}>{v}</strong></div>
                  ))}
                </div>
                <Tabs<Filter> value={filter} onChange={setFilter} items={[
                  { value: 'all', label: 'Toutes', count: result.rows.length },
                  { value: 'valid', label: 'Valides', count: result.rows.filter((r) => r.status !== 'invalid').length },
                  { value: 'invalid', label: 'Invalides', count: result.summary.invalid },
                  { value: 'duplicates', label: 'Doublons', count: result.summary.duplicates },
                ]} />
              </div>
              {rows.length === 0 ? <EmptyState title="Aucune ligne" /> : (
                <div className="table-scroll" style={{ maxHeight: 480 }}>
                  <table>
                    <thead><tr><th scope="col">Ligne</th><th scope="col">Clé</th><th scope="col">Résultat</th><th scope="col">Erreurs / champs modifiés</th></tr></thead>
                    <tbody>
                      {rows.map((r) => (
                        <tr key={r.line}>
                          <td className="mono">{r.line}</td>
                          <td className="mono">{r.key || '—'}</td>
                          <td><Status tone={ROW_STATUS[r.status][1]}>{isDuplicate(r) ? 'Doublon' : ROW_STATUS[r.status][0]}</Status></td>
                          <td>{r.errors.length ? <ul className="admin-bullets red-text">{r.errors.map((e, i) => <li key={i}>{e}</li>)}</ul> : r.changes?.length ? <span className="muted">{r.changes.join(', ')}</span> : '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>
          )}

          <section className="panel table-panel section-gap">
            <div className="panel-title"><div><h2>Historique</h2><p>30 derniers imports confirmés</p></div></div>
            {(history.data?.data ?? []).length === 0 ? <EmptyState title="Aucun import confirmé" /> : (
              <div className="table-scroll">
                <table>
                  <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Fichier</th><th scope="col">Résultat</th><th scope="col">Bilan</th><th scope="col">Par</th></tr></thead>
                  <tbody>
                    {history.data!.data.map((h) => (
                      <tr key={h.id}>
                        <td>{fmtDateTime(h.created_at)}</td>
                        <td>{TYPE_LABEL[h.type] ?? h.type}</td>
                        <td className="mono">{h.file_name}</td>
                        <td><Status tone={h.status === 'committed' ? 'success' : 'neutral'}>{h.status === 'committed' ? 'Confirmé' : h.status}</Status></td>
                        <td>{h.summary ? `${h.summary.created} créée(s) · ${h.summary.updated} mise(s) à jour · ${h.summary.unchanged} inchangée(s)` : '—'}</td>
                        <td>{h.creator ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </>
      )}
    </>
  )
}
