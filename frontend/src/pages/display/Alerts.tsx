import { useCallback, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useList } from '../../lib/hooks'
import { fmtDate, fmtDateTime, fmtTime, NETWORK_STATUS, SEVERITY } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Field, Icon, Loading, Modal, Notice, PageHeading, Pagination, Status, StatusOf, useDebounced, useToast } from '../../components/ui'
import { fmtCountdown, FlowShell, StepButtons, useNow, useReferentials, useScreenGroups } from './shared'

interface Alert {
  id: string; reference: string; title: string; message: string; translations: Record<string, { title: string; message: string }> | null; severity: string; status: string
  starts_at: string; expires_at: string; author: { id: string; name: string } | null; justification: string; revoked_at: string | null; revoker: { name: string } | null
  revoke_reason: string | null; extensions: { by: string; at: string; minutes: number; reason: string }[]; target_count: number; offline_at_publish: number
  targets: { type: string; id: string | null }[]; receipts: { received: number; activated: number } | null; scope_label: string
}
interface AlertDetail extends Alert {
  screens: { code: string; name: string; floor: string; network_status: string; received_at: string | null; activated_at: string | null; in_window: boolean }[]
}
interface AlertsMeta { current_page: number; per_page: number; total: number; last_page: number; active?: number; can_publish?: boolean; power_until?: string | null }
interface PreviewSummary { targeted: number; online: number; offline: number; off_window: number; offline_screens: string[]; notice: string }
type Target = { type: 'all' | 'zone' | 'floor' | 'group' | 'screen'; id: string | null }

const ALERT_STATUS: Record<string, [string, string]> = { active: ['Active', 'danger'], expired: ['Expirée', 'neutral'], revoked: ['Levée', 'neutral'], scheduled: ['Programmée', 'blue'] }
const TEMPLATES: { label: string; severity: string; title: string; message: string; tone: string }[] = [
  { label: 'Évacuation', severity: 'critical', title: 'Évacuation des locaux', message: 'Quitter calmement les locaux par les issues indiquées et suivre les consignes du personnel habilité.', tone: 'danger' },
  { label: 'Incendie', severity: 'critical', title: 'Alerte incendie', message: 'Ne pas utiliser les ascenseurs. Suivre les consignes d’évacuation et rejoindre le point de rassemblement.', tone: 'danger' },
  { label: 'Incident technique', severity: 'warning', title: 'Incident technique', message: 'Éviter la zone concernée jusqu’à nouvel ordre et suivre les indications du personnel.', tone: 'warning' },
  { label: 'Message prioritaire', severity: 'info', title: '', message: '', tone: 'blue' },
]
const SEVERITY_HINT: Record<string, string> = { critical: 'Critique — action immédiate', warning: 'Avertissement', info: 'Information', exercise: 'Exercice — à mentionner clairement' }
const durationOf = (a: Alert) => Math.round((new Date(a.expires_at).getTime() - new Date(a.starts_at).getTime()) / 60000)

/** V23 Centre des alertes : complète, sans les remplacer, les dispositifs réglementaires de sécurité. */
export default function Alerts() {
  const { me, can } = useAuth()
  const [wizard, setWizard] = useState<{ severity: string; title: string; message: string } | null>(null)
  const [detail, setDetail] = useState<string | null>(null)
  const history = useList<Alert>('alerts', '/alerts', {}, 10)
  const active = useQuery({
    queryKey: ['alerts', 'active'],
    queryFn: ({ signal }) => api.get<{ data: Alert[] }>('/alerts', { status: 'active', per_page: 20 }, signal).then((r) => r.data),
    refetchInterval: 30_000,
  })
  const closeDetail = useCallback(() => setDetail(null), [])
  const meta = history.meta as AlertsMeta | undefined
  const canPublish = can('alerts.manage') && meta?.can_publish !== false

  const open = (t?: (typeof TEMPLATES)[number]) => setWizard({ severity: t?.severity ?? 'critical', title: t?.title ?? '', message: t?.message ?? '' })

  return (
    <>
      <PageHeading title="Centre des alertes" description="Messages prioritaires qui interrompent les programmes ordinaires et expirent automatiquement." demo={me.demo_mode}
        actions={canPublish && <Button variant="danger" icon="alert" onClick={() => open()}>Déclencher une alerte</Button>} />
      <div className="safety-note">
        <Icon name="shield" />
        <div>
          <strong>Les alertes SAIE complètent les dispositifs de sécurité.</strong>
          <span>Elles ne remplacent jamais les alarmes réglementaires ni les consignes des services compétents.</span>
        </div>
      </div>
      {!canPublish && <div style={{ marginBottom: 15 }}><Notice tone="info">Consultation seule : le déclenchement d’une alerte exige le pouvoir temporaire « urgence » (permission de gestion des alertes).</Notice></div>}
      {canPublish && meta?.power_until && <p className="muted small" style={{ marginBottom: 10 }}>Votre pouvoir « urgence » est actif jusqu’au {fmtDate(meta.power_until)}.</p>}

      {canPublish && (
        <div className="alert-template-grid">
          {TEMPLATES.map((t) => (
            <button key={t.label} onClick={() => open(t)}>
              <span className={t.tone}><Icon name="alert" /></span>
              <strong>{t.label}</strong>
              <small>{t.message || 'Information urgente à rédiger avant diffusion.'} · {SEVERITY[t.severity][0]}</small>
              <Icon name="arrow" size={15} />
            </button>
          ))}
        </div>
      )}

      {active.isError ? <div style={{ marginBottom: 12 }}><ErrorState error={active.error} onRetry={() => active.refetch()} /></div> : (active.data ?? []).map((a) => <ActiveAlert key={a.id} alert={a} canManage={canPublish} onOpen={() => setDetail(a.id)} />)}

      <section className="panel">
        <div className="panel-title">
          <div>
            <h2>{(meta?.active ?? active.data?.length ?? 0) > 0 ? `${meta?.active ?? active.data?.length} alerte(s) active(s)` : 'Aucune alerte active'}</h2>
            <p>{(meta?.active ?? 0) > 0 ? 'Les programmes ordinaires sont interrompus sur les écrans ciblés.' : 'Les programmes ordinaires sont diffusés normalement.'}</p>
          </div>
          {(meta?.active ?? 0) > 0 ? <Status tone="danger">Alerte en cours</Status> : <Status tone="success">Situation normale</Status>}
        </div>
        <div className="alert-history">
          <h3>Historique</h3>
          {history.isLoading ? <Loading /> : history.isError ? <ErrorState error={history.error} onRetry={() => history.refetch()} /> : history.items.length === 0 ? (
            <EmptyState title="Aucune alerte" text="Aucune alerte n’a encore été déclenchée." />
          ) : (
            <>
              <div className="table-scroll">
                <table>
                  <thead><tr><th>Message</th><th>Niveau</th><th>Portée</th><th>Déclenchée par</th><th>Début · durée</th><th>Réception</th><th><span className="sr-only">Détail</span></th></tr></thead>
                  <tbody>
                    {history.items.map((a) => (
                      <tr key={a.id}>
                        <td><strong>{a.title}</strong><small>{a.reference} · <StatusOf map={ALERT_STATUS} value={a.status} />{a.status === 'revoked' && a.revoker ? ` par ${a.revoker.name}` : a.status === 'expired' ? ' · expiration automatique' : ''}</small></td>
                        <td><StatusOf map={SEVERITY} value={a.severity} /></td>
                        <td>{a.scope_label}</td>
                        <td>{a.author?.name ?? '—'}<small>pouvoir urgence</small></td>
                        <td>{fmtDateTime(a.starts_at)}<small>{durationOf(a)} min{a.extensions.length ? ` · ${a.extensions.length} prolongation(s)` : ''}</small></td>
                        <td><b>{a.receipts?.received ?? 0} / {a.target_count}</b><small>{a.receipts?.activated ?? 0} activation(s) déclarée(s){a.offline_at_publish ? ` · ${a.offline_at_publish} hors ligne à l’envoi` : ''}</small></td>
                        <td><Button size="small" variant="secondary" icon="eye" onClick={() => setDetail(a.id)} ariaLabel={`Suivi de ${a.reference}`}>Suivi</Button></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <Pagination meta={history.meta} onPage={history.setPage} />
            </>
          )}
        </div>
      </section>
      {wizard && <AlertWizard initial={wizard} powerUntil={meta?.power_until ?? null} onClose={() => setWizard(null)} />}
      {detail && (
        <Modal title="Suivi de réception" kicker="ALERTE" wide onClose={closeDetail} footer={<Button variant="secondary" onClick={closeDetail}>Fermer</Button>}>
          <AlertTracking id={detail} />
        </Modal>
      )}
    </>
  )
}

function ActiveAlert({ alert, canManage, onOpen }: { alert: Alert; canManage: boolean; onOpen: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const [action, setAction] = useState<'extend' | 'revoke' | null>(null)
  const closeAction = useCallback(() => setAction(null), [])
  const [minutes, setMinutes] = useState(15)
  const refresh = () => client.invalidateQueries({ queryKey: ['alerts'] })
  return (
    <div className={`active-alert sev-${alert.severity}`} role="status">
      <div>
        <small><StatusOf map={SEVERITY} value={alert.severity} /> · {alert.reference} · {alert.scope_label}</small>
        <strong>{alert.title}</strong>
        <p>{alert.message}</p>
        <div className="row-actions" style={{ justifyContent: 'flex-start', marginTop: 8 }}>
          <Button size="small" variant="secondary" icon="eye" onClick={onOpen}>Suivi de réception</Button>
          {canManage && <Button size="small" variant="secondary" icon="clock" onClick={() => setAction('extend')}>Prolonger</Button>}
          {canManage && <Button size="small" variant="danger" icon="x" onClick={() => setAction('revoke')}>Lever l’alerte</Button>}
        </div>
      </div>
      <div className="countdown">
        <Countdown until={alert.expires_at} />
        <small>expire à {fmtTime(alert.expires_at)}</small>
        <small>{alert.receipts?.received ?? 0}/{alert.target_count} reçus</small>
      </div>
      {action === 'extend' && (
        <ConfirmDialog title="Prolonger l’alerte" object={`${alert.reference} · ${alert.title}`} confirmLabel={`Prolonger de ${minutes} min`}
          consequence={`L’expiration passera de ${fmtTime(alert.expires_at)} à ${fmtTime(new Date(new Date(alert.expires_at).getTime() + minutes * 60000).toISOString())}. Les lecteurs hors ligne ne recevront pas la prolongation.`}
          reasonLabel="Motif de la prolongation" reasonRequired onClose={closeAction}
          onConfirm={async (reason) => {
            await api.post(`/alerts/${alert.id}/extend`, { minutes, reason })
            toast.success('Alerte prolongée')
            refresh()
          }}>
          <Field label="Durée supplémentaire" required>
            <select value={minutes} onChange={(e) => setMinutes(Number(e.target.value))}>
              {[5, 10, 15, 30, 60, 120].map((m) => <option key={m} value={m}>{m} minutes</option>)}
            </select>
          </Field>
        </ConfirmDialog>
      )}
      {action === 'revoke' && (
        <ConfirmDialog title="Lever l’alerte" object={`${alert.reference} · ${alert.title}`} danger confirmLabel="Lever l’alerte maintenant"
          consequence="Les programmes ordinaires reprennent sur les écrans connectés dès leur prochain manifeste. Un lecteur hors ligne continue d’afficher l’alerte jusqu’à sa reconnexion ou son expiration."
          reasonLabel="Motif de la levée" reasonRequired onClose={closeAction}
          onConfirm={async (reason) => {
            await api.post(`/alerts/${alert.id}/revoke`, { reason })
            toast.success('Alerte levée')
            refresh()
          }} />
      )}
    </div>
  )
}

/** Compte à rebours isolé : seul ce nœud est rendu chaque seconde. */
function Countdown({ until }: { until: string }) {
  const now = useNow()
  const left = new Date(until).getTime() - now
  return <span aria-label={left > 0 ? `Temps restant avant expiration : ${fmtCountdown(left)}` : 'Expirée'}>{left > 0 ? fmtCountdown(left) : 'Expirée'}</span>
}

function AlertTracking({ id }: { id: string }) {
  const q = useQuery({
    queryKey: ['alert', id],
    queryFn: ({ signal }) => api.get<{ data: AlertDetail }>(`/alerts/${id}`, undefined, signal).then((r) => r.data),
    refetchInterval: 5000,
  })
  if (q.isLoading) return <Loading />
  if (q.isError || !q.data) return <ErrorState error={q.error} onRetry={() => q.refetch()} />
  const a = q.data
  const offline = a.screens.filter((s) => s.network_status !== 'online').length
  return (
    <>
      <p className="muted small" style={{ marginBottom: 10 }}>{a.reference} · {a.title} · <StatusOf map={ALERT_STATUS} value={a.status} /> · expire {fmtDateTime(a.expires_at)}. La « lecture déclarée » ne prouve pas la visibilité physique de la dalle.</p>
      <div className="alert-receipts">
        <div><strong>{a.screens.length}</strong><span>Ciblés</span></div>
        <div><strong>{a.screens.filter((s) => s.received_at).length}</strong><span>Manifestes reçus</span></div>
        <div><strong>{a.screens.filter((s) => s.activated_at).length}</strong><span>Activations déclarées</span></div>
        <div><strong>{offline}</strong><span>Hors ligne ou suspects</span></div>
      </div>
      <div className="table-scroll">
        <table>
          <thead><tr><th>Écran</th><th>Niveau</th><th>Réseau</th><th>Plage</th><th>Reçue</th><th>Activée</th></tr></thead>
          <tbody>
            {a.screens.map((s) => (
              <tr key={s.code}>
                <td><strong>{s.code}</strong><small>{s.name}</small></td>
                <td>{s.floor}</td>
                <td><StatusOf map={NETWORK_STATUS} value={s.network_status} /></td>
                <td>{s.in_window ? 'Dans la plage' : <Status tone="warning">Hors plage</Status>}</td>
                <td>{s.received_at ? fmtTime(s.received_at) : 'Non'}</td>
                <td>{s.activated_at ? fmtTime(s.activated_at) : 'Non'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {(a.extensions.length > 0 || a.revoke_reason) && (
        <div className="timeline-list section-gap">
          {a.extensions.map((x, i) => <div key={i}><time>{fmtDateTime(x.at)}</time><span>Prolongée de {x.minutes} min par {x.by} · {x.reason}</span></div>)}
          {a.revoke_reason && <div><time>{fmtDateTime(a.revoked_at)}</time><span>Levée par {a.revoker?.name ?? '—'} · {a.revoke_reason}</span></div>}
        </div>
      )}
    </>
  )
}

const STEPS = ['Message', 'Portée', 'Aperçu', 'Confirmation', 'Suivi']

function AlertWizard({ initial, powerUntil, onClose }: { initial: { severity: string; title: string; message: string }; powerUntil: string | null; onClose: () => void }) {
  const client = useQueryClient()
  const refs = useReferentials()
  const groups = useScreenGroups()
  const [step, setStep] = useState(0)
  const [all, setAll] = useState(true)
  // Ciblage d'un écran précis (ex. exercice sur un palier) : l'API accepte le type « screen ».
  const screens = useQuery({
    queryKey: ['screens', 'alert-targets'],
    queryFn: ({ signal }) => api.get<{ data: { id: string; code: string; name: string }[] }>('/screens', { per_page: 500 }, signal).then((r) => r.data),
    enabled: !all,
  })
  const [severity, setSeverity] = useState(initial.severity)
  const [title, setTitle] = useState(initial.title)
  const [message, setMessage] = useState(initial.message)
  const [withEn, setWithEn] = useState(false)
  const [titleEn, setTitleEn] = useState('')
  const [messageEn, setMessageEn] = useState('')
  const [targets, setTargets] = useState<Target[]>([])
  const [duration, setDuration] = useState(30)
  const [justification, setJustification] = useState('')
  const [confirm, setConfirm] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const [created, setCreated] = useState<string | null>(null)

  const effectiveTargets: Target[] = all ? [{ type: 'all', id: null }] : targets
  const debouncedTargets = useDebounced(effectiveTargets, 300)
  const preview = useQuery({
    queryKey: ['alert-preview', debouncedTargets],
    queryFn: () => api.post<{ data: PreviewSummary }>('/alerts/preview', { targets: debouncedTargets }, false).then((r) => r.data),
    enabled: step >= 1 && debouncedTargets.length > 0,
  })
  const summary = preview.data

  const toggle = (type: Target['type'], id: string) => setTargets((l) => (l.some((t) => t.type === type && t.id === id) ? l.filter((t) => !(t.type === type && t.id === id)) : [...l, { type, id }]))
  const has = (type: Target['type'], id: string) => targets.some((t) => t.type === type && t.id === id)

  const messageValid = title.trim().length > 0 && title.length <= 120 && message.trim().length > 0 && message.length <= 280 && (!withEn || (titleEn.trim() && messageEn.trim()))
  const scopeValid = effectiveTargets.length > 0 && duration >= 5 && !!summary && summary.targeted > 0 && !preview.isFetching
  const confirmValid = justification.trim().length >= 10 && confirm

  const publish = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: Alert }>('/alerts', {
        title, message, severity, duration_min: duration, targets: effectiveTargets, justification, confirm: true,
        translations: withEn ? { en: { title: titleEn, message: messageEn } } : null,
      })
      setCreated(res.data.id)
      client.invalidateQueries({ queryKey: ['alerts'] })
      client.invalidateQueries({ queryKey: ['dashboard'] })
      setStep(4)
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(500, { message: String(e) })
      setError(err)
      if (err.field('title') || err.field('message') || err.field('severity')) setStep(0)
      else if (err.field('targets') || err.field('duration_min')) setStep(1)
    } finally {
      setBusy(false)
    }
  }

  const close = () => {
    if (step < 4 && (title || message) && !window.confirm('Abandonner la préparation de cette alerte ?')) return
    onClose()
  }

  const footer = step === 4 ? (
    <><div className="footer-note"><Icon name="shield" size={14} /> Publication inscrite au journal (pouvoir « urgence »)</div><Button onClick={onClose}>Terminer</Button></>
  ) : (
    <StepButtons onBack={step ? () => setStep(step - 1) : close} backLabel={step ? 'Retour' : 'Annuler'}
      onNext={step === 3 ? publish : () => setStep(step + 1)} nextLabel={step === 3 ? 'Publier l’alerte' : 'Continuer'} danger={step === 3} busy={busy}
      nextDisabled={step === 0 ? !messageValid : step === 1 ? !scopeValid : step === 3 ? !confirmValid : false} />
  )

  const sevClass = `sev-${severity}`
  return (
    <FlowShell title="Déclencher une alerte" kicker="ALERTE PRIORITAIRE" steps={STEPS} step={step} onClose={step === 4 ? onClose : close} footer={footer}>
      {step === 0 && (
        <>
          <h3>Message prioritaire</h3>
          <p>Le message doit rester court et compréhensible sans dépendre de la couleur : le niveau est aussi écrit en toutes lettres à l’écran.</p>
          <div className="form-grid">
            <Field label="Niveau" required error={error?.field('severity')}>
              <select value={severity} onChange={(e) => setSeverity(e.target.value)}>
                {Object.entries(SEVERITY_HINT).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </Field>
            <Field label="Titre" required error={error?.field('title')} help={`${title.length}/120`}>
              <input value={title} onChange={(e) => setTitle(e.target.value)} maxLength={120} aria-invalid={!!error?.field('title')} />
            </Field>
            <Field label="Message" required wide error={error?.field('message')} help={`${message.length}/280 caractères`}>
              <textarea value={message} onChange={(e) => setMessage(e.target.value)} maxLength={280} aria-invalid={!!error?.field('message')} />
            </Field>
            <label className="check-label wide"><input type="checkbox" checked={withEn} onChange={(e) => setWithEn(e.target.checked)} /> Ajouter une traduction anglaise (affichée en alternance)</label>
            {withEn && <>
              <Field label="Titre (EN)" required error={error?.field('translations.en.title')}><input value={titleEn} onChange={(e) => setTitleEn(e.target.value)} maxLength={120} lang="en" /></Field>
              <Field label="Message (EN)" required wide error={error?.field('translations.en.message')}><textarea value={messageEn} onChange={(e) => setMessageEn(e.target.value)} maxLength={280} lang="en" /></Field>
            </>}
          </div>
        </>
      )}
      {step === 1 && (
        <>
          <h3>Portée et durée</h3>
          <p>Une expiration est obligatoire. Les lecteurs hors ligne sont signalés sans fausse confirmation.</p>
          <div className="target-list">
            <label className={all ? 'selected' : ''}><input type="radio" name="scope" checked={all} onChange={() => setAll(true)} /><span><b>Tous les écrans</b>Toutes zones et tous niveaux</span></label>
            <label className={!all ? 'selected' : ''}><input type="radio" name="scope" checked={!all} onChange={() => setAll(false)} /><span><b>Sélection</b>Zones, niveaux ou groupes de diffusion</span></label>
          </div>
          {!all && (refs.isError ? <Notice tone="danger">Référentiels indisponibles.</Notice> : (
            <div className="target-groups">
              <fieldset><legend>Niveaux</legend>{(refs.data?.floors ?? []).map((f) => <label key={f.id}><input type="checkbox" checked={has('floor', f.id)} onChange={() => toggle('floor', f.id)} />{f.label}</label>)}</fieldset>
              <fieldset><legend>Zones</legend>{(refs.data?.zones ?? []).map((z) => <label key={z.id}><input type="checkbox" checked={has('zone', z.id)} onChange={() => toggle('zone', z.id)} />{z.name}</label>)}</fieldset>
              <fieldset><legend>Groupes</legend>{groups.isError ? <span className="muted small">Indisponibles</span> : (groups.data ?? []).map((g) => <label key={g.id}><input type="checkbox" checked={has('group', g.id)} onChange={() => toggle('group', g.id)} />{g.name} ({g.screens_count})</label>)}</fieldset>
              <fieldset><legend>Écrans</legend>{screens.isError ? <span className="muted small">Indisponibles</span> : screens.isLoading ? <span className="muted small">Chargement…</span> : [...(screens.data ?? [])].sort((a, b) => a.code.localeCompare(b.code)).map((s) => <label key={s.id}><input type="checkbox" checked={has('screen', s.id)} onChange={() => toggle('screen', s.id)} />{s.code} · {s.name}</label>)}</fieldset>
            </div>
          ))}
          <div className="form-grid" style={{ marginTop: 14 }}>
            <Field label="Durée avant expiration automatique (minutes)" required error={error?.field('duration_min')} help={`Expiration prévue vers ${fmtTime(new Date(Date.now() + duration * 60000).toISOString())}`}>
              <input type="number" min={5} step={5} value={duration} onChange={(e) => setDuration(Number(e.target.value))} aria-invalid={!!error?.field('duration_min')} />
            </Field>
          </div>
          {effectiveTargets.length === 0 ? <div className="warning-box"><Icon name="alert" /><span><strong>Aucune cible</strong><small>Sélectionnez au moins une zone, un niveau ou un groupe.</small></span></div>
            : preview.isError ? <div style={{ marginTop: 14 }}><ErrorState error={preview.error} onRetry={() => preview.refetch()} /></div>
            : !summary || preview.isFetching ? <Loading label="Calcul de la portée réelle…" /> : (
              <>
                <div className="summary-boxes">
                  <div><strong>{summary.targeted}</strong><span>écrans ciblés</span></div>
                  <div><strong className="green-text">{summary.online}</strong><span>en ligne</span></div>
                  <div><strong className="red-text">{summary.offline}</strong><span>hors ligne ou suspects</span></div>
                  <div><strong className="amber-text">{summary.off_window}</strong><span>hors plage de fonctionnement</span></div>
                </div>
                {summary.targeted === 0 && <div className="warning-box"><Icon name="alert" /><span><strong>Aucun écran dans cette portée</strong><small>Élargissez la sélection.</small></span></div>}
                {(summary.offline > 0 || summary.off_window > 0) && (
                  <div className="warning-box"><Icon name="alert" /><span>
                    <strong>La réception ne peut pas être garantie sur {summary.offline + summary.off_window} écran(s)</strong>
                    <small>{summary.notice}{summary.offline_screens.length ? ` Lecteurs hors ligne : ${summary.offline_screens.join(', ')}.` : ''}</small>
                  </span></div>
                )}
              </>
            )}
        </>
      )}
      {step === 2 && (
        <>
          <h3>Aperçu plein écran</h3>
          <p>Le message interrompt les programmes ordinaires sur {summary?.targeted ?? 0} écran(s) jusqu’à son expiration ou sa levée.</p>
          <div className={`mini-alert ${sevClass}`}>
            <Icon name="alert" size={36} />
            <small>{severity === 'exercise' ? 'EXERCICE — ' : ''}{SEVERITY[severity]?.[0]?.toUpperCase()} · ALERTE PRIORITAIRE</small>
            <strong>{title}</strong>
            <span>{message}</span>
            {withEn && <em lang="en">{titleEn} — {messageEn}</em>}
          </div>
        </>
      )}
      {step === 3 && (
        <>
          <h3>Confirmation explicite</h3>
          <p>{powerUntil ? `Votre pouvoir « urgence » est actif jusqu’au ${fmtDate(powerUntil)}.` : 'Publication soumise à votre pouvoir « urgence ».'} Motif et identité seront audités.</p>
          <div className="confirmation-box danger">
            <Icon name="alert" />
            <div>
              <strong>Interrompre les programmes sur {summary?.targeted ?? 0} écran(s) pendant {duration} minutes</strong>
              <p>{summary ? `${summary.online} lecteur(s) devraient recevoir l’alerte immédiatement. ${summary.offline} lecteur(s) hors ligne ou suspect(s), ${summary.off_window} écran(s) hors plage.` : ''} Niveau : {SEVERITY[severity]?.[0]}. « {title} »</p>
            </div>
          </div>
          <Field label="Justification" required error={error?.field('justification')} help={`10 caractères minimum (${justification.trim().length}). Inscrite au journal.`}>
            <textarea value={justification} onChange={(e) => setJustification(e.target.value)} maxLength={500} aria-invalid={!!error?.field('justification')} />
          </Field>
          <label className="check-label" style={{ marginTop: 12 }}><input type="checkbox" checked={confirm} onChange={(e) => setConfirm(e.target.checked)} /> Je confirme la diffusion immédiate de cette alerte sur la portée indiquée.</label>
          {error && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}</Notice></div>}
        </>
      )}
      {step === 4 && created && (
        <>
          <h3>Suivi de réception</h3>
          <p>L’alerte est active. Les compteurs se mettent à jour automatiquement ; ils ne prouvent pas la visibilité physique de la dalle.</p>
          <AlertTracking id={created} />
          <div className="success-box" style={{ marginTop: 12 }}><Icon name="check" /><span><strong>Programmes ordinaires suspendus sur les écrans ciblés</strong><small>Ils reprendront automatiquement à la levée ou à l’expiration.</small></span></div>
        </>
      )}
    </FlowShell>
  )
}
