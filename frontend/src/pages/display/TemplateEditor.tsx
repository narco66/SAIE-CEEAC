import { useCallback, useEffect, useRef, useState, type KeyboardEvent as ReactKeyboardEvent, type PointerEvent as ReactPointerEvent } from 'react'
import { Link, useParams } from 'react-router'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { useData } from '../../lib/hooks'
import { Button, ErrorState, Icon, Loading, Notice, PageHeading, useToast, useUnsavedWarning } from '../../components/ui'
import { ZONE_LABEL, ZONE_STYLE_COLOR, ZONE_TYPES, type LayoutZone, type Template } from './shared'

interface Draft { name: string; orientation: 'landscape' | 'portrait'; zones: LayoutZone[]; theme: string; transition: string; safe_margin: number }

const MAX_ZONES = 20
const clamp = (v: number, min: number, max: number) => Math.min(max, Math.max(min, v))
const snap = (v: number) => Math.round(v)

function fromTemplate(t: Template): Draft {
  return {
    name: t.name, orientation: t.orientation,
    zones: (t.layout.zones ?? []).map((z) => ({ ...z })),
    theme: t.layout.theme ?? 'institutionnel', transition: t.layout.transition ?? 'fade', safe_margin: t.layout.safe_margin ?? 5,
  }
}

/** V16 Éditeur de modèle : zones structurées en pourcentage, grille de 1 %, marge sûre, aucun script libre (PUB-03). */
export default function TemplateEditor() {
  const { id = '' } = useParams()
  const query = useData<Template>(['template', id], `/templates/${id}`)
  if (query.isLoading) return <Loading />
  if (query.isError || !query.data) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  return <Editor key={`${query.data.id}:${query.data.version}`} template={query.data} reload={() => query.refetch()} />
}

function Editor({ template, reload }: { template: Template; reload: () => void }) {
  const client = useQueryClient()
  const toast = useToast()
  const [saved, setSaved] = useState<Draft>(() => fromTemplate(template))
  const [hist, setHist] = useState<{ draft: Draft; past: Draft[]; future: Draft[] }>(() => ({ draft: fromTemplate(template), past: [], future: [] }))
  const { draft, past, future } = hist
  const [version, setVersion] = useState(template.version)
  const [selected, setSelected] = useState<string | null>(() => template.layout.zones?.[0]?.id ?? null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const canvasRef = useRef<HTMLDivElement>(null)
  const drag = useRef<{ id: string; mode: 'move' | 'resize'; startX: number; startY: number; origin: LayoutZone; before: Draft; moved: boolean } | null>(null)

  const archived = template.status === 'archived'
  const dirty = JSON.stringify(draft) !== JSON.stringify(saved)
  useUnsavedWarning(dirty)
  const zone = draft.zones.find((z) => z.id === selected) ?? null

  /** Applique une modification en l'inscrivant dans l'historique local (annuler / rétablir). */
  const commit = useCallback((next: Draft | ((d: Draft) => Draft)) => {
    setHist((h) => {
      const value = typeof next === 'function' ? next(h.draft) : next
      if (JSON.stringify(value) === JSON.stringify(h.draft)) return h
      return { draft: value, past: [...h.past.slice(-49), h.draft], future: [] }
    })
  }, [])

  const updateZone = (zid: string, patch: Partial<LayoutZone>) => commit((d) => ({ ...d, zones: d.zones.map((z) => (z.id === zid ? normalize({ ...z, ...patch }) : z)) }))

  const undo = useCallback(() => {
    setHist((h) => (h.past.length ? { draft: h.past[h.past.length - 1], past: h.past.slice(0, -1), future: [h.draft, ...h.future] } : h))
  }, [])
  const redo = useCallback(() => {
    setHist((h) => (h.future.length ? { draft: h.future[0], past: [...h.past, h.draft], future: h.future.slice(1) } : h))
  }, [])
  const reset = (value: Draft) => setHist({ draft: value, past: [], future: [] })

  const addZone = (type: string) => {
    if (draft.zones.length >= MAX_ZONES) return toast.info('Limite atteinte', `${MAX_ZONES} zones au maximum par modèle.`)
    let n = 1
    while (draft.zones.some((z) => z.id === `${type}-${n}`)) n++
    const zid = `${type}-${n}`
    commit((d) => ({ ...d, zones: [...d.zones, { id: zid, type, x: 10, y: 10, w: 30, h: 20, style: null, align: 'left', font_scale: 1 }] }))
    setSelected(zid)
  }
  const removeZone = (zid: string) => {
    if (draft.zones.length <= 1) return toast.info('Suppression impossible', 'Un modèle doit conserver au moins une zone.')
    commit((d) => ({ ...d, zones: d.zones.filter((z) => z.id !== zid) }))
    setSelected(null)
  }
  const reorder = (zid: string, dir: -1 | 1) => commit((d) => {
    const i = d.zones.findIndex((z) => z.id === zid)
    const j = i + dir
    if (i < 0 || j < 0 || j >= d.zones.length) return d
    const zones = [...d.zones]
    ;[zones[i], zones[j]] = [zones[j], zones[i]]
    return { ...d, zones }
  })

  /* ===== Souris : déplacement et redimensionnement, alignés sur la grille de 1 % ===== */
  const onPointerDown = (e: ReactPointerEvent, z: LayoutZone, mode: 'move' | 'resize') => {
    if (archived) return
    e.stopPropagation()
    e.preventDefault()
    setSelected(z.id)
    ;(e.target as Element).setPointerCapture?.(e.pointerId)
    drag.current = { id: z.id, mode, startX: e.clientX, startY: e.clientY, origin: { ...z }, before: draft, moved: false }
  }
  const onPointerMove = (e: ReactPointerEvent) => {
    const d = drag.current
    const rect = canvasRef.current?.getBoundingClientRect()
    if (!d || !rect) return
    const dx = ((e.clientX - d.startX) / rect.width) * 100
    const dy = ((e.clientY - d.startY) / rect.height) * 100
    const o = d.origin
    const patch = d.mode === 'move'
      ? { x: clamp(snap(o.x + dx), 0, 100 - o.w), y: clamp(snap(o.y + dy), 0, 100 - o.h) }
      : { w: clamp(snap(o.w + dx), 1, 100 - o.x), h: clamp(snap(o.h + dy), 1, 100 - o.y) }
    d.moved = true
    const zid = d.id
    setHist((h) => ({ ...h, draft: { ...h.draft, zones: h.draft.zones.map((z) => (z.id === zid ? { ...z, ...patch } : z)) } }))
  }
  const onPointerUp = () => {
    const d = drag.current
    drag.current = null
    if (!d || !d.moved) return
    const before = d.before
    setHist((h) => (JSON.stringify(h.draft) === JSON.stringify(before) ? h : { draft: h.draft, past: [...h.past.slice(-49), before], future: [] }))
  }

  /* ===== Clavier : alternative accessible à la souris ===== */
  const onZoneKey = (e: ReactKeyboardEvent, z: LayoutZone) => {
    if (archived) return
    const step = e.altKey ? 5 : 1
    const arrows: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }
    if (arrows[e.key]) {
      e.preventDefault()
      const [dx, dy] = arrows[e.key]
      if (e.shiftKey) updateZone(z.id, { w: z.w + dx, h: z.h + dy })
      else updateZone(z.id, { x: z.x + dx, y: z.y + dy })
    } else if (e.key === 'Delete' || e.key === 'Backspace') {
      e.preventDefault()
      removeZone(z.id)
    }
  }
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement
      if (['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName)) return
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !e.shiftKey) { e.preventDefault(); undo() }
      if ((e.ctrlKey || e.metaKey) && (e.key.toLowerCase() === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey))) { e.preventDefault(); redo() }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [undo, redo])

  const save = async () => {
    setBusy(true)
    setError(null)
    try {
      const body = { name: draft.name, orientation: draft.orientation, layout: { zones: draft.zones.map(cleanZone), theme: draft.theme, transition: draft.transition, safe_margin: draft.safe_margin } }
      const res = await api.patch<{ data: Template }>(`/templates/${template.id}`, body, { 'If-Match': String(version) })
      const next = fromTemplate(res.data)
      setSaved(next)
      reset(next)
      setVersion(res.data.version)
      client.setQueryData(['template', template.id], res.data)
      client.invalidateQueries({ queryKey: ['templates'] })
      toast.success('Modèle enregistré', `Version ${res.data.version}.`)
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(500, { message: String(e) }))
    } finally {
      setBusy(false)
    }
  }

  const margin = draft.safe_margin
  const outsideSafe = (z: LayoutZone) => margin > 0 && (z.x < margin || z.y < margin || z.x + z.w > 100 - margin || z.y + z.h > 100 - margin) && !(z.type === 'header' || z.type === 'ticker')
  const fieldErrors = error ? Object.entries(error.errors) : []

  return (
    <>
      <PageHeading title={`Éditeur · ${draft.name || template.name}`} description={`${template.code} · version ${version}${dirty ? ' · modifications non enregistrées' : ''}`}
        actions={<>
          <Link className="button secondary" to="/affichage/modeles"><Icon name="list" size={16} /><span>Modèles</span></Link>
          <Button icon="check" onClick={save} busy={busy} disabled={!dirty || archived || !draft.name.trim()}>Enregistrer le modèle</Button>
        </>} />
      {archived && <div style={{ marginBottom: 12 }}><Notice tone="warning">Modèle archivé : il ne peut plus être modifié. Dupliquez-le depuis la liste pour créer une variante.</Notice></div>}
      {error?.code === 'VERSION_CONFLICT' && (
        <div style={{ marginBottom: 12 }}>
          <Notice tone="warning">
            <strong>Conflit de version.</strong> {error.message} (version courante : {String(error.details.current_version ?? '?')}). Vos modifications locales seront perdues si vous rechargez.
            <div style={{ marginTop: 8 }}><Button size="small" variant="secondary" icon="refresh" onClick={() => { client.invalidateQueries({ queryKey: ['templates'] }); reload() }}>Recharger la version courante</Button></div>
          </Notice>
        </div>
      )}
      {error && error.code !== 'VERSION_CONFLICT' && (
        <div style={{ marginBottom: 12 }}>
          <Notice tone="danger">{error.message}{fieldErrors.length > 0 && <ul style={{ margin: '6px 0 0 16px' }}>{fieldErrors.map(([k, v]) => <li key={k}><span className="mono">{k}</span> : {v[0]}</li>)}</ul>}</Notice>
        </div>
      )}

      <div className="layout-workspace">
        <section className="panel block-library" aria-label="Palette de blocs">
          <h2>Blocs disponibles</h2>
          <p>Cliquez pour ajouter une zone ({draft.zones.length}/{MAX_ZONES})</p>
          {ZONE_TYPES.map((b) => (
            <button key={b.type} onClick={() => addZone(b.type)} disabled={archived} aria-label={`Ajouter un bloc ${b.label}`}>
              <span><Icon name={b.icon} /></span>{b.label}<Icon name="plus" size={15} />
            </button>
          ))}
          <div className="layers">
            <h3>Calques</h3>
            {draft.zones.map((z, i) => (
              <button key={z.id} className={z.id === selected ? 'active' : ''} onClick={() => setSelected(z.id)} aria-pressed={z.id === selected}>
                <span>{i + 1}. {ZONE_LABEL[z.type] ?? z.type}</span><span className="mono">{z.id}</span>
              </button>
            ))}
          </div>
        </section>

        <section className="panel canvas-panel">
          <div className="panel-title">
            <div><h2>Composition</h2><p>{draft.orientation === 'portrait' ? 'Portrait 9:16' : 'Paysage 16:9'} · grille 1 % · marge sûre {margin} % (pointillés)</p></div>
          </div>
          <div className="editor-toolbar" role="toolbar" aria-label="Édition">
            <Button size="small" variant="secondary" icon="refresh" onClick={undo} disabled={!past.length} title="Ctrl+Z">Annuler</Button>
            <Button size="small" variant="secondary" icon="arrow" onClick={redo} disabled={!future.length} title="Ctrl+Y">Rétablir</Button>
            {zone && !archived && <>
              <Button size="small" variant="secondary" onClick={() => updateZone(zone.id, { x: snap((100 - zone.w) / 2) })}>Centrer horiz.</Button>
              <Button size="small" variant="secondary" onClick={() => updateZone(zone.id, { y: snap((100 - zone.h) / 2) })}>Centrer vert.</Button>
              <Button size="small" variant="secondary" onClick={() => updateZone(zone.id, { x: margin, y: zone.y < margin ? margin : zone.y, w: Math.min(zone.w, 100 - 2 * margin) })}>Aligner marge</Button>
              <Button size="small" variant="secondary" onClick={() => reorder(zone.id, 1)}>Premier plan</Button>
              <Button size="small" variant="secondary" onClick={() => reorder(zone.id, -1)}>Arrière-plan</Button>
              <Button size="small" variant="ghost" icon="trash" onClick={() => removeZone(zone.id)}>Supprimer</Button>
            </>}
          </div>
          <div className="editor-canvas-wrap">
            <div ref={canvasRef} className={`editor-canvas ${draft.orientation === 'portrait' ? 'portrait' : ''} theme-${draft.theme}`}
              onPointerMove={onPointerMove} onPointerUp={onPointerUp} onPointerCancel={onPointerUp} onPointerDown={() => setSelected(null)}
              role="application" aria-label="Canevas du modèle. Sélectionnez une zone puis utilisez les flèches pour la déplacer, Maj+flèches pour la redimensionner, Suppr pour la retirer.">
              {margin > 0 && <div className="safe-area" style={{ left: `${margin}%`, top: `${margin}%`, right: `${margin}%`, bottom: `${margin}%` }} />}
              {draft.zones.map((z) => (
                <div key={z.id} tabIndex={0} role="button" aria-pressed={z.id === selected}
                  aria-label={`${ZONE_LABEL[z.type] ?? z.type} ${z.id} : x ${z.x} %, y ${z.y} %, largeur ${z.w} %, hauteur ${z.h} %${outsideSafe(z) ? ', hors marge sûre' : ''}`}
                  className={`canvas-zone ${z.id === selected ? 'selected' : ''} ${outsideSafe(z) ? 'outside' : ''}`}
                  style={{ left: `${z.x}%`, top: `${z.y}%`, width: `${z.w}%`, height: `${z.h}%`, background: z.style ? ZONE_STYLE_COLOR[z.style] : undefined, color: z.style === 'light' || z.style === 'accent' ? '#14283c' : undefined, justifyItems: z.align === 'right' ? 'end' : z.align === 'left' ? 'start' : 'center' }}
                  onPointerDown={(e) => onPointerDown(e, z, 'move')} onFocus={() => setSelected(z.id)} onKeyDown={(e) => onZoneKey(e, z)}>
                  <span style={{ fontSize: `${10 * (z.font_scale ?? 1)}px` }}>{ZONE_LABEL[z.type] ?? z.type}{outsideSafe(z) ? ' ⚠' : ''}</span>
                  {z.id === selected && !archived && <i className="handle" onPointerDown={(e) => onPointerDown(e, z, 'resize')} aria-hidden="true" />}
                </div>
              ))}
            </div>
          </div>
          <p className="muted small" style={{ padding: '10px 19px 0', textAlign: 'center' }}>Le signe ⚠ et le contour rouge signalent une zone qui déborde de la marge sûre (hors en-tête et bandeau).</p>
        </section>

        <section className="panel properties" aria-label="Inspecteur">
          <h2>Modèle</h2>
          <label>Nom<input value={draft.name} onChange={(e) => commit({ ...draft, name: e.target.value })} maxLength={120} disabled={archived} /></label>
          <label>Orientation
            <select value={draft.orientation} onChange={(e) => commit({ ...draft, orientation: e.target.value as Draft['orientation'] })} disabled={archived}>
              <option value="landscape">Paysage 16:9</option><option value="portrait">Portrait 9:16</option>
            </select>
          </label>
          <label>Thème
            <select value={draft.theme} onChange={(e) => commit({ ...draft, theme: e.target.value })} disabled={archived}>
              <option value="institutionnel">Institutionnel</option><option value="clair">Clair</option><option value="sombre">Sombre</option>
            </select>
          </label>
          <label>Transition
            <select value={draft.transition} onChange={(e) => commit({ ...draft, transition: e.target.value })} disabled={archived}>
              <option value="fade">Fondu doux</option><option value="slide">Glissement</option><option value="none">Aucune</option>
            </select>
          </label>
          <label>Marge sûre (%)<input type="number" min={0} max={10} step={1} value={draft.safe_margin} onChange={(e) => commit({ ...draft, safe_margin: clamp(Number(e.target.value) || 0, 0, 10) })} disabled={archived} /></label>
          <hr />
          <h2>Propriétés du bloc</h2>
          {!zone ? <p className="muted small">Sélectionnez une zone sur le canevas ou dans les calques.</p> : (
            <fieldset disabled={archived} style={{ border: 0, padding: 0, margin: 0 }}>
              <label>Type de bloc
                <select value={zone.type} onChange={(e) => updateZone(zone.id, { type: e.target.value })}>
                  {ZONE_TYPES.map((t) => <option key={t.type} value={t.type}>{t.label}</option>)}
                </select>
              </label>
              <div className="grid-xy">
                {(['x', 'y', 'w', 'h'] as const).map((k) => (
                  <label key={k}>{{ x: 'Position X (%)', y: 'Position Y (%)', w: 'Largeur (%)', h: 'Hauteur (%)' }[k]}
                    <input type="number" min={k === 'w' || k === 'h' ? 1 : 0} max={100} step={1} value={zone[k]} onChange={(e) => updateZone(zone.id, { [k]: Number(e.target.value) })} />
                  </label>
                ))}
              </div>
              <label>Style
                <select value={zone.style ?? ''} onChange={(e) => updateZone(zone.id, { style: e.target.value || null })}>
                  <option value="">Transparent</option><option value="primary">Primaire</option><option value="light">Clair</option><option value="dark">Sombre</option><option value="accent">Accent</option>
                </select>
              </label>
              <label>Alignement
                <select value={zone.align ?? ''} onChange={(e) => updateZone(zone.id, { align: e.target.value || null })}>
                  <option value="">Par défaut</option><option value="left">Gauche</option><option value="center">Centré</option><option value="right">Droite</option>
                </select>
              </label>
              <label>Échelle de police ({(zone.font_scale ?? 1).toFixed(1).replace('.', ',')}×)
                <input type="range" min={0.5} max={3} step={0.1} value={zone.font_scale ?? 1} onChange={(e) => updateZone(zone.id, { font_scale: Number(e.target.value) })} />
              </label>
              <p className="muted small">Identifiant technique : <span className="mono">{zone.id}</span></p>
            </fieldset>
          )}
          <hr />
          <Button icon="check" onClick={save} busy={busy} disabled={!dirty || archived || !draft.name.trim()}>Enregistrer le modèle</Button>
          <Button variant="secondary" icon="x" onClick={() => reset(saved)} disabled={!dirty}>Abandonner les modifications</Button>
        </section>
      </div>
    </>
  )
}

function normalize(z: LayoutZone): LayoutZone {
  const w = clamp(snap(z.w), 1, 100)
  const h = clamp(snap(z.h), 1, 100)
  return { ...z, w, h, x: clamp(snap(z.x), 0, 100 - w), y: clamp(snap(z.y), 0, 100 - h) }
}

/** N'envoie que les propriétés acceptées par l'API (aucun contenu libre). */
function cleanZone(z: LayoutZone): LayoutZone {
  return { id: z.id, type: z.type, x: z.x, y: z.y, w: z.w, h: z.h, style: z.style ?? null, align: z.align ?? null, font_scale: z.font_scale ?? null }
}
