import { useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, fileUrl, type Paginated } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useData, useList } from '../../lib/hooks'
import { CLASS_LABEL, CLASS_TONE, CONTENT_STATUS, fmtBytes, fmtDateTime, fmtRelative, LANG_STATUS, SCAN_STATUS } from '../../lib/format'
import { Button, EmptyState, ErrorState, Field, Icon, Loading, Modal, Notice, PageHeading, Select, Status, StatusOf, Toolbar, useDebounced, useToast, useUnsavedWarning } from '../../components/ui'
import {
  ALL_LANGS, errorText, HISTORY_LABEL, KIND_ICON, KIND_LABEL, LIMITS, LocalDateTime, joinLocal, langLabel, splitLocal, Stepper, TemplatePreview, useReferentials,
  type Asset, type ContentDetail, type MediaItem, type Template,
} from './shared'

interface LangForm { title: string; body: string; overflow: boolean; subtitles_asset_version_id: string | null }
interface Form {
  title: string; category_id: string; owner_structure_id: string; classification: string; priority: number; template_id: string
  is_permanent: boolean; valid_from_date: string; valid_from_time: string; expires_date: string; expires_time: string
  media: MediaItem[]; qr_enabled: boolean; qr_document_id: string; qr_label: string
  languages: Record<string, LangForm>
}

const STEPS = ['Informations', 'Composition', 'Langues', 'Vérification', 'Soumission']
const STEP_OF_CODE: Record<string, number> = {
  EXPIRY_REQUIRED: 0, RESTRICTED_CONTENT: 0, MEDIA_NOT_READY: 1, CLASSIFICATION_MISMATCH: 1, TEXT_OVERFLOW: 2, SUBTITLES_REQUIRED: 2, LANGUAGE_INCOMPLETE: 2, LANGUAGE_MISSING: 2,
}

function emptyForm(structureId: string): Form {
  return {
    title: '', category_id: '', owner_structure_id: structureId, classification: 'interne', priority: 50, template_id: '', is_permanent: false,
    valid_from_date: '', valid_from_time: '', expires_date: '', expires_time: '18:00', media: [], qr_enabled: false, qr_document_id: '', qr_label: '', languages: {},
  }
}

function fromContent(c: ContentDetail): Form {
  const from = splitLocal(c.valid_from)
  const exp = splitLocal(c.expires_at)
  const languages: Record<string, LangForm> = {}
  c.languages.forEach((l) => { languages[l.lang] = { title: l.title ?? '', body: l.body ?? '', overflow: !!l.overflow, subtitles_asset_version_id: l.subtitles_asset_version_id } })
  return {
    title: c.title, category_id: c.category?.id ?? '', owner_structure_id: c.owner?.id ?? '', classification: c.classification, priority: c.priority ?? 50,
    template_id: c.template?.id ?? '', is_permanent: c.is_permanent, valid_from_date: from.date, valid_from_time: from.time, expires_date: exp.date, expires_time: exp.time || '18:00',
    media: (c.media ?? []).map(normMedia), qr_enabled: !!c.qr?.enabled, qr_document_id: c.qr?.document_id ?? '', qr_label: c.qr?.label ?? '', languages,
  }
}

const normMedia = (m: MediaItem): MediaItem => ({ asset_version_id: m.asset_version_id, pages: m.pages ?? null, page_duration_s: m.page_duration_s ?? null, role: m.role ?? 'main' })
const sameInstant = (a: string | null, b: string | null) => (a && b ? new Date(a).getTime() === new Date(b).getTime() : a === b)
const hasText = (l?: LangForm) => !!l && (l.title.trim() !== '' || l.body.trim() !== '')

/** Construit le corps POST (création) ou le PATCH limité aux champs modifiés, pour ne pas rouvrir inutilement des versions approuvées. */
function buildPayload(f: Form, original?: ContentDetail): Record<string, unknown> {
  const qr = f.qr_enabled ? { enabled: true, document_id: f.qr_document_id || null, label: f.qr_label.trim() || null } : { enabled: false }
  const full: Record<string, unknown> = {
    title: f.title.trim(), classification: f.classification, priority: f.priority, template_id: f.template_id || null,
    owner_structure_id: f.owner_structure_id || null, media: f.media.map(normMedia), qr, is_permanent: f.is_permanent,
    valid_from: joinLocal(f.valid_from_date, f.valid_from_time, '00:00'),
    expires_at: f.is_permanent ? null : joinLocal(f.expires_date, f.expires_time, '23:59'),
  }
  if (f.category_id) full.category_id = f.category_id
  const languages: Record<string, Record<string, unknown>> = {}
  for (const [lang, l] of Object.entries(f.languages)) {
    if (!hasText(l)) continue
    const o = original?.languages.find((x) => x.lang === lang)
    const line = { title: l.title.trim(), body: l.body.trim() || null, overflow: l.overflow, subtitles_asset_version_id: l.subtitles_asset_version_id }
    if (!o) { languages[lang] = line; continue }
    const textChanged = o.title !== line.title || (o.body ?? null) !== line.body || (o.subtitles_asset_version_id ?? null) !== line.subtitles_asset_version_id
    const editable = o.status === 'draft' || o.status === 'returned'
    if (textChanged) languages[lang] = line
    else if (editable && o.overflow !== line.overflow) languages[lang] = { title: line.title, overflow: line.overflow }
  }
  if (!original) return { ...full, languages }

  const patch: Record<string, unknown> = {}
  const oQr = original.qr?.enabled ? { enabled: true, document_id: original.qr.document_id ?? null, label: original.qr.label ?? null } : { enabled: false }
  if (full.title !== original.title) patch.title = full.title
  if (f.category_id && f.category_id !== original.category?.id) patch.category_id = f.category_id
  if (full.classification !== original.classification) patch.classification = full.classification
  if (full.priority !== original.priority) patch.priority = full.priority
  if (full.template_id !== (original.template?.id ?? null)) patch.template_id = full.template_id
  if (f.owner_structure_id && f.owner_structure_id !== original.owner?.id) patch.owner_structure_id = f.owner_structure_id
  if (JSON.stringify(full.media) !== JSON.stringify((original.media ?? []).map(normMedia))) patch.media = full.media
  if (JSON.stringify(qr) !== JSON.stringify(oQr)) patch.qr = qr
  if (full.is_permanent !== original.is_permanent) patch.is_permanent = full.is_permanent
  if (!sameInstant(full.valid_from as string | null, original.valid_from)) patch.valid_from = full.valid_from
  if (!sameInstant(full.expires_at as string | null, original.is_permanent ? null : original.expires_at)) patch.expires_at = full.expires_at
  if (Object.keys(languages).length) patch.languages = languages
  return patch
}

/** V18 Composition de contenu : informations, composition, langues, vérification puis soumission par langue (PUB-01, PUB-08). */
export default function ContentEditor() {
  const { id } = useParams()
  const location = useLocation()
  const navigate = useNavigate()
  const client = useQueryClient()
  const toast = useToast()
  const { me, can } = useAuth()

  const detailQuery = useQuery({
    queryKey: ['content', id],
    queryFn: ({ signal }) => api.get<{ data: ContentDetail }>(`/contents/${id}`, undefined, signal).then((r) => r.data),
    enabled: !!id, refetchOnWindowFocus: false, staleTime: Infinity,
  })
  const detail = detailQuery.data
  const refs = useReferentials()
  const templatesQuery = useData<Template[]>(['templates', 'active'], '/templates')
  const assetIndex = useQuery({
    queryKey: ['assets', 'index'],
    queryFn: ({ signal }) => api.get<Paginated<Asset>>('/assets', { per_page: 500 }, signal).then((r) => r.data),
    staleTime: 60_000,
  })

  const [form, setForm] = useState<Form>(() => emptyForm(me.structure?.id ?? ''))
  const [loadedVersion, setLoadedVersion] = useState<number | null>(null)
  const [step, setStep] = useState<number>(() => (location.state as { step?: number } | null)?.step ?? 0)
  const [lang, setLang] = useState<string>('fr')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [conflict, setConflict] = useState(false)
  const [picker, setPicker] = useState(false)
  const [pickedAssets, setPickedAssets] = useState<Record<string, Asset>>({})
  const [showErrors, setShowErrors] = useState(false)
  const [submitLangs, setSubmitLangs] = useState<string[]>([])
  const [submitting, setSubmitting] = useState(false)
  const [uploadingSub, setUploadingSub] = useState<string | null>(null)

  const reset = (c: ContentDetail) => {
    setForm(fromContent(c))
    setLoadedVersion(c.version)
    setConflict(false)
    client.setQueryData(['content', c.id], c)
  }
  useEffect(() => {
    if (detail && loadedVersion === null) reset(detail)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [detail, loadedVersion])

  const activeLangs = useMemo(() => {
    const list = (refs.data?.languages ?? []).filter((l) => l.is_active).sort((a, b) => a.sort - b.sort).map((l) => l.code)
    const existing = detail?.languages.map((l) => l.lang) ?? []
    const merged = [...list, ...existing.filter((c) => !list.includes(c))]
    return merged.length ? merged : ['fr']
  }, [refs.data, detail])
  useEffect(() => { if (!activeLangs.includes(lang)) setLang(activeLangs[0]) }, [activeLangs, lang])

  const templates = templatesQuery.data ?? []
  const template = templates.find((t) => t.id === form.template_id)
  const orientation: 'landscape' | 'portrait' = template?.orientation ?? detail?.template?.orientation ?? 'landscape'
  const layout = template?.layout ?? (form.template_id === detail?.template?.id ? detail?.template_layout : null)
  const limits = LIMITS[orientation]

  const assetsByVersion = useMemo(() => {
    const map: Record<string, Asset> = {}
    for (const a of assetIndex.data ?? []) if (a.current_version) map[a.current_version.id] = a
    for (const [vid, a] of Object.entries(pickedAssets)) map[vid] = a
    return map
  }, [assetIndex.data, pickedAssets])
  const mediaAssets = form.media.map((m) => ({ item: m, asset: assetsByVersion[m.asset_version_id] as Asset | undefined }))
  const hasVideo = mediaAssets.some((m) => m.asset?.kind === 'video')
  const mainImage = mediaAssets.find((m) => m.asset?.kind === 'image' && m.asset.status === 'ready')
  const pdfItem = mediaAssets.find((m) => m.asset?.kind === 'pdf')

  const readOnly = id ? !!detail && !detail.permissions.edit : !can('contents.create')
  const patch = useMemo(() => buildPayload(form, detail), [form, detail])
  const dirty = id ? Object.keys(patch).length > 0 : form.title.trim() !== '' || Object.values(form.languages).some(hasText)
  useUnsavedWarning(dirty && !readOnly)

  const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((f) => ({ ...f, [key]: value }))
  const setLine = (code: string, changes: Partial<LangForm>) => setForm((f) => {
    const current = f.languages[code] ?? { title: '', body: '', overflow: false, subtitles_asset_version_id: null }
    const next = { ...current, ...changes }
    if (current.title === next.title && current.body === next.body && current.overflow === next.overflow && current.subtitles_asset_version_id === next.subtitles_asset_version_id) return f
    return { ...f, languages: { ...f.languages, [code]: next } }
  })

  /* ===== Contrôles côté client ===== */
  const infoErrors: Record<string, string> = {}
  if (!form.title.trim()) infoErrors.title = 'Le titre est obligatoire.'
  if (!form.category_id) infoErrors.category_id = 'Choisissez une catégorie.'
  if (!form.is_permanent && !form.expires_date) infoErrors.expires_at = 'La date d’expiration est obligatoire pour une annonce temporaire.'
  if (form.priority < 1 || form.priority > 100) infoErrors.priority = 'La priorité est comprise entre 1 et 100.'
  const langErrors = (code: string): string[] => {
    const l = form.languages[code]
    if (!hasText(l)) return []
    const errs: string[] = []
    if (!l!.title.trim()) errs.push('titre manquant')
    if (l!.title.length > limits.title) errs.push(`titre trop long (${l!.title.length}/${limits.title})`)
    if (l!.body.length > limits.body) errs.push(`texte trop long (${l!.body.length}/${limits.body})`)
    if (l!.overflow) errs.push('débordement du modèle')
    if (hasVideo && !l!.subtitles_asset_version_id) errs.push('sous-titres VTT manquants')
    return errs
  }
  const fieldError = (name: string) => error?.field(name) ?? (showErrors ? infoErrors[name] : undefined)

  const goStep = (s: number) => {
    if (s > 0 && Object.keys(infoErrors).length) { setShowErrors(true); setStep(0); return }
    setStep(s)
  }

  /* ===== Enregistrement ===== */
  const save = async (): Promise<ContentDetail | null> => {
    setError(null)
    setShowErrors(true)
    if (Object.keys(infoErrors).length) { setStep(0); return null }
    if (!id && !Object.values(form.languages).some(hasText)) {
      setError(new ApiError(422, { code: 'LANGUAGE_REQUIRED', message: 'Saisissez au moins une version linguistique (titre) avant le premier enregistrement.' }))
      setStep(2)
      return null
    }
    setSaving(true)
    try {
      if (!id) {
        const res = await api.post<{ data: ContentDetail }>('/contents', buildPayload(form))
        client.invalidateQueries({ queryKey: ['contents'] })
        client.setQueryData(['content', res.data.id], res.data)
        toast.success('Brouillon enregistré', `${res.data.reference} créé.`)
        navigate(`/affichage/contenus/${res.data.id}/edition`, { replace: true, state: { step } })
        return res.data
      }
      if (!dirty) return detail ?? null
      const res = await api.patch<{ data: ContentDetail }>(`/contents/${id}`, patch, { 'If-Match': String(detail!.version) })
      reset(res.data)
      client.invalidateQueries({ queryKey: ['contents'] })
      toast.success('Modifications enregistrées', `Version ${res.data.version}.`)
      return res.data
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(500, { message: errorText(e) })
      setError(err)
      if (err.code === 'VERSION_CONFLICT') setConflict(true)
      else if (STEP_OF_CODE[err.code] !== undefined) setStep(STEP_OF_CODE[err.code])
      else if (Object.keys(err.errors).some((k) => k.startsWith('languages'))) setStep(2)
      return null
    } finally {
      setSaving(false)
    }
  }

  const reload = async () => {
    const res = await detailQuery.refetch()
    if (res.data) { reset(res.data); setError(null) }
  }

  const submit = async () => {
    if (!id || !submitLangs.length) return
    setSubmitting(true)
    setError(null)
    try {
      const res = await api.post<{ data: ContentDetail }>(`/contents/${id}/submit`, { languages: submitLangs })
      reset(res.data)
      setSubmitLangs([])
      client.invalidateQueries({ queryKey: ['contents'] })
      toast.success('Soumission enregistrée', `Version(s) ${submitLangs.map((l) => l.toUpperCase()).join(', ')} transmise(s) au validateur.`)
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(500, { message: errorText(e) })
      setError(err)
      if (STEP_OF_CODE[err.code] !== undefined) setStep(STEP_OF_CODE[err.code])
    } finally {
      setSubmitting(false)
    }
  }

  const uploadSubtitles = async (code: string, file: File) => {
    if (!/\.vtt$/i.test(file.name)) { toast.error(new Error('Seuls les fichiers WebVTT (.vtt) sont acceptés.'), 'Sous-titres refusés'); return }
    setUploadingSub(code)
    try {
      const fd = new FormData()
      fd.append('file', file)
      fd.append('classification', form.classification === 'restreint' ? 'interne' : form.classification)
      fd.append('purpose', 'subtitle')
      fd.append('language', code)
      const res = await api.upload<{ data: Asset; version: { id: string } }>('/assets', fd)
      setLine(code, { subtitles_asset_version_id: res.version.id })
      toast.success('Sous-titres déposés', `${file.name} est en cours d’analyse. Enregistrez le brouillon pour les associer.`)
    } catch (e) {
      toast.error(e, 'Dépôt des sous-titres impossible')
    } finally {
      setUploadingSub(null)
    }
  }

  /* ===== États de chargement ===== */
  if (id && detailQuery.isLoading) return <Loading />
  if (id && (detailQuery.isError || !detail)) return <ErrorState error={detailQuery.error} onRetry={() => detailQuery.refetch()} />
  if (!id && !can('contents.create')) return <ErrorState error={new ApiError(403, { message: 'Accès interdit' })} />

  const reopened = detail?.languages.some((l) => l.status === 'approved' || l.status === 'submitted')
  const returned = detail?.languages.filter((l) => l.status === 'returned' && l.last_return_reason) ?? []
  const openApprovals = detail?.approvals.filter((a) => a.status === 'open') ?? []
  const filledLangs = activeLangs.filter((c) => hasText(form.languages[c]))
  const submittable = detail?.languages.filter((l) => l.status === 'draft' || l.status === 'returned') ?? []
  const qrValue = detail?.qr?.code ? `${window.location.origin}/q/${detail.qr.code}` : null

  /* ===== Étapes ===== */
  const stepInfo = (
    <>
      <h3>Informations générales</h3>
      <p>Définissez le propriétaire, la classification et la validité avant la composition.</p>
      <div className="form-grid">
        <Field label="Titre du contenu" required wide error={fieldError('title')} help="Titre interne de l’annonce (200 caractères maximum).">
          <input value={form.title} maxLength={200} onChange={(e) => set('title', e.target.value)} aria-invalid={!!fieldError('title')} />
        </Field>
        <Field label="Catégorie" required error={fieldError('category_id')}>
          <select value={form.category_id} onChange={(e) => set('category_id', e.target.value)} aria-invalid={!!fieldError('category_id')}>
            <option value="">— Choisir —</option>
            {(refs.data?.categories ?? []).filter((c) => c.is_active || c.id === form.category_id).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </Field>
        <Field label="Propriétaire" required error={error?.field('owner_structure_id')}>
          <select value={form.owner_structure_id} onChange={(e) => set('owner_structure_id', e.target.value)}>
            <option value="">Ma structure{me.structure ? ` (${me.structure.name})` : ''}</option>
            {(refs.data?.structures ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </Field>
        <Field label="Classification" required error={error?.field('classification')} help="Les contenus restreints sont exclus des écrans.">
          <select value={form.classification} onChange={(e) => set('classification', e.target.value)}>
            {Object.entries(CLASS_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </Field>
        <Field label="Priorité" error={fieldError('priority')} help="De 1 à 100 ; 50 correspond à une priorité normale.">
          <input type="number" min={1} max={100} value={form.priority} onChange={(e) => set('priority', Number(e.target.value))} aria-invalid={!!fieldError('priority')} />
        </Field>
        <LocalDateTime label="Début de validité" date={form.valid_from_date} time={form.valid_from_time} onDate={(v) => set('valid_from_date', v)} onTime={(v) => set('valid_from_time', v)} error={error?.field('valid_from')} help="Facultatif · heure de Libreville (UTC+1)" />
        <div>
          <label className="check-label" style={{ marginTop: 18 }}>
            <input type="checkbox" checked={form.is_permanent} onChange={(e) => set('is_permanent', e.target.checked)} /> Contenu permanent (sans date d’expiration)
          </label>
        </div>
        {!form.is_permanent && (
          <LocalDateTime label="Expiration" required date={form.expires_date} time={form.expires_time} onDate={(v) => set('expires_date', v)} onTime={(v) => set('expires_time', v)} error={fieldError('expires_at')} help="Obligatoire pour une annonce temporaire · heure de Libreville (UTC+1)" />
        )}
      </div>
      {form.classification === 'restreint' && <div className="warning-box"><Icon name="alert" /><span><strong>Contenu restreint</strong><small>Il ne pourra être ni soumis à diffusion ni programmé sur un écran collectif.</small></span></div>}
    </>
  )

  const stepComposition = (
    <>
      <h3>Composition du message</h3>
      <p>Choisissez le modèle par orientation, les médias sains de la médiathèque et, si besoin, un QR code vers un document.</p>
      <h4 className="small" style={{ margin: '0 0 8px' }}>Modèle</h4>
      {templatesQuery.isError ? <Notice tone="warning">Modèles indisponibles : {errorText(templatesQuery.error)}</Notice> : templatesQuery.isLoading ? <Loading label="Chargement des modèles…" /> : (
        <div className="pub-templates" role="group" aria-label="Modèles">
          <button type="button" aria-pressed={!form.template_id} onClick={() => set('template_id', '')}>
            <Icon name="layout" /><span><strong>Sans modèle</strong><small>Mise en page par défaut · 16:9</small></span>{!form.template_id && <Icon name="check" />}
          </button>
          {templates.filter((t) => t.status !== 'archived' && (t.purpose === 'content' || t.purpose === 'welcome' || t.id === form.template_id)).map((t) => (
            <button type="button" key={t.id} aria-pressed={form.template_id === t.id} onClick={() => set('template_id', t.id)}>
              <Icon name="layout" />
              <span><strong>{t.name}</strong><small>{t.orientation === 'portrait' ? '9:16 portrait' : '16:9 paysage'} · {[...new Set(t.layout.zones.map((z) => z.type))].join(', ')}</small></span>
              {form.template_id === t.id && <Icon name="check" />}
            </button>
          ))}
        </div>
      )}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', margin: '4px 0 8px' }}>
        <h4 className="small" style={{ margin: 0 }}>Médias ({form.media.length}/6)</h4>
        <Button variant="secondary" icon="image" size="small" onClick={() => setPicker(true)} disabled={readOnly || form.media.length >= 6}>Choisir un média</Button>
      </div>
      {error?.field('media') && <span className="field-error">{error.field('media')}</span>}
      {form.media.length === 0 ? <p className="muted small">Aucun média : le message sera composé de texte uniquement.</p> : (
        <div className="pub-media-list">
          {mediaAssets.map(({ item, asset }, idx) => {
            const pages = asset?.current_version?.pages ?? 0
            const selected = item.pages ?? Array.from({ length: Math.min(pages, 50) }, (_, i) => i + 1)
            const updateItem = (changes: Partial<MediaItem>) => set('media', form.media.map((m, i) => (i === idx ? { ...m, ...changes } : m)))
            return (
              <div className="pub-media-item" key={item.asset_version_id}>
                <header>
                  <span className="doc-thumb"><Icon name={asset ? KIND_ICON[asset.kind] : 'file'} size={17} /></span>
                  <span>
                    <strong>{asset?.title ?? `Média ${item.asset_version_id.slice(0, 8)}`}</strong>
                    <small>
                      {asset ? `${KIND_LABEL[asset.kind]} · ${fmtBytes(asset.current_version?.size)}${asset.current_version?.duration_s ? ` · ${asset.current_version.duration_s} s` : ''}${pages ? ` · ${pages} pages` : ''}` : 'Métadonnées non disponibles'}
                    </small>
                  </span>
                  {asset && <StatusOf map={SCAN_STATUS} value={asset.status} />}
                  {asset && form.classification === 'public' && asset.classification === 'restreint' && <Status tone="danger">Média restreint</Status>}
                  <Button variant="ghost" size="small" icon="trash" ariaLabel="Retirer ce média" onClick={() => set('media', form.media.filter((_, i) => i !== idx))} disabled={readOnly} />
                </header>
                {asset?.kind === 'pdf' && pages > 0 && (
                  <>
                    <div className="pdf-pages" role="group" aria-label="Pages à diffuser">
                      {Array.from({ length: Math.min(pages, 50) }, (_, i) => i + 1).map((n) => {
                        const on = selected.includes(n)
                        return (
                          <button type="button" key={n} className={on ? 'selected' : ''} aria-pressed={on} disabled={readOnly}
                            onClick={() => updateItem({ pages: on ? selected.filter((p) => p !== n) : [...selected, n].sort((a, b) => a - b) })}>
                            <span><Icon name="file" /></span><b>Page {n}</b><small>{on ? `${item.page_duration_s ?? 12} secondes` : 'Non sélectionnée'}</small>
                          </button>
                        )
                      })}
                    </div>
                    <div className="form-grid" style={{ marginTop: 10 }}>
                      <Field label="Durée par page (secondes)" help="Entre 5 et 120 s." error={error?.field(`media.${idx}.page_duration_s`)}>
                        <input type="number" min={5} max={120} value={item.page_duration_s ?? 12} onChange={(e) => updateItem({ page_duration_s: Number(e.target.value) })} />
                      </Field>
                      <div className="muted small" style={{ alignSelf: 'end' }}>{selected.length} page(s) sélectionnée(s) · durée totale {selected.length * (item.page_duration_s ?? 12)} s{pages > 50 ? ' · 50 pages au plus' : ''}</div>
                    </div>
                  </>
                )}
              </div>
            )
          })}
        </div>
      )}
      <h4 className="small" style={{ margin: '18px 0 8px' }}>QR code (facultatif)</h4>
      <label className="check-label"><input type="checkbox" checked={form.qr_enabled} onChange={(e) => set('qr_enabled', e.target.checked)} /> Afficher un QR code</label>
      {form.qr_enabled && <QrDocumentFields form={form} set={set} error={error} />}
    </>
  )

  const line = form.languages[lang] ?? { title: '', body: '', overflow: false, subtitles_asset_version_id: null }
  const serverLine = detail?.languages.find((l) => l.lang === lang)
  const langTabs = (
    <>
      <h3>Versions linguistiques</h3>
      <p>Chaque langue est validée séparément. Une langue non approuvée sera ignorée à la diffusion. Seules les langues actives sont proposées.</p>
      <div className="language-tabs" role="tablist" aria-label="Langues">
        {ALL_LANGS.map((code) => {
          const active = activeLangs.includes(code)
          const sl = detail?.languages.find((l) => l.lang === code)
          const errs = langErrors(code)
          return (
            <button key={code} role="tab" aria-selected={lang === code} className={lang === code ? 'active' : ''} disabled={!active} onClick={() => setLang(code)}>
              {code.toUpperCase()}{' '}
              {!active ? <span>Inactif</span> : errs.length ? <Status tone="danger">À corriger</Status> : sl ? <StatusOf map={LANG_STATUS} value={sl.status} /> : hasText(form.languages[code]) ? <Status tone="neutral">Nouveau</Status> : <span>Vide</span>}
            </button>
          )
        })}
      </div>
      {serverLine?.status === 'returned' && serverLine.last_return_reason && (
        <Notice tone="danger"><strong>Retour du validateur :</strong> {serverLine.last_return_reason}</Notice>
      )}
    </>
  )
  const stepLanguages = (
    <>
      <div className="pub-lang-grid" style={{ marginTop: 12 }}>
        <div>
          <Field label={`Titre (${langLabel(lang)})`} required error={error?.field(`languages.${lang}.title`)}>
            <input lang={lang} value={line.title} onChange={(e) => setLine(lang, { title: e.target.value })} aria-invalid={line.title.length > limits.title || !!error?.field(`languages.${lang}.title`)} aria-describedby={`cnt-title-${lang}`} />
          </Field>
          <div id={`cnt-title-${lang}`} className={`pub-counter ${line.title.length > limits.title ? 'over' : ''}`}><span>{orientation === 'portrait' ? 'Portrait' : 'Paysage'}</span><span>{line.title.length} / {limits.title} caractères</span></div>
          <Field label={`Texte (${langLabel(lang)})`} error={error?.field(`languages.${lang}.body`)}>
            <textarea lang={lang} value={line.body} onChange={(e) => setLine(lang, { body: e.target.value })} style={{ height: 140 }} aria-invalid={line.body.length > limits.body} aria-describedby={`cnt-body-${lang}`} />
          </Field>
          <div id={`cnt-body-${lang}`} className={`pub-counter ${line.body.length > limits.body ? 'over' : ''}`}><span>{line.body.length > limits.body ? 'Texte trop long : raccourcissez-le.' : ''}</span><span>{line.body.length} / {limits.body} caractères</span></div>
          {hasVideo && (
            <div style={{ marginTop: 14 }}>
              <strong className="small">Sous-titres WebVTT ({lang.toUpperCase()}) <em className="red-text">*</em></strong>
              <p className="muted small" style={{ margin: '4px 0 8px' }}>Une vidéo diffusée sur écran muet exige des sous-titres dans chaque langue.</p>
              {line.subtitles_asset_version_id ? <Status tone="success">Fichier associé</Status> : <Status tone="danger">Sous-titres manquants</Status>}
              <label className="button secondary small" style={{ marginLeft: 8, cursor: readOnly ? 'not-allowed' : 'pointer' }}>
                <Icon name="upload" size={14} /> {uploadingSub === lang ? 'Dépôt…' : line.subtitles_asset_version_id ? 'Remplacer' : 'Déposer un .vtt'}
                <input type="file" accept=".vtt,text/vtt" className="sr-only" disabled={readOnly || uploadingSub !== null} onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadSubtitles(lang, f); e.target.value = '' }} />
              </label>
            </div>
          )}
        </div>
        <div>
          <TemplatePreview
            layout={layout} orientation={orientation} title={line.title} body={line.body} lang={lang}
            kicker={refs.data?.categories.find((c) => c.id === form.category_id)?.name}
            imageUrl={mainImage ? fileUrl(`/asset-versions/${mainImage.item.asset_version_id}/file`) : null}
            mediaLabel={pdfItem ? `PDF · ${(pdfItem.item.pages ?? []).length || pdfItem.asset?.current_version?.pages || ''} page(s)` : null}
            qrValue={form.qr_enabled ? qrValue : null}
            onOverflow={(o) => { if (hasText(form.languages[lang]) && (form.languages[lang]?.overflow ?? false) !== o) setLine(lang, { overflow: o }) }}
          />
          {hasText(line) && (line.overflow
            ? <div className="warning-box"><Icon name="alert" /><span><strong>Le texte déborde du modèle.</strong><small>Raccourcissez le titre ou le texte : la soumission sera refusée.</small></span></div>
            : <div className="success-box" style={{ marginTop: 14 }}><Icon name="check" /><span><strong>La version {langLabel(lang).toLowerCase()} tient dans le modèle.</strong><small>Aucun débordement mesuré dans l’aperçu {orientation === 'portrait' ? '9:16' : '16:9'}.</small></span></div>)}
        </div>
      </div>
    </>
  )

  const checks: { ok: boolean; warn?: boolean; label: string }[] = [
    { ok: !infoErrors.title && !infoErrors.category_id, label: 'Métadonnées complètes (titre, catégorie, propriétaire)' },
    { ok: !infoErrors.expires_at, label: form.is_permanent ? 'Contenu permanent' : 'Date d’expiration renseignée' },
    { ok: form.classification !== 'restreint', label: form.classification === 'restreint' ? 'Contenu restreint : exclu des écrans' : `Classification ${CLASS_LABEL[form.classification]?.toLowerCase()} diffusable` },
    { ok: mediaAssets.every((m) => m.asset?.status === 'ready'), warn: mediaAssets.some((m) => !m.asset), label: form.media.length ? 'Médias sains et prêts' : 'Aucun média (texte seul)' },
    { ok: !mediaAssets.some((m) => form.classification === 'public' && m.asset?.classification === 'restreint'), label: 'Classification des médias compatible' },
    ...filledLangs.map((c) => ({ ok: langErrors(c).length === 0, label: langErrors(c).length ? `Version ${c.toUpperCase()} : ${langErrors(c).join(', ')}` : `Rendu ${c.toUpperCase()} sans débordement${hasVideo ? ', sous-titres présents' : ''}` })),
    { ok: filledLangs.length > 0, label: filledLangs.length ? `${filledLangs.length} version(s) linguistique(s) saisie(s)` : 'Aucune version linguistique saisie' },
    { ok: !dirty && !!id, warn: dirty, label: dirty || !id ? 'Modifications non enregistrées' : 'Brouillon enregistré' },
  ]
  const stepCheck = (
    <>
      <h3>Vérification avant soumission</h3>
      <p>Les contrôles bloquants sont rejoués par le serveur au moment de la soumission.</p>
      <div className="check-list">
        {checks.map((c) => (
          <span key={c.label} className={c.ok ? '' : c.warn ? 'warn' : 'fail'}>
            <Icon name={c.ok ? 'check' : 'alert'} /> {c.label}<span className="sr-only">{c.ok ? ' — conforme' : ' — à corriger'}</span>
          </span>
        ))}
      </div>
    </>
  )

  const stepSubmit = (
    <>
      <h3>Soumission par langue</h3>
      <p>Chaque version est transmise à un validateur distinct de l’auteur, habilité pour la langue (HAB-02).</p>
      {!id || !detail ? <Notice tone="info">Enregistrez d’abord le brouillon pour pouvoir le soumettre.</Notice> : (
        <>
          {dirty && <Notice tone="warning">Des modifications ne sont pas enregistrées : enregistrez le brouillon avant de soumettre.</Notice>}
          {submittable.length === 0 ? <p className="muted small">Aucune version en brouillon ou à corriger : rien à soumettre.</p> : (
            <div className="pub-select-list" style={{ margin: '10px 0' }}>
              {submittable.map((l) => (
                <label key={l.lang}>
                  <input type="checkbox" checked={submitLangs.includes(l.lang)} disabled={readOnly} onChange={(e) => setSubmitLangs((s) => (e.target.checked ? [...s, l.lang] : s.filter((x) => x !== l.lang)))} />
                  <span>{langLabel(l.lang)} · révision {l.revision}<small>{l.title}</small></span>
                  <StatusOf map={LANG_STATUS} value={l.status} />
                </label>
              ))}
            </div>
          )}
          <Button icon="check" onClick={submit} busy={submitting} disabled={readOnly || dirty || submitLangs.length === 0}>Soumettre {submitLangs.length ? `${submitLangs.length} langue(s)` : ''}</Button>
          {openApprovals.map((a) => (
            <div className="workflow-card" key={a.id} style={{ marginTop: 14 }}>
              <div className="avatar">{detail.author?.initials ?? '—'}</div>
              <div><strong>{detail.author?.name ?? 'Auteur'} <small>Auteur</small></strong><p>Version {a.lang.toUpperCase()} · révision {a.revision}</p></div>
              <Icon name="arrow" />
              <div className="avatar dark">{a.assignee?.initials ?? '?'}</div>
              <div>
                <strong>{a.assignee?.name ?? 'Aucun validateur disponible'} <small>Validateur {a.lang.toUpperCase()}</small></strong>
                <p>Décision attendue avant le {fmtDateTime(a.due_at)}{a.escalated_at ? ` · escaladée ${fmtRelative(a.escalated_at)}` : ''}{a.backup ? ` · suppléant : ${a.backup.name}` : ''}</p>
              </div>
            </div>
          ))}
        </>
      )}
    </>
  )

  const content = [stepInfo, stepComposition, stepLanguages, stepCheck, stepSubmit][step]

  return (
    <>
      <PageHeading
        title={detail ? detail.title : 'Nouveau contenu'}
        description={detail ? `${detail.reference} · version ${detail.version} · ${detail.author ? `auteur ${detail.author.name}` : ''}` : 'Composition d’une annonce multilingue.'}
        demo={me.demo_mode}
        actions={<>
          <Button variant="secondary" icon="list" onClick={() => navigate('/affichage/contenus')}>Liste des contenus</Button>
          {detail && detail.permissions.validate_languages.length > 0 && detail.languages.some((l) => l.status === 'submitted' && detail.permissions.validate_languages.includes(l.lang)) && (
            <Button variant="secondary" icon="check" onClick={() => navigate(`/affichage/contenus/${detail.id}/validation?lang=${detail.languages.find((l) => l.status === 'submitted' && detail.permissions.validate_languages.includes(l.lang))!.lang}`)}>Valider</Button>
          )}
        </>}
      />
      {readOnly && <Notice tone="info" icon="shield">Lecture seule : vous ne pouvez pas modifier ce contenu (statut ou périmètre).</Notice>}
      {reopened && !readOnly && <div className="warning-box" style={{ marginTop: 0, marginBottom: 12 }}><Icon name="alert" /><span><strong>Une version est approuvée ou en validation.</strong><small>Toute modification du texte d’une langue — ou du média, de la classification, de l’expiration ou du QR — crée une nouvelle révision à revalider. Les campagnes continuent d’utiliser la dernière version approuvée.</small></span></div>}
      {conflict && (
        <Notice tone="danger">
          <strong>Conflit de version.</strong> Ce contenu a été modifié par une autre personne. Vos saisies sont conservées à l’écran ; rechargez la version courante pour reprendre (vos modifications non enregistrées seront perdues).
          <div style={{ marginTop: 8 }}><Button variant="secondary" size="small" icon="refresh" onClick={reload}>Recharger la version courante</Button></div>
        </Notice>
      )}
      {error && !conflict && <div style={{ marginBottom: 12 }}><Notice tone="danger"><strong>{error.message}</strong>{error.code && STEP_OF_CODE[error.code] !== undefined && <> — voir l’étape « {STEPS[STEP_OF_CODE[error.code]]} ».</>}</Notice></div>}
      <div className="pub-editor-grid">
        <section className="flow-modal pub-flow" aria-label="Assistant de composition">
          <header>
            <div><small>COMPOSITION DE CONTENU</small><h2>{STEPS[step]}</h2></div>
            {detail && <StatusOf map={CONTENT_STATUS} value={detail.status} />}
          </header>
          <Stepper steps={STEPS} current={step} onSelect={goStep} />
          <div className="flow-content">
            <div className="step-label">ÉTAPE {step + 1} SUR {STEPS.length}</div>
            {step === 2 && langTabs}
            <fieldset disabled={readOnly} style={{ border: 0, padding: 0, margin: 0, minWidth: 0 }}>{content}</fieldset>
          </div>
          <footer>
            <Button variant="secondary" onClick={() => (step ? setStep(step - 1) : navigate('/affichage/contenus'))}>{step ? 'Retour' : 'Annuler'}</Button>
            <div className="footer-note"><Icon name="shield" size={14} /> Action tracée dans le journal</div>
            <span className="pub-spacer" />
            {!readOnly && <Button variant="secondary" icon="check" onClick={save} busy={saving} disabled={!!id && !dirty}>{id ? (dirty ? 'Enregistrer le brouillon' : 'Enregistré') : 'Enregistrer le brouillon'}</Button>}
            {step < STEPS.length - 1 && <Button onClick={() => goStep(step + 1)}>Continuer <Icon name="arrow" size={15} /></Button>}
          </footer>
        </section>
        <aside className="pub-aside">
          <section className="panel">
            <div className="panel-title"><div><h2>État par langue</h2><p>Statut éditorial de chaque version</p></div></div>
            <div className="panel-body">
              {!detail || detail.languages.length === 0 ? <p className="muted small">Aucune version enregistrée.</p> : (
                <div className="timeline-list">
                  {detail.languages.map((l) => (
                    <div key={l.lang}>
                      <time>{langLabel(l.lang)} · rév. {l.revision}</time>
                      <span><StatusOf map={LANG_STATUS} value={l.status} />{l.submitted_at && <small className="muted"> soumise {fmtRelative(l.submitted_at)}</small>}{l.last_return_reason && <small className="red-text" style={{ display: 'block' }}>Motif : {l.last_return_reason}</small>}</span>
                    </div>
                  ))}
                </div>
              )}
              {detail && <p className="small muted" style={{ marginTop: 10 }}><Status tone={CLASS_TONE[detail.classification]}>{CLASS_LABEL[detail.classification]}</Status> · priorité {detail.priority}</p>}
            </div>
          </section>
          {returned.length > 0 && (
            <Notice tone="danger"><strong>Dernier motif de retour</strong>{returned.map((l) => <div key={l.lang}>{l.lang.toUpperCase()} : {l.last_return_reason}</div>)}</Notice>
          )}
          {detail && detail.approvals.length > 0 && (
            <section className="panel">
              <div className="panel-title"><div><h2>Validations</h2><p>Échéances, escalades et suppléants</p></div></div>
              <div className="panel-body timeline-list">
                {detail.approvals.map((a) => (
                  <div key={a.id}>
                    <time>{a.lang.toUpperCase()} · rév. {a.revision}</time>
                    <span>
                      <strong className="small">{a.status === 'open' ? 'En attente' : a.status === 'cancelled' ? 'Annulée (révision)' : a.decision === 'approved' ? 'Approuvée' : 'Retournée'}</strong>
                      <small className="muted" style={{ display: 'block' }}>
                        {a.status === 'open' ? `${a.assignee?.name ?? 'non assignée'} · échéance ${fmtDateTime(a.due_at)}` : `${a.decider?.name ?? '—'} · ${fmtDateTime(a.decided_at)}`}
                        {a.escalated_at && ` · escaladée le ${fmtDateTime(a.escalated_at)}`}{a.backup && a.status === 'open' && ` · suppléant ${a.backup.name}`}
                      </small>
                      {a.reason && <small style={{ display: 'block' }}>« {a.reason} »</small>}
                    </span>
                  </div>
                ))}
              </div>
            </section>
          )}
          {detail && (
            <section className="panel">
              <div className="panel-title"><div><h2>Historique</h2><p>Chronologie du cycle éditorial</p></div></div>
              <div className="panel-body timeline-list">
                {detail.history.length === 0 ? <p className="muted small">Aucun événement.</p> : detail.history.map((h, i) => (
                  <div key={i}>
                    <time>{fmtDateTime(h.at)}</time>
                    <span><strong className="small">{HISTORY_LABEL[h.action] ?? h.action}{h.lang ? ` · ${h.lang.toUpperCase()}` : ''}{h.revision ? ` rév. ${h.revision}` : ''}</strong><small className="muted" style={{ display: 'block' }}>{h.actor ?? 'Système'}{h.role ? ` (${h.role})` : ''}</small>{h.reason && <small style={{ display: 'block' }}>« {h.reason} »</small>}</span>
                  </div>
                ))}
              </div>
            </section>
          )}
          {detail && detail.campaigns.length > 0 && (
            <section className="panel">
              <div className="panel-title"><div><h2>Campagnes</h2><p>Programmations utilisant ce contenu</p></div></div>
              <div className="panel-body timeline-list">
                {detail.campaigns.map((c) => <div key={c.id}><time>{c.reference}</time><a className="link-text" href={`/affichage/diffusions/${c.id}`} onClick={(e) => { e.preventDefault(); navigate(`/affichage/diffusions/${c.id}`) }}>{c.name}</a></div>)}
              </div>
            </section>
          )}
        </aside>
      </div>
      {picker && (
        <MediaPicker
          onClose={() => setPicker(false)}
          exclude={form.media.map((m) => m.asset_version_id)}
          onPick={(a) => {
            const v = a.current_version!
            setPickedAssets((p) => ({ ...p, [v.id]: a }))
            set('media', [...form.media, { asset_version_id: v.id, role: 'main', pages: a.kind === 'pdf' && v.pages ? Array.from({ length: Math.min(v.pages, 50) }, (_, i) => i + 1) : null, page_duration_s: a.kind === 'pdf' ? 12 : null }])
            setPicker(false)
          }}
        />
      )}
    </>
  )
}

function QrDocumentFields({ form, set, error }: { form: Form; set: <K extends keyof Form>(key: K, value: Form[K]) => void; error: ApiError | null }) {
  const docs = useQuery({
    queryKey: ['documents', 'qr-choices'],
    queryFn: ({ signal }) => api.get<Paginated<{ id: string; reference: string; title: string; classification: string; status: string }>>('/documents', { status: 'published', per_page: 500 }, signal).then((r) => r.data),
  })
  return (
    <div className="form-grid" style={{ marginTop: 10 }}>
      <Field label="Document lié" error={error?.field('qr.document_id')} help="Sans document, le QR renvoie vers la fiche du contenu. Seuls les documents publiés sont proposés.">
        <select value={form.qr_document_id} onChange={(e) => set('qr_document_id', e.target.value)} disabled={docs.isLoading}>
          <option value="">— Aucun document —</option>
          {(docs.data ?? []).filter((d) => d.classification !== 'restreint').map((d) => <option key={d.id} value={d.id}>{d.reference} · {d.title}</option>)}
        </select>
      </Field>
      <Field label="Libellé du QR" error={error?.field('qr.label')} help="60 caractères maximum.">
        <input value={form.qr_label} maxLength={60} onChange={(e) => set('qr_label', e.target.value)} />
      </Field>
      {docs.isError && <Notice tone="warning">Documents indisponibles : {errorText(docs.error)}</Notice>}
    </div>
  )
}

/** Sélecteur de la médiathèque : seuls les médias sains (« Prêt ») sont proposés. */
function MediaPicker({ onClose, onPick, exclude }: { onClose: () => void; onPick: (a: Asset) => void; exclude: string[] }) {
  const [q, setQ] = useState('')
  const [kind, setKind] = useState('')
  const search = useDebounced(q)
  const list = useList<Asset>('assets', '/assets', { ready_only: true, q: search || undefined, kind: kind || undefined }, 24)
  return (
    <Modal title="Choisir un média" kicker="MÉDIATHÈQUE" wide onClose={onClose} footer={<Button variant="secondary" onClick={onClose}>Fermer</Button>}>
      <Toolbar value={q} onChange={(v) => { setQ(v); list.setPage(1) }} placeholder="Rechercher un média sain…">
        <Select label="Type" allLabel="Tous types diffusables" value={kind} onChange={(v) => { setKind(v); list.setPage(1) }} options={['image', 'video', 'pdf'].map((k) => ({ value: k, label: KIND_LABEL[k] }))} />
      </Toolbar>
      {list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? <EmptyState title="Aucun média prêt" text="Déposez un fichier dans la médiathèque puis attendez la fin de l’analyse." /> : (
        <div className="pub-picker-grid" style={{ marginTop: 12 }}>
          {list.items.map((a) => {
            const v = a.current_version
            const disabled = !v || a.kind === 'office' || exclude.includes(v.id)
            return (
              <button type="button" className="media-card" key={a.id} disabled={disabled} onClick={() => onPick(a)} title={a.kind === 'office' ? 'Fichier bureautique non diffusable sur écran' : undefined}>
                <div className={`media-preview ${a.kind === 'video' ? 'video' : a.kind === 'pdf' ? 'pdf' : ''}`} style={{ height: 96 }}>
                  {v?.has_thumbnail ? <img src={fileUrl(`/asset-versions/${v.id}/thumbnail`)} alt="" /> : <Icon name={KIND_ICON[a.kind]} size={28} />}
                </div>
                <div className="media-body">
                  <div><strong>{a.title}</strong></div>
                  <small>{KIND_LABEL[a.kind]} · {fmtBytes(v?.size)}{v?.pages ? ` · ${v.pages} p.` : ''}{v?.duration_s ? ` · ${v.duration_s} s` : ''}</small>
                  <div className="media-foot"><Status tone={CLASS_TONE[a.classification]}>{CLASS_LABEL[a.classification]}</Status><span>{exclude.includes(v?.id ?? '') ? 'Déjà ajouté' : `v${v?.version ?? '—'}`}</span></div>
                </div>
              </button>
            )
          })}
        </div>
      )}
      <div style={{ marginTop: 8 }}>{list.meta && list.meta.last_page > 1 && <div className="row-actions"><Button variant="secondary" size="small" disabled={list.page <= 1} onClick={() => list.setPage(list.page - 1)}>Précédent</Button><Button variant="secondary" size="small" disabled={list.page >= list.meta.last_page} onClick={() => list.setPage(list.page + 1)}>Suivant</Button></div>}</div>
    </Modal>
  )
}
