# syntax=docker/dockerfile:1.7
#
# Next.js customer web app, standalone output.
# Build from the REPOSITORY ROOT:
#   docker build -f apps/customer-web/Dockerfile -t sfo-customer-web:local .
#
# NEXT_PUBLIC_* values are inlined at build time, so they are build args here,
# not runtime environment. They are public by definition - never put a secret
# in one.
ARG NODE_VERSION=22.12.0
ARG PNPM_VERSION=10.34.9

FROM node:${NODE_VERSION}-alpine AS base
ARG PNPM_VERSION
ENV PNPM_HOME=/pnpm
ENV PATH=$PNPM_HOME:$PATH
# Installed through npm rather than corepack: the corepack bundled with
# this Node release cannot verify current pnpm releases, and the version is
# pinned either way.
RUN npm install -g pnpm@${PNPM_VERSION}
WORKDIR /app

FROM base AS deps
COPY pnpm-lock.yaml pnpm-workspace.yaml package.json .npmrc ./
COPY apps/customer-web/package.json ./apps/customer-web/
COPY packages/api-contracts/package.json ./packages/api-contracts/
COPY packages/ui/package.json ./packages/ui/
COPY packages/config/package.json ./packages/config/
RUN --mount=type=cache,id=pnpm-store,target=/pnpm/store \
    pnpm install --frozen-lockfile --filter @scp/customer-web... --filter .

FROM deps AS build
ARG NEXT_PUBLIC_API_BASE_URL=http://localhost:3000/api/v1
ARG NEXT_PUBLIC_SITE_NAME=Smart Commerce Platform
ENV NEXT_PUBLIC_API_BASE_URL=$NEXT_PUBLIC_API_BASE_URL
ENV NEXT_PUBLIC_SITE_NAME=$NEXT_PUBLIC_SITE_NAME
ENV NEXT_TELEMETRY_DISABLED=1
# Produce .next/standalone, which the runtime stage copies.
ENV NEXT_OUTPUT_STANDALONE=true
COPY tsconfig.base.json turbo.json ./
COPY packages ./packages
COPY apps/customer-web ./apps/customer-web
RUN pnpm --filter @scp/api-contracts build \
 && pnpm --filter @scp/customer-web build

FROM base AS runtime
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV PORT=3001
ENV HOSTNAME=0.0.0.0

# Standalone output already contains the pruned node_modules it needs.
COPY --from=build --chown=node:node /app/apps/customer-web/.next/standalone ./
COPY --from=build --chown=node:node /app/apps/customer-web/.next/static ./apps/customer-web/.next/static
COPY --from=build --chown=node:node /app/apps/customer-web/public ./apps/customer-web/public

USER node
EXPOSE 3001

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD node -e "require('node:http').get('http://127.0.0.1:'+process.env.PORT+'/',r=>process.exit(r.statusCode<500?0:1)).on('error',()=>process.exit(1))"

CMD ["node", "apps/customer-web/server.js"]
