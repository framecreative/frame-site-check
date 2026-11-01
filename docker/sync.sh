#!/usr/bin/env bash
# Copies the site (mounted read-only at /src) into /app for a run.
#
# - .env.site-check is the only env file copied; every other .env* file stays out of the container.
# - Statamic keeps its definitions (content/*/*.yaml) but never its content, trees, global values or users.
#   Paths passed as arguments (relative to the site root) are kept in full, for reference data.
set -euo pipefail

rm -rf /app/content /app/users /app/storage

filters=(
    --include=/.env.site-check
    --exclude='/.env*'
    --exclude=/vendor/
    --exclude=/node_modules/
    --exclude=/.git/
    --exclude=/.site-check/
    --exclude=/.pnpm-store/
    --exclude=/.claude/
    --exclude=/storage/
    --exclude=/users/
    --exclude=/public/build/
    --exclude=/public/hot
    --exclude=/public/storage
    --exclude=/bootstrap/ssr/
    --exclude='/bootstrap/cache/*.php'
)

for path in "$@"; do
    path="${path#/}"
    path="${path%/}"
    filters+=(--include="/${path}/" --include="/${path}/**" --include="/${path}")
done

filters+=(
    --include=/content/
    --include='/content/*/'
    --include='/content/*/*.yaml'
    --exclude='/content/**'
)

rsync -a --delete "${filters[@]}" /src/ /app/

# Placeholder entries the app needs before it can boot (written by the host runner from site-check.yaml).
if [ -d /src/.site-check/stubs/content ]; then
    cp -R /src/.site-check/stubs/content/. /app/content/
fi

mkdir -p /app/users \
    /app/storage/app/public \
    /app/storage/framework/cache/data \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/framework/testing \
    /app/storage/logs
