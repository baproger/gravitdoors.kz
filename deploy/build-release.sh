#!/usr/bin/env bash
#
# Архив релиза для хостинга без SSH и Composer: код + vendor + сборка фронтенда.
#
#   bash deploy/build-release.sh            → deploy/release/gravit-<дата>.zip
#
# Зачем: на виртуальном хостинге может не быть ни консоли, ни composer.
# Тогда vendor собирается здесь, под PHP той версии, что на сервере
# (composer.json: config.platform.php = 8.3.0, поэтому подходит для 8.3+),
# и уезжает в архиве. На сервере архив распаковывается Файловым менеджером
# Plesk, дальше — README, раздел «Развёртывание».
#
# Локальный vendor с dev-пакетами не трогается: сборка идёт во временной копии.

set -euo pipefail
cd "$(dirname "$0")/.."

STAMP="$(date +%Y%m%d-%H%M)"
OUT_DIR="deploy/release"
OUT="$OUT_DIR/gravit-$STAMP.zip"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "→ Сборка фронтенда"
if [ -d node_modules ]; then npm run build --silent; fi
[ -f public/build/manifest.json ] || { echo "✗ Нет public/build/manifest.json: npm install && npm run build"; exit 1; }

echo "→ Код (из git, без tests/.git/node_modules)"
git archive --format=tar HEAD | tar -x -C "$WORK"
# public/build мог быть пересобран после коммита — берём актуальный.
rm -rf "$WORK/public/build" && cp -R public/build "$WORK/public/build"
rm -rf "$WORK/tests" "$WORK/.github" "$WORK/deploy/release"

echo "→ vendor без dev-пакетов (платформа php 8.3, как в composer.json)"
( cd "$WORK" && composer install --no-dev --classmap-authoritative --no-interaction --prefer-dist --no-progress --quiet )

# Каталоги, которые должны существовать, но в git пустыми не хранятся.
mkdir -p "$WORK/storage/app/public" "$WORK/storage/backups" "$WORK/storage/logs" \
  "$WORK/storage/framework/cache" "$WORK/storage/framework/sessions" "$WORK/storage/framework/views" "$WORK/bootstrap/cache"
rm -f "$WORK/.env"

mkdir -p "$OUT_DIR"
( cd "$WORK" && zip -qr "$OLDPWD/$OUT" . -x '.DS_Store' )

echo "✓ $OUT ($(du -h "$OUT" | cut -f1))"
echo "   Дальше: Plesk → Файлы → загрузить и распаковать в каталог сайта, README, раздел «Развёртывание», шаг 4."
