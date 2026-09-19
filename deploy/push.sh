#!/usr/bin/env bash
#
# Заливка кода на сервер с рабочей машины и обновление сайта.
#
# Зачем rsync, а не git pull на сервере: у проекта нет удалённого репозитория,
# а сборка фронтенда (public/build) всё равно делается локально — на 1 ГБ
# памяти Node не ставится. Один скрипт делает всё: собирает, копирует только
# нужное, запускает deploy.sh на сервере.
#
# Запуск:  bash deploy/push.sh root@1.2.3.4            # залить и обновить
#          bash deploy/push.sh root@1.2.3.4 --setup    # первый раз: ещё и настроить сервер
#          bash deploy/push.sh root@1.2.3.4 --no-build # не пересобирать фронтенд
#
# Переменные: APP_DIR (по умолчанию /var/www/gravit), SSH_PORT (22).

set -euo pipefail
cd "$(dirname "$0")/.."

HOST="${1:-}"
APP_DIR="${APP_DIR:-/var/www/gravit}"
SSH_PORT="${SSH_PORT:-22}"
SETUP=0
BUILD=1

for arg in "${@:2}"; do
  case "$arg" in
    --setup) SETUP=1 ;;
    --no-build) BUILD=0 ;;
    *) echo "✗ Неизвестный параметр: $arg"; exit 1 ;;
  esac
done

if [ -z "$HOST" ]; then
  echo "Использование: bash deploy/push.sh user@host [--setup] [--no-build]"; exit 1
fi

SSH="ssh -p $SSH_PORT -o ConnectTimeout=15"

if [ "$BUILD" -eq 1 ] && [ -d node_modules ]; then
  echo "→ Сборка фронтенда"
  npm run build --silent
fi
[ -f public/build/manifest.json ] || { echo "✗ Нет public/build/manifest.json: выполните npm install && npm run build"; exit 1; }

echo "→ Проверка кода перед заливкой"
vendor/bin/pint --test -q 2>/dev/null || { echo "✗ Pint нашёл замечания: vendor/bin/pint"; exit 1; }

echo "→ Копирование на $HOST:$APP_DIR"
$SSH "$HOST" "mkdir -p $APP_DIR"
# Что НЕ копируем и почему:
#   vendor       — composer install --no-dev на сервере: dev-пакеты весят 60 МБ;
#   node_modules — не нужны, сборка уже в public/build;
#   .env, storage, database/*.sqlite — данные сервера (чеки, бэкапы, сессии,
#                кэш composer), затирать нельзя; каталоги внутри storage
#                создаёт deploy.sh;
#   .git, tests, кэши — на сервере не нужны.
rsync -az --delete \
  -e "ssh -p $SSH_PORT" \
  --exclude '.git' --exclude '.github' --exclude 'node_modules' --exclude 'vendor' \
  --exclude '.env' --exclude '.env.backup' --exclude '.env.production' \
  --exclude 'storage/**' \
  --exclude 'bootstrap/cache/*.php' \
  --exclude 'database/*.sqlite' --exclude 'database/*.sqlite-*' \
  --exclude 'public/storage' --exclude 'public/hot' \
  --exclude 'tests' --exclude '.phpunit.cache' --exclude '.phpunit.result.cache' \
  --exclude '.DS_Store' --exclude '.idea' --exclude '.vscode' --exclude '.fleet' --exclude '.zed' \
  ./ "$HOST:$APP_DIR/"

if [ "$SETUP" -eq 1 ]; then
  echo "→ Настройка сервера (пакеты, swap, PHP-FPM, nginx, cron)"
  $SSH "$HOST" "APP_DIR=$APP_DIR bash $APP_DIR/deploy/server-setup.sh"
fi

echo "→ Обновление сайта на сервере"
# От www-data: файлы кэшей и бэкапов не должны оказаться под root, иначе
# сайт потом не сможет их перезаписать. SKIP_GIT_PULL: код уже приехал rsync-ом.
$SSH "$HOST" "chown -R www-data:www-data $APP_DIR && cd $APP_DIR && sudo -u www-data SKIP_GIT_PULL=1 bash deploy.sh"

echo "✓ Залито: https://$($SSH "$HOST" "grep -m1 '^APP_URL=' $APP_DIR/.env 2>/dev/null | sed 's#^APP_URL=##; s#https\?://##'" || echo "$HOST")"
