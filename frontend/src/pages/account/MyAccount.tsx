import { useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { QRCodeSVG } from 'qrcode.react'
import { api, ApiError } from '../../lib/api'
import { useAuth, type Me } from '../../lib/auth'
import { pushSupported, subscribePush, unsubscribePush, type PushStatus } from '../../lib/push'
import { fmtDate, fmtDateTime, fmtRelative, LANG_LABEL, TZ } from '../../lib/format'
import { Button, ConfirmDialog, ErrorState, Field, Icon, Kv, Loading, Notice, PageHeading, Status, useToast } from '../../components/ui'
import { PasswordRules, passwordValid } from './Verification'
import '../admin/admin.css'
import './account.css'

type MeFull = Me & {
  timezone?: string | null
  timezone_institution?: string
  backup?: { id: string; name: string } | null
  last_login_at?: string | null
}
interface SessionRow { id: string; current: boolean; ip_address: string | null; user_agent: string; last_activity: string }

const PUSH_EVENT_LABEL: Record<string, string> = {
  'reservation.confirmed': 'réservation confirmée', 'reservation.refused': 'réservation refusée', 'reservation.reminder': 'rappel de réunion', 'content.returned': 'contenu retourné pour correction',
}
const POWER_LABEL: Record<string, string> = { urgence: 'Urgence', modeles: 'Modèles', prestations: 'Prestations' }
/** Événements dont l'envoi par courriel peut être coupé (la notification interne reste toujours visible). */
const EVENTS: { code: string; label: string }[] = [
  { code: 'content.submitted', label: 'Contenu soumis à validation' },
  { code: 'content.approved', label: 'Contenu approuvé' },
  { code: 'content.returned', label: 'Contenu retourné pour correction' },
  { code: 'content.expiring', label: 'Contenu bientôt expiré' },
  { code: 'content.reassigned', label: 'Validation réassignée' },
  { code: 'approval.reminder', label: 'Relance d’échéance' },
  { code: 'approval.no_backup', label: 'Tâche sans suppléant' },
  { code: 'incident.opened', label: 'Incident de diffusion détecté' },
  { code: 'incident.assigned', label: 'Incident qui m’est affecté' },
  { code: 'approval.unassigned', label: 'Validation sans validateur habilité' },
  { code: 'reservation.pending', label: 'Réservation en attente' },
  { code: 'reservation.confirmed', label: 'Réservation confirmée' },
  { code: 'reservation.refused', label: 'Réservation refusée' },
  { code: 'reservation.cancelled', label: 'Réservation annulée' },
  { code: 'reservation.moved', label: 'Réservation déplacée' },
  { code: 'reservation.expired', label: 'Demande de réservation expirée' },
  { code: 'service.requested', label: 'Prestation demandée' },
  { code: 'document.broadcast', label: 'Document diffusé par courriel' },
]

/** V03 Mon compte : identité, préférences, mot de passe, sessions et second facteur. */
export default function MyAccount() {
  const { me: base } = useAuth()
  const me = base as MeFull
  const [params] = useSearchParams()
  // Ordre figé au montage : les sections ne sont pas remontées (codes de secours conservés) quand /me est rafraîchi.
  const [securityFirst] = useState(() => me.must_change_password || me.mfa_enrollment_required || params.get('securite') === '1')

  return (
    <>
      <PageHeading title="Mon compte" description="Identité, préférences, sécurité et sessions actives." />
      {(me.must_change_password || me.mfa_enrollment_required) && (
        <div style={{ marginBottom: 15 }}>
          <Notice tone="warning">
            <strong>Action de sécurité requise.</strong>{' '}
            {me.must_change_password && 'Vous devez définir un nouveau mot de passe avant d’accéder aux autres pages. '}
            {me.mfa_enrollment_required && 'Votre rôle impose l’activation du second facteur (application d’authentification). '}
          </Notice>
        </div>
      )}
      <div className="account-grid">
        {securityFirst && <><PasswordSection me={me} /><MfaSection me={me} /></>}
        <IdentitySection me={me} />
        <RolesSection me={me} />
        <PreferencesSection me={me} />
        <PushSection me={me} />
        {!securityFirst && <><PasswordSection me={me} /><MfaSection me={me} /></>}
        <SessionsSection />
        <CalendarSubscriptionsSection />
      </div>
    </>
  )
}

function IdentitySection({ me }: { me: MeFull }) {
  return (
    <section className="panel account-section">
      <div className="panel-title"><div><h2>Identité</h2><p>{me.source === 'local' ? 'Compte local · modification par l’administration' : `Identité gérée par l’annuaire (${me.source}) : lecture seule`}</p></div></div>
      <div className="panel-body">
        <Kv items={[
          ['Nom', me.name], ['Adresse professionnelle', me.email], ['Fonction', me.title], ['Structure', me.structure?.name ?? null],
          ['Suppléant', me.backup?.name ?? 'Aucun'], ['Fuseau horaire', me.timezone || me.timezone_institution || TZ],
          ['Dernière connexion', me.last_login_at ? `${fmtDateTime(me.last_login_at)} (${fmtRelative(me.last_login_at)})` : null],
        ]} />
        {me.source === 'local' && <p className="muted small" style={{ marginTop: 10 }}>Pour corriger votre nom, votre fonction ou votre structure, adressez-vous à l’administrateur fonctionnel.</p>}
      </div>
    </section>
  )
}

function RolesSection({ me }: { me: MeFull }) {
  return (
    <section className="panel account-section">
      <div className="panel-title"><div><h2>Rôles et pouvoirs</h2><p>Droits effectifs calculés par le serveur</p></div></div>
      <div className="panel-body" style={{ display: 'grid', gap: 10 }}>
        {me.roles.length === 0 ? <p className="muted small">Aucun rôle actif.</p> : (
          <ul className="admin-bullets" style={{ listStyle: 'none', margin: 0 }}>
            {me.roles.map((r) => (
              <li key={r.id}>
                <strong>{r.name}</strong>
                {r.languages?.length ? ` · langues : ${r.languages.map((l) => l.toUpperCase()).join(', ')}` : ''}
                {r.ends_at ? ` · jusqu’au ${fmtDate(r.ends_at)}` : ''}
              </li>
            ))}
          </ul>
        )}
        {me.powers.length > 0 && (
          <div>
            <strong className="small">Pouvoirs étendus</strong>
            <div className="chip-row" style={{ marginTop: 6 }}>
              {me.powers.map((p, i) => <Status key={i} tone={p.active ? 'warning' : 'neutral'}>{POWER_LABEL[p.power] ?? p.power} · {p.active ? `jusqu’au ${fmtDate(p.ends_at)}` : 'inactif'}</Status>)}
            </div>
          </div>
        )}
        {me.validation_languages.length > 0 && <p className="small">Langues de validation : {me.validation_languages.map((l) => LANG_LABEL[l] ?? l).join(', ')}</p>}
      </div>
    </section>
  )
}

function PreferencesSection({ me }: { me: MeFull }) {
  const client = useQueryClient()
  const toast = useToast()
  const prefs = me.notification_preferences ?? {}
  const [locale, setLocale] = useState(me.locale)
  const [email, setEmail] = useState(prefs.email ?? true)
  const [muted, setMuted] = useState<string[]>(prefs.muted_events ?? [])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const dirty = locale !== me.locale || email !== (prefs.email ?? true) || muted.slice().sort().join() !== (prefs.muted_events ?? []).slice().sort().join()

  const save = async () => {
    setBusy(true)
    setError(null)
    try {
      await api.patch('/me', { locale, notification_preferences: { email, push: prefs.push ?? false, muted_events: muted } })
      await client.invalidateQueries({ queryKey: ['me'] })
      toast.success('Préférences enregistrées')
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="panel account-section span-2">
      <div className="panel-title"><div><h2>Langue et notifications</h2><p>Les notifications internes restent toujours visibles ; seuls les envois par courriel peuvent être coupés.</p></div></div>
      <div className="panel-body" style={{ display: 'grid', gap: 14 }}>
        <div className="form-grid">
          <Field label="Langue d’interface" error={error?.field('locale')}>
            <select className="input" value={locale} onChange={(e) => setLocale(e.target.value)}>
              {Object.entries(LANG_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </Field>
          <fieldset className="admin-fieldset">
            <legend>Canaux</legend>
            <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 11.5 }}><input type="checkbox" checked={email} onChange={(e) => setEmail(e.target.checked)} /> Recevoir les notifications par courriel</label>
            <p className="muted small" style={{ marginTop: 6 }}>Notifications push du navigateur : voir la section « Notifications push ».</p>
          </fieldset>
        </div>
        <fieldset className="admin-fieldset" disabled={!email}>
          <legend>Événements coupés pour le courriel {email ? '' : '(courriel désactivé)'}</legend>
          <div className="check-grid">
            {EVENTS.map((ev) => (
              <label key={ev.code}>
                <input type="checkbox" checked={muted.includes(ev.code)} onChange={(e) => setMuted(e.target.checked ? [...muted, ev.code] : muted.filter((m) => m !== ev.code))} />
                <span>{ev.label}<span className="sr-only"> ({muted.includes(ev.code) ? 'coupé' : 'actif'})</span></span>
              </label>
            ))}
          </div>
        </fieldset>
        {error && <Notice tone="danger">{error.message}</Notice>}
        <div className="form-actions" style={{ marginTop: 0 }}><Button onClick={save} busy={busy} disabled={!dirty}>Enregistrer les préférences</Button></div>
      </div>
    </section>
  )
}

function PasswordSection({ me }: { me: MeFull }) {
  const client = useQueryClient()
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [done, setDone] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    setDone(null)
    try {
      const r = await api.post<{ message: string }>('/me/password', { current_password: current, password, password_confirmation: confirmation }, false)
      setDone(r.message)
      setCurrent('')
      setPassword('')
      setConfirmation('')
      await client.invalidateQueries({ queryKey: ['me'] })
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, { message: String(err) }))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className={`panel account-section ${me.must_change_password ? 'highlight' : ''}`}>
      <div className="panel-title"><div><h2>Mot de passe</h2><p>{me.must_change_password ? 'Changement obligatoire avant de poursuivre' : 'Le changement ferme vos autres sessions'}</p></div></div>
      <form className="panel-body" style={{ display: 'grid', gap: 12 }} onSubmit={submit} noValidate>
        {me.source !== 'local' && <Notice tone="info">Votre mot de passe est géré par l’annuaire de l’institution.</Notice>}
        <Field label="Mot de passe actuel" required error={error?.field('current_password') ?? (error?.code === 'INVALID_PASSWORD' ? error.message : undefined)}>
          <input className="input" type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} />
        </Field>
        <Field label="Nouveau mot de passe" required error={error?.field('password')}>
          <input className="input" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} aria-invalid={!!error?.field('password')} />
        </Field>
        <Field label="Confirmation" required error={error?.field('password_confirmation')}>
          <input className="input" type="password" autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
        </Field>
        <PasswordRules password={password} confirmation={confirmation} />
        {error && !error.field('password') && !error.field('current_password') && error.code !== 'INVALID_PASSWORD' && <Notice tone="danger">{error.message}</Notice>}
        {done && <Notice tone="success">{done}</Notice>}
        <div className="form-actions" style={{ marginTop: 0 }}><Button type="submit" busy={busy} disabled={!current || !passwordValid(password, confirmation) || me.source !== 'local'}>Changer le mot de passe</Button></div>
      </form>
    </section>
  )
}

function MfaSection({ me }: { me: MeFull }) {
  const client = useQueryClient()
  const [setup, setSetup] = useState<{ secret: string; otpauth_uri: string } | null>(null)
  const [code, setCode] = useState('')
  const [codes, setCodes] = useState<string[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [copied, setCopied] = useState(false)
  const [mfaPassword, setMfaPassword] = useState('')

  const start = async () => {
    setBusy(true)
    setError(null)
    try {
      setSetup(await api.post<{ secret: string; otpauth_uri: string }>('/me/mfa/setup', undefined, false))
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }
  const confirm = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const r = await api.post<{ recovery_codes: string[] }>('/me/mfa/confirm', { code: code.replace(/\s/g, ''), password: mfaPassword }, false)
      setCodes(r.recovery_codes)
      setSetup(null)
      await client.invalidateQueries({ queryKey: ['me'] })
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, { message: String(err) }))
    } finally {
      setBusy(false)
    }
  }
  const copy = async () => {
    if (!codes) return
    try {
      await navigator.clipboard.writeText(codes.join('\n'))
      setCopied(true)
    } catch {
      setCopied(false)
    }
  }

  return (
    <section className={`panel account-section ${me.mfa_enrollment_required ? 'highlight' : ''}`}>
      <div className="panel-title">
        <div><h2>Second facteur (TOTP)</h2><p>Application d’authentification : FreeOTP, Aegis, Google Authenticator…</p></div>
        {me.mfa_enabled || codes ? <Status tone="success">Activé</Status> : <Status tone={me.mfa_enrollment_required ? 'danger' : 'neutral'}>{me.mfa_enrollment_required ? 'Obligatoire' : 'Non activé'}</Status>}
      </div>
      <div className="panel-body" style={{ display: 'grid', gap: 12 }}>
        {codes ? (
          <>
            <Notice tone="warning"><strong>Codes de secours — affichés une seule fois.</strong> Conservez-les hors ligne : chacun permet une connexion si vous perdez votre appareil.</Notice>
            <div className="recovery-codes" aria-label="Codes de secours">{codes.map((c) => <span key={c}>{c}</span>)}</div>
            <div className="row-actions" style={{ justifyContent: 'flex-start' }}>
              <Button variant="secondary" icon="file" onClick={copy}>{copied ? 'Codes copiés' : 'Copier les codes'}</Button>
              <Button onClick={() => setCodes(null)}>J’ai conservé mes codes</Button>
            </div>
          </>
        ) : me.mfa_enabled ? (
          <p className="small">Le second facteur est actif : il vous sera demandé à chaque connexion. Pour le réinitialiser (perte d’appareil), contactez l’administrateur technique.</p>
        ) : setup ? (
          <form onSubmit={confirm} style={{ display: 'grid', gap: 12 }}>
            <ol className="admin-bullets" style={{ listStyle: 'decimal' }}>
              <li>Scannez le QR code avec votre application, ou saisissez la clé manuellement.</li>
              <li>Saisissez le code à 6 chiffres affiché pour confirmer.</li>
            </ol>
            <div className="qr-box">
              <QRCodeSVG value={setup.otpauth_uri} size={168} level="M" title="QR code d’enrôlement du second facteur" />
              <div>
                <span className="small muted">Clé manuelle</span>
                <p className="mono" style={{ fontSize: 13, wordBreak: 'break-all' }}>{setup.secret.replace(/(.{4})/g, '$1 ').trim()}</p>
              </div>
            </div>
            <Field label="Code de vérification" required error={error?.field('code') ?? (error?.code === 'MFA_INVALID' ? error.message : undefined)}>
              <input className="input" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value)} maxLength={10} style={{ maxWidth: 180 }} />
            </Field>
            <Field label="Mot de passe actuel" required help="Redemandé pour lier ce second facteur à votre compte." error={error?.field('password') ?? (error?.code === 'INVALID_PASSWORD' ? error.message : undefined)}>
              <input className="input" type="password" autoComplete="current-password" value={mfaPassword} onChange={(e) => setMfaPassword(e.target.value)} style={{ maxWidth: 320 }} />
            </Field>
            {error && error.code !== 'MFA_INVALID' && error.code !== 'INVALID_PASSWORD' && !error.field('code') && !error.field('password') && <Notice tone="danger">{error.message}</Notice>}
            <div className="row-actions" style={{ justifyContent: 'flex-start' }}>
              <Button variant="secondary" onClick={() => { setSetup(null); setCode('') }}>Annuler</Button>
              <Button type="submit" busy={busy} disabled={code.replace(/\s/g, '').length < 6 || !mfaPassword}>Activer le second facteur</Button>
            </div>
          </form>
        ) : (
          <>
            <p className="small">{me.mfa_enrollment_required ? 'Votre rôle (administration ou validation) exige un second facteur. L’accès aux autres pages est bloqué jusqu’à son activation.' : 'Renforcez la protection de votre compte par un code à usage unique.'}</p>
            {error && <Notice tone="danger">{error.message}</Notice>}
            <div><Button icon="shield" onClick={start} busy={busy}>Configurer le second facteur</Button></div>
          </>
        )}
      </div>
    </section>
  )
}

function SessionsSection() {
  const client = useQueryClient()
  const toast = useToast()
  const [revoking, setRevoking] = useState<SessionRow | null>(null)
  const query = useQuery({ queryKey: ['me', 'sessions'], queryFn: ({ signal }) => api.get<{ data: SessionRow[] }>('/me/sessions', undefined, signal).then((r) => r.data) })
  return (
    <section className="panel table-panel account-section span-2">
      <div className="panel-title"><div><h2>Sessions actives</h2><p>Révoquez toute session que vous ne reconnaissez pas.</p></div></div>
      {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (
        <div className="table-scroll">
          <table>
            <thead><tr><th scope="col">Appareil / navigateur</th><th scope="col">Adresse IP</th><th scope="col">Dernière activité</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {query.data.map((s) => (
                <tr key={s.id}>
                  <td><strong>{s.user_agent || 'Inconnu'}</strong>{s.current && <small>Session actuelle</small>}</td>
                  <td className="mono">{s.ip_address ?? '—'}</td>
                  <td>{fmtDateTime(s.last_activity)}<small>{fmtRelative(s.last_activity)}</small></td>
                  <td>{s.current ? <Status tone="success">Cette session</Status> : <Button size="small" variant="secondary" icon="logout" onClick={() => setRevoking(s)}>Révoquer</Button>}</td>
                </tr>
              ))}
              {query.data.length === 0 && <tr><td colSpan={4} className="muted">Aucune session.</td></tr>}
            </tbody>
          </table>
        </div>
      )}
      {revoking && (
        <ConfirmDialog title="Révoquer la session" object={revoking.user_agent} confirmLabel="Révoquer" danger
          consequence="Cette session est fermée immédiatement ; l’appareil concerné devra se reconnecter."
          onClose={() => setRevoking(null)}
          onConfirm={async () => { await api.delete(`/me/sessions/${revoking.id}`); await client.invalidateQueries({ queryKey: ['me', 'sessions'] }); toast.success('Session révoquée') }} />
      )}
      <div style={{ padding: '10px 19px 16px' }} className="footer-note"><Icon name="shield" size={14} /> Les révocations sont tracées dans le journal.</div>
    </section>
  )
}

interface SubscriptionRow { id: string; room: { id: string; name: string | null }; created_at: string | null; last_used_at: string | null }

/** Abonnements iCalendar personnels aux salles : chaque adresse se révoque individuellement. */
function CalendarSubscriptionsSection() {
  const client = useQueryClient()
  const toast = useToast()
  const [revoking, setRevoking] = useState<SubscriptionRow | null>(null)
  const query = useQuery({ queryKey: ['me', 'calendar-subscriptions'], queryFn: ({ signal }) => api.get<{ data: SubscriptionRow[] }>('/me/calendar-subscriptions', undefined, signal).then((r) => r.data) })
  if (query.data && query.data.length === 0) return null
  return (
    <section className="panel table-panel account-section span-2">
      <div className="panel-title"><div><h2>Abonnements d’agenda</h2><p>Adresses iCalendar créées depuis la fiche d’une salle.</p></div></div>
      {query.isLoading ? <Loading /> : query.isError || !query.data ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (
        <div className="table-scroll">
          <table>
            <thead><tr><th scope="col">Salle</th><th scope="col">Créé le</th><th scope="col">Dernière lecture</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {query.data.map((s) => (
                <tr key={s.id}>
                  <td><strong>{s.room.name ?? '—'}</strong></td>
                  <td>{fmtDateTime(s.created_at)}</td>
                  <td>{s.last_used_at ? <>{fmtDateTime(s.last_used_at)}<small>{fmtRelative(s.last_used_at)}</small></> : 'Jamais lue'}</td>
                  <td><Button size="small" variant="secondary" icon="x" onClick={() => setRevoking(s)}>Révoquer</Button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {revoking && (
        <ConfirmDialog title="Révoquer l’abonnement" object={revoking.room.name ?? ''} confirmLabel="Révoquer" danger
          consequence="L’adresse cesse immédiatement de fonctionner ; votre agenda ne recevra plus les réservations de cette salle."
          onClose={() => setRevoking(null)}
          onConfirm={async () => { await api.post(`/calendar-subscriptions/${revoking.id}/revoke`); await client.invalidateQueries({ queryKey: ['me', 'calendar-subscriptions'] }); toast.success('Abonnement révoqué') }} />
      )}
    </section>
  )
}

/** Notifications push (NOT-03) : abonnement de ce navigateur ; seuls les événements éligibles sont poussés, sans titre confidentiel. */
function PushSection({ me }: { me: MeFull }) {
  const client = useQueryClient()
  const toast = useToast()
  const status = useQuery({ queryKey: ['me', 'push'], queryFn: ({ signal }) => api.get<{ data: PushStatus }>('/me/push', undefined, signal).then((r) => r.data) })
  const [busy, setBusy] = useState(false)
  const enabled = (me.notification_preferences?.push ?? false) && (status.data?.subscriptions ?? 0) > 0
  const supported = pushSupported()

  const toggle = async () => {
    setBusy(true)
    try {
      const next = enabled ? await unsubscribePush() : await subscribePush(status.data?.public_key ?? '')
      client.setQueryData(['me', 'push'], next)
      await client.invalidateQueries({ queryKey: ['me'], exact: true })
      toast.success(enabled ? 'Notifications push désactivées' : 'Notifications push activées')
    } catch (e) {
      toast.error(e, 'Notifications push')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="panel account-section">
      <div className="panel-title"><div><h2>Notifications push</h2><p>Alertes du navigateur, même lorsque l’application est fermée</p></div>{enabled ? <Status tone="success">Activées</Status> : <Status tone="neutral">Désactivées</Status>}</div>
      <div className="panel-body" style={{ display: 'grid', gap: 10 }}>
        {status.isLoading ? <Loading /> : status.isError || !status.data ? <ErrorState error={status.error} onRetry={() => status.refetch()} /> : !status.data.available ? (
          <Notice tone="info">Les notifications push ne sont pas activées sur ce serveur (paramètre « Notifications push » de l’administration et clés VAPID).</Notice>
        ) : !supported ? (
          <Notice tone="warning">Ce navigateur ne permet pas les notifications push, ou la page n’est pas servie en HTTPS.</Notice>
        ) : (
          <>
            <p className="small">Événements poussés : {status.data.events.map((code) => PUSH_EVENT_LABEL[code] ?? code).join(', ')}. Les autres notifications restent dans l’application et par courriel.</p>
            {status.data.subscriptions > 0 && <p className="muted small">{status.data.subscriptions} navigateur(s) abonné(s) pour votre compte. La désactivation les supprime tous.</p>}
            <div><Button variant={enabled ? 'secondary' : 'primary'} icon={enabled ? 'x' : 'check'} busy={busy} onClick={toggle}>{enabled ? 'Désactiver les notifications push' : 'Activer sur ce navigateur'}</Button></div>
          </>
        )}
      </div>
    </section>
  )
}
