import { useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router'
import { useList } from '../../lib/hooks'
import { fmtDateTime, LANG_LABEL } from '../../lib/format'
import { Button, EmptyState, ErrorState, Kv, Loading, Modal, Notice, PageHeading, Pagination, Status } from '../../components/ui'
import { useAuth } from '../../lib/auth'
import { ExportDialog, periodBounds, POWER_LABEL } from './common'
import './admin.css'

interface AuditRow {
  id: string; occurred_at: string; actor: string | null; actor_type: string | null; role: string | null; power: string | null; action: string; action_label?: string
  object_type: string | null; object_id: string | null; object_label: string | null; lang: string | null; version: number | string | null
  reason: string | null; outcome: string; request_id: string | null; ip: string | null; before: unknown; after: unknown
}

const OUTCOME: Record<string, [string, string]> = { success: ['Succès', 'success'], denied: ['Refusé', 'danger'], failure: ['Échec', 'warning'] }
const ACTION_PREFIXES = ['auth', 'account', 'user', 'role', 'power', 'content', 'campaign', 'alert', 'reservation', 'document', 'asset', 'device', 'screen', 'incident', 'export', 'import', 'referential', 'setting']
const EMPTY = { from: '', to: '', actor: '', action: '', object_type: '', object_id: '', outcome: '', power: '' }

/** V38 Journal d'audit : lecture seule, filtres serveur, détail avant/après ; aucune édition possible. */
export default function Audit() {
  const { can } = useAuth()
  const [params] = useSearchParams()
  // Lien profond depuis une fiche (compte, rôle) : filtres initiaux lus dans l'adresse.
  const initial = { ...EMPTY, action: params.get('action') ?? '', object_type: params.get('object_type') ?? '', object_id: params.get('object_id') ?? '' }
  const [draft, setDraft] = useState(initial)
  const [filters, setFilters] = useState(initial)
  const [detail, setDetail] = useState<AuditRow | null>(null)
  const [exporting, setExporting] = useState(false)
  const bounds = periodBounds(filters.from, filters.to)
  const list = useList<AuditRow>('audit', '/audit-logs', {
    ...bounds, actor: filters.actor || undefined, action: filters.action || undefined, object_type: filters.object_type || undefined,
    object_id: filters.object_id || undefined, outcome: filters.outcome || undefined, power: filters.power || undefined,
  }, 50)

  const apply = (e: FormEvent) => {
    e.preventDefault()
    setFilters(draft)
    list.setPage(1)
  }

  return (
    <>
      <PageHeading
        title="Journal d’audit"
        description="Trace inaltérable des actions : acteur, rôle, pouvoir étendu, objet, motif et résultat. Les secrets sont toujours masqués."
        actions={can('audit.view') && can('exports.create') && <Button variant="secondary" icon="download" onClick={() => setExporting(true)}>Extrait PDF09 / CSV</Button>}
      />
      <section className="panel table-panel">
        {filters.object_id && (
          <div style={{ marginBottom: 10 }}>
            <Notice tone="info">Journal limité à un objet précis (<span className="mono">{filters.object_id}</span>). « Réinitialiser » retire ce filtre.</Notice>
          </div>
        )}
        <form className="filters-grid" onSubmit={apply} aria-label="Filtres du journal">
          <label>Du<input className="input" type="date" value={draft.from} max={draft.to || undefined} onChange={(e) => setDraft({ ...draft, from: e.target.value })} /></label>
          <label>Au<input className="input" type="date" value={draft.to} min={draft.from || undefined} onChange={(e) => setDraft({ ...draft, to: e.target.value })} /></label>
          <label>Acteur<input className="input" value={draft.actor} placeholder="Nom" onChange={(e) => setDraft({ ...draft, actor: e.target.value })} /></label>
          <label>Action (préfixe)
            <input className="input" list="audit-actions" value={draft.action} placeholder="ex. content.approved" onChange={(e) => setDraft({ ...draft, action: e.target.value })} />
            <datalist id="audit-actions">{ACTION_PREFIXES.map((a) => <option key={a} value={a} />)}</datalist>
          </label>
          <label>Type d’objet<input className="input" value={draft.object_type} placeholder="ex. content" onChange={(e) => setDraft({ ...draft, object_type: e.target.value })} /></label>
          <label>Résultat
            <select className="input" value={draft.outcome} onChange={(e) => setDraft({ ...draft, outcome: e.target.value })}>
              <option value="">Tous</option>{Object.entries(OUTCOME).map(([k, [l]]) => <option key={k} value={k}>{l}</option>)}
            </select>
          </label>
          <label>Pouvoir étendu
            <select className="input" value={draft.power} onChange={(e) => setDraft({ ...draft, power: e.target.value })}>
              <option value="">Tous</option>{Object.entries(POWER_LABEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
          </label>
          <Button type="submit" icon="filter">Filtrer</Button>
          <Button variant="ghost" onClick={() => { setDraft(EMPTY); setFilters(EMPTY); list.setPage(1) }}>Réinitialiser</Button>
        </form>
        {list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? <EmptyState title="Aucun événement" text="Aucune entrée du journal ne correspond à ces filtres." /> : (
          <>
            <div className="table-scroll">
              <table>
                <thead>
                  <tr><th scope="col">Date</th><th scope="col">Acteur</th><th scope="col">Rôle</th><th scope="col">Pouvoir</th><th scope="col">Action</th><th scope="col">Objet</th><th scope="col">Version</th><th scope="col">Langue</th><th scope="col">Motif</th><th scope="col">Résultat</th><th scope="col">Corrélation</th><th scope="col"><span className="sr-only">Détail</span></th></tr>
                </thead>
                <tbody>
                  {list.items.map((a) => (
                    <tr key={a.id}>
                      <td style={{ whiteSpace: 'nowrap' }}>{fmtDateTime(a.occurred_at)}</td>
                      <td>{a.actor ?? <span className="muted">{a.actor_type === 'device' ? 'Lecteur' : 'Système'}</span>}</td>
                      <td>{a.role ?? '—'}</td>
                      <td>{a.power ? <Status tone="warning">{POWER_LABEL[a.power] ?? a.power}</Status> : '—'}</td>
                      <td>{a.action_label ?? a.action}<small className="mono muted">{a.action}</small></td>
                      <td>{a.object_label ?? '—'}{a.object_type && <small>{a.object_type}</small>}</td>
                      <td>{a.version ?? '—'}</td>
                      <td>{a.lang ? a.lang.toUpperCase() : '—'}</td>
                      <td style={{ maxWidth: 220 }}>{a.reason ?? '—'}</td>
                      <td><Status tone={(OUTCOME[a.outcome] ?? [a.outcome, 'neutral'])[1]}>{(OUTCOME[a.outcome] ?? [a.outcome])[0]}</Status></td>
                      <td className="mono">{a.request_id ? a.request_id.slice(0, 8) : '—'}</td>
                      <td><Button size="small" variant="secondary" onClick={() => setDetail(a)} ariaLabel={`Détail de l’événement ${a.action}`}>Détail</Button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination meta={list.meta} onPage={list.setPage} />
          </>
        )}
      </section>
      {detail && (
        <Modal title={detail.action} kicker="ÉVÉNEMENT DU JOURNAL" onClose={() => setDetail(null)} wide>
          <Kv items={[
            ['Date', fmtDateTime(detail.occurred_at)], ['Acteur', detail.actor ?? (detail.actor_type === 'device' ? 'Lecteur' : 'Système')], ['Rôle', detail.role],
            ['Pouvoir étendu', detail.power ? POWER_LABEL[detail.power] ?? detail.power : null], ['Objet', detail.object_label ? `${detail.object_label} (${detail.object_type ?? '—'})` : detail.object_type],
            ['Identifiant d’objet', detail.object_id ? <span className="mono">{detail.object_id}</span> : null], ['Version', detail.version], ['Langue', detail.lang ? LANG_LABEL[detail.lang] ?? detail.lang : null],
            ['Motif', detail.reason], ['Résultat', (OUTCOME[detail.outcome] ?? [detail.outcome])[0]], ['Corrélation (request_id)', detail.request_id ? <span className="mono">{detail.request_id}</span> : null],
            ['Adresse IP', detail.ip],
          ]} />
          <div className="grid-2 section-gap">
            <div><strong className="small">Avant</strong><pre className="code-block">{detail.before == null ? '—' : JSON.stringify(detail.before, null, 2)}</pre></div>
            <div><strong className="small">Après</strong><pre className="code-block">{detail.after == null ? '—' : JSON.stringify(detail.after, null, 2)}</pre></div>
          </div>
          <div style={{ marginTop: 12 }}><Notice tone="info">Le journal est en lecture seule : aucune entrée ne peut être modifiée ni supprimée depuis l’interface.</Notice></div>
        </Modal>
      )}
      {exporting && (
        <ExportDialog type="audit_extract" title="Extrait du journal" params={{
          action: filters.action || undefined, actor: filters.actor || undefined, object_type: filters.object_type || undefined, outcome: filters.outcome || undefined, power: filters.power || undefined,
        }} initialFrom={filters.from} initialTo={filters.to} onClose={() => setExporting(false)}>
          {[filters.action, filters.actor, filters.object_type, filters.outcome, filters.power].some(Boolean) && <Notice tone="info">Les filtres du journal (acteur, action, type d’objet, résultat, pouvoir) sont appliqués à l’extrait.</Notice>}
        </ExportDialog>
      )}
    </>
  )
}
