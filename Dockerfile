# App runtime image — shared across Internal Prod, every External Client
# instance, and the dev droplet's local test stack (see
# stack.xten.au/VPS-Two-Droplet-Deploy-Plan.md, "Two Docker images, not
# three"). Per-instance differences (DB creds, site branding, enabled
# modules) come from env vars and the database, not from separate images.
#
# Pinned to PHP 8.3 (see the plan doc, stack.xten.au/roadmap/Library-Upgrade-Roadmap.md,
# for the sequencing rationale beyond this one dependency). Phalcon
# itself comes straight from its own GitHub releases now, not
# packages.sury.org — see the dedicated RUN block below (REQ-204,
# 2026-09-06): sury.org quietly dropped its PHP 8.3 Phalcon package
# around 2026-08-28, and Phalcon's own release assets are a more
# durable source for this one dependency regardless of what sury does
# next. Still a prebuilt binary either way, not compiling from source.

# ---- Stage 1: vendor/ ------------------------------------------------
# Composer only, no Phalcon extension needed here — --ignore-platform-req
# =ext-phalcon is safe: it's skipping a metadata check, not actually
# resolving anything against it. Also faked via composer.json's own
# "config.platform" (ext-phalcon/ext-pdo_pgsql) — needed because
# wikimedia/composer-merge-plugin's internal "composer update to apply
# merge settings" (triggered whenever composer.local.json adds a new,
# not-yet-locked require) doesn't honor --ignore-platform-req or
# COMPOSER_IGNORE_PLATFORM_REQS, only composer.json's own static config.
# docs/INSTALL.md's non-Docker install path still documents these
# extensions as a hard prerequisite, so this doesn't remove the real
# safety net there. Private/internal modules (REQ-073) live in a sibling
# repo, not in this tree — composer.local.json (gitignored, see
# docs/INTERNAL-MODULES.md) references them as `../internal/*`
# path repositories, so that sibling checkout has to land at
# /internal (matching /app's own parent) before `composer install`
# runs. The `internal-modules` build context is declared in
# docker-compose.yml; it must exist on the host even if empty so a build
# with no private modules configured still works unmodified.
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock composer.local.jso[n] ./
COPY --from=internal-modules . /internal

# composer.lock (committed, public) deliberately does NOT include any
# private/internal module — a plain `install` from it is what keeps a
# public clone/CI build hermetic with no private-repo access at all.
# When composer.local.json merges in a private require (see
# docs/INTERNAL-MODULES.md), that entry is missing from the lock by
# definition, and `composer install` refuses rather than silently
# resolving it (confirmed 2026-08-11 — this is what broke every CI run
# from REQ-073 onward: composer.lock had briefly been committed WITH a
# private entry baked in, which broke the no-composer.local.json case
# instead). `composer update` (private-module instances only) resolves
# and re-locks fresh each build — the accepted tradeoff for those
# instances vs. keeping the shared lock reproducible for everyone else.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --optimize-autoloader \
        --ignore-platform-req=ext-phalcon \
        --ignore-platform-req=ext-pdo_pgsql \
    || (test -f composer.local.json && composer update \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --optimize-autoloader \
        --ignore-platform-req=ext-phalcon \
        --ignore-platform-req=ext-pdo_pgsql)

# ---- Stage 2: runtime --------------------------------------------------
# Deliberately NOT based on the official php:8.3-fpm image — that build
# doesn't register PHP with dpkg at all (docker-library compiles it from
# source), so deb.sury.org's Phalcon package — which depends on sury's own
# php8.3-common build — has no installation candidate on top of it
# (verified 2026-07-30: apt-cache policy shows the package present in the
# index but Candidate: (none)). The whole PHP-FPM stack has to come from
# the same source, so this builds PHP-FPM itself from sury.org too rather
# than mixing sources.
FROM debian:bookworm-slim AS runtime

ARG DEBIAN_FRONTEND=noninteractive

# Extension list per docs.phalcon.io/5.17/installation/#software, corrected
# 2026-07-30 against a real build+run test: Phalcon's own Config/Collection
# internals call mb_strtolower() directly on every request (bootstrap
# fatal without it), despite the docs listing mbstring as merely
# "conditional on application needs" — it's a hard dependency in practice
# regardless of what this app's own code calls. gd, imagick, memcached,
# gettext are still genuinely unused (grepped the codebase to confirm) and
# stay out. curl is kept despite being unused today, for the coming
# ExternalConnections work. msmtp/msmtp-mta (not a PHP extension — a
# system package, from Debian's own repo, not sury's) provide a
# sendmail-compatible relay binary for PHP's mail(): a drop-in swap for
# local dev's mhsendmail-to-MailHog, relaying instead to a real SMTP
# server via entrypoint.sh's rendered /etc/msmtprc. Only actually wired up
# (sendmail_path set) if SMTP_HOST/SMTP_PASSWORD are configured — see
# entrypoint.sh. postgresql-client-18 (from PGDG's own repo, same
# version-gap problem this project already solved on the host per
# REQ-052 — Debian bookworm's default postgresql-client is older than
# the postgres:18 server this talks to) + gzip give BackupTask
# (REQ-077) a real `pg_dump` inside the app container itself, so backup
# runs through the same CronRunner/cron_jobs system as everything else
# instead of needing a separate host-crontab entry.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg lsb-release \
    && curl -sSL https://packages.sury.org/php/apt.gpg -o /etc/apt/trusted.gpg.d/sury-php.gpg \
    && echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/sury-php.list \
    && curl -sSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /etc/apt/trusted.gpg.d/pgdg.asc \
    && echo "deb https://apt.postgresql.org/pub/repos/apt $(lsb_release -sc)-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update && apt-get install -y --no-install-recommends \
        php8.3-fpm \
        php8.3-pgsql \
        php8.3-sqlite3 \
        php8.3-curl \
        php8.3-mbstring \
        php8.3-intl \
        msmtp \
        msmtp-mta \
        postgresql-client-18 \
        gzip \
    # Sury's default pool listens on a Unix socket (/run/php/php8.3-fpm.sock)
    # — switched to TCP :9000 since Caddy and PHP-FPM are separate
    # containers here, not sharing a filesystem for a socket.
    && sed -i 's|^listen = .*|listen = 9000|' /etc/php/8.3/fpm/pool.d/www.conf \
    && apt-get purge -y --auto-remove curl gnupg \
    && rm -rf /var/lib/apt/lists/*

# Phalcon installed from its own GitHub release, not sury.org's apt
# package -- REQ-204: sury.org's php8.3-phalcon quietly became an empty
# "(dummy)" transitional package around 2026-08-28 (the day php8.5
# packages appeared in that repo; php-phalcon5 now only targets 8.5
# there), which also drags php8.5-cli onto PATH via an unversioned
# dependency with no install-time error -- entrypoint.sh's CLI calls
# then silently ran under a PHP with neither pdo_pgsql nor Phalcon
# loaded. Phalcon's own releases (github.com/phalcon/cphalcon) still
# publish a PHP 8.3 NTS build directly and verified working (loads
# cleanly, phpversion('phalcon') === 5.20.3) against this exact
# sury.org PHP 8.3 build on debian:bookworm-slim -- pinned by version +
# a checksum computed from that verified download, not "latest", so a
# future release change fails the build loudly instead of silently
# swapping in an unverified binary. Two conf.d copies (not one
# phpenmod-style symlink) because phpenmod already showed itself
# unreliable across SAPIs once a stray php8.5 was on this system (see
# the now-removed update-alternatives workaround this replaces) --
# writing both directly removes that failure mode entirely.
ARG PHALCON_VERSION=5.20.3
ARG PHALCON_SHA256=62b4034164326848260d264c486f3811dc494f7c891a8c37440ed79afa33bd3f
RUN apt-get update && apt-get install -y --no-install-recommends curl unzip \
    && curl -sSL -o /tmp/phalcon.zip \
        "https://github.com/phalcon/cphalcon/releases/download/v${PHALCON_VERSION}/phalcon-php8.3-nts-ubuntu-gcc-x64.zip" \
    && cd /tmp && unzip -q phalcon.zip phalcon.so \
    && echo "${PHALCON_SHA256}  phalcon.so" | sha256sum -c - \
    && install -m 644 phalcon.so "$(php -r 'echo ini_get("extension_dir");')/phalcon.so" \
    && echo "extension=phalcon.so" > /etc/php/8.3/cli/conf.d/20-phalcon.ini \
    && echo "extension=phalcon.so" > /etc/php/8.3/fpm/conf.d/20-phalcon.ini \
    && rm -f /tmp/phalcon.zip /tmp/phalcon.so \
    && php -m | grep -qi '^phalcon$' \
    && apt-get purge -y --auto-remove curl unzip \
    && rm -rf /var/lib/apt/lists/*

# sury's default upload_max_filesize (2M)/post_max_size (8M) are both below
# TicketsController::MAX_ATTACHMENT_BYTES (10MB) — a multipart POST over
# post_max_size gets silently emptied by PHP itself (no $_POST, no $_FILES,
# no warning the app can see), which the app's own CSRF check then
# misreports as "session expired" since it just sees a POST with no token.
# Some headroom above the app's own limit for multipart overhead and any
# other form fields on the same request.
RUN { \
        echo "upload_max_filesize = 12M"; \
        echo "post_max_size = 15M"; \
    } > /etc/php/8.3/fpm/conf.d/zz-uploads.ini

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# www-data (the pool's configured run user) needs to write here at
# runtime — everything else in /app stays read-only, which is the point.
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p public/files public/temp cache/volt logs sessions \
    && chown -R www-data:www-data public/files public/temp cache/volt logs sessions

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm8.3", "-F"]
