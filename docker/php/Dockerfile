FROM node:22-bookworm-slim AS node

FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev git ca-certificates curl \
    && docker-php-ext-install pdo_pgsql \
    && curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg -o /usr/share/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" > /etc/apt/sources.list.d/github-cli.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends gh \
    && rm -rf /var/lib/apt/lists/*

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && npm install -g @earendil-works/pi-coding-agent@0.87.1

# Pi writes ~/.pi/grok-cli/accounts.json mode 0600. The process uid must match
# the host user or that file becomes root-owned and the host cannot open it.
RUN groupadd --gid 1000 holder \
    && useradd --uid 1000 --gid 1000 --create-home --shell /bin/sh holder

WORKDIR /repo/api
