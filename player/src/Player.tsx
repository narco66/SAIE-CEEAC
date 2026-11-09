import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react'
import { engine } from './engine.ts'
import { audioAllowed, fallbackValid, liveView, resolve } from './schedule.ts'
import type { Manifest } from './types.ts'
import { AlertView, NeutralFallback, OfflineRibbon, OrientationView, PairingView, RevokedView, RoomPanelView, setDisplayTimezone, SlideView, Stage } from './views.tsx'

function useEngine() {
  return useSyncExternalStore(engine.subscribe, engine.getSnapshot)
}

function useNow(): Date {
  const [now, setNow] = useState(() => engine.now())
  useEffect(() => {
    const t = setInterval(() => {
      setNow(engine.now())
      ;(window as unknown as { __saieLastRender: number }).__saieLastRender = Date.now()
    }, 1000)
    return () => clearInterval(t)
  }, [])
  return now
}

/** Langue de rotation des vues non programmées (alertes, orientation) : FR puis EN, toutes les 12 secondes. */
const rotatingLang = (now: Date, langs = ['fr', 'en'], periodS = 12) => langs[Math.floor(now.getTime() / (periodS * 1000)) % langs.length]

export default function Player() {
  const state = useEngine()
  const now = useNow()
  // Réappairage d'un terminal révoqué : opération de maintenance au clavier (Ctrl + Alt + Maj + P), jamais un bouton public (T04).
  useEffect(() => {
    if (state.phase !== 'revoked') return
    const onKey = (e: KeyboardEvent) => { if (e.ctrlKey && e.altKey && e.shiftKey && e.code === 'KeyP') void engine.resetPairing() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [state.phase])
  // Programme du manifeste actif ; alertes et informations de salle du dernier manifeste vérifié (PUB-06, PLY-05).
  const manifest = useMemo(() => (state.active ? liveView(state.active.manifest, state.live, now) : null), [state.active, state.live, now])
  const offline = !state.online && (!state.lastContact || now.getTime() - Date.parse(state.lastContact) > 90_000)
  const onError = useCallback((m: string) => engine.pushError(m), [])

  if (state.phase === 'boot') return <Stage><NeutralFallback manifest={null} /></Stage>
  if (state.phase === 'pairing') return <Stage><PairingView code={state.pairing?.code} expiresAt={state.pairing?.expiresAt} online={state.online} /></Stage>
  if (!manifest) {
    return (
      <Stage>
        {state.phase === 'revoked' ? <RevokedView /> : (
          <div className="pairing-view">
            <h1>Téléchargement initial du programme</h1>
            {state.download ? <><div className="progress-bar"><i style={{ width: `${(state.download.done / state.download.total) * 100}%` }} /></div><p>{state.download.done} / {state.download.total} ressources vérifiées</p></> : <p>{state.online ? 'Synchronisation en cours…' : 'Serveur injoignable : cache vide, aucun programme disponible.'}</p>}
          </div>
        )}
      </Stage>
    )
  }
  return <Display manifest={manifest} now={now} offline={offline} lastContact={state.lastContact} readyAssets={state.readyAssets} revoked={state.phase === 'revoked'} paused={state.paused} onError={onError} />
}

function Display({ manifest, now, offline, lastContact, readyAssets, revoked, paused, onError }: { manifest: Manifest; now: Date; offline: boolean; lastContact: string | null; readyAssets: Set<string>; revoked: boolean; paused: boolean; onError: (m: string) => void }) {
  const resolution = useMemo(() => resolve(manifest, now, (id) => readyAssets.has(id)), [manifest, now, readyAssets])
  const playlist = resolution.mode === 'program' ? resolution.playlist : []
  const signature = playlist.map((e) => e.entry_id).join('|')
  const [index, setIndex] = useState(0)
  const [cycle, setCycle] = useState(0)
  const entry = playlist.length ? playlist[index % playlist.length] : null
  // Seule une diapositive réellement rendue compte comme lecture : un panneau de salle ou une borne d'orientation n'affiche pas la liste.
  const showsSlides = manifest.screen.kind !== 'room_panel' && manifest.screen.kind !== 'orientation'
  const failedEntry = useRef<string | null>(null)
  const onSlideError = useCallback((message: string) => {
    if (entry) failedEntry.current = entry.entry_id
    if (entry) engine.pushError(message, { entry_id: entry.entry_id })
    else onError(message)
  }, [entry, onError])

  useEffect(() => setIndex(0), [signature])
  useEffect(() => engine.applyFullscreen(), [manifest.config?.hash])
  useEffect(() => {
    // Diffusion arrêtée : aucune lecture n'est déclarée pendant l'écran noir (preuve de diffusion non faussée).
    if (!entry || !showsSlides || paused) return
    failedEntry.current = null
    const startedAt = Date.now()
    engine.reportPlaying(entry)
    // PUB-07 : la lecture est déclarée à la fin du créneau, avec sa durée réelle, et seulement si le rendu n'a pas échoué.
    const t = setTimeout(() => {
      if (failedEntry.current !== entry.entry_id) engine.reportPlay(entry, manifest.version, (Date.now() - startedAt) / 1000)
      if (playlist.length >= 2) setIndex((i) => i + 1)
      else setCycle((c) => c + 1)
    }, Math.max(10, entry.duration_s) * 1000)
    return () => clearTimeout(t)
  }, [entry, playlist.length, manifest.version, showsSlides, cycle, paused])
  useEffect(() => {
    if (resolution.mode === 'alert') resolution.alerts.forEach((a) => engine.reportAlertActivated(a.id, manifest.version))
    engine.reportMode(paused && resolution.mode !== 'alert' ? 'paused' : resolution.mode)
  }, [resolution, manifest.version, paused])

  // Hors plage : écran noir et synchronisation espacée (PLY-08). L'extinction de la dalle dépend du canal matériel.
  // §7.1-b : rotation logicielle quand le profil la demande et que la sortie vidéo n'a pas l'orientation de l'écran.
  setDisplayTimezone(manifest.timezone)
  const rotate = Boolean(manifest.config?.settings.software_rotation) && (manifest.screen.orientation === 'portrait') === (window.innerWidth > window.innerHeight)
  if (resolution.mode === 'off') return <Stage resolution={manifest.screen.resolution} off>{null}</Stage>
  // Diffusion arrêtée à distance (§11) : écran noir ; une alerte prioritaire reste toujours affichée.
  if (paused && resolution.mode !== 'alert') return <Stage resolution={manifest.screen.resolution} off>{null}</Stage>

  // §7.3 : langues et période de rotation du profil de configuration, sinon français puis anglais toutes les 12 s.
  const lang = rotatingLang(now, manifest.config?.settings.languages?.length ? manifest.config.settings.languages : undefined, manifest.config?.settings.language_rotation_s)
  const audio = audioAllowed(manifest, now)
  let body
  if (resolution.mode === 'alert') {
    body = <AlertView alert={resolution.alerts[0]} now={now} lang={lang} />
  } else if (manifest.screen.kind === 'room_panel') {
    body = <RoomPanelView manifest={manifest} now={now} offline={offline} lastContact={lastContact} />
  } else if (manifest.screen.kind === 'orientation') {
    body = <OrientationView manifest={manifest} now={now} lang={lang} />
  } else if (entry) {
    body = <SlideView key={entry.entry_id} slide={entry.slide} manifest={manifest} now={now} audio={audio} onError={onSlideError} />
  } else if (fallbackValid(manifest, now)) {
    body = <SlideView slide={manifest.fallback.slide!} manifest={manifest} now={now} audio={false} onError={onError} />
  } else if (revoked) {
    body = <RevokedView />
  } else {
    body = <NeutralFallback manifest={manifest} />
  }
  return (
    <Stage resolution={manifest.screen.resolution} rotate={rotate}>
      {body}
      {offline && manifest.screen.kind !== 'room_panel' && resolution.mode !== 'alert' && <OfflineRibbon lastContact={lastContact} />}
    </Stage>
  )
}
