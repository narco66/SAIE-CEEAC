import { useEffect, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, fileUrl } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { CLASS_LABEL, CLASS_TONE, fmtDateTime, fmtRelative, LANG_STATUS } from '../../lib/format'
import { Button, ErrorState, Field, Icon, Loading, Notice, PageHeading, Status, StatusOf, useToast } from '../../components/ui'
import { errorText, HISTORY_LABEL, langLabel, TemplatePreview, type ContentDetail } from './shared'

type Decision = 'approve' | 'return' | 'reassign'

function targetsLabel(t: unknown): string {
  if (!t) return 'Aucune cible proposée'
  if (Array.isArray(t)) {
    if (!t.length) return 'Aucune cible proposée'
    return t.map((x) => (typeof x === 'object' && x ? String((x as Record<string, unknown>).label ?? (x as Record<string, unknown>).name ?? (x as Record<string, unknown>).type ?? '') : String(x))).filter(Boolean).join(', ')
  }
  if (typeof t === 'object') return Object.entries(t as Record<string, unknown>).map(([k, v]) => `${k} : ${Array.isArray(v) ? v.length : String(v)}`).join(' · ')
  return String(t)
}

/** V19 Validation éditoriale par langue (PUB-08, HAB-02) : approuver, retourner avec motif ou réaffecter. */
export default function ContentValidation() {
  const { id } = useParams()
  const [params, setParams] = useSearchParams()
  const navigate = useNavigate()
  const client = useQueryClient()
  const toast = useToast()
  const { me } = useAuth()
  const query = useQuery({
    queryKey: ['content', id, 'validation'],
    queryFn: ({ signal }) => api.get<{ data: ContentDetail }>(`/contents/${id}`, undefined, signal).then((r) => r.data),
    refetchOnWindowFocus: false,
  })
  const [decision, setDecision] = useState<Decision | null>(null)
  const [reason, setReason] = useState('')
  const [reassignTo, setReassignTo] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [done, setDone] = useState<string | null>(null)

  const c = query.data
  const lang = params.get('lang') ?? c?.languages.find((l) => l.status === 'submitted' && c.permissions.validate_languages.includes(l.lang))?.lang ?? c?.languages[0]?.lang ?? 'fr'
  useEffect(() => { setDecision(null); setReason(''); setError(null); setDone(null) }, [lang])

  if (query.isLoading) return <Loading />
  if (query.isError || !c) return <ErrorState error={query.error} onRetry={() => query.refetch()} />

  const line = c.languages.find((l) => l.lang === lang)
  const isAuthor = c.author?.id === me.id
  const habilitated = c.permissions.validate_languages.includes(lang)
  const approval = c.approvals.find((a) => a.lang === lang && a.status === 'open')
  const previous = c.approved_versions?.find((v) => v.lang === lang)
  const history = c.history.filter((h) => !h.lang || h.lang === lang)
  const orientation = c.template?.orientation ?? 'landscape'
  const image = (c.media ?? []).find((m) => m.role !== 'logo')
  const reasonInvalid = decision === 'return' && reason.trim().length < 5
  const reassignInvalid = decision === 'reassign' && !reassignTo
  const candidates = c.reassign_candidates?.[lang] ?? []

  const decide = async () => {
    if (!line || !decision) return
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: ContentDetail }>(`/contents/${c.id}/approve`, {
        lang, decision, reason: reason.trim() || null, expected_revision: line.revision, reassign_to: decision === 'reassign' ? reassignTo : null,
      })
      client.setQueryData(['content', id, 'validation'], res.data)
      client.invalidateQueries({ queryKey: ['contents'] })
      client.invalidateQueries({ queryKey: ['content', id] })
      const msg = decision === 'approve' ? `Version ${lang.toUpperCase()} approuvée : elle est désormais immuable et programmable.`
        : decision === 'return' ? `Version ${lang.toUpperCase()} retournée à l’auteur avec votre motif.` : `Validation ${lang.toUpperCase()} réaffectée.`
      setDone(msg)
      toast.success('Décision enregistrée', msg)
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: errorText(e) }))
    } finally {
      setBusy(false)
    }
  }

  const refusal = isAuthor
    ? 'Vous êtes l’auteur de ce contenu : un auteur ne peut pas valider sa propre publication (HAB-02). La décision revient à un validateur distinct.'
    : !habilitated ? `Vous n’êtes pas habilité à valider la version ${langLabel(lang).toLowerCase()} de ce contenu, ou vos droits de validation ont expiré.` : null

  return (
    <>
      <PageHeading
        title={c.title}
        description={`${c.reference} · validation éditoriale par langue`}
        demo={me.demo_mode}
        actions={<Button variant="secondary" icon="edit" onClick={() => navigate(`/affichage/contenus/${c.id}/edition`)}>Ouvrir la fiche</Button>}
      />
      <div className="language-tabs" role="tablist" aria-label="Versions linguistiques">
        {c.languages.map((l) => (
          <button key={l.lang} role="tab" aria-selected={l.lang === lang} className={l.lang === lang ? 'active' : ''} onClick={() => setParams({ lang: l.lang }, { replace: true })}>
            {l.lang.toUpperCase()} <StatusOf map={LANG_STATUS} value={l.status} />
          </button>
        ))}
      </div>
      {!line ? <ErrorState error={new ApiError(404, { message: 'Version absente' })} /> : (
        <div className="pub-editor-grid">
          <section className="panel">
            <div className="flow-content">
              <div className="step-label">VERSION {lang.toUpperCase()} · RÉVISION {line.revision}</div>
              <h3>{line.title}</h3>
              <p>
                Version {line.status === 'submitted' ? 'figée en attente de décision' : `au statut « ${LANG_STATUS[line.status]?.[0] ?? line.status} »`}
                {line.submitted_at ? ` · soumise ${fmtRelative(line.submitted_at)} par ${c.author?.name ?? 'l’auteur'}` : ''}
                {approval?.due_at ? ` · échéance ${fmtDateTime(approval.due_at)}` : ''}
              </p>
              <div className="validation-preview" lang={lang}>
                <span>{(c.category?.name ?? 'CONTENU').toUpperCase()}</span>
                <h4>{line.title}</h4>
                {line.body && <p style={{ whiteSpace: 'pre-wrap' }}>{line.body}</p>}
              </div>
              <div style={{ marginTop: 14, maxWidth: orientation === 'portrait' ? 300 : 560 }}>
                <TemplatePreview layout={c.template_layout} orientation={orientation} title={line.title} body={line.body} lang={lang} kicker={c.category?.name}
                  imageUrl={image ? fileUrl(`/asset-versions/${image.asset_version_id}/thumbnail`) : null}
                  qrValue={c.qr?.enabled && c.qr.code ? `${window.location.origin}/q/${c.qr.code}` : null} label={`Rendu simulé du modèle — ${langLabel(lang)}`} />
              </div>
              {line.overflow && <Notice tone="warning">Le rendu déclaré par l’auteur signale un débordement du modèle.</Notice>}
              <div className="metadata-row">
                <span><b>Classification</b><Status tone={CLASS_TONE[c.classification]}>{CLASS_LABEL[c.classification]}</Status></span>
                <span><b>Cibles proposées</b>{targetsLabel(c.proposed_targets)}</span>
                <span><b>Version</b>Révision {line.revision} · {langLabel(lang)}</span>
              </div>
              <div className="metadata-row">
                <span><b>Validité</b>{c.is_permanent ? 'Permanent' : `${c.valid_from ? `du ${fmtDateTime(c.valid_from)} ` : ''}jusqu’au ${fmtDateTime(c.expires_at)}`}</span>
                <span><b>Modèle</b>{c.template?.name ?? 'Mise en page par défaut'}</span>
                <span><b>Médias</b>{c.media.length ? `${c.media.length} fichier(s)` : 'Aucun'}{c.qr?.enabled ? ' · QR code' : ''}</span>
              </div>
              {previous && (
                <div className="pub-diff" aria-label="Modifications depuis la dernière version approuvée">
                  <div><b>Dernière version approuvée (rév. {previous.revision}{previous.approver ? `, ${previous.approver}` : ''})</b><strong>{previous.payload?.title}</strong><p style={{ whiteSpace: 'pre-wrap', margin: '4px 0 0' }}>{previous.payload?.body}</p></div>
                  <div><b>Version soumise (rév. {line.revision})</b><strong>{line.title}</strong><p style={{ whiteSpace: 'pre-wrap', margin: '4px 0 0' }}>{line.body}</p></div>
                </div>
              )}

              <h3 style={{ marginTop: 24 }}>Votre décision</h3>
              {refusal ? (
                <div className="confirmation-box danger" role="alert"><Icon name="shield" /><div><strong>Décision non autorisée</strong><p>{refusal}</p></div></div>
              ) : line.status !== 'submitted' ? (
                <Notice tone="info">Cette version n’est pas en attente de validation (statut : {LANG_STATUS[line.status]?.[0] ?? line.status}). Aucune décision n’est attendue.</Notice>
              ) : done ? (
                <div className="success-box"><Icon name="check" /><span><strong>Décision enregistrée</strong><small>{done}</small></span></div>
              ) : (
                <>
                  <p className="muted small">L’auteur et le validateur sont distincts conformément à HAB-02. Votre identité, votre rôle et l’heure serveur sont inscrits dans l’historique.</p>
                  <div className="decision-options three" role="radiogroup" aria-label="Décision">
                    <button role="radio" aria-checked={decision === 'approve'} className={decision === 'approve' ? 'selected' : ''} onClick={() => setDecision('approve')}>
                      <Icon name="check" /><span><strong>Approuver la version</strong><small>Elle devient immuable et pourra être programmée par un diffuseur.</small></span>
                    </button>
                    <button role="radio" aria-checked={decision === 'return'} className={decision === 'return' ? 'selected danger-choice' : ''} onClick={() => setDecision('return')}>
                      <Icon name="edit" /><span><strong>Retourner pour correction</strong><small>Un motif précis sera transmis à l’auteur.</small></span>
                    </button>
                    <button role="radio" aria-checked={decision === 'reassign'} className={decision === 'reassign' ? 'selected' : ''} onClick={() => setDecision('reassign')}>
                      <Icon name="users" /><span><strong>Réaffecter</strong><small>Confier la décision à un autre validateur habilité.</small></span>
                    </button>
                  </div>
                  {decision === 'reassign' && (
                    <Field label="Nouveau validateur" required error={error?.field('reassign_to')} help={`Il doit être habilité pour la langue ${langLabel(lang).toLowerCase()} et distinct de l’auteur.`}>
                      <select value={reassignTo} onChange={(e) => setReassignTo(e.target.value)} aria-invalid={!!error?.field('reassign_to')}>
                        <option value="">{candidates.length ? '— Choisir —' : 'Aucun autre validateur habilité'}</option>
                        {candidates.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                      </select>
                    </Field>
                  )}
                  {(decision === 'return' || decision === 'reassign') && (
                    <Field label={decision === 'return' ? 'Motif du retour' : 'Commentaire'} required={decision === 'return'} error={error?.field('reason') ?? (decision === 'return' && reason && reasonInvalid ? '5 caractères minimum.' : undefined)} help={decision === 'return' ? 'Obligatoire, 5 caractères minimum. Transmis à l’auteur.' : 'Facultatif.'}>
                      <textarea value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} aria-invalid={decision === 'return' && !!reason && reasonInvalid} />
                    </Field>
                  )}
                  {decision && (
                    <div className={`confirmation-box ${decision === 'return' ? 'danger' : ''}`} style={decision === 'return' ? { marginTop: 12 } : { marginTop: 12, background: 'var(--blue-soft)', color: '#0b4f78' }}>
                      <Icon name={decision === 'return' ? 'alert' : 'info'} />
                      <div>
                        <strong>{decision === 'approve' ? `Approuver la version ${lang.toUpperCase()} révision ${line.revision}` : decision === 'return' ? `Retourner la version ${lang.toUpperCase()} pour correction` : `Réaffecter la validation ${lang.toUpperCase()}`}</strong>
                        <p>{decision === 'approve' ? 'Un instantané immuable est créé ; les autres langues restent inchangées.' : decision === 'return' ? `La version repassera « à corriger » et ${c.author?.name ?? 'l’auteur'} sera notifié(e). Les autres langues restent inchangées.` : 'Le nouveau validateur est notifié ; l’échéance est conservée.'}</p>
                      </div>
                    </div>
                  )}
                  {error && (
                    <div style={{ marginTop: 12 }}>
                      <Notice tone="danger">
                        <strong>{error.message}</strong>
                        {error.code === 'VERSION_CONFLICT' && <div style={{ marginTop: 6 }}><Button variant="secondary" size="small" icon="refresh" onClick={() => { setError(null); query.refetch() }}>Recharger la révision courante</Button></div>}
                      </Notice>
                    </div>
                  )}
                  <div className="form-actions">
                    <Button variant="secondary" onClick={() => navigate('/mes-taches')}>Annuler</Button>
                    <Button variant={decision === 'return' ? 'danger' : 'primary'} onClick={decide} busy={busy} disabled={!decision || reasonInvalid || reassignInvalid}>
                      {decision === 'approve' ? 'Approuver' : decision === 'return' ? 'Retourner pour correction' : decision === 'reassign' ? 'Réaffecter' : 'Choisir une décision'}
                    </Button>
                  </div>
                </>
              )}
            </div>
          </section>
          <aside className="pub-aside">
            <section className="panel">
              <div className="panel-title"><div><h2>Échéance</h2><p>Demande de validation {lang.toUpperCase()}</p></div></div>
              <div className="panel-body">
                {approval ? (
                  <div className="workflow-card" style={{ marginBottom: 0 }}>
                    <div className="avatar dark">{approval.assignee?.initials ?? '?'}</div>
                    <div>
                      <strong>{approval.assignee?.name ?? 'Non assignée'} <small>Validateur</small></strong>
                      <p>Avant le {fmtDateTime(approval.due_at)}{approval.escalated_at ? ` · escaladée le ${fmtDateTime(approval.escalated_at)}` : ''}</p>
                      {approval.backup && <p>Suppléant : {approval.backup.name}</p>}
                    </div>
                  </div>
                ) : <p className="muted small">Aucune demande ouverte pour cette langue.</p>}
              </div>
            </section>
            <section className="panel">
              <div className="panel-title"><div><h2>Chronologie</h2><p>Événements {lang.toUpperCase()} et communs</p></div></div>
              <div className="panel-body timeline-list">
                {history.length === 0 ? <p className="muted small">Aucun événement.</p> : history.map((h, i) => (
                  <div key={i}>
                    <time>{fmtDateTime(h.at)}</time>
                    <span><strong className="small">{HISTORY_LABEL[h.action] ?? h.action}{h.revision ? ` · rév. ${h.revision}` : ''}</strong><small className="muted" style={{ display: 'block' }}>{h.actor ?? 'Système'}{h.role ? ` (${h.role})` : ''}</small>{h.reason && <small style={{ display: 'block' }}>« {h.reason} »</small>}</span>
                  </div>
                ))}
              </div>
            </section>
          </aside>
        </div>
      )}
    </>
  )
}
