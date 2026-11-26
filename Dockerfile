FROM php:8.3-cli-bookworm AS http
WORKDIR /app
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates libcurl4-openssl-dev libonig-dev libxml2-dev \
    && docker-php-ext-install -j2 curl mbstring dom simplexml pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY App ./App
COPY scripts ./scripts
COPY bootstrap.php ./bootstrap.php
COPY tests ./tests
RUN php tests/run.php && php tests/url-guard.php
USER www-data
CMD ["php", "scripts/collect.php"]

FROM node:24-bookworm-slim AS browser-dependencies
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts

FROM http AS browser
USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends chromium xvfb xauth fonts-liberation \
    && install -d -o www-data -g www-data /var/www/.config /var/www/.cache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=browser-dependencies /usr/local/bin/node /usr/local/bin/node
COPY --from=browser-dependencies /app/node_modules ./node_modules
ENV CHROMIUM_PATH=/usr/bin/chromium COLLECTOR_BROWSER_ENABLED=true FUZU_BROWSER_HEADLESS=true
# Crashpad uses Chromium's default config directory even with a temporary profile.
ENV XDG_CONFIG_HOME=/var/www/.config XDG_CACHE_HOME=/var/www/.cache
USER www-data
CMD ["sh", "/app/scripts/browser-entrypoint.sh"]
RUN node --test tests/fuzu-browser.mjs
