import { useCallback, useEffect, useMemo, useState, useSyncExternalStore } from 'react'
import { engine } from './engine.ts'
import { audioAllowed, fallbackValid, liveView, resolve } from './schedule.ts'
import type { Manifest } from './types.ts'
import { AlertView, NeutralFallback, OfflineRibbon, OrientationView, PairingView, RevokedView, RoomPanelView, SlideView, Stage } from './views.tsx'

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
const rotatingLang = (now: Date, langs = ['fr', 'en']) => langs[Math.floor(now.getTime() / 12000) % langs.length]

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
  return <Display manifest={manifest} now={now} offline={offline} lastContact={state.lastContact} readyAssets={state.readyAssets} revoked={state.phase === 'revoked'} onError={onError} />
}

function Display({ manifest, now, offline, lastContact, readyAssets, revoked, onError }: { manifest: Manifest; now: Date; offline: boolean; lastContact: string | null; readyAssets: Set<string>; revoked: boolean; onError: (m: string) => void }) {
  const resolution = useMemo(() => resolve(manifest, now, (id) => readyAssets.has(id)), [manifest, now, readyAssets])
  const playlist = resolution.mode === 'program' ? resolution.playlist : []
  const signature = playlist.map((e) => e.entry_id).join('|')
  const [index, setIndex] = useState(0)
  const entry = playlist.length ? playlist[index % playlist.length] : null

  useEffect(() => setIndex(0), [signature])
  useEffect(() => {
    if (!entry) return
    engine.reportPlay(entry, manifest.version)
    if (playlist.length < 2) return
    const t = setTimeout(() => setIndex((i) => i + 1), Math.max(10, entry.duration_s) * 1000)
    return () => clearTimeout(t)
  }, [entry, playlist.length, manifest.version])
  useEffect(() => {
    if (resolution.mode === 'alert') resolution.alerts.forEach((a) => engine.reportAlertActivated(a.id, manifest.version))
    engine.reportMode(resolution.mode)
  }, [resolution, manifest.version])

  // Hors plage : écran noir et synchronisation espacée (PLY-08). L'extinction de la dalle dépend du canal matériel.
  if (resolution.mode === 'off') return <Stage resolution={manifest.screen.resolution} off>{null}</Stage>

  const lang = rotatingLang(now)
  const audio = audioAllowed(manifest, now)
  let body
  if (resolution.mode === 'alert') {
    body = <AlertView alert={resolution.alerts[0]} now={now} lang={lang} />
  } else if (manifest.screen.kind === 'room_panel') {
    body = <RoomPanelView manifest={manifest} now={now} offline={offline} lastContact={lastContact} />
  } else if (manifest.screen.kind === 'orientation') {
    body = <OrientationView manifest={manifest} now={now} lang={lang} />
  } else if (entry) {
    body = <SlideView key={entry.entry_id} slide={entry.slide} manifest={manifest} now={now} audio={audio} onError={onError} />
  } else if (fallbackValid(manifest, now)) {
    body = <SlideView slide={manifest.fallback.slide!} manifest={manifest} now={now} audio={false} onError={onError} />
  } else if (revoked) {
    body = <RevokedView />
  } else {
    body = <NeutralFallback manifest={manifest} />
  }
  return (
    <Stage resolution={manifest.screen.resolution}>
      {body}
      {offline && manifest.screen.kind !== 'room_panel' && resolution.mode !== 'alert' && <OfflineRibbon lastContact={lastContact} />}
    </Stage>
  )
}
