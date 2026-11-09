/**
 * Abonnement aux notifications push du navigateur (NOT-03). La clé publique VAPID est fournie par l'API (/me/push)
 * uniquement quand le canal est configuré et activé par l'administration.
 */
import { api } from './api'

export interface PushStatus { available: boolean; public_key: string | null; subscriptions: number; events: string[] }

export function pushSupported(): boolean {
  return typeof window !== 'undefined' && window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
  const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(padded)
  const bytes = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i)
  return bytes
}

/** Demande l'autorisation, abonne ce navigateur et enregistre l'abonnement côté serveur. */
export async function subscribePush(publicKey: string): Promise<PushStatus> {
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') {
    throw new Error('Autorisation refusée par le navigateur : activez les notifications pour ce site dans ses réglages.')
  }
  const registration = await navigator.serviceWorker.register('/sw.js')
  await navigator.serviceWorker.ready
  const existing = await registration.pushManager.getSubscription()
  const subscription = existing ?? await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(publicKey) })
  const json = subscription.toJSON()
  const res = await api.post<{ data: PushStatus }>('/me/push', { endpoint: json.endpoint, keys: json.keys })
  return res.data
}

/** Désabonne ce navigateur et supprime les abonnements de l'utilisateur côté serveur. */
export async function unsubscribePush(): Promise<PushStatus> {
  const registration = await navigator.serviceWorker.getRegistration('/sw.js')
  const subscription = await registration?.pushManager.getSubscription()
  await subscription?.unsubscribe()
  const res = await api.delete<{ data: PushStatus }>('/me/push')
  return res.data
}
