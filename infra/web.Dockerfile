# Construit le frontend et le lecteur puis les sert avec Nginx devant PHP-FPM (même domaine : /, /player/, /api).
FROM node:22-bookworm AS build
WORKDIR /src
COPY frontend/package*.json frontend/
COPY player/package*.json player/
RUN cd frontend && npm ci && cd ../player && npm ci
COPY frontend frontend
COPY player player
RUN cd frontend && npm run build && cd ../player && NODE_ENV=production npm run build

FROM nginx:1.27-alpine
COPY infra/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /src/frontend/dist /usr/share/nginx/html/app
COPY --from=build /src/player/dist /usr/share/nginx/html/player
COPY backend/public /var/www/html/public
