import { useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import { api, ApiError, ensureCsrf } from '../../lib/api'
import { Button, Field, Icon, Notice } from '../../components/ui'
import './account.css'

export const PASSWORD_RULES: { label: string; test: (p: string) => boolean }[] = [
  { label: 'De 12 à 64 caractères (une phrase de passe est conseillée)', test: (p) => p.length >= 12 && p.length <= 64 },
  { label: 'Au moins 6 caractères différents', test: (p) => new Set([...p.toLowerCase()]).size >= 6 },
]

export function PasswordRules({ password, confirmation }: { password: string; confirmation?: string }) {
  const rules = [...PASSWORD_RULES.map((r) => ({ label: r.label, ok: r.test(password) })), ...(confirmation !== undefined ? [{ label: 'Confirmation identique', ok: !!password && password === confirmation }] : [])]
  return (
    <ul className="rule-list" aria-live="polite">
      {rules.map((r) => (
        <li key={r.label} className={r.ok ? 'ok' : ''}>
          <Icon name={r.ok ? 'check' : 'x'} size={13} />
          <span>{r.label}<span className="sr-only">{r.ok ? ' : satisfaite' : ' : non satisfaite'}</span></span>
        </li>
      ))}
    </ul>
  )
}

export const passwordValid = (p: string, c: string) => PASSWORD_RULES.every((r) => r.test(p)) && p === c

/** V02 Récupération et définition du mot de passe : réponse neutre qui ne révèle pas l'existence d'un compte. */
export default function Verification() {
  const [params] = useSearchParams()
  // Le lien porte le jeton dans le fragment (#), jamais transmis au serveur ; l'ancien format (?token=) reste accepté.
  const fragment = new URLSearchParams(window.location.hash.slice(1))
  const token = fragment.get('token') ?? params.get('token')
  const presetEmail = fragment.get('email') ?? params.get('email') ?? ''
  const [email, setEmail] = useState(presetEmail)
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [show, setShow] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [done, setDone] = useState<string | null>(null)
  const resetMode = !!token && !!presetEmail

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await ensureCsrf(true)
      const r = resetMode
        ? await api.post<{ message: string }>('/auth/reset-password', { token, email: presetEmail, password, password_confirmation: confirmation }, false)
        : await api.post<{ message: string }>('/auth/forgot-password', { email }, false)
      setDone(r.message)
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, { message: 'Erreur inattendue' }))
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="public-shell">
      <form onSubmit={submit} className="panel public-card" noValidate>
        <div className="public-brand">
          <img src="/ceeac-logo.jpg" alt="Logo officiel de la CEEAC" />
          <div><strong>SAIE<span>—CEEAC</span></strong><div className="muted small">Système d’affichage institutionnel électronique</div></div>
        </div>
        <h1>{resetMode ? 'Définir un nouveau mot de passe' : 'Mot de passe oublié'}</h1>
        <p className="muted small" style={{ marginBottom: 18 }}>
          {resetMode ? `Compte : ${presetEmail}. Le lien est personnel et à usage unique.` : 'Saisissez votre adresse professionnelle : si un compte y correspond, un lien de réinitialisation vous sera envoyé.'}
        </p>
        {done ? (
          <div style={{ display: 'grid', gap: 14 }}>
            <Notice tone="success">{done}</Notice>
            {!resetMode && <p className="muted small">Pensez à vérifier le dossier des courriels indésirables. Le lien expire après un délai limité.</p>}
            <Link to="/connexion" className="button" style={{ justifyContent: 'center' }}>Aller à la connexion</Link>
          </div>
        ) : (
          <>
            {error && <div style={{ marginBottom: 14 }}><Notice tone="danger">{error.message}{error.code === 'RESET_LINK_INVALID' && <> <Link to="/compte/verification" className="link-text">Demander un nouveau lien</Link></>}</Notice></div>}
            {resetMode ? (
              <div style={{ display: 'grid', gap: 14 }}>
                <Field label="Nouveau mot de passe" required error={error?.field('password')}>
                  <div style={{ display: 'flex', gap: 6 }}>
                    <input className="input" type={show ? 'text' : 'password'} autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} aria-invalid={!!error?.field('password')} aria-describedby="pw-rules" />
                    <button type="button" className="button secondary" onClick={() => setShow(!show)} aria-pressed={show} aria-label={show ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}><Icon name="eye" size={16} /></button>
                  </div>
                </Field>
                <Field label="Confirmation" required error={error?.field('password_confirmation')}>
                  <input className="input" type={show ? 'text' : 'password'} autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} />
                </Field>
                <div id="pw-rules"><PasswordRules password={password} confirmation={confirmation} /></div>
              </div>
            ) : (
              <Field label="Adresse professionnelle" required error={error?.field('email')}>
                <input className="input" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} aria-invalid={!!error?.field('email')} />
              </Field>
            )}
            <div style={{ marginTop: 20, display: 'grid', gap: 10 }}>
              <Button type="submit" busy={busy} disabled={resetMode ? !passwordValid(password, confirmation) : !/^\S+@\S+\.\S+$/.test(email)}>
                {resetMode ? 'Enregistrer le mot de passe' : 'Envoyer le lien'}
              </Button>
              <Link to="/connexion" className="link" style={{ justifyContent: 'center' }}>Retour à la connexion</Link>
            </div>
          </>
        )}
        <p className="muted" style={{ fontSize: 10, marginTop: 18, display: 'flex', gap: 6 }}><Icon name="shield" size={14} /> Les demandes de réinitialisation sont journalisées et limitées en nombre.</p>
      </form>
    </main>
  )
}
