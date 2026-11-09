import { useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { fmtDateTime, NETWORK_STATUS } from '../../lib/format'
import { Button, ErrorState, Field, Icon, Loading, Notice, PageHeading, StatusOf } from '../../components/ui'
import { FlowProgress, KIND_LABEL, networkOf, ORIENTATION_LABEL, useAllScreens, type Device } from './shared'

const STEPS = ['Code', 'Association', 'Affectation', 'Synchronisation']
const CODE_MESSAGES: Record<string, string> = {
  CODE_UNKNOWN: 'Code inconnu : vérifiez les caractères affichés sur le lecteur (pas de confusion entre O et 0, I et 1).',
  CODE_USED: 'Ce code a déjà servi. Redémarrez l’assistant d’appairage du lecteur pour obtenir un nouveau code.',
  CODE_EXPIRED: 'Ce code a expiré. Le lecteur affiche automatiquement un nouveau code : saisissez-le.',
}

/** V09 Appairage d'un lecteur par code temporaire à usage unique (PLY-02). Aucun jeton n'est affiché. */
export default function Pairing() {
  const { me } = useAuth()
  const client = useQueryClient()
  const [params] = useSearchParams()
  const [step, setStep] = useState(0)
  const [left, setLeft] = useState('')
  const [right, setRight] = useState('')
  const [screenId, setScreenId] = useState(params.get('screen_id') ?? '')
  const [filter, setFilter] = useState('')
  const [replace, setReplace] = useState(false)
  const [needsReplace, setNeedsReplace] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState<{ device: Device; message?: string } | null>(null)
  const rightRef = useRef<HTMLInputElement>(null)
  const screens = useAllScreens()

  const code = (left + right).toUpperCase()
  const codeValid = /^[A-Z0-9]{6}$/.test(code)
  const screen = screens.data?.data.find((s) => s.id === screenId)
  const filtered = useMemo(() => {
    const t = filter.trim().toLowerCase()
    return (screens.data?.data ?? []).filter((s) => !t || s.code.toLowerCase().includes(t) || s.name.toLowerCase().includes(t) || (s.location?.name ?? '').toLowerCase().includes(t))
  }, [screens.data, filter])

  const clean = (v: string) => v.toUpperCase().replace(/[^A-Z0-9]/g, '')
  const onLeft = (v: string) => {
    const c = clean(v)
    if (c.length > 3) {
      setLeft(c.slice(0, 3))
      setRight(c.slice(3, 6))
      rightRef.current?.focus()
    } else {
      setLeft(c)
      if (c.length === 3) rightRef.current?.focus()
    }
  }

  const pair = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: Device; message?: string }>('/devices/pair', { code, screen_id: screenId, replace })
      setResult({ device: res.data, message: res.message })
      client.invalidateQueries({ queryKey: ['screens'] })
      client.invalidateQueries({ queryKey: ['screen', screenId] })
      client.invalidateQueries({ queryKey: ['dashboard'] })
      setStep(3)
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(500, { message: String(e) })
      setError(err)
      if (err.code in CODE_MESSAGES) setStep(0)
      else if (err.code === 'SCREEN_ALREADY_PAIRED') setNeedsReplace(true)
    } finally {
      setBusy(false)
    }
  }

  const restart = () => {
    setStep(0); setLeft(''); setRight(''); setScreenId(''); setReplace(false); setNeedsReplace(false); setError(null); setResult(null)
  }

  const codeError = error && error.code in CODE_MESSAGES ? CODE_MESSAGES[error.code] : error?.field('code')

  return (
    <>
      <PageHeading title="Appairage d’un lecteur" description="Associez un lecteur à un écran inventorié à l’aide du code temporaire affiché à son démarrage." demo={me.demo_mode} />
      <section className="panel pair-panel">
        <FlowProgress steps={STEPS} step={step} />
        <div className="flow-content">
          <div className="step-label">ÉTAPE {step + 1} SUR {STEPS.length}</div>
          {step === 0 && (
            <>
              <h3>Code d’appairage temporaire</h3>
              <p>Saisissez les 6 caractères affichés sur l’écran du lecteur. Le code est à usage unique et expire après quelques minutes.</p>
              <div className="pairing-code" role="group" aria-label="Code d’appairage">
                <label><span className="sr-only">Caractères 1 à 3</span><input value={left} onChange={(e) => onLeft(e.target.value)} maxLength={6} autoComplete="off" spellCheck={false} aria-invalid={!!codeError} inputMode="text" /></label>
                <span aria-hidden="true">—</span>
                <label><span className="sr-only">Caractères 4 à 6</span><input ref={rightRef} value={right} onChange={(e) => setRight(clean(e.target.value).slice(0, 3))} onKeyDown={(e) => e.key === 'Backspace' && !right && document.querySelector<HTMLInputElement>('.pairing-code input')?.focus()} maxLength={3} autoComplete="off" spellCheck={false} aria-invalid={!!codeError} /></label>
                <div>{codeValid ? <>Code saisi : <b className="mono">{code}</b></> : 'Lettres et chiffres uniquement'}</div>
              </div>
              {codeError && <div style={{ marginTop: 12 }}><Notice tone="danger">{codeError}</Notice></div>}
              <div className="info-box"><Icon name="shield" /><span>Aucun jeton durable n’est affiché ni partagé : le lecteur récupère lui-même son identité après validation.</span></div>
            </>
          )}
          {step === 1 && (
            <>
              <h3>Écran à associer</h3>
              <p>L’écran physique et le lecteur restent deux objets distincts. Choisissez l’écran inventorié devant lequel le lecteur est installé.</p>
              {screens.isLoading ? <Loading /> : screens.isError ? <ErrorState error={screens.error} onRetry={() => screens.refetch()} /> : (
                <>
                  <Field label="Filtrer les écrans"><input value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Code, nom ou emplacement" /></Field>
                  <div className="target-list" style={{ marginTop: 10, maxHeight: 300, overflow: 'auto' }} role="radiogroup" aria-label="Écran cible">
                    {filtered.map((s) => (
                      <label key={s.id} className={s.id === screenId ? 'selected' : ''}>
                        <input type="radio" name="screen" checked={s.id === screenId} onChange={() => { setScreenId(s.id); setNeedsReplace(false); setReplace(false); setError(null) }} />
                        <span style={{ flex: 1 }}><b>{s.code} · {s.name}</b>{s.location?.floor?.label} · {s.location?.name} · {ORIENTATION_LABEL[s.orientation]} {s.resolution}</span>
                        <StatusOf map={NETWORK_STATUS} value={networkOf(s)} />
                      </label>
                    ))}
                    {filtered.length === 0 && <p className="muted small">Aucun écran ne correspond.</p>}
                  </div>
                  {(screens.data?.meta.total ?? 0) > 500 && <p className="muted small" style={{ marginTop: 6 }}>Seuls les 500 premiers écrans sont listés : affinez le filtre.</p>}
                </>
              )}
            </>
          )}
          {step === 2 && screen && (
            <>
              <h3>Confirmer l’affectation</h3>
              <p>Vérifiez l’association avant validation. Elle est inscrite au journal.</p>
              <div className="device-identity">
                <span><Icon name="monitor" size={30} /></span>
                <div><strong>{screen.code} · {screen.name}</strong><small>{KIND_LABEL[screen.kind]} · {screen.location?.floor?.label} · {screen.location?.name}</small></div>
                <span className="mono" style={{ width: 'auto', height: 'auto', padding: '6px 10px', background: '#f1f3f5', color: 'var(--ink)' }}>Code {code}</span>
              </div>
              <div className="metadata-row">
                <span><b>Orientation</b>{ORIENTATION_LABEL[screen.orientation]}</span>
                <span><b>Résolution</b>{screen.resolution.replace('x', ' × ')}</span>
                <span><b>Lecteur actuel</b>{screen.device ? `${screen.device.code} (${NETWORK_STATUS[screen.device.network_status]?.[0] ?? screen.device.network_status})` : 'Aucun'}</span>
              </div>
              {(screen.device || needsReplace) && (
                <div className="warning-box">
                  <Icon name="alert" />
                  <span style={{ flex: 1 }}>
                    <strong>Cet écran a déjà un lecteur actif{screen.device ? ` (${screen.device.code})` : ''}</strong>
                    <small>Le remplacer révoque l’ancien lecteur : son jeton est invalidé immédiatement, mais s’il est hors ligne il lit son cache jusqu’à l’expiration de l’autorisation en cours.</small>
                    <label className="check-label" style={{ marginTop: 8, background: 'white' }}><input type="checkbox" checked={replace} onChange={(e) => setReplace(e.target.checked)} /> Remplacer le lecteur actif</label>
                  </span>
                </div>
              )}
              {error && !(error.code in CODE_MESSAGES) && error.code !== 'SCREEN_ALREADY_PAIRED' && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}{error.field('screen_id') ? ` ${error.field('screen_id')}` : ''}</Notice></div>}
            </>
          )}
          {step === 3 && result && (
            <>
              <h3>Synchronisation initiale</h3>
              <p>Le lecteur interroge le serveur, récupère automatiquement son identité puis télécharge son premier programme. Il ne bascule qu’après téléchargement complet et vérification.</p>
              <div className="success-box">
                <Icon name="check" />
                <span><strong>{result.message ?? 'Lecteur associé.'}</strong><small>Appairé le {fmtDateTime(result.device.paired_at)} à l’écran {screen?.code ?? ''}.</small></span>
              </div>
              <div className="device-identity" style={{ marginTop: 14 }}>
                <span><Icon name="wifi" size={30} /></span>
                <div><strong>Lecteur {result.device.code}</strong><small>ID matériel · <span className="mono">{result.device.hardware_id ?? '—'}</span></small></div>
                <StatusOf map={NETWORK_STATUS} value={result.device.network_status} />
              </div>
              <div className="metadata-row">
                <span><b>Version</b>{result.device.app_version ?? 'Non déclarée'}</span>
                <span><b>Plateforme</b>{result.device.platform ?? 'Non déclarée'}</span>
                <span><b>Premier contact</b>{result.device.last_seen_at ? fmtDateTime(result.device.last_seen_at) : 'En attente du premier signal'}</span>
              </div>
              <div className="info-box"><Icon name="info" /><span>Suivez la synchronisation (programme, ressources, dernier contact) sur la fiche de l’écran, onglet Lecteur.</span></div>
            </>
          )}
        </div>
        <footer>
          {step === 3 ? (
            <>
              <Button variant="secondary" icon="plus" onClick={restart}>Appairer un autre lecteur</Button>
              <Link className="button" to={`/affichage/ecrans/${screenId}`}><span>Ouvrir la fiche de l’écran</span><Icon name="arrow" size={15} /></Link>
            </>
          ) : (
            <>
              {step > 0 ? <Button variant="secondary" onClick={() => setStep(step - 1)}>Retour</Button> : <Link className="button secondary" to="/affichage/ecrans"><span>Annuler</span></Link>}
              <div className="footer-note"><Icon name="shield" size={14} /> Action tracée dans le journal</div>
              {step === 0 && <Button onClick={() => { setError(null); setStep(1) }} disabled={!codeValid}>Continuer<Icon name="arrow" size={15} /></Button>}
              {step === 1 && <Button onClick={() => setStep(2)} disabled={!screen}>Continuer<Icon name="arrow" size={15} /></Button>}
              {step === 2 && <Button icon="check" onClick={pair} busy={busy} disabled={(!!screen?.device || needsReplace) && !replace}>Associer le lecteur</Button>}
            </>
          )}
        </footer>
      </section>
    </>
  )
}
