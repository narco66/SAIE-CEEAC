import { useEffect, useSyncExternalStore } from 'react'
import { useQueryClient } from '@tanstack/react-query'

/**
 * §10.22 : flux temps réel du parc (Server-Sent Events). Chaque annonce ne porte que la nature du changement et l'écran ;
 * les vues concernées sont relues par l'API (bornée au périmètre). Le navigateur se reconnecte seul et reprend au dernier
 * identifiant reçu ; le rafraîchissement périodique des pages reste en secours si le flux est indisponible.
 */
type Status = 'connecting' | 'live' | 'offline'
let status: Status = 'offline'
const listeners = new Set<() => void>()
const setStatus = (s: Status) => {
  status = s
  listeners.forEach((l) => l())
}

export function useRealtimeStatus(): Status {
  return useSyncExternalStore((l) => {
    listeners.add(l)
    return () => listeners.delete(l)
  }, () => status)
}

interface Change { topic: 'screen' | 'device' | 'incident' | 'command'; screen_id: string | null; ref_id: string | null }

export function useRealtime(enabled: boolean): void {
  const client = useQueryClient()
  useEffect(() => {
    if (!enabled || typeof EventSource === 'undefined') return
    let source: EventSource | null = null
    let timer: number | undefined
    const pending = new Set<string>()
    const flush = () => {
      timer = undefined
      const keys = [...pending]
      pending.clear()
      for (const key of keys) {
        const [kind, id] = key.split(':')
        if (kind === 'screen' && id) client.invalidateQueries({ queryKey: ['screen', id] })
        if (kind === 'all') {
          for (const k of ['screens', 'supervision', 'incidents', 'incident-stats', 'fleet-overview', 'screen-groups', 'device-commands', 'screen-history', 'device-logs']) client.invalidateQueries({ queryKey: [k] })
        }
      }
    }
    const open = () => {
      setStatus('connecting')
      source = new EventSource('/api/v1/realtime/stream', { withCredentials: true })
      source.onopen = () => setStatus('live')
      source.addEventListener('change', (e) => {
        const change = JSON.parse((e as MessageEvent<string>).data) as Change
        pending.add('all')
        if (change.screen_id) pending.add(`screen:${change.screen_id}`)
        // Regroupement : une rafale d'annonces ne provoque qu'une relecture.
        timer ??= window.setTimeout(flush, 800)
      })
      // Fin de flux bornée (reconnexion automatique) ou serveur indisponible : état affiché, reprise par le navigateur.
      source.onerror = () => setStatus(source?.readyState === EventSource.CLOSED ? 'offline' : 'connecting')
    }
    const onVisibility = () => {
      if (document.hidden) {
        source?.close()
        source = null
        setStatus('offline')
      } else if (!source) {
        open()
      }
    }
    open()
    document.addEventListener('visibilitychange', onVisibility)
    return () => {
      document.removeEventListener('visibilitychange', onVisibility)
      source?.close()
      if (timer) window.clearTimeout(timer)
      setStatus('offline')
    }
  }, [enabled, client])
}
