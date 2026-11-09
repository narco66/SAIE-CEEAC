import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import { useData } from '../../lib/hooks'
import { fmtDateTime, localParts, toIsoFromLocal, todayLocal, LANG_LABEL } from '../../lib/format'
import { Button, ConfirmDialog, EmptyState, ErrorState, Field, Loading, Modal, Notice, PageHeading, Status, useToast } from '../../components/ui'
import { MODE_STATUS, ProgramResult, ProofLegend, useAllScreens, useReferentials, type ProgramPreview } from './shared'

interface Fallback { id: string; zone: { id: string; name: string }; valid_until: string | null; is_active: boolean; content: { id: string; title: string; lang: string; reference: string } }
interface ContentOption { id: string; reference: string; title: string; classification: string; approved_versions: { id: string; lang: string; revision: number }[] }
interface Slot { time: string; data?: ProgramPreview; error?: string }

const SLOTS = Array.from({ length: 21 }, (_, i) => {
  const m = 8 * 60 + i * 30
  return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`
})
const MODE_SHORT: Record<string, string> = { program: 'Prog.', alert: 'Alerte', fallback: 'Repli', off: 'Veille' }

function slotSignature(d?: ProgramPreview): string {
  if (!d) return 'error'
  return `${d.result.mode}|${d.result.reason}|${d.result.playlist.map((e) => e.entry_id).join(',')}|${d.result.alerts.map((a) => a.id).join(',')}`
}
function slotSummary(d: ProgramPreview): string {
  if (d.result.mode === 'program') return d.result.playlist.map((e) => `${e.slide?.title || '(sans titre)'} [${e.lang.toUpperCase()}]`).join(' · ')
  if (d.result.mode === 'alert') return d.result.alerts.map((a) => a.title).join(' · ')
  if (d.result.mode === 'fallback') return d.fallback.slide ? `${d.fallback.slide.title} [${d.fallback.slide.lang?.toUpperCase()}]` : 'Écran institutionnel par défaut'
  return 'Écran en veille'
}

/** V22 Programme et prévisualisation : version gagnante, langue, règle de priorité, repli par zone (PUB-05, PUB-06). */
export default function Program() {
  const { me, can } = useAuth()
  const [params, setParams] = useSearchParams()
  const screens = useAllScreens()
  const [screenId, setScreenId] = useState(params.get('screen_id') ?? '')
  const [date, setDate] = useState(params.get('date') ?? todayLocal())
  const [time, setTime] = useState(localParts(new Date()).time)

  useEffect(() => {
    if (!screenId && screens.data?.data.length) setScreenId(screens.data.data[0].id)
  }, [screenId, screens.data])
  useEffect(() => {
    const next = new URLSearchParams(params)
    if (screenId) next.set('screen_id', screenId)
    next.set('date', date)
    setParams(next, { replace: true })
  }, [screenId, date])

  const at = date && time ? toIsoFromLocal(date, time) : undefined
  const preview = useQuery({
    queryKey: ['program', screenId, at],
    queryFn: ({ signal }) => api.get<{ data: ProgramPreview }>('/program', { screen_id: screenId, at }, signal).then((r) => r.data),
    enabled: !!screenId && !!at,
  })

  /* Piste horaire : 21 instants de 08:00 à 18:00, appelés séquentiellement. */
  const [slots, setSlots] = useState<Slot[]>([])
  const [running, setRunning] = useState(false)
  const [runKey, setRunKey] = useState(0)
  useEffect(() => {
    if (!screenId || !date) return
    const controller = new AbortController()
    setSlots([])
    setRunning(true)
    ;(async () => {
      const acc: Slot[] = []
      for (const t of SLOTS) {
        if (controller.signal.aborted) return
        try {
          const r = await api.get<{ data: ProgramPreview }>('/program', { screen_id: screenId, at: toIsoFromLocal(date, t) }, controller.signal)
          acc.push({ time: t, data: r.data })
        } catch (e) {
          if ((e as Error).name === 'AbortError') return
          acc.push({ time: t, error: e instanceof ApiError ? e.message : String(e) })
        }
        setSlots([...acc])
      }
      setRunning(false)
    })()
    return () => controller.abort()
  }, [screenId, date, runKey])

  const segments = useMemo(() => {
    const out: { from: string; to: string; slot: Slot }[] = []
    slots.forEach((s, i) => {
      const end = SLOTS[i + 1] ?? '18:30'
      const last = out[out.length - 1]
      if (last && slotSignature(last.slot.data) === slotSignature(s.data) && !s.error && !last.slot.error) last.to = end
      else out.push({ from: s.time, to: end, slot: s })
    })
    return out
  }, [slots])

  const screen = screens.data?.data.find((s) => s.id === screenId)

  return (
    <>
      <PageHeading title="Programme et prévisualisation" description="Simulez ce qu’un écran diffuse à une date et une heure données, dans le fuseau Africa/Libreville (UTC+1)." demo={me.demo_mode} />
      <section className="panel">
        <div className="sim-controls">
          <Field label="Écran">
            {screens.isError ? <span className="field-error">Liste des écrans indisponible</span> : (
              <select value={screenId} onChange={(e) => setScreenId(e.target.value)} style={{ minWidth: 260 }}>
                {!screens.data && <option value="">Chargement…</option>}
                {(screens.data?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.code} · {s.name}{s.location?.floor ? ` (${s.location.floor.label})` : ''}</option>)}
              </select>
            )}
          </Field>
          <Field label="Date"><input type="date" value={date} onChange={(e) => setDate(e.target.value)} /></Field>
          <Field label="Heure"><input type="time" value={time} onChange={(e) => setTime(e.target.value)} /></Field>
          <Button variant="secondary" icon="refresh" onClick={() => { preview.refetch(); setRunKey((k) => k + 1) }} disabled={!screenId}>Recalculer</Button>
        </div>
        <ProofLegend />
        <div className="panel-body" style={{ paddingTop: 14 }}>
          {screens.isLoading ? <Loading /> : screens.isError ? <ErrorState error={screens.error} onRetry={() => screens.refetch()} /> : !screenId ? (
            <EmptyState title="Aucun écran" text="Aucun écran n’est accessible dans votre périmètre." />
          ) : preview.isLoading ? <Loading label="Résolution du programme…" /> : preview.isError ? <ErrorState error={preview.error} onRetry={() => preview.refetch()} /> : preview.data ? <ProgramResult data={preview.data} /> : null}
        </div>
      </section>

      {screenId && (
        <section className="panel section-gap">
          <div className="panel-title">
            <div><h2>Piste horaire · {screen?.code ?? ''}</h2><p>Version gagnante toutes les 30 minutes, de 08:00 à 18:00, le {date.split('-').reverse().join('/')}{running ? ` · calcul ${slots.length}/${SLOTS.length}…` : ''}</p></div>
          </div>
          <div className="day-strip" role="list" aria-label="Piste horaire">
            {SLOTS.map((t) => {
              const s = slots.find((x) => x.time === t)
              const mode = s?.data?.result.mode
              return (
                <button key={t} role="listitem" className={`${s?.error ? 'error' : mode ?? ''} ${t === time ? 'current' : ''}`} onClick={() => setTime(t)}
                  title={s?.data ? `${t} · ${MODE_STATUS[mode ?? '']?.[0] ?? mode} · ${s.data.result.reason}` : s?.error ?? 'Calcul en cours'}
                  aria-label={`${t} : ${s?.data ? `${MODE_STATUS[mode ?? '']?.[0] ?? mode}, ${slotSummary(s.data)}` : s?.error ? `erreur, ${s.error}` : 'calcul en cours'}`}>
                  <b>{t}</b>{s?.data ? (mode === 'program' ? `P${s.data.result.playlist[0]?.priority ?? ''}` : MODE_SHORT[mode ?? '']) : s?.error ? '!' : '…'}
                </button>
              )
            })}
          </div>
          <div className="segment-list">
            {segments.map((seg) => (
              <div key={seg.from}>
                <time>{seg.from}–{seg.to}</time>
                <span>{seg.slot.data ? <Status tone={MODE_STATUS[seg.slot.data.result.mode]?.[1]}>{MODE_STATUS[seg.slot.data.result.mode]?.[0]}</Status> : <Status tone="danger">Erreur</Status>}</span>
                <span>{seg.slot.data ? <><strong>{slotSummary(seg.slot.data)}</strong><small className="muted" style={{ display: 'block' }}>Règle : {seg.slot.data.result.reason}{seg.slot.data.result.playlist.length ? ` · langues ${[...new Set(seg.slot.data.result.playlist.map((e) => LANG_LABEL[e.lang] ?? e.lang))].join(', ')}` : ''}</small></> : seg.slot.error}</span>
              </div>
            ))}
            {!running && slots.length === 0 && <p className="muted small">Aucun instant simulé.</p>}
          </div>
        </section>
      )}

      <Fallbacks canManage={can('fallback.manage')} />
    </>
  )
}

function Fallbacks({ canManage }: { canManage: boolean }) {
  const client = useQueryClient()
  const toast = useToast()
  const query = useData<Fallback[]>(['fallbacks'], '/fallbacks')
  const [adding, setAdding] = useState(false)
  const [disabling, setDisabling] = useState<Fallback | null>(null)
  const closeAdd = useCallback(() => setAdding(false), [])
  const closeDisable = useCallback(() => setDisabling(null), [])
  return (
    <section className="panel section-gap">
      <div className="panel-title">
        <div><h2>Contenus de repli</h2><p>Diffusés par zone quand aucun programme n’est valide. Uniquement des versions publiques approuvées.</p></div>
        {canManage && <Button icon="plus" onClick={() => setAdding(true)}>Ajouter un repli</Button>}
      </div>
      {query.isLoading ? <Loading /> : query.isError ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (query.data ?? []).length === 0 ? (
        <EmptyState title="Aucun contenu de repli" text="Sans repli, les écrans affichent l’écran institutionnel par défaut." />
      ) : (
        <div className="table-scroll">
          <table>
            <thead><tr><th>Zone</th><th>Contenu</th><th>Langue</th><th>Valide jusqu’au</th><th>État</th><th><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {(query.data ?? []).map((f) => (
                <tr key={f.id}>
                  <td><strong>{f.zone.name}</strong></td>
                  <td>{f.content.title || '(sans titre)'}<small className="mono">{f.content.reference}</small></td>
                  <td><span className="lang">{f.content.lang?.toUpperCase()}</span></td>
                  <td>{fmtDateTime(f.valid_until)}</td>
                  <td>{f.is_active ? <Status tone="success">Actif</Status> : <Status>Inactif ou expiré</Status>}</td>
                  <td><div className="row-actions">{canManage && f.is_active && <Button size="small" variant="ghost" icon="x" onClick={() => setDisabling(f)}>Désactiver</Button>}</div></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {adding && <AddFallbackModal onClose={closeAdd} />}
      {disabling && (
        <ConfirmDialog title="Désactiver le contenu de repli" object={`${disabling.content.title} · zone ${disabling.zone.name}`} confirmLabel="Désactiver"
          consequence="Les écrans de cette zone utiliseront le repli actif suivant, ou l’écran institutionnel par défaut, à la réception de leur prochain programme."
          onClose={closeDisable}
          onConfirm={async () => {
            await api.delete(`/fallbacks/${disabling.id}`)
            client.invalidateQueries({ queryKey: ['fallbacks'] })
            client.invalidateQueries({ queryKey: ['program'] })
            toast.success('Contenu de repli désactivé')
          }} />
      )}
    </section>
  )
}

function AddFallbackModal({ onClose }: { onClose: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const refs = useReferentials()
  const contents = useQuery({
    queryKey: ['contents', 'fallback-options'],
    queryFn: ({ signal }) => api.get<{ data: ContentOption[] }>('/contents', { approved_only: 1, classification: 'public', per_page: 500 }, signal).then((r) => r.data),
  })
  const [zoneId, setZoneId] = useState('')
  const [contentId, setContentId] = useState('')
  const [versionId, setVersionId] = useState('')
  const [until, setUntil] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const content = contents.data?.find((c) => c.id === contentId)

  const submit = async () => {
    setBusy(true)
    setError(null)
    try {
      const [d, t] = until.split('T')
      await api.post('/fallbacks', { zone_id: zoneId, content_version_id: versionId, valid_until: toIsoFromLocal(d, t.slice(0, 5)) })
      client.invalidateQueries({ queryKey: ['fallbacks'] })
      client.invalidateQueries({ queryKey: ['program'] })
      toast.success('Contenu de repli ajouté')
      onClose()
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal title="Ajouter un contenu de repli" kicker="REPLI PAR ZONE" onClose={onClose} footer={<>
      <Button variant="secondary" onClick={onClose}>Annuler</Button>
      <Button icon="check" onClick={submit} busy={busy} disabled={!zoneId || !versionId || !until}>Ajouter</Button>
    </>}>
      <div className="form-grid">
        <Field label="Zone d’affichage" required wide error={error?.field('zone_id')}>
          <select value={zoneId} onChange={(e) => setZoneId(e.target.value)} aria-invalid={!!error?.field('zone_id')}>
            <option value="">— Choisir —</option>
            {(refs.data?.zones ?? []).map((z) => <option key={z.id} value={z.id}>{z.name} ({z.code})</option>)}
          </select>
        </Field>
        <Field label="Contenu public approuvé" required wide help={contents.isError ? 'Liste des contenus indisponible' : contents.data && contents.data.length === 0 ? 'Aucun contenu public approuvé disponible.' : undefined}>
          <select value={contentId} onChange={(e) => { setContentId(e.target.value); setVersionId('') }}>
            <option value="">{contents.isLoading ? 'Chargement…' : '— Choisir —'}</option>
            {(contents.data ?? []).filter((c) => c.classification === 'public' && c.approved_versions.length).map((c) => <option key={c.id} value={c.id}>{c.reference} · {c.title}</option>)}
          </select>
        </Field>
        <Field label="Version approuvée (langue)" required error={error?.field('content_version_id') ?? (error?.code === 'FALLBACK_NOT_PUBLIC' ? error.message : undefined)}>
          <select value={versionId} onChange={(e) => setVersionId(e.target.value)} disabled={!content} aria-invalid={!!error?.field('content_version_id')}>
            <option value="">— Choisir —</option>
            {(content?.approved_versions ?? []).map((v) => <option key={v.id} value={v.id}>{LANG_LABEL[v.lang] ?? v.lang} · révision {v.revision}</option>)}
          </select>
        </Field>
        <Field label="Valide jusqu’au (Africa/Libreville)" required error={error?.field('valid_until')}>
          <input type="datetime-local" value={until} onChange={(e) => setUntil(e.target.value)} aria-invalid={!!error?.field('valid_until')} />
        </Field>
      </div>
      {error && !Object.keys(error.errors).length && error.code !== 'FALLBACK_NOT_PUBLIC' && <div style={{ marginTop: 12 }}><Notice tone="danger">{error.message}</Notice></div>}
    </Modal>
  )
}
