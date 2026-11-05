import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useAction, useList } from '../../lib/hooks'
import { fmtDateTime, fmtRelative, SERVICE_STATUS } from '../../lib/format'
import { Button, EmptyState, ErrorState, Field, Icon, Loading, Modal, Notice, PageHeading, Pagination, Select, StatusOf, useToast } from '../../components/ui'
import { slotLabel, TZ_LABEL, useExportPdf, type UserRef } from './shared'

interface ChecklistRow { id: string; phase: 'opening' | 'closing'; label: string; done_at: string | null; note: string | null }
interface ServiceRequest {
  id: string; type: { code: string; name: string }; headcount?: number | null; serve_time?: string | null; details: string | null; status: string; due_at: string | null; assignee: UserRef | null; response_note: string | null
  meeting: { reference: string; subject: string; room: string; floor: string; participants: number; starts_at: string; ends_at: string; occurrence_id: string }
  checklist: ChecklistRow[]
}
type NoteAction = { item: ServiceRequest; status: 'clarification' | 'impossible' }

/** V32 Prestations de réunion : seulement les données nécessaires au type de prestation attribué. */
export default function Services() {
  const { me } = useAuth()
  const [status, setStatus] = useState('')
  const list = useList<ServiceRequest>('service-requests', '/service-requests', { status: status || undefined })
  const pdf = useExportPdf()
  const [noteAction, setNoteAction] = useState<NoteAction | null>(null)
  const [checklist, setChecklist] = useState<ServiceRequest | null>(null)
  const change = useAction((v: { id: string; status: string; note?: string }) => api.patch(`/service-requests/${v.id}`, { status: v.status, note: v.note ?? null }), { success: 'Prestation mise à jour', invalidate: ['service-requests'] })

  return (
    <>
      <PageHeading title="Prestations de réunion" description={`Demandes relevant de vos types de prestation · ${TZ_LABEL}`} demo={me.demo_mode} />
      <section className="panel table-panel">
        <div className="toolbar">
          <Select label="État" value={status} onChange={(v) => { setStatus(v); list.setPage(1) }} allLabel="Toutes (hors annulées)" options={Object.entries(SERVICE_STATUS).map(([value, [label]]) => ({ value, label }))} />
          <div className="toolbar-spacer" />
          <span className="muted small">Réunions confidentielles : objet masqué</span>
        </div>
        {list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? (
          <EmptyState title="Aucune prestation" text="Aucune demande ne correspond à ce filtre pour vos types de prestation." />
        ) : (
          <div className="table-scroll">
            <table>
              <thead><tr><th>Réunion</th><th>Type</th><th>Besoin</th><th>Échéance</th><th>Responsable</th><th>État</th><th><span className="sr-only">Actions</span></th></tr></thead>
              <tbody>
                {list.items.map((s) => {
                  const closed = ['ready', 'impossible', 'cancelled'].includes(s.status)
                  const late = s.due_at && !closed && new Date(s.due_at).getTime() < Date.now()
                  const busy = change.isPending && change.variables?.id === s.id
                  const done = s.checklist.filter((c) => c.done_at).length
                  return (
                    <tr key={s.id}>
                      <td><strong>{s.meeting.subject}</strong><small className="muted">{s.meeting.reference} · {s.meeting.room} ({s.meeting.floor}) · {s.meeting.participants} pers.</small><small className="muted">{slotLabel(s.meeting.starts_at, s.meeting.ends_at)}</small></td>
                      <td>{s.type.name}{(s.headcount || s.serve_time) && <small><strong>{[s.headcount ? `${s.headcount} couvert${s.headcount > 1 ? 's' : ''}` : null, s.serve_time ? `service à ${s.serve_time}` : null].filter(Boolean).join(' · ')}</strong></small>}</td>
                      <td>{s.details || <span className="muted">—</span>}{s.response_note && <small className="muted">Note : {s.response_note}</small>}</td>
                      <td>{s.due_at ? <>{fmtDateTime(s.due_at)}<small className={late ? '' : 'muted'} style={late ? { color: 'var(--red)' } : undefined}>{late ? 'En retard · ' : ''}{fmtRelative(s.due_at)}</small></> : '—'}</td>
                      <td>{s.assignee?.name ?? <span className="muted">Non attribuée</span>}</td>
                      <td><StatusOf map={SERVICE_STATUS} value={s.status} /></td>
                      <td>
                        <div className="row-actions">
                          {s.status !== 'cancelled' && (closed
                            ? <Button size="small" variant="secondary" busy={busy} onClick={() => change.mutate({ id: s.id, status: 'accepted' })}>Rouvrir</Button>
                            : <>
                              {s.status !== 'accepted' && <Button size="small" icon="check" busy={busy} onClick={() => change.mutate({ id: s.id, status: 'accepted' })}>Accepter</Button>}
                              <Button size="small" variant="secondary" onClick={() => setNoteAction({ item: s, status: 'clarification' })}>Demander une précision</Button>
                              <Button size="small" variant="secondary" icon="check" busy={busy} onClick={() => change.mutate({ id: s.id, status: 'ready' })}>Déclarer prête</Button>
                              <Button size="small" variant="secondary" icon="x" onClick={() => setNoteAction({ item: s, status: 'impossible' })}>Impossible</Button>
                            </>)}
                          <Button size="small" variant="secondary" icon="list" onClick={() => setChecklist(s)}>Liste de contrôle{s.checklist.length ? ` (${done}/${s.checklist.length})` : ''}</Button>
                          <Button size="small" variant="ghost" icon="download" busy={pdf.busy === 'meeting_preparation'} onClick={() => pdf.run('meeting_preparation', { occurrence_id: s.meeting.occurrence_id }, 'Fiche de préparation PDF03')}>PDF03</Button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
        <Pagination meta={list.meta} onPage={list.setPage} />
      </section>
      {noteAction && <NoteDialog action={noteAction} onClose={() => setNoteAction(null)} />}
      {checklist && <ChecklistDialog item={checklist} onClose={() => setChecklist(null)} />}
    </>
  )
}

function NoteDialog({ action, onClose }: { action: NoteAction; onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const clarif = action.status === 'clarification'
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      await api.patch(`/service-requests/${action.item.id}`, { status: action.status, note: note.trim() })
      toast.success(clarif ? 'Précision demandée' : 'Prestation déclarée impossible', 'L’organisateur est notifié.')
      client.invalidateQueries({ queryKey: ['service-requests'] })
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title={clarif ? 'Demander une précision' : 'Déclarer la prestation impossible'} kicker={`${action.item.type.name} · ${action.item.meeting.reference}`} onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button variant={clarif ? 'primary' : 'danger'} busy={busy} disabled={!note.trim()} onClick={submit}>{clarif ? 'Envoyer la demande' : 'Déclarer impossible'}</Button>
    </>}>
      <Field label={clarif ? 'Question à l’organisateur' : 'Motif'} required error={error?.field('note')} help="Transmis à l’organisateur et inscrit au journal (500 caractères max.)">
        <textarea value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />
      </Field>
      {error && !error.field('note') && <div style={{ marginTop: 10 }}><Notice tone="danger">{error.message}</Notice></div>}
    </Modal>
  )
}

/** Liste de contrôle d'ouverture et de remise en état (SAL-14, P1) : chaque constat est horodaté par le serveur. */
function ChecklistDialog({ item, onClose }: { item: ServiceRequest; onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const [rows, setRows] = useState(item.checklist.map((c) => ({ id: c.id as string | undefined, phase: c.phase, label: c.label, done: !!c.done_at, done_at: c.done_at, note: c.note ?? '' })))
  const [newLabel, setNewLabel] = useState<Record<'opening' | 'closing', string>>({ opening: '', closing: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const add = (phase: 'opening' | 'closing') => {
    if (!newLabel[phase].trim()) return
    setRows([...rows, { id: undefined, phase, label: newLabel[phase].trim(), done: false, done_at: null, note: '' }])
    setNewLabel({ ...newLabel, [phase]: '' })
  }
  const save = async () => {
    setBusy(true)
    setError(null)
    try {
      await api.post(`/occurrences/${item.meeting.occurrence_id}/checklist`, { items: rows.map((r) => ({ ...(r.id ? { id: r.id } : {}), phase: r.phase, label: r.label, done: r.done, note: r.note.trim() || null })) }, false)
      toast.success('Liste de contrôle enregistrée', 'Les constats sont horodatés.')
      client.invalidateQueries({ queryKey: ['service-requests'] })
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Liste de contrôle (P1)" kicker={`${item.meeting.reference} · ${item.meeting.room}`} wide onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button busy={busy} onClick={save}>Enregistrer les constats</Button>
    </>}>
      <p className="small muted">{slotLabel(item.meeting.starts_at, item.meeting.ends_at)} · un point coché ne peut plus être décoché : le constat est horodaté et attribué.</p>
      {(['opening', 'closing'] as const).map((phase) => (
        <div className="checklist-phase" key={phase}>
          <h3>{phase === 'opening' ? 'Ouverture (avant la réunion)' : 'Remise en état (après la réunion)'}</h3>
          {rows.filter((r) => r.phase === phase).length === 0 && <p className="muted small">Aucun point.</p>}
          {rows.map((r, i) => r.phase !== phase ? null : (
            <div className="checklist-item" key={r.id ?? `new-${i}`}>
              <input type="checkbox" checked={r.done} disabled={!!r.done_at} aria-label={`Constat : ${r.label}`} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, done: e.target.checked } : x)))} />
              <span>{r.label}{r.done_at ? <small>Fait {fmtDateTime(r.done_at)}</small> : r.done ? <small>Sera horodaté à l’enregistrement</small> : <small>À faire</small>}</span>
              <input className="input" placeholder="Observation" maxLength={200} value={r.note} aria-label={`Observation pour ${r.label}`} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, note: e.target.value } : x)))} />
            </div>
          ))}
          <div className="inline-form" style={{ marginTop: 6 }}>
            <input className="input" style={{ maxWidth: 360 }} placeholder="Nouveau point de contrôle" maxLength={200} value={newLabel[phase]} aria-label={`Nouveau point (${phase === 'opening' ? 'ouverture' : 'remise en état'})`}
              onChange={(e) => setNewLabel({ ...newLabel, [phase]: e.target.value })} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); add(phase) } }} />
            <Button size="small" variant="secondary" icon="plus" onClick={() => add(phase)} disabled={!newLabel[phase].trim()}>Ajouter</Button>
          </div>
        </div>
      ))}
      {error && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}</Notice></div>}
      <div className="footer-note" style={{ marginTop: 12 }}><Icon name="shield" size={14} /> Constats inscrits au journal (pouvoir « prestations »)</div>
    </Modal>
  )
}
