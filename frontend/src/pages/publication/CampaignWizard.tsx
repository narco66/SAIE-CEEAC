import { useEffect, useMemo, useState } from 'react'
import { useLocation, useNavigate, useParams } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, type Paginated } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useData, useList } from '../../lib/hooks'
import { addDays, CAMPAIGN_STATUS, CLASS_LABEL, CLASS_TONE, DAYS, NETWORK_STATUS, todayLocal, toIsoFromLocal } from '../../lib/format'
import { Button, EmptyState, ErrorState, Field, Icon, Loading, Notice, PageHeading, Status, StatusOf, Tabs, Toolbar, useDebounced, useToast, useUnsavedWarning } from '../../components/ui'
import {
  errorText, langLabel, LocalDateTime, splitLocal, Stepper, useReferentials,
  type Campaign, type CampaignPreview, type Content,
} from './shared'

interface Item { content_id: string; title: string; reference: string; classification: string; duration_s: number | null; approved: string[] }
interface Form {
  name: string; kind: 'standard' | 'event'; priority: number; start_date: string; start_time: string; end_date: string; end_time: string
  days: number[]; time_from: string; time_to: string; slide_duration_s: number
  items: Item[]; languages: string[]; targets: { type: 'screen' | 'group' | 'zone' | 'floor'; id: string }[]
}
interface CampaignDetail extends Campaign { approved_languages: Record<string, string[]>; preview: CampaignPreview }
interface ScreenRow { id: string; code: string; name: string; kind: string; location: { name: string; floor: { label: string } | null; zone: { name: string; max_classification: string } | null } | null; device: { network_status: string } | null }
interface GroupRow { id: string; code: string; name: string; screens_count: number }

const STEPS = ['Version approuvée', 'Langues', 'Cibles', 'Calendrier', 'Vérification']
const FIELD_STEP: [RegExp, number][] = [[/^(name|items)/, 0], [/^languages/, 1], [/^targets/, 2], [/^(starts_at|ends_at|days|time_|priority|slide_duration_s)/, 3]]
const CODE_STEP: Record<string, number> = { EVENT_TOO_LONG: 3, NO_APPROVED_LANGUAGE: 1, CONTENT_NOT_DIFFUSABLE: 0, NO_COMPATIBLE_TARGET: 2, CAMPAIGN_EXPIRED: 3, CAMPAIGN_INCOMPLETE: 0 }
const TARGET_LABEL = { screen: 'Écran', group: 'Groupe', zone: 'Zone', floor: 'Niveau' } as const

function emptyForm(): Form {
  const start = todayLocal()
  return {
    name: '', kind: 'standard', priority: 50, start_date: start, start_time: '08:00', end_date: addDays(start, 7), end_time: '18:00', days: [1, 2, 3, 4, 5, 6, 7], time_from: '', time_to: '',
    slide_duration_s: 12, items: [], languages: [], targets: [],
  }
}

function fromCampaign(c: CampaignDetail): Form {
  const s = splitLocal(c.starts_at)
  const e = splitLocal(c.ends_at)
  return {
    name: c.name, kind: c.kind ?? 'standard', priority: c.priority, start_date: s.date, start_time: s.time, end_date: e.date, end_time: e.time, days: c.days?.length ? c.days : [1, 2, 3, 4, 5, 6, 7],
    time_from: c.time_from ?? '', time_to: c.time_to ?? '', slide_duration_s: c.slide_duration_s ?? 12,
    items: [...c.items].sort((a, b) => a.position - b.position).filter((i) => i.content).map((i) => ({
      content_id: i.content!.id, title: i.content!.title, reference: i.content!.reference, classification: i.content!.classification, duration_s: i.duration_s,
      approved: c.approved_languages?.[i.content!.id] ?? [],
    })),
    languages: [...c.languages].sort((a, b) => a.rank - b.rank).map((l) => l.lang),
    targets: c.targets.filter((t) => t.type !== 'all' && t.id).map((t) => ({ type: t.type as Form['targets'][number]['type'], id: t.id! })),
  }
}

function payload(f: Form) {
  return {
    name: f.name.trim(), kind: f.kind, priority: f.priority, starts_at: toIsoFromLocal(f.start_date, f.start_time || '00:00'), ends_at: toIsoFromLocal(f.end_date, f.end_time || '23:59'),
    days: f.days.length === 7 ? [] : [...f.days].sort(), time_from: f.time_from || null, time_to: f.time_to || null, slide_duration_s: f.slide_duration_s,
    items: f.items.map((i) => ({ content_id: i.content_id, duration_s: i.duration_s })), languages: f.languages, targets: f.targets,
  }
}

/** V21 Assistant de campagne : versions approuvées, rotation des langues, cibles, calendrier et vérification (PUB-02, PUB-04, PUB-08). */
export default function CampaignWizard() {
  const { id } = useParams()
  const location = useLocation()
  const navigate = useNavigate()
  const client = useQueryClient()
  const toast = useToast()
  const { me } = useAuth()
  const initial = location.state as { step?: number; error?: { code: string; message: string } } | null

  const detailQuery = useQuery({
    queryKey: ['campaign', id],
    queryFn: ({ signal }) => api.get<{ data: CampaignDetail }>(`/campaigns/${id}`, undefined, signal).then((r) => r.data),
    enabled: !!id, refetchOnWindowFocus: false, staleTime: Infinity,
  })
  const detail = detailQuery.data
  const refs = useReferentials()
  const [form, setForm] = useState<Form>(emptyForm)
  const [baseline, setBaseline] = useState<string>(() => JSON.stringify(emptyForm()))
  const [loaded, setLoaded] = useState<number | null>(null)
  const [step, setStep] = useState(initial?.step ?? 0)
  const [error, setError] = useState<ApiError | null>(initial?.error ? new ApiError(422, initial.error) : null)
  const [showErrors, setShowErrors] = useState(false)
  const [saving, setSaving] = useState<'draft' | 'publish' | null>(null)

  // Nouvelle campagne : la durée de diapositive proposée est celle des paramètres (V39), tant que rien n'a été saisi.
  useEffect(() => {
    const preset = refs.data?.defaults?.slide_duration_s
    if (!id && preset && JSON.stringify(form) === baseline && form.slide_duration_s !== preset) {
      const f = { ...form, slide_duration_s: preset }
      setForm(f)
      setBaseline(JSON.stringify(f))
    }
  }, [id, refs.data, form, baseline])

  useEffect(() => {
    if (detail && loaded === null) {
      const f = fromCampaign(detail)
      setForm(f)
      setBaseline(JSON.stringify(f))
      setLoaded(detail.version)
    }
  }, [detail, loaded])

  const dirty = JSON.stringify(form) !== baseline
  useUnsavedWarning(dirty)
  const locked = !!detail && !['draft', 'suspended'].includes(detail.status)
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm((f) => ({ ...f, [k]: v }))

  /* ===== Contrôles par étape ===== */
  const stepErrors = (s: number): string[] => {
    const errs: string[] = []
    if (s === 0) {
      if (!form.name.trim()) errs.push('Le nom de la campagne est obligatoire.')
      if (!form.items.length) errs.push('Ajoutez au moins un contenu approuvé.')
    }
    if (s === 1) {
      if (!form.languages.length) errs.push('Choisissez au moins une langue de rotation.')
      form.items.filter((i) => !i.approved.some((l) => form.languages.includes(l))).forEach((i) => errs.push(`${i.reference} n’a aucune version approuvée dans les langues choisies.`))
    }
    if (s === 2 && !form.targets.length) errs.push('Choisissez au moins une cible.')
    if (s === 3) {
      if (!form.start_date || !form.end_date) errs.push('Les dates de début et de fin sont obligatoires.')
      else if (toIsoFromLocal(form.end_date, form.end_time || '23:59') <= toIsoFromLocal(form.start_date, form.start_time || '00:00')) errs.push('La fin doit être postérieure au début.')
      if (!form.days.length) errs.push('Choisissez au moins un jour de la semaine.')
      if ((form.time_from && !form.time_to) || (!form.time_from && form.time_to)) errs.push('Renseignez les deux bornes du créneau horaire ou aucune.')
      if (form.time_from && form.time_to && form.time_to <= form.time_from) errs.push('La fin du créneau doit suivre son début.')
      if (form.priority < 1 || form.priority > 99) errs.push('La priorité est comprise entre 1 et 99.')
      if (form.slide_duration_s < 10 || form.slide_duration_s > 300) errs.push('La durée de diapositive est comprise entre 10 et 300 s.')
      form.items.filter((i) => i.duration_s !== null && (i.duration_s < 10 || i.duration_s > 600)).forEach((i) => errs.push(`Durée de ${i.reference} : entre 10 et 600 s.`))
    }
    return errs
  }
  const firstInvalid = [0, 1, 2, 3].find((s) => stepErrors(s).length > 0)
  const goStep = (s: number) => {
    setShowErrors(true)
    if (firstInvalid !== undefined && s > firstInvalid) { setStep(firstInvalid); return }
    setShowErrors(false)
    setStep(s)
  }

  const handleError = (e: unknown) => {
    const err = e instanceof ApiError ? e : new ApiError(500, { message: errorText(e) })
    setError(err)
    const key = Object.keys(err.errors)[0]
    const fieldStep = key ? FIELD_STEP.find(([re]) => re.test(key))?.[1] : undefined
    if (fieldStep !== undefined) setStep(fieldStep)
    else if (CODE_STEP[err.code] !== undefined) setStep(CODE_STEP[err.code])
  }

  const save = async (publish: boolean) => {
    setError(null)
    setShowErrors(true)
    if (firstInvalid !== undefined) { setStep(firstInvalid); return }
    setSaving(publish ? 'publish' : 'draft')
    let current: CampaignDetail | undefined = detail
    try {
      if (!id) {
        current = (await api.post<{ data: CampaignDetail }>('/campaigns', payload(form))).data
        client.invalidateQueries({ queryKey: ['campaigns'] })
      } else if (dirty) {
        current = (await api.patch<{ data: CampaignDetail }>(`/campaigns/${id}`, payload(form), { 'If-Match': String(detail!.version) })).data
        client.invalidateQueries({ queryKey: ['campaigns'] })
      }
      setBaseline(JSON.stringify(form))
      if (!publish) {
        toast.success('Brouillon enregistré', `${current!.reference} · ${current!.name}`)
        if (!id) navigate(`/affichage/campagnes/${current!.id}/modifier`, { replace: true, state: { step, afterSave: true } })
        else { client.setQueryData(['campaign', id], current); setLoaded(current!.version) }
        return
      }
      try {
        const res = await api.post<{ data: CampaignDetail }>(`/campaigns/${current!.id}/${current!.status === 'suspended' ? 'resume' : 'publish'}`)
        client.invalidateQueries({ queryKey: ['campaigns'] })
        client.invalidateQueries({ queryKey: ['contents'] })
        toast.success(res.data.status === 'active' ? 'Campagne en diffusion' : 'Campagne programmée', `${res.data.reference} · ${res.data.preview?.effective ?? 0} écran(s) effectif(s).`)
        navigate(`/affichage/diffusions/${res.data.id}`, { state: { afterSave: true } })
      } catch (e) {
        if (!id) {
          const err = e instanceof ApiError ? e : new ApiError(500, { message: errorText(e) })
          toast.info('Brouillon enregistré', 'La programmation a été refusée : corrigez puis réessayez.')
          navigate(`/affichage/campagnes/${current!.id}/modifier`, { replace: true, state: { afterSave: true, step: CODE_STEP[err.code] ?? 4, error: { code: err.code, message: err.message } } })
          return
        }
        client.setQueryData(['campaign', id], current)
        throw e
      }
    } catch (e) {
      handleError(e)
    } finally {
      setSaving(null)
    }
  }

  const reload = async () => {
    const res = await detailQuery.refetch()
    if (res.data) { const f = fromCampaign(res.data); setForm(f); setBaseline(JSON.stringify(f)); setLoaded(res.data.version); setError(null) }
  }

  if (id && detailQuery.isLoading) return <Loading />
  if (id && (detailQuery.isError || !detail)) return <ErrorState error={detailQuery.error} onRetry={() => detailQuery.refetch()} />

  const errs = showErrors ? stepErrors(step) : []
  const body = [
    <StepItems key="0" form={form} set={set} />,
    <StepLanguages key="1" form={form} set={set} active={(refs.data?.languages ?? []).filter((l) => l.is_active).sort((a, b) => a.sort - b.sort).map((l) => l.code)} />,
    <StepTargets key="2" form={form} set={set} />,
    <StepSchedule key="3" form={form} set={set} error={error} />,
    <StepReview key="4" form={form} campaignId={id} />,
  ][step]

  return (
    <>
      <PageHeading
        title={detail ? `Modifier ${detail.name}` : 'Programmer une campagne'}
        description={detail ? `${detail.reference} · version ${detail.version}` : 'Seules les versions approuvées et immuables peuvent être programmées.'}
        demo={me.demo_mode}
        actions={<Button variant="secondary" icon="list" onClick={() => navigate('/affichage/campagnes')}>Campagnes</Button>}
      />
      {locked && <Notice tone="warning">Cette campagne est « {CAMPAIGN_STATUS[detail!.status]?.[0]} » : suspendez-la depuis la liste avant de la modifier.</Notice>}
      {error && (
        <div style={{ marginBottom: 12 }}>
          <Notice tone="danger">
            <strong>{error.message}</strong>
            {Object.entries(error.errors).slice(0, 6).map(([k, v]) => <div key={k} className="small">{k} : {v[0]}</div>)}
            {error.code === 'VERSION_CONFLICT' && <div style={{ marginTop: 6 }}><Button variant="secondary" size="small" icon="refresh" onClick={reload}>Recharger la version courante</Button></div>}
          </Notice>
        </div>
      )}
      <section className="flow-modal pub-flow" aria-label="Assistant de campagne">
        <header>
          <div><small>ASSISTANT DE CAMPAGNE · HEURE DE LIBREVILLE (UTC+1)</small><h2>{STEPS[step]}</h2></div>
          {detail && <StatusOf map={CAMPAIGN_STATUS} value={detail.status} />}
        </header>
        <Stepper steps={STEPS} current={step} onSelect={goStep} />
        <div className="flow-content">
          <div className="step-label">ÉTAPE {step + 1} SUR {STEPS.length}</div>
          <fieldset disabled={locked} style={{ border: 0, padding: 0, margin: 0, minWidth: 0 }}>{body}</fieldset>
          {errs.length > 0 && <div className="warning-box" role="alert"><Icon name="alert" /><span><strong>À compléter avant de continuer</strong>{errs.map((e) => <small key={e}>{e}</small>)}</span></div>}
        </div>
        <footer>
          <Button variant="secondary" onClick={() => (step ? setStep(step - 1) : navigate('/affichage/campagnes'))}>{step ? 'Retour' : 'Annuler'}</Button>
          <div className="footer-note"><Icon name="shield" size={14} /> Action tracée dans le journal</div>
          <span className="pub-spacer" />
          {!locked && <Button variant="secondary" onClick={() => save(false)} busy={saving === 'draft'} disabled={saving !== null}>Enregistrer en brouillon</Button>}
          {step < STEPS.length - 1
            ? <Button onClick={() => goStep(step + 1)}>Continuer <Icon name="arrow" size={15} /></Button>
            : !locked && <Button icon="play" onClick={() => save(true)} busy={saving === 'publish'} disabled={saving !== null}>{detail?.status === 'suspended' ? 'Reprendre la diffusion' : 'Programmer'}</Button>}
        </footer>
      </section>
    </>
  )
}

type SetFn = <K extends keyof Form>(k: K, v: Form[K]) => void

function move<T>(list: T[], from: number, to: number): T[] {
  if (to < 0 || to >= list.length) return list
  const copy = [...list]
  const [x] = copy.splice(from, 1)
  copy.splice(to, 0, x)
  return copy
}

function StepItems({ form, set }: { form: Form; set: SetFn }) {
  const [q, setQ] = useState('')
  const search = useDebounced(q)
  const list = useList<Content>('contents', '/contents', { approved_only: true, q: search || undefined }, 20)
  const add = (c: Content) => {
    const approved = [...new Set((c.approved_versions ?? []).map((v) => v.lang))]
    set('items', [...form.items, { content_id: c.id, title: c.title, reference: c.reference, classification: c.classification, duration_s: null, approved }])
  }
  return (
    <>
      <h3>Versions approuvées</h3>
      <p>Composez la liste de lecture à partir de contenus ayant au moins une version approuvée. L’ordre de la liste est l’ordre de passage.</p>
      <div className="form-grid" style={{ marginBottom: 16 }}>
        <Field label="Nom de la campagne" required wide>
          <input value={form.name} maxLength={160} onChange={(e) => set('name', e.target.value)} aria-invalid={!form.name.trim()} />
        </Field>
      </div>
      <div className="playlist" style={{ marginBottom: 16 }}>
        <strong>Liste de lecture ({form.items.length})</strong>
        {form.items.length === 0 && <div><span /><span /><p className="muted">Aucun contenu ajouté.</p><span /></div>}
        {form.items.map((i, idx) => (
          <div key={i.content_id}>
            <span>{idx + 1}</span>
            <Icon name="file" />
            <p>{i.title}<small>{i.reference} · {CLASS_LABEL[i.classification]} · approuvé : {i.approved.map((l) => l.toUpperCase()).join(', ') || 'aucune langue'}</small></p>
            <div className="row-actions">
              <label className="sr-only" htmlFor={`dur-${i.content_id}`}>Durée de {i.title} (secondes)</label>
              <input id={`dur-${i.content_id}`} type="number" min={10} max={600} placeholder={`${form.slide_duration_s} s`} value={i.duration_s ?? ''} title="Durée d’affichage en secondes (vide = durée de diapositive de la campagne)"
                onChange={(e) => set('items', form.items.map((x, k) => (k === idx ? { ...x, duration_s: e.target.value ? Number(e.target.value) : null } : x)))} />
              <Button variant="ghost" size="small" icon="arrow" ariaLabel={`Monter ${i.title}`} disabled={idx === 0} onClick={() => set('items', move(form.items, idx, idx - 1))} />
              <Button variant="ghost" size="small" icon="chevron" ariaLabel={`Descendre ${i.title}`} disabled={idx === form.items.length - 1} onClick={() => set('items', move(form.items, idx, idx + 1))} />
              <Button variant="ghost" size="small" icon="trash" ariaLabel={`Retirer ${i.title}`} onClick={() => set('items', form.items.filter((_, k) => k !== idx))} />
            </div>
          </div>
        ))}
      </div>
      <div className="panel">
        <Toolbar value={q} onChange={(v) => { setQ(v); list.setPage(1) }} placeholder="Rechercher un contenu approuvé…" />
        {list.isLoading ? <Loading /> : list.isError ? <ErrorState error={list.error} onRetry={() => list.refetch()} /> : list.items.length === 0 ? <EmptyState title="Aucun contenu approuvé" text="Un contenu doit être approuvé dans au moins une langue pour être programmé." /> : (
          <div className="table-scroll">
            <table>
              <thead><tr><th>Contenu</th><th>Classification</th><th>Versions approuvées</th><th><span className="sr-only">Ajouter</span></th></tr></thead>
              <tbody>
                {list.items.map((c) => {
                  const langs = [...new Set((c.approved_versions ?? []).map((v) => `${v.lang.toUpperCase()} rév. ${v.revision}`))]
                  const added = form.items.some((i) => i.content_id === c.id)
                  return (
                    <tr key={c.id}>
                      <td><strong>{c.title}</strong><small>{c.reference}</small></td>
                      <td><Status tone={CLASS_TONE[c.classification]}>{CLASS_LABEL[c.classification]}</Status></td>
                      <td>{langs.join(' · ') || '—'}</td>
                      <td>
                        {c.classification === 'restreint' ? <span className="red-text small">Non diffusable</span>
                          : <Button size="small" variant="secondary" icon={added ? 'check' : 'plus'} disabled={added || form.items.length >= 50} onClick={() => add(c)}>{added ? 'Ajouté' : 'Ajouter'}</Button>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
        {list.meta && list.meta.last_page > 1 && (
          <div className="pagination"><span>Page {list.page} / {list.meta.last_page}</span><div><button disabled={list.page <= 1} onClick={() => list.setPage(list.page - 1)}>Précédent</button><button disabled={list.page >= list.meta.last_page} onClick={() => list.setPage(list.page + 1)}>Suivant</button></div></div>
        )}
      </div>
    </>
  )
}

function StepLanguages({ form, set, active }: { form: Form; set: SetFn; active: string[] }) {
  const available = [...new Set([...active, ...form.languages])]
  return (
    <>
      <h3>Langues et ordre de rotation</h3>
      <p>Les versions sont diffusées dans l’ordre choisi (par exemple FR puis EN). Une langue non approuvée pour un contenu est ignorée pour ce contenu.</p>
      <div className="pub-select-list" style={{ marginBottom: 14 }}>
        {available.map((code) => (
          <label key={code}>
            <input type="checkbox" checked={form.languages.includes(code)} onChange={(e) => set('languages', e.target.checked ? [...form.languages, code] : form.languages.filter((l) => l !== code))} />
            <span>{langLabel(code)}<small>{active.includes(code) ? 'Langue active' : 'Langue désactivée dans les référentiels'}</small></span>
          </label>
        ))}
      </div>
      {form.languages.length > 0 && (
        <div className="playlist">
          <strong>Ordre de rotation</strong>
          {form.languages.map((code, idx) => (
            <div key={code}>
              <span>{idx + 1}</span><Icon name="refresh" />
              <p>{langLabel(code)}<small>{form.items.filter((i) => !i.approved.includes(code)).length ? `Ignorée pour : ${form.items.filter((i) => !i.approved.includes(code)).map((i) => i.reference).join(', ')}` : 'Approuvée pour tous les contenus'}</small></p>
              <div className="row-actions">
                <Button variant="ghost" size="small" icon="arrow" ariaLabel={`Avancer ${langLabel(code)}`} disabled={idx === 0} onClick={() => set('languages', move(form.languages, idx, idx - 1))} />
                <Button variant="ghost" size="small" icon="chevron" ariaLabel={`Reculer ${langLabel(code)}`} disabled={idx === form.languages.length - 1} onClick={() => set('languages', move(form.languages, idx, idx + 1))} />
              </div>
            </div>
          ))}
        </div>
      )}
      {form.items.some((i) => form.languages.some((l) => !i.approved.includes(l))) && (
        <div className="warning-box"><Icon name="alert" /><span><strong>Certaines langues ne sont pas approuvées pour tous les contenus</strong><small>Elles seront ignorées en rotation pour les contenus concernés ; aucune version non approuvée n’est diffusée.</small></span></div>
      )}
    </>
  )
}

function StepTargets({ form, set }: { form: Form; set: SetFn }) {
  const [tab, setTab] = useState<'screen' | 'group' | 'zone' | 'floor'>('group')
  const [q, setQ] = useState('')
  const refs = useReferentials()
  const screens = useQuery({ queryKey: ['screens', 'all'], queryFn: ({ signal }) => api.get<Paginated<ScreenRow>>('/screens', { per_page: 500 }, signal).then((r) => r.data) })
  const groups = useData<GroupRow[]>(['screen-groups'], '/screen-groups')
  const options = useMemo(() => {
    const term = q.toLowerCase()
    const all: Record<string, { id: string; label: string; help: string }[]> = {
      screen: (screens.data ?? []).map((s) => ({ id: s.id, label: `${s.code} · ${s.name}`, help: `${s.location?.floor?.label ?? '—'} · zone ${s.location?.zone?.name ?? '—'} (max. ${CLASS_LABEL[s.location?.zone?.max_classification ?? ''] ?? '—'})${s.kind !== 'display' ? ` · ${s.kind}` : ''}` })),
      group: (groups.data ?? []).map((g) => ({ id: g.id, label: g.name, help: `${g.code} · ${g.screens_count} écran(s)` })),
      zone: (refs.data?.zones ?? []).filter((z) => z.is_active).map((z) => ({ id: z.id, label: z.name, help: `${z.code} · classification max. ${CLASS_LABEL[z.max_classification] ?? z.max_classification}` })),
      floor: (refs.data?.floors ?? []).filter((f) => f.is_active).map((f) => ({ id: f.id, label: f.label, help: `${f.building?.name ?? ''} · niveau ${f.level}` })),
    }
    return { all, filtered: all[tab].filter((o) => !term || o.label.toLowerCase().includes(term) || o.help.toLowerCase().includes(term)) }
  }, [screens.data, groups.data, refs.data, tab, q])
  const labelOf = (t: Form['targets'][number]) => options.all[t.type].find((o) => o.id === t.id)?.label ?? t.id.slice(0, 8)
  const has = (id: string) => form.targets.some((t) => t.type === tab && t.id === id)
  const toggle = (id: string) => set('targets', has(id) ? form.targets.filter((t) => !(t.type === tab && t.id === id)) : [...form.targets, { type: tab, id }])
  const loading = tab === 'screen' ? screens.isLoading : tab === 'group' ? groups.isLoading : refs.isLoading
  const failed = tab === 'screen' ? screens.error : tab === 'group' ? groups.error : refs.error
  return (
    <>
      <h3>Cibles</h3>
      <p>Combinez écrans, groupes, zones et niveaux : les écrans effectifs sont dédoublonnés à l’étape de vérification.</p>
      <div className="field" style={{ marginBottom: 12 }}>
        <span>Cibles retenues ({form.targets.length})</span>
        <div className="target-box">
          {form.targets.length === 0 && <small className="muted">Aucune cible</small>}
          {form.targets.map((t) => (
            <span key={`${t.type}-${t.id}`}>{TARGET_LABEL[t.type]} · {labelOf(t)} <button type="button" aria-label={`Retirer ${labelOf(t)}`} onClick={() => set('targets', form.targets.filter((x) => !(x.type === t.type && x.id === t.id)))}><Icon name="x" size={13} /></button></span>
          ))}
        </div>
      </div>
      <Tabs value={tab} onChange={(v) => { setTab(v); setQ('') }} items={(['group', 'screen', 'zone', 'floor'] as const).map((v) => ({ value: v, label: `${TARGET_LABEL[v]}s`, count: form.targets.filter((t) => t.type === v).length }))} />
      <div className="search-field" style={{ marginBottom: 10 }}><Icon name="search" size={17} /><input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Filtrer…" aria-label="Filtrer les cibles" /></div>
      {loading ? <Loading /> : failed ? <ErrorState error={failed} /> : options.filtered.length === 0 ? <p className="muted small">Aucun élément.</p> : (
        <div className="pub-select-list">
          {options.filtered.map((o) => (
            <label key={o.id}>
              <input type="checkbox" checked={has(o.id)} onChange={() => toggle(o.id)} />
              <span>{o.label}<small>{o.help}</small></span>
            </label>
          ))}
        </div>
      )}
    </>
  )
}

function StepSchedule({ form, set, error }: { form: Form; set: SetFn; error: ApiError | null }) {
  return (
    <>
      <h3>Calendrier</h3>
      <p>Toutes les heures sont saisies et appliquées en heure de Libreville (Africa/Libreville, UTC+1, sans heure d’été).</p>
      <fieldset style={{ border: 0, padding: 0, margin: '0 0 14px' }}>
        <legend style={{ padding: 0, fontWeight: 600 }}>Type de programme</legend>
        <div className="chip-row" style={{ marginTop: 6 }}>
          <label className="check-label" style={{ padding: '6px 10px' }}><input type="radio" name="campaign-kind" checked={form.kind === 'standard'} onChange={() => set('kind', 'standard')} /> Campagne ordinaire</label>
          <label className="check-label" style={{ padding: '6px 10px' }}><input type="radio" name="campaign-kind" checked={form.kind === 'event'} onChange={() => set('kind', 'event')} /> Mode événement (sommet, conseil des ministres, session statutaire)</label>
        </div>
        {form.kind === 'event' && (
          <div style={{ marginTop: 8 }}><Notice tone="warning">Pendant sa période, ce programme remplace tout le programme ordinaire des écrans ciblés, quelle que soit sa priorité ; seules les alertes passent avant. Durée limitée à 31 jours : le programme ordinaire reprend automatiquement à la date de fin.</Notice></div>
        )}
      </fieldset>
      <div className="form-grid">
        <LocalDateTime label="Début" required date={form.start_date} time={form.start_time} onDate={(v) => set('start_date', v)} onTime={(v) => set('start_time', v)} error={error?.field('starts_at')} help="Heure de Libreville" />
        <LocalDateTime label="Fin" required date={form.end_date} time={form.end_time} onDate={(v) => set('end_date', v)} onTime={(v) => set('end_time', v)} error={error?.field('ends_at')} help="Heure de Libreville" />
        <fieldset className="field wide" style={{ border: 0, padding: 0, margin: 0, gridColumn: 'span 2' }}>
          <legend style={{ padding: 0 }}>Jours de diffusion</legend>
          <div className="chip-row" style={{ marginTop: 6 }}>
            {[1, 2, 3, 4, 5, 6, 7].map((d) => (
              <label key={d} className="check-label" style={{ padding: '6px 10px' }}>
                <input type="checkbox" checked={form.days.includes(d)} onChange={(e) => set('days', e.target.checked ? [...form.days, d] : form.days.filter((x) => x !== d))} /> {DAYS[d]}
              </label>
            ))}
          </div>
          {error?.field('days') && <span className="field-error">{error.field('days')}</span>}
        </fieldset>
        <Field label="Créneau — début" error={error?.field('time_from')} help="Facultatif · vide = toute la journée">
          <input type="time" value={form.time_from} onChange={(e) => set('time_from', e.target.value)} />
        </Field>
        <Field label="Créneau — fin" error={error?.field('time_to')} help="Heure de Libreville">
          <input type="time" value={form.time_to} onChange={(e) => set('time_to', e.target.value)} />
        </Field>
        <Field label="Priorité" required error={error?.field('priority')} help="1 à 99 ; à priorité égale, les campagnes alternent en rotation ordonnée.">
          <input type="number" min={1} max={99} value={form.priority} onChange={(e) => set('priority', Number(e.target.value))} />
        </Field>
        <Field label="Durée de diapositive (secondes)" required error={error?.field('slide_duration_s')} help="Au moins 10 s, au plus 300 s.">
          <input type="number" min={10} max={300} value={form.slide_duration_s} onChange={(e) => set('slide_duration_s', Number(e.target.value))} />
        </Field>
      </div>
    </>
  )
}

function StepReview({ form, campaignId }: { form: Form; campaignId?: string }) {
  const body = {
    targets: form.targets, items: form.items.map((i) => ({ content_id: i.content_id })), priority: form.priority, id: campaignId,
    starts_at: form.start_date ? toIsoFromLocal(form.start_date, form.start_time || '00:00') : null, ends_at: form.end_date ? toIsoFromLocal(form.end_date, form.end_time || '23:59') : null,
  }
  const preview = useQuery({
    queryKey: ['campaign-preview', body],
    queryFn: () => api.post<{ data: CampaignPreview }>('/campaigns/preview', body, false).then((r) => r.data),
    enabled: form.targets.length > 0, refetchOnWindowFocus: false,
  })
  const p = preview.data
  const conflicts = p?.screens.flatMap((s) => s.conflicts.map((c) => ({ ...c, screen: s.code }))) ?? []
  return (
    <>
      <h3>Vérification</h3>
      <p>Simulation serveur des écrans effectivement ciblés, de la compatibilité de classification et des conflits de programmation.</p>
      <div className="metadata-row" style={{ marginTop: 0, marginBottom: 14 }}>
        <span><b>Contenus</b>{form.items.length} · rotation {form.languages.map((l) => l.toUpperCase()).join(' → ')}</span>
        <span><b>Période (Libreville)</b>{form.start_date} {form.start_time} → {form.end_date} {form.end_time}</span>
        <span><b>Créneau · priorité</b>{form.time_from && form.time_to ? `${form.time_from}–${form.time_to}` : 'Toute la journée'} · {form.priority}</span>
      </div>
      {!form.targets.length ? <Notice tone="warning">Aucune cible choisie.</Notice> : preview.isLoading ? <Loading label="Calcul des écrans effectifs…" /> : preview.isError ? <ErrorState error={preview.error} onRetry={() => preview.refetch()} /> : p && (
        <>
          {p.effective > 0
            ? <div className="success-box"><Icon name="check" /><span><strong>{p.effective} écran(s) effectif(s) sur {p.screens.length} ciblé(s) après dédoublonnage</strong><small>Les écrans incompatibles sont exclus automatiquement.</small></span></div>
            : <div className="warning-box" style={{ marginTop: 0 }}><Icon name="alert" /><span><strong>Aucun écran compatible</strong><small>La programmation sera refusée (NO_COMPATIBLE_TARGET) : choisissez d’autres cibles ou un contenu public.</small></span></div>}
          {p.warnings.map((w) => <div className="warning-box" key={w}><Icon name="alert" /><span><strong>{w}</strong></span></div>)}
          {conflicts.length > 0 && (
            <div className="warning-box"><Icon name="alert" /><span><strong>{conflicts.length} conflit(s) détecté(s)</strong>{conflicts.slice(0, 8).map((c, i) => <small key={i}>{c.screen} : « {c.name} » ({c.reference}) priorité {c.priority}{c.same_priority ? ' — même priorité : rotation ordonnée appliquée' : c.priority > form.priority ? ' — prioritaire sur cette campagne' : ' — cette campagne est prioritaire'}</small>)}</span></div>
          )}
          <div className="table-scroll" style={{ marginTop: 14 }}>
            <table>
              <thead><tr><th>Écran</th><th>Niveau · zone</th><th>Compatibilité</th><th>Réseau</th><th>Conflits</th></tr></thead>
              <tbody>
                {p.screens.map((s) => (
                  <tr key={s.id}>
                    <td><strong>{s.code}</strong><small>{s.name}</small></td>
                    <td>{s.floor}<small>{s.zone} · max. {CLASS_LABEL[s.zone_max_classification] ?? s.zone_max_classification}</small></td>
                    <td>{s.compatible ? <Status tone="success">Compatible</Status> : <Status tone="danger">Exclu</Status>}</td>
                    <td><StatusOf map={NETWORK_STATUS} value={s.network_status} /></td>
                    <td>{s.conflicts.length ? s.conflicts.map((c) => `${c.reference} (P${c.priority})`).join(', ') : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </>
  )
}
