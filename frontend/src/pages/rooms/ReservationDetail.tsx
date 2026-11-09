import { useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, fileUrl, type Paginated } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useData } from '../../lib/hooks'
import { CLASS_LABEL, CLASS_TONE, CONTENT_STATUS, fmtDateTime, fmtRelative, fmtTime, LANG_LABEL, OCC_STATUS, SERVICE_STATUS } from '../../lib/format'
import { Button, ConfirmDialog, ErrorState, Field, Icon, Kv, Loading, Modal, Notice, PageHeading, Status, StatusOf, useDebounced, useToast } from '../../components/ui'
import { OccurrenceEditor } from './OccurrenceEditor'
import { CancelOccurrenceDialog, ensureArray, MEMBER_STATES, recurrenceLabel, Section, slotLabel, TZ_LABEL, useExportPdf, useReferentials, type Occurrence, type Reservation } from './shared'

const CHECK_IN_LEAD_MS = 15 * 60_000
type Dialog =
  | { kind: 'edit'; o: Occurrence } | { kind: 'cancel'; o: Occurrence } | { kind: 'noshow'; o: Occurrence } | { kind: 'checkin'; o: Occurrence } | { kind: 'checkin-code'; o: Occurrence }
  | { kind: 'approve' } | { kind: 'refuse' } | { kind: 'welcome' } | { kind: 'agenda' } | { kind: 'priority' }

/** V28 Détail réservation. N'affiche jamais plus que ce que renvoie l'API (masquage confidentiel côté serveur). */
export default function ReservationDetail() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const client = useQueryClient()
  const toast = useToast()
  const { can } = useAuth()
  const pdf = useExportPdf()
  const refs = useReferentials()
  const query = useData<Reservation>(['reservation', id], `/reservations/${id}`)
  const [dialog, setDialog] = useState<Dialog | null>(null)

  if (query.isLoading) return <Loading />
  if (query.isError || !query.data) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const r = query.data
  const perms = r.permissions ?? { modify: false, approve: false, no_show: false, priority: false, welcome: false }
  const update = (next: Reservation) => {
    client.setQueryData(['reservation', id], next)
    client.invalidateQueries({ queryKey: ['reservations'] })
    client.invalidateQueries({ queryKey: ['rooms-calendar'] })
  }
  const occurrences = [...r.occurrences].sort((a, b) => a.starts_at.localeCompare(b.starts_at))
  const now = Date.now()
  const canPrep = can('services.process', 'reservations.approve')
  const userName = (uid: string) => refs.data?.users.find((u) => u.id === uid)?.name ?? 'Agent'
  const confirmedFuture = occurrences.filter((o) => o.status === 'confirmed' && new Date(o.ends_at).getTime() > now)
  const presence = r.presence ?? { enabled: false, codes: {}, checked_in: {} }
  const isOrganizer = Object.keys(presence.codes).length > 0
  const checkedCount = occurrences.filter((o) => presence.checked_in[o.id]).length
  const inCheckInWindow = (o: Occurrence) => !presence.checked_in[o.id] && ['confirmed', 'in_progress'].includes(o.status)
    && new Date(o.starts_at).getTime() - CHECK_IN_LEAD_MS <= now && now < new Date(o.ends_at).getTime()
  const canCheckIn = (o: Occurrence) => (isOrganizer || perms.no_show) && inCheckInWindow(o)
  // SAL-10 : dans une salle où la libération automatique est active, un participant muni du code temporaire confirme la présence.
  const canCheckInWithCode = (o: Occurrence) => presence.enabled && !isOrganizer && !perms.no_show && inCheckInWindow(o)

  return (
    <>
      <PageHeading
        title={r.subject}
        description={`${r.reference} · ${r.confidential ? 'Réunion confidentielle' : 'Réunion standard'} · ${TZ_LABEL}`}
        actions={<>
          <a className="button secondary" href={fileUrl(`/reservations/${r.id}/calendar.ics`)} download title="Fichier iCalendar à importer dans un agenda"><Icon name="calendar" size={16} /><span>Ajouter à l’agenda</span></a>
          {r.can_manage && (can('reservations.create') || can('reservations.approve')) && <Button variant="secondary" icon="download" busy={pdf.busy === 'reservation_confirmation'} onClick={() => pdf.run('reservation_confirmation', { reservation_id: r.id }, 'Confirmation de réservation')}>Confirmation (PDF)</Button>}
          {perms.welcome && <Button variant="secondary" icon="users" disabled={confirmedFuture.length === 0} title={confirmedFuture.length ? undefined : 'Exige une occurrence confirmée à venir'} onClick={() => setDialog({ kind: 'welcome' })}>Message de bienvenue</Button>}
          {perms.approve && <Button variant="secondary" icon="x" onClick={() => setDialog({ kind: 'refuse' })}>Refuser</Button>}
          {perms.approve && <Button icon="check" onClick={() => setDialog({ kind: 'approve' })}>Approuver</Button>}
        </>}
      />
      {r.confidential && !r.can_manage && <Notice tone="warning" icon="shield">Réunion confidentielle : seuls le créneau et la salle sont communiqués.</Notice>}

      <div className="grid-2">
        <section className="panel">
          <div className="panel-title"><div><h2>Résumé</h2><p>Créée {fmtRelative(r.created_at)} · {fmtDateTime(r.created_at)}</p></div>{r.confidential ? <Status tone="warning">Confidentielle</Status> : <Status tone="neutral">Standard</Status>}</div>
          <div className="panel-body">
            <Kv items={[
              ['Référence', <span className="mono">{r.reference}</span>],
              ['Objet', r.subject],
              ['Organisateur', r.organizer?.name ?? '—'],
              ['Structure', r.structure ?? '—'],
              ['Participants attendus', `${r.participant_count} (nombre déclaré)`],
              ['Présence', presence.enabled
                ? `${checkedCount} occurrence(s) sur ${occurrences.length} confirmée(s) · libération automatique 15 min après le début sans confirmation`
                : <span className="muted">Présence non mesurée</span>],
              ['Récurrence', recurrenceLabel(r.recurrence)],
              ['Réunion prioritaire', <span className="row-actions">
                {r.is_priority ? 'Oui · exemptée de la libération automatique' : 'Non'}
                {perms.priority && <Button size="small" variant="secondary" onClick={() => setDialog({ kind: 'priority' })}>{r.is_priority ? 'Retirer la priorité' : 'Déclarer prioritaire'}</Button>}
              </span>],
              ['Description', r.description || '—'],
            ]} />
          </div>
        </section>
        <section className="panel">
          <div className="panel-title"><div><h2>Participants</h2><p>{r.participant_count} attendu(s) · {presence.enabled ? 'présence confirmée par l’organisateur, sans comptage nominatif' : 'présence non mesurée'}</p></div></div>
          <div className="panel-body">
            {r.internal_participants === null && r.external_participants === null ? <p className="muted small">Liste non communiquée.</p> : (
              <>
                <h3 className="small" style={{ fontWeight: 700, marginBottom: 6 }}>Agents de la Commission</h3>
                {ensureArray(r.internal_participants).length ? <div className="chip-row">{ensureArray(r.internal_participants).map((u) => <span className="chip" key={u}>{userName(u)}</span>)}</div> : <p className="muted small">Aucun agent nommé.</p>}
                <h3 className="small" style={{ fontWeight: 700, margin: '12px 0 6px' }}>Participants externes</h3>
                {ensureArray(r.external_participants).length ? (
                  <ul className="small" style={{ margin: '0 0 8px 16px' }}>{ensureArray(r.external_participants).map((p, i) => <li key={i}>{p.name}{p.organization ? ` — ${p.organization}` : ''}</li>)}</ul>
                ) : <p className="muted small">Aucun participant externe.</p>}
              </>
            )}
            {r.external_notice && <div style={{ marginTop: 10 }}><Notice tone="info" icon="shield">{r.external_notice}</Notice></div>}
          </div>
        </section>
      </div>

      <Section title="Occurrences" description={`${occurrences.length} occurrence(s) · l’échec d’une modification conserve la réservation initiale`}>
        <div className="table-scroll">
          <table>
            <thead><tr><th>#</th><th>Créneau</th><th>Salle</th><th>Statut</th><th>Blocage salle</th><th>Prestations</th><th><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {occurrences.map((o) => {
                const active = ['pending', 'confirmed'].includes(o.status)
                const started = new Date(o.starts_at).getTime() <= now
                const reason = o.cancel_reason ?? o.no_show_reason ?? o.decision_reason
                return (
                  <tr key={o.id}>
                    <td>{o.sequence}</td>
                    <td>{slotLabel(o.starts_at, o.ends_at)}{o.status === 'pending' && o.hold_until && <small className="muted">Bloquée jusqu’au {fmtDateTime(o.hold_until)}</small>}</td>
                    <td>{o.room.name ?? '—'}<small className="muted">{o.room.floor ?? ''}</small></td>
                    <td>
                      <StatusOf map={OCC_STATUS} value={o.status} />{reason && <small className="muted">Motif : {reason}</small>}
                      {presence.checked_in[o.id] && <small className="muted">Présence confirmée à {fmtTime(presence.checked_in[o.id])}</small>}
                      {presence.enabled && presence.codes[o.id] && !presence.checked_in[o.id] && ['pending', 'confirmed', 'in_progress'].includes(o.status) && <small className="muted">Code de présence : <span className="mono">{presence.codes[o.id]}</span></small>}
                    </td>
                    <td>{o.blocking_start ? `${fmtTime(o.blocking_start)}–${fmtTime(o.blocking_end)}` : '—'}<small className="muted">préparation incluse</small></td>
                    <td>{o.services.length === 0 ? <span className="muted">—</span> : o.services.map((s) => <small key={s.id}>{s.label ?? s.type ?? 'Prestation'} : {(SERVICE_STATUS[s.status] ?? [s.status])[0]}{s.details ? ` (${s.details})` : ''}</small>)}</td>
                    <td>
                      <div className="row-actions">
                        {canCheckIn(o) && <Button size="small" icon="check" onClick={() => setDialog({ kind: 'checkin', o })}>Confirmer la présence</Button>}
                        {canCheckInWithCode(o) && <Button size="small" variant="secondary" icon="check" onClick={() => setDialog({ kind: 'checkin-code', o })}>Confirmer avec un code</Button>}
                        {perms.modify && active && !started && <Button size="small" variant="secondary" icon="edit" onClick={() => setDialog({ kind: 'edit', o })}>Modifier</Button>}
                        {perms.modify && active && !started && <Button size="small" variant="secondary" icon="x" onClick={() => setDialog({ kind: 'cancel', o })}>Annuler</Button>}
                        {perms.no_show && started && ['confirmed', 'in_progress', 'completed'].includes(o.status) && <Button size="small" variant="secondary" icon="alert" onClick={() => setDialog({ kind: 'noshow', o })}>Déclarer non honorée</Button>}
                        {canPrep && <Button size="small" variant="ghost" icon="download" busy={pdf.busy === 'meeting_preparation'} onClick={() => pdf.run('meeting_preparation', { occurrence_id: o.id }, 'Fiche de préparation PDF03')}>PDF03</Button>}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </Section>

      <div className="grid-2">
        <section className="panel section-gap agenda-detail">
          <div className="panel-title">
            <div><h2>Ordre du jour</h2><p>{r.agenda.length} point(s)</p></div>
            {perms.modify && r.can_manage && <Button size="small" variant="secondary" icon="edit" onClick={() => setDialog({ kind: 'agenda' })}>Modifier</Button>}
          </div>
          {r.agenda.length === 0 ? <p className="muted small" style={{ padding: '0 18px 14px' }}>Aucun point inscrit.</p> : (
            <ol>{r.agenda.map((a, i) => <li key={a.id}><span>{String(i + 1).padStart(2, '0')}</span><div><strong>{a.title}</strong><small>{[a.time_label, a.presenter].filter(Boolean).join(' · ') || '—'}</small></div></li>)}</ol>
          )}
          <div className="panel-body">
            <h3 className="small" style={{ fontWeight: 700, margin: '6px 0' }}>Documents associés</h3>
            {r.documents === undefined ? <p className="muted small">Non communiqués.</p> : r.documents.length === 0 ? <p className="muted small">Aucun document associé.</p> : (
              <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                {r.documents.map((d) => (
                  <li key={d.id} style={{ padding: '6px 0', borderTop: '1px solid #edf0f3', display: 'flex', gap: 8, alignItems: 'center' }}>
                    <Icon name="file" size={15} />
                    <a className="link-text" href={`/documents/${d.id}`} onClick={(e) => { e.preventDefault(); navigate(`/documents/${d.id}`) }}>{d.title}</a>
                    <span className="mono muted">{d.reference}</span>
                    <Status tone={CLASS_TONE[d.classification] ?? 'neutral'}>{CLASS_LABEL[d.classification] ?? d.classification}</Status>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </section>
        <section className="panel section-gap">
          <div className="panel-title"><div><h2>Prestations et ressources</h2><p>Statut transmis par les équipes de prestation</p></div></div>
          <div className="panel-body">
            {occurrences.every((o) => o.services.length === 0) ? <p className="muted small">Aucune prestation demandée.</p> : (
              <table><thead><tr><th>Prestation</th><th>Occurrence</th><th>Statut</th></tr></thead>
                <tbody>{occurrences.flatMap((o) => o.services.map((s) => <tr key={s.id}><td>{s.label ?? s.type}<small className="muted">{s.details ?? ''}</small></td><td>{slotLabel(o.starts_at, o.ends_at)}</td><td><StatusOf map={SERVICE_STATUS} value={s.status} /></td></tr>))}</tbody></table>
            )}
            <h3 className="small" style={{ fontWeight: 700, margin: '12px 0 6px' }}>Ressources mobiles</h3>
            {r.resources === undefined ? <p className="muted small">Non communiquées.</p> : r.resources.length === 0 ? <p className="muted small">Aucune ressource réservée.</p> : (
              <div className="chip-row">{r.resources.map((x, i) => <span className="chip" key={i}>{x.name} · {slotLabel(occurrences.find((o) => o.id === x.occurrence_id)?.starts_at, occurrences.find((o) => o.id === x.occurrence_id)?.ends_at)}</span>)}</div>
            )}
            {r.welcome && r.welcome.length > 0 && (
              <>
                <h3 className="small" style={{ fontWeight: 700, margin: '12px 0 6px' }}>Messages de bienvenue</h3>
                {r.welcome.map((w) => (
                  <div key={w.id} className="booking-link" style={{ padding: 10, marginBottom: 6 }}>
                    <Icon name="monitor" />
                    <span><strong>{w.title}</strong><small>{w.reference}</small></span>
                    <StatusOf map={CONTENT_STATUS} value={w.status} />
                    <Button size="small" variant="secondary" onClick={() => navigate(`/affichage/contenus/${w.id}/edition`)}>Ouvrir</Button>
                  </div>
                ))}
              </>
            )}
          </div>
        </section>
      </div>

      {r.history && (
        <Section title="Historique" description="Extrait du journal d’audit (30 dernières actions)">
          <div className="panel-body">
            {r.history.length === 0 ? <p className="muted small">Aucune action enregistrée.</p> : (
              <div className="timeline-list">
                {r.history.map((h, i) => (
                  <div key={i}><time>{fmtDateTime(h.occurred_at)}</time><span><strong>{h.label ?? h.action}</strong> · {h.actor_label ?? 'Système'}{h.reason ? ` — ${h.reason}` : ''}</span></div>
                ))}
              </div>
            )}
          </div>
        </Section>
      )}

      {dialog?.kind === 'edit' && <OccurrenceEditor reservation={r} occurrence={dialog.o} mode="modify" onClose={() => setDialog(null)} onDone={(next) => { update(next); toast.success('Occurrence(s) modifiée(s)') }} />}
      {dialog?.kind === 'cancel' && <CancelOccurrenceDialog reservation={r} occurrence={dialog.o} onClose={() => setDialog(null)} onDone={(next) => update(next)} />}
      {dialog?.kind === 'noshow' && (
        <ConfirmDialog title="Déclarer la réunion non honorée" object={`${r.reference} · ${slotLabel(dialog.o.starts_at, dialog.o.ends_at)}`} danger
          consequence="L’occurrence passe au statut « Non honorée » et l’organisateur est notifié. Cette déclaration manuelle ne mesure aucune présence."
          confirmLabel="Déclarer non honorée" reasonLabel="Motif (constat effectué)" reasonRequired onClose={() => setDialog(null)}
          onConfirm={async (reason) => { const res = await api.post<{ data: Reservation }>(`/occurrences/${dialog.o.id}/no-show`, { reason }); update(res.data); toast.success('Non-présentation déclarée') }} />
      )}
      {dialog?.kind === 'checkin' && (
        <ConfirmDialog title="Confirmer la présence" object={`${r.reference} · ${slotLabel(dialog.o.starts_at, dialog.o.ends_at)}`}
          consequence="La réunion est marquée comme tenue : la salle n’est pas libérée automatiquement. La confirmation est inscrite au journal."
          confirmLabel="Confirmer la présence" onClose={() => setDialog(null)}
          onConfirm={async () => { const res = await api.post<{ data: Reservation }>(`/occurrences/${dialog.o.id}/check-in`, {}); update(res.data); toast.success('Présence confirmée') }} />
      )}
      {dialog?.kind === 'priority' && (
        <ConfirmDialog title={r.is_priority ? 'Retirer la priorité' : 'Déclarer la réunion prioritaire'} object={`${r.reference} · ${r.subject}`}
          consequence={r.is_priority ? 'La salle pourra de nouveau être libérée automatiquement si la présence n’est pas confirmée.' : 'La salle ne sera jamais libérée automatiquement, même sans confirmation de présence.'}
          confirmLabel={r.is_priority ? 'Retirer la priorité' : 'Déclarer prioritaire'} reasonLabel="Motif" reasonRequired onClose={() => setDialog(null)}
          onConfirm={async (reason) => { const res = await api.post<{ data: Reservation }>(`/reservations/${r.id}/priority`, { is_priority: !r.is_priority, reason }); update(res.data); toast.success(r.is_priority ? 'Priorité retirée' : 'Réunion déclarée prioritaire') }} />
      )}
      {dialog?.kind === 'checkin-code' && (
        <ConfirmDialog title="Confirmer la présence avec un code" object={`${r.reference} · ${slotLabel(dialog.o.starts_at, dialog.o.ends_at)}`}
          consequence="Saisissez le code de présence communiqué par l’organisateur. La réunion est marquée comme tenue et la confirmation est inscrite au journal."
          confirmLabel="Confirmer la présence" reasonLabel="Code de présence" reasonRequired onClose={() => setDialog(null)}
          onConfirm={async (code) => { const res = await api.post<{ data: Reservation }>(`/occurrences/${dialog.o.id}/check-in`, { code: code.trim() }); update(res.data); toast.success('Présence confirmée') }} />
      )}
      {dialog?.kind === 'approve' && (
        <ConfirmDialog title="Approuver la réservation" object={`${r.reference} · ${r.subject}`} consequence="Les occurrences en attente sont confirmées et l’organisateur est notifié."
          confirmLabel="Approuver" onClose={() => setDialog(null)}
          onConfirm={async () => { const res = await api.post<{ data: Reservation }>(`/reservations/${r.id}/approve`); update(res.data); toast.success('Réservation approuvée') }} />
      )}
      {dialog?.kind === 'refuse' && (
        <ConfirmDialog title="Refuser la réservation" object={`${r.reference} · ${r.subject}`} danger consequence="Les occurrences en attente sont refusées ; le motif est communiqué à l’organisateur."
          confirmLabel="Refuser la demande" reasonLabel="Motif du refus" reasonRequired onClose={() => setDialog(null)}
          onConfirm={async (reason) => { const res = await api.post<{ data: Reservation }>(`/reservations/${r.id}/refuse`, { reason }); update(res.data); toast.success('Demande refusée') }} />
      )}
      {dialog?.kind === 'agenda' && <AgendaEditor reservation={r} onClose={() => setDialog(null)} onDone={update} />}
      {dialog?.kind === 'welcome' && <WelcomeDialog reservation={r} occurrences={confirmedFuture} onClose={() => setDialog(null)} />}
    </>
  )
}

interface DocLite { id: string; reference: string; title: string; classification: string }

function AgendaEditor({ reservation, onClose, onDone }: { reservation: Reservation; onClose: () => void; onDone: (r: Reservation) => void }) {
  const toast = useToast()
  const [items, setItems] = useState(reservation.agenda.map((a) => ({ title: a.title, time_label: a.time_label ?? '', presenter: a.presenter ?? '' })))
  const [docs, setDocs] = useState<DocLite[]>(reservation.documents ?? [])
  const [q, setQ] = useState('')
  const search = useDebounced(q)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const found = useQuery({
    queryKey: ['documents', 'agenda-search', search],
    queryFn: ({ signal }) => api.get<Paginated<DocLite>>('/documents', { q: search, per_page: 8 }, signal).then((r) => r.data),
    enabled: search.trim().length >= 2,
  })
  const move = (i: number, d: number) => setItems((list) => { const n = [...list]; const [x] = n.splice(i, 1); n.splice(i + d, 0, x); return n })
  const save = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.put<{ data: Reservation }>(`/reservations/${reservation.id}/agenda`, {
        items: items.filter((x) => x.title.trim()).map((x) => ({ title: x.title.trim(), time_label: x.time_label.trim() || null, presenter: x.presenter.trim() || null })),
        document_ids: docs.map((d) => d.id),
      })
      onDone(res.data)
      toast.success('Ordre du jour enregistré')
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  return (
    <Modal title="Ordre du jour et documents" kicker={reservation.reference} wide onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button busy={busy} onClick={save}>Enregistrer</Button>
    </>}>
      <div className="agenda-edit-row" style={{ borderTop: 0, fontWeight: 600, fontSize: 10.5 }} aria-hidden="true"><span>Horaire</span><span>Point</span><span>Intervenant</span><span /></div>
      {items.map((x, i) => (
        <div className="agenda-edit-row" key={i}>
          <input className="input" placeholder="10:30" maxLength={20} value={x.time_label} aria-label={`Horaire du point ${i + 1}`} onChange={(e) => setItems(items.map((y, j) => (j === i ? { ...y, time_label: e.target.value } : y)))} />
          <input className="input" maxLength={200} value={x.title} aria-label={`Intitulé du point ${i + 1}`} aria-invalid={!!error?.field(`items.${i}.title`)} onChange={(e) => setItems(items.map((y, j) => (j === i ? { ...y, title: e.target.value } : y)))} />
          <input className="input" maxLength={120} value={x.presenter} aria-label={`Intervenant du point ${i + 1}`} onChange={(e) => setItems(items.map((y, j) => (j === i ? { ...y, presenter: e.target.value } : y)))} />
          <div className="row-actions">
            <Button size="small" variant="ghost" ariaLabel="Monter" disabled={i === 0} onClick={() => move(i, -1)}>↑</Button>
            <Button size="small" variant="ghost" ariaLabel="Descendre" disabled={i === items.length - 1} onClick={() => move(i, 1)}>↓</Button>
            <Button size="small" variant="ghost" icon="trash" ariaLabel={`Supprimer le point ${i + 1}`} onClick={() => setItems(items.filter((_, j) => j !== i))} />
          </div>
        </div>
      ))}
      <div style={{ margin: '8px 0 16px' }}><Button size="small" variant="secondary" icon="plus" disabled={items.length >= 30} onClick={() => setItems([...items, { title: '', time_label: '', presenter: '' }])}>Ajouter un point</Button></div>
      <h3 className="small" style={{ fontWeight: 700, marginBottom: 6 }}>Documents associés</h3>
      <div className="chip-row" style={{ marginBottom: 8 }}>
        {docs.length === 0 && <span className="muted small">Aucun document.</span>}
        {docs.map((d) => <span className="chip" key={d.id}>{d.title}<button type="button" aria-label={`Retirer ${d.title}`} onClick={() => setDocs(docs.filter((x) => x.id !== d.id))}><Icon name="x" size={12} /></button></span>)}
      </div>
      <Field label="Rechercher un document de la bibliothèque" help="2 caractères minimum ; seuls les documents que vous pouvez consulter sont proposés">
        <input value={q} onChange={(e) => setQ(e.target.value)} />
      </Field>
      {found.isFetching && <p className="muted small">Recherche…</p>}
      {found.isError && <p className="field-error">{(found.error as Error).message}</p>}
      {(found.data ?? []).filter((d) => !docs.some((x) => x.id === d.id)).map((d) => (
        <div key={d.id} style={{ display: 'flex', gap: 8, alignItems: 'center', padding: '5px 0', borderTop: '1px solid #edf0f3' }}>
          <span style={{ flex: 1 }}>{d.title} <span className="mono muted">{d.reference}</span></span>
          <Button size="small" variant="secondary" icon="plus" onClick={() => setDocs([...docs, d])}>Associer</Button>
        </div>
      ))}
      {error && <div style={{ marginTop: 10 }}><Notice tone="danger">{error.message}</Notice></div>}
    </Modal>
  )
}

/** Message de bienvenue (ACC-02) : réservation confirmée et non confidentielle ; le contenu expire à la fin de la réunion. */
function WelcomeDialog({ reservation, occurrences, onClose }: { reservation: Reservation; occurrences: Occurrence[]; onClose: () => void }) {
  const navigate = useNavigate()
  const client = useQueryClient()
  const [occ, setOcc] = useState(occurrences[0]?.id ?? '')
  const [delegation, setDelegation] = useState('')
  const [institution, setInstitution] = useState('')
  const [visitors, setVisitors] = useState(String(reservation.participant_count))
  const [languages, setLanguages] = useState<string[]>(['fr'])
  const [flags, setFlags] = useState<string[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [done, setDone] = useState<{ content_id: string; reference: string } | null>(null)
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: { content_id: string; reference: string } }>(`/reservations/${reservation.id}/welcome`, {
        occurrence_id: occ, delegation: delegation.trim(), institution: institution.trim() || null, visitors_count: Number(visitors) || null, languages, flags,
      })
      setDone(res.data)
      client.invalidateQueries({ queryKey: ['reservation', reservation.id] })
      client.invalidateQueries({ queryKey: ['visits'] })
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  if (done) {
    return (
      <Modal title="Message de bienvenue créé" onClose={onClose} footer={<>
        <Button variant="secondary" onClick={onClose}>Fermer</Button>
        <Button icon="edit" onClick={() => navigate(`/affichage/contenus/${done.content_id}/edition`)}>Ouvrir le message</Button>
      </>}>
        <Notice tone="success">Le brouillon {done.reference} a été créé. Il doit être validé (circuit éditorial et protocolaire) avant diffusion ; il expirera automatiquement à la fin de la réunion.</Notice>
      </Modal>
    )
  }
  return (
    <Modal title="Message de bienvenue" kicker="PROTOCOLE" wide onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button busy={busy} disabled={!occ || !delegation.trim() || languages.length === 0} onClick={submit}>Créer le brouillon</Button>
    </>}>
      <Notice tone="info">Un message exige une occurrence confirmée d’une réunion non confidentielle. Il cible l’accueil et l’étage de la salle et expire à la fin de la réunion ; un déplacement le recompile.</Notice>
      <div className="form-grid" style={{ marginTop: 12 }}>
        <Field label="Occurrence" required error={error?.field('occurrence_id')} wide>
          <select value={occ} onChange={(e) => setOcc(e.target.value)}>
            {occurrences.map((o) => <option key={o.id} value={o.id}>{slotLabel(o.starts_at, o.ends_at)} · {o.room.name}</option>)}
          </select>
        </Field>
        <Field label="Nom de la délégation" required error={error?.field('delegation')} wide><input value={delegation} maxLength={160} onChange={(e) => setDelegation(e.target.value)} aria-invalid={!!error?.field('delegation')} /></Field>
        <Field label="Institution" error={error?.field('institution')}><input value={institution} maxLength={160} onChange={(e) => setInstitution(e.target.value)} /></Field>
        <Field label="Nombre de visiteurs" error={error?.field('visitors_count')}><input type="number" min={1} value={visitors} onChange={(e) => setVisitors(e.target.value)} /></Field>
        <fieldset className="wide">
          <legend>Langues *</legend>
          <div className="radio-row">
            {Object.entries(LANG_LABEL).map(([code, label]) => (
              <label key={code}><input type="checkbox" checked={languages.includes(code)} onChange={(e) => setLanguages(e.target.checked ? [...languages, code] : languages.filter((l) => l !== code))} /> {label}</label>
            ))}
          </div>
          {error?.field('languages') && <span className="field-error">{error.field('languages')}</span>}
        </fieldset>
        <fieldset className="wide">
          <legend>Drapeaux des États membres (soumis à validation du Protocole)</legend>
          <div className="flag-grid">
            {MEMBER_STATES.map(([code, name]) => (
              <label key={code}><input type="checkbox" checked={flags.includes(code)} onChange={(e) => setFlags(e.target.checked ? [...flags, code] : flags.filter((x) => x !== code))} /><b>{code}</b> {name}</label>
            ))}
          </div>
        </fieldset>
      </div>
      {error && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}</Notice></div>}
    </Modal>
  )
}
