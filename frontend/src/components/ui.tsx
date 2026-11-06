import { createContext, useCallback, useContext, useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { useBlocker } from 'react-router'
import { ApiError } from '../lib/api'

export type IconName =
  | 'home' | 'file' | 'image' | 'folder' | 'layout' | 'calendar' | 'monitor' | 'door' | 'users' | 'alert' | 'check' | 'chart' | 'settings' | 'play' | 'info'
  | 'search' | 'bell' | 'plus' | 'arrow' | 'more' | 'filter' | 'download' | 'clock' | 'wifi' | 'upload' | 'eye' | 'shield' | 'x' | 'chevron' | 'edit' | 'building' | 'sun' | 'logout' | 'refresh' | 'trash' | 'list'

const paths: Record<IconName, ReactNode> = {
  home: <><path d="m3 11 9-8 9 8" /><path d="M5 10v10h14V10M9 20v-6h6v6" /></>,
  file: <><path d="M6 2h8l4 4v16H6z" /><path d="M14 2v5h5M9 13h6M9 17h6" /></>,
  image: <><rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="9" cy="10" r="2" /><path d="m3 17 5-5 4 4 3-3 6 6" /></>,
  folder: <path d="M3 6h7l2 2h9v11H3z" />,
  layout: <><rect x="3" y="3" width="18" height="18" rx="2" /><path d="M3 9h18M9 9v12" /></>,
  calendar: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M7 3v4M17 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01" /></>,
  monitor: <><rect x="2" y="3" width="20" height="14" rx="2" /><path d="M8 21h8M12 17v4" /></>,
  door: <><path d="M5 21V3h14v18M9 21V7h7v14" /><path d="M13 14h.01" /></>,
  users: <><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></>,
  alert: <><path d="M10.3 3.8 2.2 18a2 2 0 0 0 1.7 3h16.2a2 2 0 0 0 1.7-3L13.7 3.8a2 2 0 0 0-3.4 0Z" /><path d="M12 9v4M12 17h.01" /></>,
  check: <path d="m20 6-11 11-5-5" />,
  chart: <><path d="M4 19V9M10 19V5M16 19v-7M22 19V2" /><path d="M2 19h21" /></>,
  settings: <><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z" /></>,
  play: <path d="m8 5 11 7-11 7z" />,
  info: <><circle cx="12" cy="12" r="10" /><path d="M12 11v6M12 7h.01" /></>,
  search: <><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></>,
  bell: <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />,
  plus: <path d="M12 5v14M5 12h14" />,
  arrow: <path d="M5 12h14M13 6l6 6-6 6" />,
  more: <><circle cx="5" cy="12" r="1" /><circle cx="12" cy="12" r="1" /><circle cx="19" cy="12" r="1" /></>,
  filter: <path d="M4 5h16M7 12h10M10 19h4" />,
  download: <path d="M12 3v12m0 0 5-5m-5 5-5-5M4 21h16" />,
  clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
  wifi: <path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M12 20h.01M2 9a14 14 0 0 1 20 0" />,
  upload: <path d="M12 16V4m0 0-5 5m5-5 5 5M4 20h16" />,
  eye: <><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /></>,
  shield: <><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" /><path d="m9 12 2 2 4-4" /></>,
  x: <path d="m6 6 12 12M18 6 6 18" />,
  chevron: <path d="m9 18 6-6-6-6" />,
  edit: <path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z" />,
  building: <path d="M4 21V4h10v17M14 9h6v12M8 8h2M8 12h2M8 16h2M17 13h.01M17 17h.01M2 21h20" />,
  sun: <><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41" /></>,
  logout: <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />,
  refresh: <path d="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5" />,
  trash: <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6" />,
  list: <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />,
}

export function Icon({ name, size = 18 }: { name: IconName; size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {paths[name]}
    </svg>
  )
}

export function Status({ children, tone = 'neutral', title }: { children: ReactNode; tone?: string; title?: string }) {
  return (
    <span className={`status ${tone}`} title={title}>
      <span className="status-dot" aria-hidden="true" />
      {children}
    </span>
  )
}

export function StatusOf({ map, value }: { map: Record<string, [string, string]>; value?: string | null }) {
  const [label, tone] = (value && map[value]) || [value ?? '—', 'neutral']
  return <Status tone={tone}>{label}</Status>
}

interface ButtonProps {
  children?: ReactNode
  icon?: IconName
  variant?: 'primary' | 'secondary' | 'light' | 'danger' | 'ghost'
  size?: 'small'
  onClick?: () => void
  disabled?: boolean
  type?: 'button' | 'submit'
  busy?: boolean
  title?: string
  ariaLabel?: string
}

export function Button({ children, icon, variant = 'primary', size, onClick, disabled, type = 'button', busy, title, ariaLabel }: ButtonProps) {
  return (
    <button type={type} className={`button ${variant === 'primary' ? '' : variant} ${size ?? ''}`} onClick={onClick} disabled={disabled || busy} title={title} aria-label={ariaLabel} aria-busy={busy}>
      {busy ? <span className="spinner" style={{ width: 14, height: 14, borderWidth: 2 }} aria-hidden="true" /> : icon && <Icon name={icon} size={16} />}
      {children && <span>{children}</span>}
    </button>
  )
}

export function PageHeading({ title, description, actions, demo }: { title: string; description?: string; actions?: ReactNode; demo?: boolean }) {
  return (
    <>
      <div className="page-heading">
        <div>
          <h1>{title}</h1>
          {description && <p>{description}</p>}
        </div>
        {actions && <div className="heading-actions">{actions}</div>}
      </div>
      {demo ? (
        <div className="demo-note">
          <Icon name="info" size={15} />
          <span>Données fictives de démonstration enregistrées en base — site de Libreville</span>
        </div>
      ) : (
        <div style={{ height: 6 }} />
      )}
    </>
  )
}

export function Toolbar({ value, onChange, placeholder = 'Rechercher…', children, right }: { value: string; onChange: (v: string) => void; placeholder?: string; children?: ReactNode; right?: ReactNode }) {
  return (
    <div className="toolbar">
      <label className="search-field">
        <Icon name="search" size={17} />
        <span className="sr-only">Recherche</span>
        <input value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} />
      </label>
      {children}
      <div className="toolbar-spacer" />
      {right}
    </div>
  )
}

export function Select({ value, onChange, options, label, allLabel }: { value: string; onChange: (v: string) => void; options: { value: string; label: string }[]; label: string; allLabel?: string }) {
  return (
    <label className="sr-label" style={{ display: 'inline-flex' }}>
      <span className="sr-only">{label}</span>
      <select className="input" style={{ width: 'auto', minWidth: 150, height: 34 }} value={value} onChange={(e) => onChange(e.target.value)} aria-label={label}>
        {allLabel !== undefined && <option value="">{allLabel}</option>}
        {options.map((o) => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </select>
    </label>
  )
}

export function Pagination({ meta, onPage }: { meta?: { current_page: number; last_page: number; total: number; per_page: number }; onPage: (p: number) => void }) {
  if (!meta) return null
  const from = meta.total === 0 ? 0 : (meta.current_page - 1) * meta.per_page + 1
  const to = Math.min(meta.total, meta.current_page * meta.per_page)
  const pages = Array.from({ length: meta.last_page }, (_, i) => i + 1).filter((p) => Math.abs(p - meta.current_page) < 3 || p === 1 || p === meta.last_page)
  return (
    <nav className="pagination" aria-label="Pagination">
      <span>{from}–{to} sur {meta.total}</span>
      <div>
        <button disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Précédent</button>
        {pages.map((p) => (
          <button key={p} className={p === meta.current_page ? 'active' : ''} aria-current={p === meta.current_page ? 'page' : undefined} onClick={() => onPage(p)}>{p}</button>
        ))}
        <button disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Suivant</button>
      </div>
    </nav>
  )
}

export function Loading({ label = 'Chargement…' }: { label?: string }) {
  return (
    <div className="state-box" role="status" aria-live="polite">
      <span className="spinner" />
      <p>{label}</p>
    </div>
  )
}

export function EmptyState({ title, text, action }: { title: string; text?: string; action?: ReactNode }) {
  return (
    <div className="state-box">
      <div className="empty-icon"><Icon name="search" size={23} /></div>
      <h3>{title}</h3>
      {text && <p>{text}</p>}
      {action}
    </div>
  )
}

/** États d'erreur, d'accès interdit et de session expirée (UX-03, V42). */
export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const e = error instanceof ApiError ? error : null
  if (e?.status === 403) {
    return (
      <div className="state-box forbidden" role="alert">
        <div className="empty-icon"><Icon name="shield" size={23} /></div>
        <h3>Accès non autorisé</h3>
        <p>Vos habilitations ne permettent pas d’afficher cette page ou d’effectuer cette action.</p>
        {e.requestId && <small className="mono">Diagnostic : {e.requestId}</small>}
      </div>
    )
  }
  if (e?.status === 404) {
    return (
      <div className="state-box">
        <div className="empty-icon"><Icon name="search" size={23} /></div>
        <h3>Objet absent ou inaccessible</h3>
        <p>L’élément demandé n’existe pas, a été retiré ou n’entre pas dans votre périmètre.</p>
      </div>
    )
  }
  return (
    <div className="state-box error" role="alert">
      <div className="empty-icon"><Icon name="alert" size={23} /></div>
      <h3>{e?.status === 0 ? 'Connexion impossible' : 'Une erreur est survenue'}</h3>
      <p>{e?.message ?? 'Le service n’a pas pu répondre.'}</p>
      {e?.requestId && <small className="mono">Identifiant de diagnostic : {e.requestId}</small>}
      {onRetry && <Button variant="secondary" icon="refresh" onClick={onRetry}>Réessayer</Button>}
    </div>
  )
}

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * Comportement clavier d'une fenêtre modale (UX-01, WCAG 2.1.2 et 2.4.3) : focus initial à l'intérieur, tabulation piégée
 * dans la fenêtre, Échap pour fermer, retour du focus à l'élément d'origine à la fermeture.
 */
export function useDialogFocus(ref: React.RefObject<HTMLElement | null>, onClose: () => void, initialSelector = FOCUSABLE) {
  const close = useRef(onClose)
  close.current = onClose
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    ref.current?.querySelector<HTMLElement>(initialSelector)?.focus()
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        close.current()
        return
      }
      if (e.key !== 'Tab' || !ref.current) return
      const items = Array.from(ref.current.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((el) => el.offsetParent !== null || el === document.activeElement)
      if (!items.length) return
      const first = items[0]
      const last = items[items.length - 1]
      if (e.shiftKey && (document.activeElement === first || !ref.current.contains(document.activeElement))) {
        e.preventDefault()
        last.focus()
      } else if (!e.shiftKey && (document.activeElement === last || !ref.current.contains(document.activeElement))) {
        e.preventDefault()
        first.focus()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('keydown', onKey)
      previous?.focus()
    }
  }, [ref, initialSelector])
}

export function Modal({ title, kicker, children, footer, onClose, wide }: { title: string; kicker?: string; children: ReactNode; footer?: ReactNode; onClose: () => void; wide?: boolean }) {
  const id = useId()
  const ref = useRef<HTMLDivElement>(null)
  useDialogFocus(ref, onClose, 'input, select, textarea, button')
  return (
    <div className="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby={id}>
      <div className={`dialog ${wide ? 'wide-dialog' : ''}`} ref={ref}>
        <header>
          <div>
            {kicker && <small>{kicker}</small>}
            <h2 id={id}>{title}</h2>
          </div>
          <button className="icon-button" onClick={onClose} aria-label="Fermer"><Icon name="x" /></button>
        </header>
        <div className="dialog-body">{children}</div>
        {footer && <footer>{footer}</footer>}
      </div>
    </div>
  )
}

/**
 * Boîte de confirmation contextualisée (chapitre 30) : objet, portée, conséquence, motif ; le bouton exprime l'action réelle.
 */
export function ConfirmDialog({ title, object, consequence, confirmLabel, reasonLabel, reasonRequired, danger, onConfirm, onClose, children }: {
  title: string; object?: string; consequence: string; confirmLabel: string; reasonLabel?: string; reasonRequired?: boolean; danger?: boolean
  onConfirm: (reason: string) => Promise<unknown> | void; onClose: () => void; children?: ReactNode
}) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      await onConfirm(reason)
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  const invalid = reasonRequired && reason.trim().length < 5
  return (
    <Modal title={title} kicker="CONFIRMATION" onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button variant={danger ? 'danger' : 'primary'} onClick={submit} busy={busy} disabled={invalid}>{confirmLabel}</Button>
    </>}>
      <div className={`confirmation-box ${danger ? 'danger' : ''}`} style={danger ? undefined : { background: 'var(--blue-soft)', color: '#0b4f78' }}>
        <Icon name={danger ? 'alert' : 'info'} />
        <div>
          {object && <strong>{object}</strong>}
          <p>{consequence}</p>
        </div>
      </div>
      {children}
      {reasonLabel && (
        <label className="field" style={{ marginTop: 14 }}>
          {reasonLabel} {reasonRequired && <em aria-hidden="true">*</em>}
          <textarea value={reason} onChange={(e) => setReason(e.target.value)} required={reasonRequired} aria-invalid={!!error?.field('reason')} />
          {reasonRequired && <span className="field-help">5 caractères minimum. Le motif est inscrit au journal.</span>}
        </label>
      )}
      {error && <div className="notice danger" style={{ marginTop: 12 }} role="alert"><Icon name="alert" size={16} /><span>{error.message}</span></div>}
      <div className="footer-note" style={{ marginTop: 12 }}><Icon name="shield" size={14} /> Action tracée dans le journal</div>
    </Modal>
  )
}

export function Field({ label, required, error, help, children, wide }: { label: string; required?: boolean; error?: string; help?: string; children: ReactNode; wide?: boolean }) {
  return (
    <label className={`field ${wide ? 'wide' : ''}`}>
      <span>{label} {required && <em aria-hidden="true">*</em>}{required && <span className="sr-only">(obligatoire)</span>}</span>
      {children}
      {help && !error && <span className="field-help">{help}</span>}
      {error && <span className="field-error" role="alert">{error}</span>}
    </label>
  )
}

export function Tabs<T extends string>({ value, onChange, items }: { value: T; onChange: (v: T) => void; items: { value: T; label: string; count?: number | null }[] }) {
  return (
    <div className="tabs" role="tablist">
      {items.map((item) => (
        <button key={item.value} role="tab" aria-selected={value === item.value} className={value === item.value ? 'active' : ''} onClick={() => onChange(item.value)}>
          {item.label}
          {item.count ? <span>{item.count}</span> : null}
        </button>
      ))}
    </div>
  )
}

export function Notice({ tone = 'info', children, icon }: { tone?: 'info' | 'warning' | 'danger' | 'success'; children: ReactNode; icon?: IconName }) {
  return (
    <div className={`notice ${tone}`} role={tone === 'danger' ? 'alert' : undefined}>
      <Icon name={icon ?? (tone === 'success' ? 'check' : tone === 'info' ? 'info' : 'alert')} size={16} />
      <div>{children}</div>
    </div>
  )
}

/* ===== Notifications éphémères ===== */
interface Toast { id: number; tone: 'success' | 'error' | 'info'; title: string; text?: string }
const ToastContext = createContext<(t: Omit<Toast, 'id'>) => void>(() => undefined)

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])
  const push = useCallback((t: Omit<Toast, 'id'>) => {
    const id = Date.now() + Math.random()
    setToasts((list) => [...list, { ...t, id }])
    setTimeout(() => setToasts((list) => list.filter((x) => x.id !== id)), 6000)
  }, [])
  return (
    <ToastContext.Provider value={push}>
      {children}
      <div className="toast-stack" aria-live="polite">
        {toasts.map((t) => (
          <div key={t.id} className={`toast ${t.tone}`}>
            <Icon name={t.tone === 'success' ? 'check' : t.tone === 'error' ? 'alert' : 'info'} size={16} />
            <div><strong>{t.title}</strong>{t.text && <small>{t.text}</small>}</div>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast() {
  const push = useContext(ToastContext)
  return {
    success: (title: string, text?: string) => push({ tone: 'success', title, text }),
    error: (e: unknown, title = 'Action impossible') => push({ tone: 'error', title, text: e instanceof ApiError ? e.message : String(e) }),
    info: (title: string, text?: string) => push({ tone: 'info', title, text }),
  }
}

/** Avertit avant de quitter une page contenant un brouillon non enregistré (UX-03). */
/**
 * UX-03 : prévient la perte d'un brouillon, à la fermeture de l'onglet comme à la navigation interne (menu, liens,
 * retour arrière). Les redirections qui suivent un enregistrement passent `state: { afterSave: true }` et ne sont pas bloquées.
 */
export function useUnsavedWarning(dirty: boolean) {
  useEffect(() => {
    if (!dirty) return
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', handler)
    return () => window.removeEventListener('beforeunload', handler)
  }, [dirty])
  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && currentLocation.pathname !== nextLocation.pathname
    && !(nextLocation.state as { afterSave?: boolean } | null)?.afterSave)
  useEffect(() => {
    if (blocker.state !== 'blocked') return
    if (window.confirm('Des modifications ne sont pas enregistrées. Quitter cette page et les abandonner ?')) blocker.proceed()
    else blocker.reset()
  }, [blocker])
}

export function useDebounced<T>(value: T, delay = 300): T {
  const [v, setV] = useState(value)
  useEffect(() => {
    const t = setTimeout(() => setV(value), delay)
    return () => clearTimeout(t)
  }, [value, delay])
  return v
}

export function Kv({ items }: { items: [string, ReactNode][] }) {
  return (
    <dl className="kv">
      {items.map(([k, v]) => (
        <div key={k} style={{ display: 'contents' }}>
          <dt>{k}</dt>
          <dd>{v ?? '—'}</dd>
        </div>
      ))}
    </dl>
  )
}
