import { useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../lib/api'
import { useAuth } from '../lib/auth'
import { useRealtime } from '../lib/realtime'
import { fmtRelative, fmtTime } from '../lib/format'
import { useDebounced, useToast, Icon, type IconName } from './ui'

interface NavItem { to: string; label: string; icon: IconName; perm: string[]; badge?: 'tasks' }
/**
 * Menu principal : publication, diffusion d'urgence, parc d'écrans (ordre du §16 de la spécification du module), salles,
 * pilotage et administration. Chaque entrée n'apparaît qu'avec l'une de ses permissions ; un groupe vide est masqué.
 */
const NAV: { group: string; items: NavItem[] }[] = [
  { group: 'Vue générale', items: [
    { to: '/accueil', label: 'Tableau de bord', icon: 'home', perm: ['dashboard.view'] },
    { to: '/mes-taches', label: 'Tâches & validations', icon: 'check', perm: ['dashboard.view'], badge: 'tasks' },
    { to: '/notifications', label: 'Notifications', icon: 'bell', perm: [] },
  ] },
  { group: 'Publication', items: [
    { to: '/affichage/contenus', label: 'Contenus', icon: 'file', perm: ['contents.view'] },
    { to: '/mediatheque', label: 'Médiathèque', icon: 'image', perm: ['media.view'] },
    { to: '/documents', label: 'Documents', icon: 'folder', perm: ['documents.view'] },
    { to: '/affichage/modeles', label: 'Modèles', icon: 'layout', perm: ['templates.view', 'templates.manage'] },
    { to: '/affichage/campagnes', label: 'Campagnes', icon: 'calendar', perm: ['campaigns.view'] },
    { to: '/affichage/programme', label: 'Programme & repli', icon: 'play', perm: ['campaigns.view'] },
  ] },
  { group: 'Diffusion d’urgence', items: [
    { to: '/affichage/alertes', label: 'Alertes urgentes', icon: 'alert', perm: ['alerts.view', 'alerts.manage'] },
  ] },
  { group: 'Parc d’écrans', items: [
    { to: '/affichage/parc', label: 'Vue d’ensemble', icon: 'chart', perm: ['screens.view', 'supervision.view'] },
    { to: '/affichage/ecrans', label: 'Tous les écrans', icon: 'monitor', perm: ['screens.view'] },
    { to: '/affichage/groupes', label: 'Groupes d’écrans', icon: 'list', perm: ['screens.view', 'groups.manage'] },
    { to: '/affichage/profils', label: 'Profils de configuration', icon: 'settings', perm: ['screens.view', 'screens.manage'] },
    { to: '/affichage/appairage', label: 'Enrôlement des lecteurs', icon: 'plus', perm: ['devices.pair'] },
    { to: '/supervision', label: 'Supervision & incidents', icon: 'wifi', perm: ['supervision.view'] },
  ] },
  { group: 'Salles & réunions', items: [
    { to: '/salles', label: 'Salles', icon: 'door', perm: ['rooms.view'] },
    { to: '/salles/calendrier', label: 'Calendrier des salles', icon: 'calendar', perm: ['rooms.view'] },
    { to: '/mes-reservations', label: 'Mes réservations', icon: 'clock', perm: ['reservations.create'] },
    { to: '/reservations/a-valider', label: 'Réservations à valider', icon: 'check', perm: ['reservations.approve'] },
    { to: '/prestations', label: 'Prestations', icon: 'settings', perm: ['services.process'] },
    { to: '/accueil-protocole', label: 'Accueil & protocole', icon: 'users', perm: ['visits.manage'] },
  ] },
  { group: 'Pilotage', items: [
    { to: '/rapports', label: 'Statistiques & rapports', icon: 'chart', perm: ['reports.view'] },
    { to: '/traitements', label: 'Exports & traitements', icon: 'download', perm: ['dashboard.view'] },
  ] },
  { group: 'Administration', items: [
    { to: '/administration/utilisateurs', label: 'Utilisateurs', icon: 'users', perm: ['users.manage', 'roles.manage'] },
    { to: '/administration/roles', label: 'Rôles & délégations', icon: 'shield', perm: ['roles.manage', 'users.manage'] },
    { to: '/administration/referentiels', label: 'Référentiels & localisation', icon: 'building', perm: ['referentials.manage'] },
    { to: '/administration/imports', label: 'Imports', icon: 'upload', perm: ['imports.run'] },
    { to: '/administration/journaux', label: 'Journal d’audit', icon: 'file', perm: ['audit.view'] },
    { to: '/administration/parametres', label: 'Paramètres', icon: 'settings', perm: ['settings.manage', 'settings.technical'] },
  ] },
]

/** Pages hors menu : leur fil d'Ariane ne doit pas se réduire au nom de l'application. */
const OFF_MENU_LABELS: { to: string; label: string }[] = [
  { to: '/reservations/nouvelle', label: 'Nouvelle réservation' }, { to: '/reservations', label: 'Réservation' }, { to: '/mon-compte', label: 'Mon compte' },
  { to: '/aide', label: 'Aide & documentation' }, { to: '/affichage/diffusions', label: 'Suivi de diffusion' },
]

export function findNavLabel(pathname: string): string {
  let best: { to: string; label: string } | undefined
  for (const i of [...NAV.flatMap((g) => g.items), ...OFF_MENU_LABELS]) if (pathname.startsWith(i.to) && (!best || i.to.length > best.to.length)) best = i
  return best?.label ?? 'SAIE-CEEAC'
}

interface NotificationItem { id: string; title: string; body: string | null; created_at: string; read_at: string | null; link: string | null }

export default function Layout() {
  const { me, can } = useAuth()
  // §10.22 : flux temps réel ouvert pour les comptes qui consultent le parc ou la supervision.
  useRealtime(Boolean(me) && (can('supervision.view') || can('screens.view')))
  const location = useLocation()
  const navigate = useNavigate()
  const client = useQueryClient()
  const toast = useToast()
  const [sidebar, setSidebar] = useState(false)
  const [popover, setPopover] = useState<'notifications' | 'profile' | 'search' | null>(null)
  const [term, setTerm] = useState('')
  const debounced = useDebounced(term, 250)
  const searchRef = useRef<HTMLInputElement>(null)

  const notifications = useQuery({ queryKey: ['notifications', 'pop'], queryFn: () => api.get<{ data: NotificationItem[]; meta: { unread: number } }>('/notifications', { per_page: 6 }), refetchInterval: 60_000 })
  const tasks = useQuery({ queryKey: ['tasks', 'count'], queryFn: () => api.get<{ meta: { counts: { open: number } } }>('/tasks'), refetchInterval: 120_000, enabled: can('dashboard.view') })
  const search = useQuery({ queryKey: ['search', debounced], queryFn: () => api.get<{ data: { type: string; label: string; sub: string; link: string }[] }>('/search', { q: debounced }), enabled: debounced.length >= 2 })

  const firstPage = useRef(true)
  useEffect(() => {
    setSidebar(false)
    setPopover(null)
    // WCAG 2.4.3 : après une navigation interne, le focus passe au contenu principal (pas au premier chargement).
    if (firstPage.current) {
      firstPage.current = false
      return
    }
    document.getElementById('contenu')?.focus()
  }, [location.pathname])
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        setPopover('search')
        setTimeout(() => searchRef.current?.focus(), 10)
      }
      // Échap ferme tout menu ouvert (notifications, compte, recherche) — UX-01.
      if (e.key === 'Escape') setPopover(null)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  const logout = async () => {
    await api.post('/auth/logout', undefined, false).catch(() => undefined)
    client.clear()
    navigate('/connexion', { replace: true })
  }
  const openNotification = async (n: NotificationItem) => {
    setPopover(null)
    try {
      const r = await api.get<{ data: { link: string | null } }>(`/notifications/${n.id}/open`)
      client.invalidateQueries({ queryKey: ['notifications'] })
      if (r.data.link) navigate(r.data.link)
    } catch {
      navigate('/notifications?inaccessible=1')
    }
  }
  const unread = notifications.data?.meta.unread ?? 0
  const taskCount = tasks.data?.meta.counts.open ?? 0
  const title = findNavLabel(location.pathname)

  return (
    <div className="app-shell">
      <a className="skip-link" href="#contenu">Aller au contenu</a>
      <aside className={`sidebar ${sidebar ? 'open' : ''}`} aria-label="Navigation principale">
        <div className="brand">
          <img src="/ceeac-logo.jpg" alt="Logo officiel de la CEEAC" />
          <div>
            <strong>SAIE<span>—CEEAC</span></strong>
            <small>Système d’information</small>
          </div>
          <button className="mobile-close" onClick={() => setSidebar(false)} aria-label="Fermer le menu"><Icon name="x" /></button>
        </div>
        <nav aria-label="Menu principal">
          {NAV.map((group) => {
            const items = group.items.filter((i) => i.perm.length === 0 || can(...i.perm))
            if (!items.length) return null
            return (
              <div className="nav-group" key={group.group}>
                <small>{group.group}</small>
                {items.map((item) => (
                  <NavLink key={item.to} to={item.to} end={item.to === '/salles'} className={({ isActive }) => (isActive ? 'active' : '')}>
                    <Icon name={item.icon} />
                    <span>{item.label}</span>
                    {item.badge === 'tasks' && taskCount > 0 && <b>{taskCount}</b>}
                  </NavLink>
                ))}
              </div>
            )
          })}
        </nav>
        <div className="sidebar-foot">
          <div className="system-state">
            <span><i />Session active</span>
            <small>Heure serveur · {fmtTime(me.server_time)} · Africa/Libreville</small>
          </div>
          <NavLink to="/aide"><Icon name="info" /><span>Aide & documentation</span></NavLink>
        </div>
      </aside>
      {sidebar && <button className="scrim" onClick={() => setSidebar(false)} aria-label="Fermer le menu" />}
      <div className="main-shell">
        <header className="topbar">
          <button className="menu-button" onClick={() => setSidebar(true)} aria-label="Ouvrir le menu"><Icon name="layout" /></button>
          <div className="breadcrumb">
            <span>SAIE-CEEAC</span>
            <Icon name="chevron" size={14} />
            <strong>{title}</strong>
          </div>
          <button className="global-search" onClick={() => { setPopover('search'); setTimeout(() => searchRef.current?.focus(), 10) }}>
            <Icon name="search" />
            <span>Rechercher dans SAIE…</span>
            <kbd>Ctrl K</kbd>
          </button>
          <div className="top-actions">
            <button className="icon-button notification" onClick={() => setPopover(popover === 'notifications' ? null : 'notifications')} aria-label={`Notifications (${unread} non lues)`}>
              <Icon name="bell" />
              {unread > 0 && <i>{unread > 99 ? '99+' : unread}</i>}
            </button>
            <div className="top-divider" />
            <button className="profile" onClick={() => setPopover(popover === 'profile' ? null : 'profile')} aria-haspopup="menu" aria-expanded={popover === 'profile'}>
              <span>{me.initials}</span>
              <div>
                <strong>{me.name}</strong>
                <small>{me.role_label}</small>
              </div>
              <Icon name="chevron" size={14} />
            </button>
          </div>
          {popover === 'search' && (
            <div className="notification-pop" style={{ left: '50%', right: 'auto', transform: 'translateX(-50%)', width: 'min(520px, calc(100vw - 20px))' }} role="dialog" aria-label="Recherche globale">
              <header>
                <label className="search-field" style={{ width: '100%' }}>
                  <Icon name="search" size={17} />
                  <input ref={searchRef} value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Contenus, documents, salles, écrans…" onKeyDown={(e) => e.key === 'Escape' && setPopover(null)} />
                </label>
              </header>
              {debounced.length < 2 && <div className="state-box" style={{ padding: 20 }}><p>Saisissez au moins deux caractères. Seuls les objets autorisés sont proposés.</p></div>}
              {search.data?.data.map((r) => (
                <button key={r.link} onClick={() => { setPopover(null); setTerm(''); navigate(r.link) }}>
                  <span><strong>{r.label}</strong><small>{{ content: 'Contenu', document: 'Document', room: 'Salle', screen: 'Écran' }[r.type] ?? r.type} · {r.sub}</small></span>
                </button>
              ))}
              {debounced.length >= 2 && search.data && search.data.data.length === 0 && <div className="state-box" style={{ padding: 20 }}><p>Aucun résultat dans votre périmètre.</p></div>}
            </div>
          )}
          {popover === 'notifications' && (
            <div className="notification-pop" role="dialog" aria-label="Notifications">
              <header>
                <strong>Notifications</strong>
                <button onClick={async () => {
                  try {
                    await api.post('/notifications/read-all', undefined, false)
                    client.invalidateQueries({ queryKey: ['notifications'] })
                  } catch (e) {
                    toast.error(e)
                  }
                }}>Tout marquer lu</button>
              </header>
              {notifications.data?.data.length === 0 && <div className="state-box" style={{ padding: 20 }}><p>Aucune notification.</p></div>}
              {notifications.data?.data.map((n) => (
                <button key={n.id} onClick={() => openNotification(n)}>
                  {!n.read_at && <i aria-label="non lue" />}
                  <span><strong>{n.title}</strong><small>{n.body ? `${n.body} · ` : ''}{fmtRelative(n.created_at)}</small></span>
                </button>
              ))}
              <button onClick={() => { setPopover(null); navigate('/notifications') }} style={{ justifyContent: 'center', color: 'var(--blue)' }}>Voir toutes les notifications</button>
            </div>
          )}
          {popover === 'profile' && (
            <div className="profile-pop" role="menu">
              <div>
                <strong>{me.name}</strong>
                <small>{me.structure?.name ?? 'Sans structure'} · {me.role_label}</small>
              </div>
              <button role="menuitem" onClick={() => navigate('/mon-compte')}><Icon name="users" />Mon compte</button>
              <button role="menuitem" onClick={() => navigate('/mon-compte?securite=1')}><Icon name="shield" />Sécurité & sessions</button>
              <button role="menuitem" onClick={() => navigate('/traitements')}><Icon name="download" />Mes exports</button>
              <hr />
              <button role="menuitem" onClick={logout}><Icon name="logout" />Se déconnecter</button>
            </div>
          )}
        </header>
        <main className="content-area" id="contenu" tabIndex={-1}>
          <Outlet />
        </main>
      </div>
    </div>
  )
}
