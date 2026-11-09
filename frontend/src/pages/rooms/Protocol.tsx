import { useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, type Paginated } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useData } from '../../lib/hooks'
import { fmtDate, fmtLongDate, fmtTime, LANG_LABEL } from '../../lib/format'
import { Button, EmptyState, ErrorState, Field, Icon, Loading, Notice, PageHeading, Status, useDialogFocus, useToast, useUnsavedWarning } from '../../components/ui'
import { FlowProgress, MEMBER_STATES, slotLabel, TZ_LABEL, useReferentials, type Occurrence, type Reservation, type Room } from './shared'

interface Visit {
  id: string; reference: string; name: string; institution: string | null; visitors_count: number; status: string
  languages: string[]; flags: string[]; notes: string | null
  occurrence: { id: string; status: string; starts_at: string; ends_at: string; room: string; floor: string; reservation_id: string; reservation_reference: string; confidential: boolean } | null
  content: { id: string; reference: string; status: string; languages: { lang: string; status: string; title: string }[] } | null
}
interface ScreenLite { id: string; code: string; name: string; kind: string; orientation: string; location: { name: string; floor: { id: string; label: string; level: number } | null } | null }

const VISIT_STATUS: Record<string, [string, string]> = {
  draft: ['À préparer', 'neutral'], message_draft: ['Message à valider', 'warning'], planned: ['Planifiée', 'blue'], done: ['Terminée', 'success'], cancelled: ['Annulée', 'neutral'],
}

/** Accueil et protocole (ACC-02, parcours P09). */
export default function Protocol() {
  const navigate = useNavigate()
  const { me, can } = useAuth()
  const [preparing, setPreparing] = useState(false)
  const visits = useData<Visit[]>(['visits'], '/visits')
  const refs = useReferentials()
  const rooms = useQuery({
    queryKey: ['rooms', 'wayfinding'],
    queryFn: ({ signal }) => api.get<Paginated<Room>>('/rooms', { per_page: 500 }, signal).then((r) => r.data),
    enabled: can('rooms.view'),
  })

  const list = useMemo(() => [...(visits.data ?? [])].sort((a, b) => (a.occurrence?.starts_at ?? '9').localeCompare(b.occurrence?.starts_at ?? '9')), [visits.data])
  const now = Date.now()
  const next = list.find((v) => v.occurrence && new Date(v.occurrence.ends_at).getTime() > now && v.status !== 'cancelled') ?? null

  const floors = useMemo(() => {
    const fl = [...(refs.data?.floors ?? [])].filter((f) => f.is_active !== false).sort((a, b) => a.level - b.level)
    return fl.map((f) => ({
      id: f.id, badge: f.level === 0 ? 'RDC' : String(f.level), label: f.label,
      structures: (refs.data?.structures ?? []).filter((s) => s.floor_id === f.id && s.is_active !== false).map((s) => s.name),
      rooms: (rooms.data ?? []).filter((r) => r.floor?.id === f.id).map((r) => r.name),
    })).filter((f) => f.structures.length || f.rooms.length)
  }, [refs.data, rooms.data])

  return (
    <>
      <PageHeading title="Accueil et protocole" description={`Visites, délégations et messages de bienvenue liés aux réservations confirmées · ${TZ_LABEL}`} demo={me.demo_mode}
        actions={<Button icon="plus" onClick={() => setPreparing(true)}>Préparer une visite</Button>} />

      {visits.isLoading ? <Loading /> : visits.isError ? <ErrorState error={visits.error} onRetry={() => visits.refetch()} /> : (
        <>
          {next && next.occurrence ? (
            <div className="protocol-hero">
              <div>
                <Status tone="blue">Prochaine arrivée · {fmtTime(next.occurrence.starts_at)}</Status>
                <h2>{next.name}</h2>
                <p>{[next.institution, next.occurrence.room, `${next.visitors_count} visiteur(s)`].filter(Boolean).join(' · ')}</p>
                <div className="protocol-meta">
                  <span><Icon name="calendar" />{fmtLongDate(next.occurrence.starts_at)}, {fmtTime(next.occurrence.starts_at)}–{fmtTime(next.occurrence.ends_at)}</span>
                  <span><Icon name="door" />{next.occurrence.room} · {next.occurrence.floor}</span>
                  <span><Icon name="info" />{(VISIT_STATUS[next.status] ?? [next.status])[0]}</span>
                </div>
                {next.content ? (
                  <Button variant="light" icon="eye" onClick={() => navigate(`/affichage/contenus/${next.content!.id}/edition`)}>Aperçu du message</Button>
                ) : (
                  <Button variant="light" icon="eye" onClick={() => navigate(`/reservations/${next.occurrence!.reservation_id}`)}>Ouvrir la réservation</Button>
                )}
              </div>
              <div className="welcome-mini" aria-label="Aperçu du message de bienvenue">
                <img src="/ceeac-logo.jpg" alt="Logo de la CEEAC" />
                <small>BIENVENUE À LA COMMISSION</small>
                <strong>{next.name}</strong>
                <span>{next.occurrence.room} · {next.occurrence.floor}</span>
                {next.flags.length > 0 && <span style={{ marginTop: 6 }}>{next.flags.join(' · ')}</span>}
              </div>
            </div>
          ) : (
            <div style={{ marginBottom: 15 }}><Notice tone="info">Aucune arrivée programmée sur une réservation à venir.</Notice></div>
          )}

          <div className="two-columns">
            <section className="panel">
              <div className="panel-title">
                <div><h2>Visites à venir</h2><p>Liées à des réservations confirmées ou en préparation</p></div>
                <button className="link" onClick={() => navigate('/salles/calendrier')}>Calendrier</button>
              </div>
              {list.length === 0 ? <EmptyState title="Aucune visite" text="Utilisez « Préparer une visite » pour enregistrer une délégation." /> : list.map((v) => {
                const [label, tone] = VISIT_STATUS[v.status] ?? [v.status, 'neutral']
                return (
                  <div className="visit-row" key={v.id}>
                    <time>{v.occurrence ? fmtDate(v.occurrence.starts_at).replace(/ \d{4}$/, '') : '—'}</time>
                    <span>
                      <strong>{v.name}</strong>
                      <small>{v.occurrence ? `${v.occurrence.room} · ${fmtTime(v.occurrence.starts_at)}–${fmtTime(v.occurrence.ends_at)} · ${v.occurrence.reservation_reference}` : 'Sans réservation associée'}{v.institution ? ` · ${v.institution}` : ''}</small>
                      {v.content && <small>Message {v.content.reference} · {v.content.languages.map((l) => l.lang.toUpperCase()).join(' / ')}</small>}
                    </span>
                    <Status tone={tone}>{label}</Status>
                  </div>
                )
              })}
            </section>
            <section className="panel orientation-card">
              <div className="panel-title">
                <div><h2>Orientation visiteurs</h2><p>Informations issues des référentiels</p></div>
                {refs.isSuccess && <Status tone="success">Référentiels chargés</Status>}
              </div>
              {refs.isLoading ? <Loading /> : refs.isError ? <ErrorState error={refs.error} onRetry={() => refs.refetch()} /> : floors.length === 0 ? (
                <EmptyState title="Aucune donnée d’orientation" text="Associez les structures et salles à leur niveau dans les référentiels." />
              ) : (
                <div className="wayfinding">
                  {floors.map((f) => (
                    <div key={f.id}>
                      <b title={f.label}>{f.badge}</b>
                      <span><span className="sr-only">{f.label} : </span>{[...f.structures, ...f.rooms].join(' · ')}</span>
                    </div>
                  ))}
                </div>
              )}
            </section>
          </div>
        </>
      )}
      {preparing && <VisitFlow onClose={() => setPreparing(false)} />}
    </>
  )
}

const STEPS = ['Délégation', 'Réservation', 'Message', 'Écrans', 'Validation']

function VisitFlow({ onClose }: { onClose: () => void }) {
  const navigate = useNavigate()
  const client = useQueryClient()
  const toast = useToast()
  const { can } = useAuth()
  const [step, setStep] = useState(0)
  const [name, setName] = useState('')
  const [institution, setInstitution] = useState('')
  const [visitors, setVisitors] = useState('')
  const [choice, setChoice] = useState<{ r: Reservation; o: Occurrence } | null>(null)
  const [languages, setLanguages] = useState<string[]>(['fr'])
  const [flags, setFlags] = useState<string[]>([])
  const [notes, setNotes] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [done, setDone] = useState<{ kind: 'welcome'; content_id: string; reference: string } | { kind: 'visit'; reference: string } | null>(null)
  useUnsavedWarning(!done && !!name)
  const dialogRef = useRef<HTMLDivElement>(null)
  useDialogFocus(dialogRef, onClose)

  const reservations = useQuery({
    queryKey: ['reservations', 'protocol-candidates', can('reservations.approve')],
    queryFn: async ({ signal }) => {
      // « welcome » : réunions confirmées, non confidentielles et à venir, ouvertes au Protocole (ACC-02).
      const scopes = ['welcome', 'mine', ...(can('reservations.approve') ? ['managed'] : [])]
      const seen = new Map<string, Reservation>()
      for (const scope of scopes) {
        try {
          const page = await api.get<Paginated<Reservation>>('/reservations', { scope, when: 'future', status: 'confirmed', per_page: 500 }, signal)
          page.data.forEach((r) => seen.set(r.id, r))
        } catch (e) {
          if ((e as Error).name === 'AbortError') throw e
          if (scope === 'mine') throw e
        }
      }
      const now = Date.now()
      return [...seen.values()].filter((r) => !r.confidential).flatMap((r) => r.occurrences.filter((o) => o.status === 'confirmed' && new Date(o.ends_at).getTime() > now).map((o) => ({ r, o })))
        .sort((a, b) => a.o.starts_at.localeCompare(b.o.starts_at))
    },
    enabled: step >= 1,
  })
  const screens = useQuery({
    queryKey: ['screens', 'welcome-targets'],
    queryFn: ({ signal }) => api.get<Paginated<ScreenLite>>('/screens', { per_page: 500 }, signal).then((r) => r.data),
    enabled: step === 3 && can('screens.view'),
  })
  const roomFloor = choice?.o.room.floor ?? null
  const targetScreens = (screens.data ?? []).filter((s) => s.location?.floor && (s.location.floor.level === 0 || s.location.floor.label === roomFloor))

  const canNext = step === 0 ? !!name.trim() : step === 2 ? !choice || languages.length > 0 : true
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      if (choice) {
        const res = await api.post<{ data: { content_id: string; reference: string } }>(`/reservations/${choice.r.id}/welcome`, {
          occurrence_id: choice.o.id, delegation: name.trim(), institution: institution.trim() || null, visitors_count: Number(visitors) || null, languages, flags,
        })
        setDone({ kind: 'welcome', ...res.data })
      } else {
        const res = await api.post<{ data: { reference: string } }>('/visits', { name: name.trim(), institution: institution.trim() || null, visitors_count: Number(visitors) || null, notes: notes.trim() || null })
        setDone({ kind: 'visit', reference: res.data.reference })
      }
      client.invalidateQueries({ queryKey: ['visits'] })
      toast.success('Visite enregistrée')
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(500, { message: String(e) })
      setError(err)
      if (err.field('delegation') || err.field('name')) setStep(0)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="visit-flow-title">
      <div className="flow-modal visit-modal" ref={dialogRef}>
        <header>
          <div><small>PARCOURS P09</small><h2 id="visit-flow-title">Préparer une visite</h2></div>
          <button className="close icon-button" onClick={onClose} aria-label="Fermer"><Icon name="x" /></button>
        </header>
        <FlowProgress steps={STEPS} step={done ? STEPS.length : step} />
        <div className="flow-content">
          {done ? (
            <>
              <h3>{done.kind === 'welcome' ? 'Message de bienvenue créé' : 'Visite enregistrée'}</h3>
              <p>{done.kind === 'welcome' ? 'Le brouillon doit être approuvé avant son activation automatique ; il expirera à la fin de la réunion.' : 'Aucun message n’est créé sans réservation confirmée et non confidentielle.'}</p>
              <div className="booking-confirmed">
                <span><Icon name="check" size={30} /></span>
                <h4>{name}</h4>
                {choice && <p>{slotLabel(choice.o.starts_at, choice.o.ends_at)}</p>}
                {choice && <strong>{choice.o.room.name} · {choice.o.room.floor}</strong>}
                <small>Référence {done.reference}</small>
              </div>
            </>
          ) : (
            <>
              <div className="step-label">ÉTAPE {step + 1} SUR {STEPS.length}</div>
              {step === 0 && (
                <>
                  <h3>Informations sur la délégation</h3>
                  <p>Données limitées au nécessaire pour l’accueil.</p>
                  <div className="form-grid">
                    <Field label="Nom de la délégation" required wide error={error?.field('delegation') ?? error?.field('name')}><input value={name} maxLength={160} onChange={(e) => setName(e.target.value)} /></Field>
                    <Field label="Institution" error={error?.field('institution')}><input value={institution} maxLength={160} onChange={(e) => setInstitution(e.target.value)} /></Field>
                    <Field label="Nombre de visiteurs" error={error?.field('visitors_count')}><input type="number" min={1} value={visitors} onChange={(e) => setVisitors(e.target.value)} /></Field>
                  </div>
                </>
              )}
              {step === 1 && (
                <>
                  <h3>Réservation associée</h3>
                  <p>Un message d’accueil exige une réservation confirmée et non confidentielle. Il expire automatiquement à la fin de la réunion.</p>
                  {reservations.isLoading ? <Loading /> : reservations.isError ? <ErrorState error={reservations.error} onRetry={() => reservations.refetch()} /> : (
                    <div className="room-options" role="radiogroup" aria-label="Réservation associée">
                      <button type="button" role="radio" aria-checked={!choice} className={!choice ? 'selected' : ''} onClick={() => setChoice(null)}>
                        <span><Icon name="x" /></span>
                        <div><strong>Aucune réservation</strong><small>Visite enregistrée sans message de bienvenue</small></div>
                      </button>
                      {(reservations.data ?? []).map((c) => (
                        <button type="button" role="radio" key={c.o.id} aria-checked={choice?.o.id === c.o.id} className={choice?.o.id === c.o.id ? 'selected' : ''} onClick={() => { setChoice(c); if (!visitors) setVisitors(String(c.r.participant_count)) }}>
                          <span><Icon name="calendar" /></span>
                          <div><strong>{c.r.reference} · {c.r.subject}</strong><small>{slotLabel(c.o.starts_at, c.o.ends_at)} · {c.o.room.name} · {c.o.room.floor}</small></div>
                          <Status tone="success">Confirmée</Status>
                        </button>
                      ))}
                      {(reservations.data ?? []).length === 0 && <Notice tone="info">Aucune réservation confirmée et non confidentielle à venir dans votre périmètre.</Notice>}
                    </div>
                  )}
                </>
              )}
              {step === 2 && (
                choice ? (
                  <>
                    <h3>Composer le message de bienvenue</h3>
                    <p>Le texte est généré dans chaque langue retenue, puis modifiable et soumis au circuit de validation des contenus. Le Protocole valide les dénominations et drapeaux.</p>
                    <div className="welcome-mini" style={{ maxWidth: 360, margin: '0 auto 14px', background: '#f9f4e8' }}>
                      <img src="/ceeac-logo.jpg" alt="" />
                      <small>BIENVENUE À LA COMMISSION</small>
                      <strong>{name}</strong>
                      <span>{choice.o.room.name} · {choice.o.room.floor}</span>
                    </div>
                    <fieldset className="form-grid" style={{ display: 'block', border: 0, padding: 0 }}>
                      <legend className="small" style={{ fontWeight: 700 }}>Langues *</legend>
                      <div className="language-tabs" style={{ display: 'flex', gap: 6, flexWrap: 'wrap', margin: '6px 0 12px' }}>
                        {Object.entries(LANG_LABEL).map(([code, label]) => (
                          <label key={code} className="check-label"><input type="checkbox" checked={languages.includes(code)} onChange={(e) => setLanguages(e.target.checked ? [...languages, code] : languages.filter((l) => l !== code))} />{label}</label>
                        ))}
                      </div>
                    </fieldset>
                    <fieldset style={{ border: 0, padding: 0 }}>
                      <legend className="small" style={{ fontWeight: 700, marginBottom: 6 }}>Drapeaux des États membres</legend>
                      <div className="flag-grid">
                        {MEMBER_STATES.map(([code, label]) => (
                          <label key={code}><input type="checkbox" checked={flags.includes(code)} onChange={(e) => setFlags(e.target.checked ? [...flags, code] : flags.filter((x) => x !== code))} /><b>{code}</b> {label}</label>
                        ))}
                      </div>
                    </fieldset>
                  </>
                ) : (
                  <>
                    <h3>Notes d’accueil</h3>
                    <p>Sans réservation confirmée et non confidentielle, aucun message de bienvenue n’est diffusé.</p>
                    <Field label="Notes (itinéraire, contacts, besoins particuliers)"><textarea value={notes} maxLength={1000} onChange={(e) => setNotes(e.target.value)} /></Field>
                  </>
                )
              )}
              {step === 3 && (
                <>
                  <h3>Écrans d’accueil</h3>
                  <p>Le message cible l’accueil (rez-de-chaussée) et l’étage de la salle ; la diffusion commence et prend fin automatiquement avec la visite. Le ciblage définitif est confirmé lors de la validation.</p>
                  {!choice ? <Notice tone="info">Aucun écran concerné : visite sans message.</Notice> : !can('screens.view') ? (
                    <Notice tone="info">Ciblage automatique : accueil et {choice.o.room.floor ?? 'étage de la salle'}.</Notice>
                  ) : screens.isLoading ? <Loading /> : screens.isError ? <ErrorState error={screens.error} /> : targetScreens.length === 0 ? (
                    <Notice tone="warning">Aucun écran inventorié à l’accueil ou au niveau {choice.o.room.floor}.</Notice>
                  ) : (
                    <div className="target-list" aria-label="Écrans ciblés">
                      {targetScreens.map((s) => (
                        <div key={s.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '11px 13px', border: '1px solid var(--line)', borderRadius: 6, fontSize: 9 }}>
                          <Icon name="monitor" size={16} />
                          <span><b>{s.name}</b>{s.location?.floor?.label} · {s.location?.name} · {s.orientation === 'portrait' ? 'Écran portrait' : 'Écran paysage'}</span>
                        </div>
                      ))}
                    </div>
                  )}
                </>
              )}
              {step === 4 && (
                <>
                  <h3>{choice ? 'Soumettre au circuit de validation' : 'Enregistrer la visite'}</h3>
                  <p>{choice ? 'Le message est créé en brouillon ; il doit être approuvé avant son activation automatique.' : 'La visite est enregistrée sans message de bienvenue.'}</p>
                  <div className="booking-link" style={{ marginBottom: 12 }}>
                    <Icon name="users" />
                    <span><strong>{name}</strong><small>{[institution, visitors && `${visitors} visiteur(s)`].filter(Boolean).join(' · ') || '—'}</small></span>
                  </div>
                  {choice && (
                    <div className="booking-link" style={{ marginBottom: 12 }}>
                      <Icon name="calendar" />
                      <span><strong>{choice.r.reference} · {choice.r.subject}</strong><small>{slotLabel(choice.o.starts_at, choice.o.ends_at)} · {choice.o.room.name}</small></span>
                      <Status tone="success">Confirmée</Status>
                    </div>
                  )}
                  {choice && <p className="small">Langues : {languages.map((l) => LANG_LABEL[l]).join(', ')}{flags.length ? ` · Drapeaux : ${flags.join(', ')}` : ''}</p>}
                  {error && <div style={{ marginTop: 10 }}><Notice tone="danger">{error.message}</Notice></div>}
                </>
              )}
            </>
          )}
        </div>
        <footer>
          {done ? (
            <>
              <Button variant="secondary" onClick={onClose}>Fermer</Button>
              {done.kind === 'welcome' && <Button icon="edit" onClick={() => navigate(`/affichage/contenus/${done.content_id}/edition`)}>Ouvrir le message</Button>}
            </>
          ) : (
            <>
              <Button variant="secondary" onClick={step ? () => setStep(step - 1) : onClose}>{step ? 'Retour' : 'Annuler'}</Button>
              <div className="footer-note"><Icon name="shield" size={14} /> Action tracée dans le journal</div>
              <Button busy={busy} disabled={!canNext} onClick={() => (step === STEPS.length - 1 ? submit() : setStep(step + 1))}>
                {step === STEPS.length - 1 ? (choice ? 'Créer le message' : 'Enregistrer la visite') : 'Continuer'}
                <Icon name="arrow" size={15} />
              </Button>
            </>
          )}
        </footer>
      </div>
    </div>
  )
}
