import { useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError, ensureCsrf } from '../../lib/api'
import { Button, Field, Icon, Notice } from '../../components/ui'

/** V01 Connexion : erreur générique sans révéler l'existence du compte ; second facteur sur étape séparée. */
export default function Login() {
  const [params] = useSearchParams()
  const location = useLocation()
  const navigate = useNavigate()
  const client = useQueryClient()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [show, setShow] = useState(false)
  const [code, setCode] = useState('')
  const [step, setStep] = useState<'credentials' | 'mfa'>('credentials')
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const expired = (location.state as { expired?: boolean } | null)?.expired && params.get('retour')

  const finish = async () => {
    await client.invalidateQueries({ queryKey: ['me'] })
    const target = params.get('retour')
    navigate(target && target.startsWith('/') && !target.startsWith('//') ? target : '/accueil', { replace: true })
  }

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await ensureCsrf(true)
      if (step === 'credentials') {
        const r = await api.post<{ mfa_required?: boolean }>('/auth/login', { email, password }, false)
        if (r.mfa_required) {
          setStep('mfa')
          return
        }
      } else {
        await api.post('/auth/mfa', { code }, false)
      }
      await finish()
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, { message: 'Erreur inattendue' }))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div style={{ minHeight: '100vh', display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1.1fr)', background: 'var(--bg)' }} className="login-shell">
      <section style={{ background: 'linear-gradient(160deg, var(--navy), #123a63)', color: 'white', padding: '56px 48px', display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }} className="login-aside">
        <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
          <img src="/ceeac-logo.jpg" alt="Logo officiel de la CEEAC" style={{ width: 64, height: 64, borderRadius: '50%', background: 'white' }} />
          <div>
            <strong style={{ font: '800 22px Manrope, sans-serif' }}>SAIE<span style={{ color: '#77bfde' }}>—CEEAC</span></strong>
            <div style={{ color: '#9fb4ca', fontSize: 12 }}>Système d’affichage institutionnel électronique</div>
          </div>
        </div>
        <div>
          <h1 style={{ font: '700 30px Manrope, sans-serif', marginBottom: 12, maxWidth: 460 }}>Une information institutionnelle claire, validée et diffusée au bon endroit.</h1>
          <p style={{ color: '#b9cadb', maxWidth: 460, fontSize: 13 }}>Contenus, écrans du siège de Libreville, salles de réunion et alertes prioritaires : chaque action est contrôlée par le serveur et tracée.</p>
        </div>
        <small style={{ color: '#7f97b0' }}>Commission de la Communauté Économique des États de l’Afrique Centrale</small>
      </section>
      <section style={{ display: 'grid', placeItems: 'center', padding: 24 }}>
        <form onSubmit={submit} className="panel" style={{ width: 'min(420px, 100%)', padding: 28 }} noValidate>
          <h2 style={{ font: '700 20px Manrope, sans-serif', marginBottom: 4 }}>{step === 'credentials' ? 'Connexion' : 'Vérification en deux étapes'}</h2>
          <p className="muted small" style={{ marginBottom: 18 }}>
            {step === 'credentials' ? 'Utilisez votre identifiant professionnel.' : 'Saisissez le code à 6 chiffres de votre application d’authentification, ou un code de secours.'}
          </p>
          {expired && <div style={{ marginBottom: 14 }}><Notice tone="warning">Votre session a expiré. Reconnectez-vous pour reprendre là où vous étiez.</Notice></div>}
          {error && <div style={{ marginBottom: 14 }}><Notice tone="danger">{error.message}</Notice></div>}
          {step === 'credentials' ? (
            <div style={{ display: 'grid', gap: 14 }}>
              <Field label="Identifiant professionnel" required error={error?.field('email')}>
                <input className="input" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required aria-invalid={!!error?.field('email')} />
              </Field>
              <Field label="Mot de passe" required error={error?.field('password')}>
                <div style={{ display: 'flex', gap: 6 }}>
                  <input className="input" type={show ? 'text' : 'password'} autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
                  <button type="button" className="button secondary" onClick={() => setShow(!show)} aria-pressed={show} aria-label={show ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}>
                    <Icon name="eye" size={16} />
                  </button>
                </div>
              </Field>
            </div>
          ) : (
            <Field label="Code de vérification" required>
              <input className="input" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value)} autoFocus />
            </Field>
          )}
          <div style={{ marginTop: 20, display: 'grid', gap: 10 }}>
            <Button type="submit" busy={busy} disabled={step === 'credentials' ? !email || !password : code.length < 6}>{step === 'credentials' ? 'Se connecter' : 'Vérifier'}</Button>
            {step === 'credentials' ? <Link to="/compte/verification" className="link" style={{ justifyContent: 'center' }}>Mot de passe oublié ?</Link> : <button type="button" className="link" onClick={() => setStep('credentials')}>Revenir à l’identification</button>}
          </div>
          <p className="muted" style={{ fontSize: 10, marginTop: 18, display: 'flex', gap: 6 }}><Icon name="shield" size={14} /> Connexions et refus sont journalisés. Après 5 échecs, le compte est verrouillé 15 minutes.</p>
        </form>
      </section>
      <style>{`@media (max-width: 900px){ .login-shell{ grid-template-columns: 1fr !important } .login-aside{ display:none !important } }`}</style>
    </div>
  )
}
