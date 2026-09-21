# Multi-stage: `development` (used by docker-compose.yml, bind-mounts
# the repo over this COPY — what actually runs is the host's own
# working tree) and `production` (used by docker-compose.staging.yml —
# a real `nuxt build` output, no dev server, no bind-mount; the image
# itself is the deployable artifact, per ADR-0002's "Production
# artifacts must be immutable"). Neither existing `docker-compose.yml`
# behavior nor its own dev workflow changes — it now builds with
# `target: development` explicitly rather than relying on "last stage
# wins".

FROM node:26.8.1-alpine AS development

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

EXPOSE 3000

CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "3000"]

# Build stage — production only. Installs full (dev-included)
# dependencies since `nuxt build` itself is a build-time tool, then
# produces the real Nitro server bundle under `.output/`.
FROM node:26.8.1-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN npm run build

# Production runtime — used by docker-compose.staging.yml. Only the
# built `.output/` (a self-contained Nitro server, its own production
# `node_modules` already bundled in) is copied in; no source, no dev
# dependencies, no build tooling ship in this layer.
FROM node:26.8.1-alpine AS production

WORKDIR /app

COPY --from=build /app/.output ./.output

ENV NUXT_HOST=0.0.0.0
ENV NUXT_PORT=3000

EXPOSE 3000

CMD ["node", ".output/server/index.mjs"]
