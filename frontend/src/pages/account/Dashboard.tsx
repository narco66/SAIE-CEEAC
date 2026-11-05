import { useNavigate } from 'react-router'
import { useAuth } from '../../lib/auth'
import { useData } from '../../lib/hooks'
import { fmtDateTime, fmtLongDate, fmtRelative, fmtTime, localParts } from '../../lib/format'
import { Button, ErrorState, Icon, Loading, PageHeading, Status, type IconName } from '../../components/ui'

interface DashboardData {
  generated_at: string
  cards: {
    screens: { online: number; total: number; suspect: number; unavailable: number; unpaired: number }
    campaigns: { active: number; upcoming: number }
    tasks: { total: number; due_soon: number; overdue: number }
    rooms: { free: number; total: number; meetings_today: number }
    alerts: { active: number }
    incidents: { open: number }
  }
  floors: { id: string; label: string; total: number; online: number; state: string }[]
  last_contact: string | null
  tasks: { id: string; object: string; action: string; due_at: string | null; state: string; link: string; type: string }[]
  meetings: { id: string; reservation_id: string; starts_at: string; label: string; room: string; participants: number; confidential: boolean }[]
  timeline: { id: string; name: string; starts_at: string; ends_at: string; priority: number; status: string }[]
  incidents: { id: string; reference: string; title: string; severity: string }[]
  activity: { occurred_at: string; actor_label: string | null; action: string; object_label: string | null }[]
  documents: { id: string; reference: string; title: string; classification: string }[]
  my_reservations: { id: string; reservation_id: string; room: string; starts_at: string; status: string }[]
  tasks_without_backup: number | null
  indicators?: { code: string; label: string; value: number | null; unit: string; target: string; basis: string; period: { from: string | null; to: string } }[]
}

const ACTION_LABEL: Record<string, string> = {
  'auth.login': 'Connexion', 'content.submitted': 'Soumission', 'content.approved': 'Approbation', 'content.returned': 'Retour pour correction',
  'campaign.published': 'Programmation', 'alert.published': 'Alerte publiée', 'reservation.created': 'Réservation', 'device.paired': 'Appairage',
}

/** V04 Accueil par rôle : tous les chiffres proviennent de la base, avec leur période de référence (TAB-01). */
export default function Dashboard() {
  const { me, can } = useAuth()
  const navigate = useNavigate()
  const query = useData<DashboardData>(['dashboard'], '/dashboard')
  if (query.isLoading) return <Loading />
  if (query.isError || !query.data) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const d = query.data
  const firstName = me.name.split(' ')[0]

  const stats: { icon: IconName; value: number; total?: number; label: string; delta: string; tone: string; to: string; show: boolean }[] = [
    { icon: 'monitor', value: d.cards.screens.online, total: d.cards.screens.total, label: 'Écrans connectés', delta: `${d.cards.screens.unavailable + d.cards.screens.suspect} hors ligne ou suspect(s)`, tone: 'blue', to: '/affichage/ecrans?network=unavailable', show: can('screens.view') },
    { icon: 'play', value: d.cards.campaigns.active, label: 'Diffusions actives', delta: `${d.cards.campaigns.upcoming} à venir`, tone: 'green', to: '/affichage/campagnes?status=active', show: can('campaigns.view') },
    { icon: 'check', value: d.cards.tasks.total, label: 'À traiter', delta: `${d.cards.tasks.overdue} en retard · ${d.cards.tasks.due_soon} sous 24 h`, tone: 'amber', to: '/mes-taches', show: true },
    { icon: 'door', value: d.cards.rooms.free, total: d.cards.rooms.total, label: 'Salles libres maintenant', delta: `${d.cards.rooms.meetings_today} réunion(s) aujourd’hui`, tone: 'violet', to: '/salles/calendrier', show: can('rooms.view') },
  ]
  const span = { start: 8 * 60, end: 18 * 60 }
  const pos = (iso: string) => Math.max(0, Math.min(100, ((localParts(iso).minutes - span.start) / (span.end - span.start)) * 100))

  return (
    <>
      <PageHeading
        title={`Bonjour, ${firstName}`}
        description="Voici la situation du SAIE-CEEAC aujourd’hui."
        demo={me.demo_mode}
        actions={<>
          <Button variant="secondary" icon="calendar">{fmtLongDate()}</Button>
          {can('contents.create') && <Button icon="plus" onClick={() => navigate('/affichage/contenus/nouveau')}>Créer un contenu</Button>}
        </>}
      />
      {d.cards.alerts.active > 0 && (
        <div className="safety-note" style={{ marginBottom: 15, borderColor: '#edc7ca', background: 'var(--red-soft)' }}>
          <Icon name="alert" />
          <div><strong>{d.cards.alerts.active} alerte(s) prioritaire(s) en cours</strong><span>Les programmes ordinaires sont interrompus sur les écrans ciblés.</span></div>
          <Button variant="secondary" onClick={() => navigate('/affichage/alertes')}>Ouvrir le centre des alertes</Button>
        </div>
      )}
      <div className="stat-grid">
        {stats.filter((s) => s.show).map((s) => (
          <button className="stat-card" key={s.label} onClick={() => navigate(s.to)}>
            <div className={`stat-icon ${s.tone}`}><Icon name={s.icon} /></div>
            <div className="stat-top"><strong>{s.value}</strong>{s.total !== undefined && <span>/ {s.total}</span>}<Icon name="arrow" size={17} /></div>
            <p>{s.label}</p>
            <small>{s.delta}</small>
          </button>
        ))}
      </div>
      <div className="dashboard-grid">
        {can('screens.view') && (
          <section className="panel span-2">
            <div className="panel-title">
              <div><h2>État du parc par niveau</h2><p>Dernier contact reçu {d.last_contact ? `à ${fmtTime(d.last_contact)} (${fmtRelative(d.last_contact)})` : ': aucun'}</p></div>
              <button className="link" onClick={() => navigate('/affichage/ecrans')}>Voir le parc <Icon name="arrow" size={15} /></button>
            </div>
            <div className="building-overview">
              {d.floors.map((f) => (
                <button className="floor-row" key={f.id} style={{ width: '100%', border: 0, background: 'none', textAlign: 'left' }} onClick={() => navigate(`/affichage/ecrans?floor_id=${f.id}`)}>
                  <span>{f.label}</span>
                  <div className="floor-track"><i className={f.state === 'ok' ? '' : 'warn'} style={{ width: `${f.total ? (f.online / f.total) * 100 : 0}%` }} /></div>
                  <strong>{f.online}/{f.total}</strong>
                  {f.state === 'ok' ? <Status tone="success">Opérationnel</Status> : <Status tone="warning">À vérifier</Status>}
                </button>
              ))}
              {d.floors.length === 0 && <p className="muted small" style={{ padding: 12 }}>Aucun écran inventorié.</p>}
            </div>
          </section>
        )}
        <section className="panel">
          <div className="panel-title">
            <div><h2>Actions attendues</h2><p>{d.cards.tasks.total} tâche(s) vous sont assignées</p></div>
            <button className="icon-button" onClick={() => navigate('/mes-taches')} aria-label="Ouvrir mes tâches"><Icon name="arrow" /></button>
          </div>
          <div className="task-list">
            {d.tasks.map((t) => (
              <button key={t.id} onClick={() => navigate(t.link)}>
                <span className={`task-symbol ${t.state === 'escalated' || t.state === 'overdue' ? 'red' : t.type === 'reservation_approval' ? 'blue' : 'amber'}`}><Icon name={t.type === 'reservation_approval' ? 'door' : 'file'} /></span>
                <span><strong>{t.object}</strong><small>{t.action} · {t.state === 'escalated' ? 'escaladée' : t.due_at ? `échéance ${fmtRelative(t.due_at)}` : ''}</small></span>
                <Icon name="chevron" size={16} />
              </button>
            ))}
            {d.tasks.length === 0 && <p className="muted small" style={{ padding: '14px 18px' }}>Aucune action en attente.</p>}
          </div>
          <button className="full-link" onClick={() => navigate('/mes-taches')}>Afficher toutes les tâches</button>
        </section>
        {can('campaigns.view') && (
          <section className="panel span-2">
            <div className="panel-title">
              <div><h2>Diffusions du jour</h2><p>Fuseau Africa/Libreville (UTC+1) · plage 08:00–18:00</p></div>
              <button className="link" onClick={() => navigate('/affichage/campagnes')}>Ouvrir la programmation</button>
            </div>
            <div className="timeline">
              <div className="time-labels">{['08:00', '10:00', '12:00', '14:00', '16:00', '18:00'].map((t) => <span key={t}>{t}</span>)}</div>
              {d.timeline.map((c, i) => (
                <div className="timeline-row" key={c.id}>
                  <b>Priorité {c.priority}</b>
                  <button className={`event ${['blue-event', 'green-event', 'gray-event'][i % 3]}`} style={{ left: `${pos(c.starts_at)}%`, width: `${Math.max(6, pos(c.ends_at) - pos(c.starts_at))}%`, border: 0, borderLeft: '3px solid' }} onClick={() => navigate(`/affichage/diffusions/${c.id}`)}>
                    {c.name}
                  </button>
                </div>
              ))}
              {d.timeline.length === 0 && <p className="muted small" style={{ padding: 12 }}>Aucune campagne aujourd’hui : les écrans affichent le contenu de repli.</p>}
            </div>
          </section>
        )}
        {can('rooms.view') && (
          <section className="panel">
            <div className="panel-title">
              <div><h2>Réunions du jour</h2><p>Confidentielles affichées « Réservée »</p></div>
              <button className="link" onClick={() => navigate('/salles/calendrier')}>Toutes</button>
            </div>
            <div className="meeting-list">
              {d.meetings.map((m) => (
                <div key={m.id}>
                  <time>{fmtTime(m.starts_at)}</time>
                  <span><strong>{m.label}</strong><small>{m.room} · {m.confidential ? 'confidentielle' : `${m.participants} participants`}</small></span>
                </div>
              ))}
              {d.meetings.length === 0 && <p className="muted small" style={{ padding: '10px 0' }}>Aucune réunion aujourd’hui.</p>}
            </div>
          </section>
        )}
        {d.indicators && (
          <section className="panel span-2">
            <div className="panel-title">
              <div><h2>Indicateurs de succès (GOV-01)</h2><p>Valeurs calculées en base · 90 derniers jours sauf mention</p></div>
              <button className="link" onClick={() => navigate('/rapports')}>Voir les rapports <Icon name="arrow" size={15} /></button>
            </div>
            <div className="project-list">
              {d.indicators.map((ind) => (
                <button key={ind.code} onClick={() => navigate('/rapports')} title={ind.basis}>
                  <span><strong>{ind.label}</strong><small>{ind.basis}</small></span>
                  <i><b style={{ width: `${ind.unit === '%' && ind.value !== null ? ind.value : ind.value === 0 ? 100 : 0}%` }} /></i>
                  <em>{ind.value === null ? 'n.d.' : `${String(ind.value).replace('.', ',')}${ind.unit === '%' ? ' %' : ind.unit ? ` ${ind.unit}` : ''}`}</em>
                  <Status tone="neutral">Cible {ind.target}</Status>
                </button>
              ))}
            </div>
          </section>
        )}
        <section className="panel">
          <div className="panel-title"><div><h2>{can('audit.view', 'users.manage') ? 'Activité récente' : 'Mes dernières actions'}</h2><p>Extrait du journal</p></div></div>
          <div className="meeting-list">
            {d.activity.map((a, i) => (
              <div key={i}>
                <time style={{ minWidth: 42 }}>{fmtTime(a.occurred_at)}</time>
                <span><strong>{ACTION_LABEL[a.action] ?? a.action}</strong><small>{a.actor_label ?? 'Système'}{a.object_label ? ` · ${a.object_label}` : ''} · {fmtDateTime(a.occurred_at)}</small></span>
              </div>
            ))}
          </div>
        </section>
        {(d.incidents.length > 0 || d.tasks_without_backup) && (
          <section className="panel">
            <div className="panel-title"><div><h2>Incidents et vigilance</h2><p>{d.cards.incidents.open} incident(s) ouvert(s)</p></div></div>
            <div className="task-list">
              {d.incidents.map((i) => (
                <button key={i.id} onClick={() => navigate('/supervision')}>
                  <span className="task-symbol red"><Icon name="alert" /></span>
                  <span><strong>{i.title}</strong><small>{i.reference} · {i.severity}</small></span>
                  <Icon name="chevron" size={16} />
                </button>
              ))}
              {!!d.tasks_without_backup && (
                <button onClick={() => navigate('/administration/utilisateurs')}>
                  <span className="task-symbol amber"><Icon name="users" /></span>
                  <span><strong>{d.tasks_without_backup} tâche(s) sans suppléant</strong><small>Désigner un suppléant (HAB-04)</small></span>
                  <Icon name="chevron" size={16} />
                </button>
              )}
            </div>
          </section>
        )}
        {d.my_reservations.length > 0 && (
          <section className="panel">
            <div className="panel-title"><div><h2>Mes prochaines réservations</h2></div><button className="link" onClick={() => navigate('/mes-reservations')}>Toutes</button></div>
            <div className="meeting-list">
              {d.my_reservations.map((r) => (
                <div key={r.id} style={{ cursor: 'pointer' }} onClick={() => navigate(`/reservations/${r.reservation_id}`)}>
                  <time>{fmtTime(r.starts_at)}</time>
                  <span><strong>{r.room}</strong><small>{fmtDateTime(r.starts_at)} · {r.status === 'pending' ? 'en attente' : 'confirmée'}</small></span>
                </div>
              ))}
            </div>
          </section>
        )}
      </div>
    </>
  )
}
