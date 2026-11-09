# Construit le frontend et le lecteur puis les sert avec Nginx devant PHP-FPM (même domaine : /, /player/, /api), en HTTPS.
# Construction depuis la racine du dépôt : docker build -f infra/web.Dockerfile .   (liste blanche : web.Dockerfile.dockerignore)
# Images de base figées par la pile (SAIE_IMAGE_NODE, SAIE_IMAGE_NGINX, étiquette@sha256) ; valeurs par défaut pour une construction manuelle.
ARG NODE_IMAGE=node:22-bookworm
ARG NGINX_IMAGE=nginx:1.27-alpine
FROM ${NODE_IMAGE} AS build
WORKDIR /src
COPY frontend/package.json frontend/package-lock.json frontend/
COPY player/package.json player/package-lock.json player/
RUN cd frontend && npm ci && cd ../player && npm ci
COPY frontend frontend
COPY player player
RUN cd frontend && npm run build && cd ../player && NODE_ENV=production npm run build

FROM ${NGINX_IMAGE}
RUN rm /etc/nginx/conf.d/default.conf && mkdir -p /etc/nginx/snippets /etc/nginx/certs /srv/player-releases /var/www/acme
COPY infra/nginx.conf /etc/nginx/conf.d/saie.conf
COPY infra/nginx/security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY --from=build /src/frontend/dist /usr/share/nginx/html/app
COPY --from=build /src/player/dist /usr/share/nginx/html/player
COPY backend/public /var/www/html/public
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -qO- http://127.0.0.1/nginx-health || exit 1
