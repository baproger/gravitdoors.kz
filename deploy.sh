#!/usr/bin/env bash
#
# Развёртывание на маленьком сервере (1 ГБ памяти, 2 vCPU, 15 ГБ диска).
#
# Что здесь важно и почему:
#  - фронтенд НЕ собирается на сервере: Vite и node_modules съедают память,
#    которой нет; готовая сборка public/build лежит в git;
#  - composer ставится без dev-пакетов и с оптимизированным автозагрузчиком —
#    это минус ~60 МБ vendor и быстрее старт каждого запроса;
#  - конфиг, маршруты, представления и компоненты Filament кэшируются —
#    иначе на каждый запрос PHP заново читает десятки файлов.
#
# Запуск: bash deploy.sh   (из каталога проекта, под пользователем сайта)

set -euo pipefail
cd "$(dirname "$0")"

# Диск проверяем до всего остального: composer, бэкап и кэши пишут сотни
# мегабайт, и на полном диске обновление останавливается посередине —
# сайт остаётся в режиме обслуживания.
FREE_MB=$(df -Pm . | awk 'NR==2 {print $4}')
if [ "${FREE_MB:-0}" -lt 1500 ]; then
  echo "✗ На диске свободно ${FREE_MB} МБ, нужно не меньше 1500. Освободите место (storage/backups, storage/logs) и повторите."
  exit 1
fi

# Код приезжает либо git pull (если у репозитория есть remote), либо rsync-ом
# через deploy/push.sh — тогда push.sh ставит SKIP_GIT_PULL=1.
if [ "${SKIP_GIT_PULL:-0}" = "1" ]; then
  echo "→ Код уже залит (rsync)"
elif git rev-parse --is-inside-work-tree >/dev/null 2>&1 && [ -n "$(git remote)" ]; then
  echo "→ Код"
  git pull --ff-only
else
  echo "→ Код: git remote не настроен, пропускаю pull"
fi

# Скрипт запускается от www-data, у которого нет домашнего каталога: composer
# держит кэш внутри проекта, в storage (в git и в rsync он не попадает).
export COMPOSER_HOME="${COMPOSER_HOME:-$PWD/storage/framework/composer}"
mkdir -p "$COMPOSER_HOME" storage/backups storage/logs storage/app/public \
  storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

echo "→ Зависимости PHP (без dev)"
# classmap-authoritative: автозагрузчик не ищет классы на диске, а берёт из готовой
# карты — меньше обращений к файловой системе на каждый запрос. Код на сервере
# меняется только этим скриптом, поэтому карта всегда актуальна.
composer install --no-dev --classmap-authoritative --no-interaction --prefer-dist --no-progress

# Первый запуск: ключ шифрования можно сгенерировать только когда vendor уже есть.
if [ ! -f .env ]; then
  cp .env.production.example .env
  echo "→ Создан .env из .env.production.example — впишите APP_URL и DB_* и запустите скрипт снова."
  exit 1
fi
if ! grep -qE '^APP_KEY=.+' .env; then
  echo "→ Ключ приложения"
  php artisan key:generate --force
fi

echo "→ Режим обслуживания"
php artisan down --retry=15 || true

echo "→ Резервная копия перед миграциями"
php artisan gravit:backup --keep=14

echo "→ Миграции"
php artisan migrate --force

echo "→ Кэши"
php artisan optimize:clear
php artisan optimize          # config + routes + views + events
php artisan filament:optimize # компоненты и иконки Filament

echo "→ Ссылка на файлы"
php artisan storage:link || true

echo "→ Права"
chmod -R ug+rwX storage bootstrap/cache

echo "→ Перезапуск PHP-FPM (opcache сбрасывается)"
# opcache.validate_timestamps=0: без reload сервер продолжит исполнять старый код.
# Правило sudo без пароля для www-data ставит deploy/server-setup.sh; `-n` —
# не ждать ввода пароля, если правила нет, а честно сказать об этом.
if command -v systemctl >/dev/null 2>&1; then
  PHP_FPM_SERVICE="php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
  if [ "$(id -u)" -eq 0 ]; then
    systemctl reload "$PHP_FPM_SERVICE"
  else
    sudo -n systemctl reload "$PHP_FPM_SERVICE" \
      || echo "✗ Не удалось перезагрузить $PHP_FPM_SERVICE: выполните от root «systemctl reload $PHP_FPM_SERVICE», иначе работает старый код."
  fi
fi

php artisan up

echo "→ Состояние сервера"
php artisan gravit:server-check --dry || true

echo "✓ Готово"
