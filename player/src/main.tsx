import { Component, type ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import '@fontsource/dm-sans/400.css'
import '@fontsource/dm-sans/700.css'
import '@fontsource/manrope/700.css'
import '@fontsource/manrope/800.css'
import './player.css'
import Player from './Player.tsx'
import { engine } from './engine.ts'
import { NeutralFallback, Stage } from './views.tsx'

/*
 * Chien de garde logiciel (PLY-01) : rechargement si le rendu est figé plus de 60 s ou après une rafale d'erreurs.
 * Le redémarrage du processus navigateur et du terminal relève du superviseur kiosque (voir docs/lecteur.md).
 */
const w = window as unknown as { __saieLastRender: number }
w.__saieLastRender = Date.now()
let errors: number[] = []
const onFatal = (message: string) => {
  engine.pushError(message)
  errors = [...errors.filter((t) => Date.now() - t < 60_000), Date.now()]
  if (errors.length >= 5) setTimeout(() => location.reload(), 5000)
}
window.addEventListener('error', (e) => onFatal(`Erreur : ${e.message}`))
window.addEventListener('unhandledrejection', (e) => onFatal(`Promesse rejetée : ${String(e.reason).slice(0, 200)}`))
setInterval(() => {
  if (Date.now() - w.__saieLastRender > 60_000) location.reload()
}, 15_000)

if ('serviceWorker' in navigator && import.meta.env.PROD) {
  navigator.serviceWorker.register(`${import.meta.env.BASE_URL}sw.js`, { scope: import.meta.env.BASE_URL }).catch(() => undefined)
}

/** Une erreur de rendu affiche le repli institutionnel neutre (jamais un écran blanc) puis relance le rendu (PLY-01). */
class RenderGuard extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError(): { failed: boolean } {
    return { failed: true }
  }

  componentDidCatch(error: Error): void {
    onFatal(`Rendu interrompu : ${error.message}`)
    setTimeout(() => this.setState({ failed: false }), 10_000)
  }

  render(): ReactNode {
    return this.state.failed ? <Stage><NeutralFallback manifest={null} /></Stage> : this.props.children
  }
}

createRoot(document.getElementById('root')!).render(<RenderGuard><Player /></RenderGuard>)
engine.start()
