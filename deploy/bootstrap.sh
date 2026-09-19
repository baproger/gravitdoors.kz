#!/usr/bin/env bash
#
# Первый запуск голого VPS с рабочей машины — одной командой.
#
#   bash deploy/bootstrap.sh root@1.2.3.4
#
# Что делает:
#   1. копирует папку deploy/ на сервер (кода там ещё нет, а server-setup.sh
#      нужны конфиги nginx и PHP рядом с собой);
#   2. запускает server-setup.sh: пакеты, swap, PHP-FPM, nginx, cron;
#   3. забирает напечатанный deploy-ключ сервера и добавляет его в GitHub
#      через gh (нужен `gh auth login` на этой машине), после чего запускает
#      server-setup.sh ещё раз — теперь git clone проходит.
#
# Дальше на сервере: .env, deploy.sh, gravit:install, certbot — скрипт
# печатает эти шаги в конце. Переменные: SSH_PORT (22), REPO (baproger/gravitdoors.kz).

set -euo pipefail
cd "$(dirname "$0")/.."

HOST="${1:-}"
SSH_PORT="${SSH_PORT:-22}"
REPO="${REPO:-baproger/gravitdoors.kz}"
REMOTE_DIR=/root/gravit-deploy

if [ -z "$HOST" ]; then
  echo "Использование: bash deploy/bootstrap.sh root@host"; exit 1
fi

SSH="ssh -p $SSH_PORT -o ConnectTimeout=15"

echo "→ Копирование deploy/ на $HOST"
$SSH "$HOST" "mkdir -p $REMOTE_DIR"
scp -q -P "$SSH_PORT" -r deploy/. "$HOST:$REMOTE_DIR/"

echo "→ Настройка сервера"
LOG="$(mktemp)"
$SSH "$HOST" "bash $REMOTE_DIR/server-setup.sh" | tee "$LOG"

KEY="$(grep -m1 -oE 'ssh-ed25519 [A-Za-z0-9+/=]+ [^ ]+' "$LOG" || true)"
if [ -n "$KEY" ] && ! grep -q 'Код склонирован' "$LOG"; then
  if command -v gh >/dev/null 2>&1; then
    echo "→ Deploy-ключ в GitHub ($REPO)"
    KEY_FILE="$(mktemp)"
    echo "$KEY" > "$KEY_FILE"
    gh repo deploy-key add "$KEY_FILE" -R "$REPO" -t "vps $(echo "$HOST" | sed 's/.*@//')" \
      || echo "   (ключ уже добавлен или нет прав — проверьте Settings → Deploy keys)"
    rm -f "$KEY_FILE"
    echo "→ Клонирование репозитория"
    $SSH "$HOST" "bash $REMOTE_DIR/server-setup.sh" | tail -12
  else
    echo "✗ gh не установлен: добавьте ключ выше в GitHub → Settings → Deploy keys и запустите"
    echo "  ssh $HOST bash $REMOTE_DIR/server-setup.sh"
  fi
fi
rm -f "$LOG"
