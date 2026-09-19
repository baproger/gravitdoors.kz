#!/usr/bin/env bash
#
# Обновление сайта на виртуальном хостинге с Plesk (hoster.kz, тариф «Турбо»).
#
# Чем отличается от deploy.sh для VPS: нет root, sudo, systemctl и swap;
# PHP-FPM, nginx и cron настраивает панель. Скрипт делает только то, что
# доступно пользователю подписки: composer, миграции, кэши, ссылка на файлы.
#
# Откуда запускается:
#   - Plesk → Git → «Действия при развёртывании»: bash deploy/plesk-deploy.sh
#     (после каждого git pull из GitHub);
#   - или по SSH: cd ~/gravit && bash deploy/plesk-deploy.sh
#
# Переменные: PHP (путь к php нужной версии), COMPOSER (путь к composer или
# composer.phar). Без них скрипт ищет самый новый PHP ≥ 8.3 в /opt/plesk/php.

set -euo pipefail
cd "$(dirname "$0")/.."

# PHP той версии, что выбрана для сайта в Plesk: `php` в консоли хостинга
# часто старый (7.x), а нам нужен 8.3+. Берём самый новый из /opt/plesk/php.
if [ -z "${PHP:-}" ]; then
  for candidate in /opt/plesk/php/8.5/bin/php /opt/plesk/php/8.4/bin/php /opt/plesk/php/8.3/bin/php; do
    [ -x "$candidate" ] && { PHP="$candidate"; break; }
  done
  PHP="${PHP:-php}"
fi
"$PHP" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' \
  || { echo "✗ Нужен PHP 8.3 или новее, найден $("$PHP" -r 'echo PHP_VERSION;') ($PHP). Укажите PHP=/opt/plesk/php/8.4/bin/php"; exit 1; }
echo "→ PHP $("$PHP" -r 'echo PHP_VERSION;') ($PHP)"

# Composer: свой в PATH, либо phar, который ставит Plesk, либо vendor уже
# приехал в архиве релиза (deploy/build-release.sh) — тогда composer не нужен.
if [ -z "${COMPOSER:-}" ]; then
  if command -v composer >/dev/null 2>&1; then
    COMPOSER="composer"
  elif [ -f /usr/lib64/plesk-9.0/composer.phar ]; then
    COMPOSER="$PHP /usr/lib64/plesk-9.0/composer.phar"
  elif [ -f "$HOME/composer.phar" ]; then
    COMPOSER="$PHP $HOME/composer.phar"
  fi
fi

if [ ! -f .env ]; then
  cp .env.production.example .env
  echo "✗ Создан .env из .env.production.example — впишите APP_URL и DB_* (Plesk → Базы данных) и запустите снова."
  exit 1
fi

FREE_MB=$(df -Pm . | awk 'NR==2 {print $4}')
if [ "${FREE_MB:-0}" -lt 700 ]; then
  echo "✗ На диске свободно ${FREE_MB} МБ. Освободите место (storage/backups, storage/logs) и повторите."
  exit 1
fi

mkdir -p storage/backups storage/logs storage/app/public \
  storage/framework/cache storage/framework/sessions storage/framework/views storage/framework/composer bootstrap/cache
export COMPOSER_HOME="${COMPOSER_HOME:-$PWD/storage/framework/composer}"

if [ -n "${COMPOSER:-}" ]; then
  echo "→ Зависимости PHP (без dev)"
  $COMPOSER install --no-dev --classmap-authoritative --no-interaction --prefer-dist --no-progress
elif [ -d vendor ]; then
  echo "→ Composer не найден, использую vendor из архива"
else
  echo "✗ Нет ни composer, ни vendor: загрузите архив из deploy/build-release.sh или включите Composer в Plesk."
  exit 1
fi

if ! grep -qE '^APP_KEY=.+' .env; then
  echo "→ Ключ приложения"
  "$PHP" artisan key:generate --force
fi

echo "→ Режим обслуживания"
"$PHP" artisan down --retry=15 || true

echo "→ Резервная копия перед миграциями"
"$PHP" artisan gravit:backup --keep=14 || echo "   (бэкап не удался — смотрите вывод выше; продолжаю)"

echo "→ Миграции"
"$PHP" artisan migrate --force

echo "→ Кэши"
"$PHP" artisan optimize:clear
"$PHP" artisan optimize
"$PHP" artisan filament:optimize

echo "→ Ссылка на файлы"
"$PHP" artisan storage:link || true

chmod -R ug+rwX storage bootstrap/cache

"$PHP" artisan up

echo "→ Состояние"
"$PHP" artisan gravit:server-check --dry || true
echo "✓ Готово"
