import { useEffect } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { api, ApiError } from '../../lib/api'
import { CLASS_LABEL, fmtDate, fmtDateTime, LANG_LABEL } from '../../lib/format'
import { Icon, Kv, Loading, Notice, Status } from '../../components/ui'
import './account.css'

interface PublicDocument {
  id: string; reference: string; title: string; summary: string | null; classification: string; language: string | null; keywords: string[]
  category: { id: string; name: string } | null; owner: { id: string; name: string } | null; valid_from: string | null; expires_at: string | null
  current_version: { id: string; version: number } | null; published_at: string | null; updated_at: string | null
}
type QrData =
  | { type: 'document'; public: true; document: PublicDocument }
  | { type: 'document'; public: false; redirect: string }
  | { type: 'content'; public: boolean; title: string; redirect?: string }

const internal = (link: unknown): link is string => typeof link === 'string' && link.startsWith('/') && !link.startsWith('//')

/** Résolution d'un QR code (DOC-05, DOC-07) : fiche publique sans fichier, ou redirection après réévaluation des droits. */
export default function QrResolve() {
  const { code = '' } = useParams()
  const navigate = useNavigate()
  const query = useQuery({
    queryKey: ['qr', code],
    queryFn: ({ signal }) => api.get<{ data: QrData }>(`/q/${encodeURIComponent(code)}`, undefined, signal).then((r) => r.data),
    retry: false,
  })

  const redirect = query.data && 'redirect' in query.data ? query.data.redirect : undefined
  const err = query.error instanceof ApiError ? query.error : null

  useEffect(() => {
    if (internal(redirect)) navigate(redirect, { replace: true })
  }, [redirect, navigate])
  useEffect(() => {
    if (err?.status === 401) {
      const target = internal(err.details.redirect) ? err.details.redirect : `/q/${code}`
      navigate(`/connexion?retour=${encodeURIComponent(target)}`, { replace: true, state: { expired: false } })
    }
  }, [err, code, navigate])

  let body
  if (query.isLoading || internal(redirect) || err?.status === 401) {
    body = <Loading label="Résolution du lien…" />
  } else if (err) {
    body = err.status === 403 ? (
      <div className="state-box forbidden" role="alert">
        <div className="empty-icon"><Icon name="shield" size={23} /></div>
        <h3>Accès refusé</h3>
        <p>Vos habilitations actuelles ne permettent pas d’ouvrir ce document. Si vous pensez qu’il s’agit d’une erreur, contactez le propriétaire du document.</p>
      </div>
    ) : (
      <div className="state-box" role="alert">
        <div className="empty-icon"><Icon name="search" size={23} /></div>
        <h3>{err.status === 404 ? 'Lien indisponible' : 'Résolution impossible'}</h3>
        <p>{err.message}</p>
        {err.requestId && <small className="mono">Diagnostic : {err.requestId}</small>}
      </div>
    )
  } else if (query.data?.type === 'document' && query.data.public) {
    const doc = query.data.document
    body = (
      <div className="qr-public">
        <Status tone="success">{CLASS_LABEL[doc.classification] ?? doc.classification}</Status>
        <h1 style={{ marginTop: 10 }}>{doc.title}</h1>
        <p className="muted small mono">{doc.reference}</p>
        {doc.summary && <p style={{ marginTop: 10, fontSize: 12 }}>{doc.summary}</p>}
        <Kv items={[
          ['Catégorie', doc.category?.name ?? null], ['Langue', doc.language ? LANG_LABEL[doc.language] ?? doc.language : null], ['Service émetteur', doc.owner?.name ?? null],
          ['Version', doc.current_version ? `v${doc.current_version.version}` : null], ['Publié le', doc.published_at ? fmtDateTime(doc.published_at) : null],
          ['Valide du', doc.valid_from ? fmtDate(doc.valid_from) : null], ['Expire le', doc.expires_at ? fmtDate(doc.expires_at) : null],
        ]} />
        <div style={{ marginTop: 14 }}><Notice tone="info">Fiche publique : le fichier n’est pas téléchargeable depuis ce lien. Il est disponible auprès du service émetteur ou dans l’espace documentaire pour les personnes habilitées.</Notice></div>
      </div>
    )
  } else if (query.data?.type === 'content') {
    body = (
      <div className="qr-public">
        <h1>{query.data.title}</h1>
        <p className="muted small" style={{ marginTop: 6 }}>{query.data.public ? 'Information publique diffusée sur les écrans de la Commission.' : 'Contenu interne : consultable par les personnes habilitées.'}</p>
      </div>
    )
  } else {
    body = <Notice tone="warning">Ce lien ne correspond à aucun contenu consultable.</Notice>
  }

  return (
    <main className="public-shell">
      <section className="panel public-card" aria-live="polite">
        <div className="public-brand">
          <img src="/ceeac-logo.jpg" alt="Logo officiel de la CEEAC" />
          <div><strong>SAIE<span>—CEEAC</span></strong><div className="muted small">Commission de la CEEAC</div></div>
        </div>
        {body}
        <div style={{ marginTop: 18, display: 'flex', justifyContent: 'space-between', gap: 10 }}>
          <Link to="/connexion" className="link">Se connecter</Link>
          <span className="muted" style={{ fontSize: 10 }}>Code : <span className="mono">{code}</span></span>
        </div>
      </section>
    </main>
  )
}
