import { useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react'
import { QRCodeSVG } from 'qrcode.react'
import type { PDFDocumentProxy, RenderTask } from 'pdfjs-dist'
import { assetBuffer, assetUrl } from './storage.ts'
import { availableLetters, directoryEntries } from './directory.ts'
import type { AgendaItem, LayoutZone, Manifest, ManifestAlert, Slide } from './types.ts'

/**
 * pdf.js chargé à la demande, seulement quand une diapositive PDF est affichée : le démarrage des écrans, panneaux et bornes
 * qui n'en affichent pas n'embarque pas cette bibliothèque (environ 400 ko). Le module est mis en cache par le service worker.
 */
let pdfjsModule: Promise<typeof import('pdfjs-dist')> | null = null
function loadPdfjs(): Promise<typeof import('pdfjs-dist')> {
  pdfjsModule ??= import('pdfjs-dist').then((pdfjs) => {
    pdfjs.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).toString()
    return pdfjs
  })
  return pdfjsModule
}

/** §7.1-i : fuseau d'affichage de l'heure = fuseau du site transmis par le manifeste. */
let TZ = 'Africa/Libreville'
export function setDisplayTimezone(tz: string | undefined): void {
  if (tz) TZ = tz
}
const time = (d: Date | string) => new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, hour: '2-digit', minute: '2-digit' }).format(typeof d === 'string' ? new Date(d) : d)
/** Date et heure du dernier contact (PUB-09, SAL-09) : hors ligne depuis plus d'un jour, l'heure seule serait trompeuse. */
const stamp = (d: string) => new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(new Date(d))
const longDate = (d: Date, lang = 'fr') => new Intl.DateTimeFormat(lang === 'en' ? 'en-GB' : 'fr-FR', { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(d)
const LOGO = `${import.meta.env.BASE_URL}ceeac-logo.jpg`

export function Stage({ resolution, off, rotate, children }: { resolution?: string; off?: boolean; rotate?: boolean; children: ReactNode }) {
  const [w, h] = (resolution ?? `${window.innerWidth}x${window.innerHeight}`).split('x').map(Number)
  const [scale, setScale] = useState(1)
  useEffect(() => {
    // §7.1-b : en rotation logicielle, la scène tournée d'un quart de tour occupe la largeur et la hauteur inversées.
    const fit = () => setScale(rotate ? Math.min(window.innerWidth / h, window.innerHeight / w) : Math.min(window.innerWidth / w, window.innerHeight / h))
    fit()
    window.addEventListener('resize', fit)
    return () => window.removeEventListener('resize', fit)
  }, [w, h, rotate])
  // Centrage absolu puis réduction : la scène reste centrée et entière quelle que soit la résolution réelle de l'écran
  // (un téléviseur qui expose 1280 × 720 au navigateur pour une scène 1920 × 1080, ou une dalle 4K pilotée en 1080p).
  const style: CSSProperties = { width: w, height: h, transform: `translate(-50%, -50%)${rotate ? ' rotate(90deg)' : ''} scale(${scale})`, fontSize: Math.min(w, h) / 45 }
  return <div className="viewport"><div className={`stage ${off ? 'off' : ''}`} style={style}>{children}</div></div>
}

export function Header({ manifest, now, lang }: { manifest: Manifest | null; now: Date; lang?: string }) {
  return (
    <div className="inst-header">
      <img src={LOGO} alt="CEEAC" />
      <div>
        <b>{lang === 'en' ? 'ECONOMIC COMMUNITY OF CENTRAL AFRICAN STATES' : 'COMMUNAUTÉ ÉCONOMIQUE DES ÉTATS DE L’AFRIQUE CENTRALE'}</b>
        <small>{lang === 'en' ? 'ECCAS Commission' : manifest?.institution.name ?? 'Commission de la CEEAC'}</small>
      </div>
      <time>{time(now)}<small>{longDate(now, lang)}</small></time>
    </div>
  )
}

export function OfflineRibbon({ lastContact }: { lastContact: string | null }) {
  return <div className="ribbon">⚠ Mode hors connexion · dernière mise à jour {lastContact ? stamp(lastContact) : 'inconnue'}</div>
}

function AgendaLine({ agenda, lang }: { agenda: AgendaItem[]; lang: string }) {
  const now = Date.now()
  const upcoming = agenda.filter((a) => Date.parse(a.end) > now).slice(0, 3)
  return (
    <div className="agenda-zone">
      <b>{lang === 'en' ? 'Today’s meetings' : 'Réunions du jour'}</b>
      {upcoming.length === 0 && <em>{lang === 'en' ? 'No further meetings today' : 'Aucune autre réunion aujourd’hui'}</em>}
      {upcoming.map((a) => <span key={a.room + a.start}>{time(a.start)} · {a.label} — {a.room}</span>)}
    </div>
  )
}

function ImageAsset({ id, cover }: { id: string; cover?: boolean }) {
  const [url, setUrl] = useState<string | null>(null)
  useEffect(() => { assetUrl(id).then(setUrl) }, [id])
  return <div className={`media-zone ${cover ? 'cover' : ''}`}>{url && <img src={url} alt="" />}</div>
}

function VideoAsset({ id, subtitles, lang, audio, volume, onError }: { id: string; subtitles: string | null; lang: string; audio: boolean; volume: number; onError: (m: string) => void }) {
  const [url, setUrl] = useState<string | null>(null)
  const [track, setTrack] = useState<string | null>(null)
  const ref = useRef<HTMLVideoElement>(null)
  useEffect(() => { assetUrl(id).then(setUrl); if (subtitles) assetUrl(subtitles).then(setTrack) }, [id, subtitles])
  useEffect(() => {
    const v = ref.current
    if (!v) return
    v.muted = !audio
    v.volume = audio ? Math.min(1, volume / 100) : 0
    v.play().catch(() => { v.muted = true; v.play().catch((e) => onError(`Lecture vidéo impossible : ${e.message}`)) })
  }, [url, audio, volume, onError])
  return (
    <div className="media-zone">
      {url && (
        <video ref={ref} src={url} autoPlay muted={!audio} loop playsInline onError={() => onError('Format vidéo non pris en charge par ce lecteur')}>
          {track && <track kind="subtitles" src={track} srcLang={lang} label={lang.toUpperCase()} default />}
        </video>
      )}
    </div>
  )
}

/** Rendu des pages choisies d'un PDF, une page après l'autre (préparation des PDF à la diffusion). */
function PdfAsset({ id, pages, pageDuration, onError }: { id: string; pages: number[] | null; pageDuration: number; onError: (m: string) => void }) {
  const canvas = useRef<HTMLCanvasElement>(null)
  const [doc, setDoc] = useState<PDFDocumentProxy | null>(null)
  const [index, setIndex] = useState(0)
  useEffect(() => {
    let cancelled = false
    assetBuffer(id).then(async (buffer) => {
      if (!buffer) return onError(`Document absent du cache : ${id}`)
      try {
        const pdfjs = await loadPdfjs()
        const loaded = await pdfjs.getDocument({ data: new Uint8Array(buffer), isEvalSupported: false }).promise
        if (!cancelled) setDoc(loaded)
      } catch (e) {
        onError(`PDF illisible : ${(e as Error).message}`)
      }
    })
    return () => { cancelled = true }
  }, [id, onError])
  const list = useMemo(() => (pages?.length ? pages : doc ? Array.from({ length: doc.numPages }, (_, i) => i + 1) : [1]).filter((p) => !doc || p <= doc.numPages), [pages, doc])
  useEffect(() => {
    if (list.length < 2) return
    const t = setTimeout(() => setIndex((i) => (i + 1) % list.length), pageDuration * 1000)
    return () => clearTimeout(t)
  }, [index, list, pageDuration])
  useEffect(() => {
    if (!doc || !canvas.current) return
    let task: RenderTask | null = null
    doc.getPage(list[index % list.length]).then((page) => {
      const el = canvas.current!
      const box = el.parentElement!.getBoundingClientRect()
      const base = page.getViewport({ scale: 1 })
      const scale = Math.min((box.width * 1.5) / base.width, (box.height * 1.5) / base.height)
      const viewport = page.getViewport({ scale })
      el.width = viewport.width
      el.height = viewport.height
      task = page.render({ canvas: el, canvasContext: el.getContext('2d')!, viewport })
      task.promise.catch(() => undefined)
    })
    return () => task?.cancel()
  }, [doc, index, list])
  return <div className="media-zone" style={{ background: 'white' }}><canvas ref={canvas} /></div>
}

const DEFAULT_LAYOUT: LayoutZone[] = [
  { id: 'header', type: 'header', x: 0, y: 0, w: 100, h: 11, style: 'dark' },
  { id: 'title', type: 'text', x: 5, y: 16, w: 52, h: 62, style: 'light' },
  { id: 'media', type: 'image', x: 60, y: 16, w: 35, h: 62 },
  { id: 'agenda', type: 'agenda', x: 0, y: 89, w: 100, h: 11, style: 'dark' },
]

export function SlideView({ slide, manifest, now, audio, onError }: { slide: Slide; manifest: Manifest; now: Date; audio: boolean; onError: (m: string) => void }) {
  const portrait = manifest.screen.orientation === 'portrait'
  let zones = slide.layout?.zones?.length ? slide.layout.zones : DEFAULT_LAYOUT
  if (portrait && !slide.layout) zones = [
    { id: 'header', type: 'header', x: 0, y: 0, w: 100, h: 7, style: 'dark' }, { id: 'media', type: 'image', x: 5, y: 10, w: 90, h: 32 },
    { id: 'title', type: 'text', x: 5, y: 45, w: 90, h: 42, style: 'light' }, { id: 'agenda', type: 'agenda', x: 0, y: 90, w: 100, h: 10, style: 'dark' },
  ]
  const main = slide.media.find((m) => m.role === 'main') ?? slide.media[0]
  const render = (zone: LayoutZone): ReactNode => {
    switch (zone.type) {
      case 'header': return <Header manifest={manifest} now={now} lang={slide.lang} />
      case 'text':
        return (
          <div className={`text-zone ${zone.align ? `align-${zone.align}` : ''}`} style={{ fontSize: `${zone.font_scale ?? 1}em` }}>
            {slide.kind === 'welcome' ? <span className="kicker">{slide.lang === 'en' ? 'Welcome to the Commission' : 'Bienvenue à la Commission'}</span> : slide.category && <span className="kicker">{slide.category}</span>}
            <h1>{slide.title}</h1>
            {slide.body && <p>{slide.body}</p>}
            {slide.kind === 'welcome' && slide.delegation?.flags?.length ? <div className="flags">{slide.delegation.flags.map((f) => <span key={f}>{f}</span>)}</div> : <div className="rule" />}
          </div>
        )
      case 'image': case 'video': case 'document':
        if (!main) return <div className="art"><div className="circle"><img src={LOGO} alt="" /></div></div>
        if (main.kind === 'video') return <VideoAsset id={main.asset} subtitles={slide.subtitles} lang={slide.lang} audio={audio} volume={manifest.audio_policy.max_volume} onError={onError} />
        if (main.kind === 'pdf') return <PdfAsset id={main.asset} pages={main.pages} pageDuration={main.page_duration_s ?? 12} onError={onError} />
        return <ImageAsset id={main.asset} />
      case 'agenda': return <AgendaLine agenda={manifest.agenda} lang={slide.lang} />
      case 'clock': return <div className="clock-zone">{time(now)}</div>
      case 'ticker': {
        const news = Object.values(manifest.dynamic ?? {}).flatMap((s) => (s.kind === 'news' ? s.items ?? [] : [])).map((i) => i.title)
        return <div className="ticker-zone"><span>{[slide.title, ...news, ...manifest.agenda.map((a) => `${time(a.start)} ${a.label} — ${a.room}`)].join('   ·   ')}</span></div>
      }
      case 'weather': {
        const w = Object.values(manifest.dynamic ?? {}).find((s) => s.kind === 'weather')
        return w ? <div className="clock-zone" title={`Relevé ${time(w.fetched_at)}`}>{w.place} · {w.temperature}{w.unit}</div> : null
      }
      case 'countdown': {
        const c = Object.values(manifest.dynamic ?? {}).find((s) => s.kind === 'countdown' && s.target)
        if (!c) return null
        const days = Math.max(0, Math.ceil((Date.parse(c.target!) - now.getTime()) / 86400000))
        return <div className="clock-zone">{c.label} · J-{days}</div>
      }
      case 'qr': return slide.qr ? <div className="qr-zone"><div className="qr"><QRCodeSVG value={slide.qr.url} level="M" /></div><small>{slide.qr.label}</small></div> : null
      default: return null
    }
  }
  return (
    <div className="fade-enter" key={`${slide.title}-${slide.lang}`} style={{ position: 'absolute', inset: 0 }}>
      {zones.map((zone) => (
        <div key={zone.id} className={`zone style-${zone.style ?? 'none'}`} style={{ left: `${zone.x}%`, top: `${zone.y}%`, width: `${zone.w}%`, height: `${zone.h}%`, padding: zone.type === 'text' ? '0 1%' : undefined }}>
          {render(zone)}
        </div>
      ))}
      <span className="lang-flag">{slide.lang.toUpperCase()}</span>
    </div>
  )
}

export function AlertView({ alert, now, lang }: { alert: ManifestAlert; now: Date; lang: string }) {
  const t = lang !== 'fr' && alert.translations?.[lang] ? alert.translations[lang] : { title: alert.title, message: alert.message }
  return (
    <div className={`alert-view ${alert.severity}`}>
      <div className="mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M10.3 3.8 2.2 18a2 2 0 0 0 1.7 3h16.2a2 2 0 0 0 1.7-3L13.7 3.8a2 2 0 0 0-3.4 0Z" /><path d="M12 9v4M12 17h.01" /></svg></div>
      <small>{alert.severity === 'exercise' ? (lang === 'en' ? 'DRILL' : 'EXERCICE') : lang === 'en' ? 'PRIORITY ALERT' : 'ALERTE PRIORITAIRE'}</small>
      <h1>{t.title}</h1>
      <p>{t.message}</p>
      <div className="time">{lang === 'en' ? 'Activated' : 'Message activé à'} {time(alert.starts_at)} · {lang === 'en' ? 'ends' : 'fin prévue à'} {time(alert.expires_at)} · {time(now)}</div>
      <footer>{lang === 'en' ? 'This information complements the regulatory safety systems in force.' : 'Cette information complète les dispositifs réglementaires en vigueur.'}</footer>
    </div>
  )
}

export function NeutralFallback({ manifest }: { manifest: Manifest | null }) {
  return (
    <div className="fallback-view">
      <img src={LOGO} alt="CEEAC" />
      <h1>{manifest?.institution.name ?? 'Commission de la CEEAC'}</h1>
      <p>{manifest?.institution.tagline ?? 'Communauté Économique des États de l’Afrique Centrale'}</p>
      <small>Contenu institutionnel de repli</small>
    </div>
  )
}

export function PairingView({ code, expiresAt, online }: { code?: string; expiresAt?: string; online: boolean }) {
  return (
    <div className="pairing-view">
      <img src={LOGO} alt="CEEAC" />
      <h1>Lecteur SAIE-CEEAC non appairé</h1>
      {code ? <div className="code" aria-label={`Code d’appairage ${code}`}>{code.slice(0, 3)}<span style={{ opacity: .4 }}>–</span>{code.slice(3)}</div> : <p>{online ? 'Demande d’un code d’appairage…' : 'Serveur injoignable : nouvelle tentative dans quelques secondes.'}</p>}
      <p>Un administrateur technique DSI saisit ce code dans « Parc d’écrans › Enrôlement des lecteurs » pour associer ce terminal à un écran.</p>
      {expiresAt && <small>Code à usage unique, valable jusqu’à {time(expiresAt)}. Il est renouvelé automatiquement.</small>}
    </div>
  )
}

/** Terminal révoqué (T04) : aucune action proposée au public ; le réappairage est une opération de maintenance (DSI). */
export function RevokedView() {
  return (
    <div className="pairing-view">
      <img src={LOGO} alt="CEEAC" />
      <h1>Terminal hors service</h1>
      <p>Ce lecteur n’est plus autorisé à diffuser. Contactez la DSI.</p>
    </div>
  )
}

/** Panneau de salle (T03, SAL-09) : « Réservée » seulement pour une réunion confidentielle ; « Statut non actualisé » hors ligne. */
export function RoomPanelView({ manifest, now, offline, lastContact }: { manifest: Manifest; now: Date; offline: boolean; lastContact: string | null }) {
  const panel = manifest.room_panel
  if (!panel) return <NeutralFallback manifest={manifest} />
  const t = now.getTime()
  const block = panel.blocks.find((b) => Date.parse(b.start) <= t && Date.parse(b.end) > t)
  const current = panel.occurrences.find((o) => Date.parse(o.start) <= t && Date.parse(o.end) > t)
  const next = panel.occurrences.filter((o) => Date.parse(o.start) > t)
  const state = block || panel.room.status !== 'active' ? 'unavailable' : current ? 'busy' : 'free'
  const label = { free: 'LIBRE', busy: 'OCCUPÉE', unavailable: 'INDISPONIBLE' }[state]
  const detail = state === 'busy' ? `${current!.label} · jusqu’à ${time(current!.end)}` : state === 'unavailable' ? `Indisponible${block ? ` jusqu’à ${time(block.end)}` : ''}` : next[0] ? `Disponible jusqu’à ${time(next[0].start)}` : 'Disponible pour le reste de la journée'
  return (
    <div className="room-view">
      <header><img src={LOGO} alt="CEEAC" /><span>SAIE–CEEAC</span><time>{time(now)}</time></header>
      <main>
        <div>
          <small>SALLE DE RÉUNION · {panel.room.floor.toUpperCase()} · {panel.room.capacity} PLACES</small>
          <h1>{panel.room.name}</h1>
          <span className={`room-state ${state}`}>{label}</span>
          <p>{detail}</p>
        </div>
        <div className="next-box">
          <small>PROCHAINE RÉSERVATION</small>
          {next[0] ? <><b>{time(next[0].start)} — {time(next[0].end)}</b><p>{next[0].label}{next[0].participants ? ` · ${next[0].participants} participants` : ''}</p></> : <p>Aucune autre réservation aujourd’hui</p>}
          {next.length > 1 && <ul>{next.slice(1, 4).map((o) => <li key={o.start}>{time(o.start)}–{time(o.end)} · {o.label}</li>)}</ul>}
        </div>
      </main>
      {offline && <footer>⚠ Statut non actualisé · dernier contact {lastContact ? stamp(lastContact) : 'inconnu'} · aucune réservation possible depuis ce panneau</footer>}
    </div>
  )
}

/** Orientation (T05, ACC-01) générée depuis les référentiels, alternée FR/EN, variante Bienvenue (ACC-02). */
/** Retour automatique à l'affichage normal après une minute sans interaction (borne tactile). */
const TOUCH_IDLE_MS = 60_000

/**
 * ORI-01 : annuaire tactile — filtres par niveau et par initiale (aucun clavier), plan du niveau choisi.
 * Aucune donnée personnelle : structures, bureaux, téléphones d'accueil et salles seulement.
 */
function TouchDirectory({ manifest, lang, onClose }: { manifest: Manifest; lang: string; onClose: () => void }) {
  const floors = manifest.orientation?.floors ?? []
  const [level, setLevel] = useState<number | null>(null)
  const [letter, setLetter] = useState<string | null>(null)
  const entries = directoryEntries(floors, { level, letter })
  const plan = level !== null ? floors.find((f) => f.level === level)?.plan ?? null : null
  const en = lang === 'en'
  return (
    <div className="touch-directory">
      <header>
        <h1>{en ? 'Directory' : 'Annuaire'}</h1>
        <button className="touch-close" onClick={onClose}>{en ? 'Close' : 'Fermer'}</button>
      </header>
      <nav aria-label={en ? 'Floors' : 'Niveaux'} className="touch-chips">
        <button className={level === null ? 'on' : ''} onClick={() => setLevel(null)}>{en ? 'All floors' : 'Tous les niveaux'}</button>
        {floors.map((f) => <button key={f.level} className={level === f.level ? 'on' : ''} onClick={() => setLevel(f.level)}>{f.label}</button>)}
      </nav>
      <nav aria-label={en ? 'Initials' : 'Initiales'} className="touch-chips letters">
        <button className={letter === null ? 'on' : ''} onClick={() => setLetter(null)}>A–Z</button>
        {availableLetters(floors).map((l) => <button key={l} className={letter === l ? 'on' : ''} onClick={() => setLetter(l)}>{l}</button>)}
      </nav>
      <div className="touch-body">
        <ul className="touch-list">
          {entries.map((e) => (
            <li key={`${e.kind}-${e.level}-${e.name}`}>
              <strong>{e.name}</strong>
              <span>{e.floor}{e.office ? ` · ${e.office}` : ''}{e.kind === 'room' ? ` · ${en ? 'meeting room' : 'salle de réunion'}` : ''}</span>
              {e.phone && <small>{en ? 'Reception' : 'Accueil'} : {e.phone}</small>}
            </li>
          ))}
          {entries.length === 0 && <li><span>{en ? 'No entry for this selection.' : 'Aucune entrée pour cette sélection.'}</span></li>}
        </ul>
        {plan && <div className="touch-plan"><ImageAsset id={plan} /></div>}
      </div>
    </div>
  )
}

export function OrientationView({ manifest, now, lang }: { manifest: Manifest; now: Date; lang: string }) {
  const data = manifest.orientation
  const [touching, setTouching] = useState(false)
  const idle = useRef<ReturnType<typeof setTimeout> | null>(null)
  const poke = () => {
    if (idle.current) clearTimeout(idle.current)
    idle.current = setTimeout(() => setTouching(false), TOUCH_IDLE_MS)
  }
  useEffect(() => () => { if (idle.current) clearTimeout(idle.current) }, [])
  if (data?.touch && touching) {
    return <div style={{ position: 'absolute', inset: 0 }} onPointerDown={poke}><TouchDirectory manifest={manifest} lang={lang} onClose={() => setTouching(false)} /></div>
  }
  const welcome = data?.welcomes.find((w) => w.lang === lang) ?? data?.welcomes[0]
  const showWelcome = welcome && Math.floor(now.getTime() / 15000) % 2 === 0
  if (showWelcome) {
    return (
      <div className="welcome-view fade-enter">
        <img src={LOGO} alt="CEEAC" />
        <small>{lang === 'en' ? 'WELCOME TO THE COMMISSION' : 'BIENVENUE À LA COMMISSION'}</small>
        <h1>{welcome.delegation?.name ?? welcome.title}</h1>
        <p>{welcome.body}</p>
      </div>
    )
  }
  return (
    <div className="orientation-view">
      <div className="zone style-dark" style={{ position: 'relative' }}><Header manifest={manifest} now={now} lang={lang} /></div>
      <div className="floors">
        {(data?.floors ?? []).map((f) => (
          <div className="floor" key={f.level}>
            <b>{f.level === 0 ? (lang === 'en' ? 'G' : 'RDC') : f.level}</b>
            <div>
              <strong>{f.structures.join(' · ') || (lang === 'en' ? 'Offices' : 'Bureaux')}</strong>
              {f.rooms.length > 0 && <span>{lang === 'en' ? 'Rooms' : 'Salles'} : {f.rooms.map((r) => r.name).join(', ')}</span>}
            </div>
          </div>
        ))}
      </div>
      <div className="zone style-dark" style={{ position: 'relative' }}><AgendaLine agenda={manifest.agenda} lang={lang} /></div>
      <span className="lang-flag">{lang.toUpperCase()}</span>
      {data?.touch && <button className="touch-open" onClick={() => { setTouching(true); poke() }}>{lang === 'en' ? 'Touch to find a service' : 'Touchez pour trouver un service'}</button>}
    </div>
  )
}
