#!/usr/bin/env bash
#
# Первичная настройка VPS под Gravit ERP (1 ГБ памяти, 2 vCPU, 15 ГБ диска).
# Ubuntu 22.04 / 24.04. Запускать от root один раз; повторный запуск безопасен —
# каждый шаг проверяет, не сделан ли он уже.
#
# Что делает:
#   1. пакеты: nginx, PHP-FPM с расширениями, composer, git, mysql-client, certbot;
#   2. swap 1 ГБ и swappiness=10 — без этого ядро при нехватке памяти убьёт PHP;
#   3. PHP-FPM: пул из трёх статичных процессов, opcache без JIT (deploy/php/*);
#   4. nginx: сайт с лимитами на публичные адреса (deploy/nginx/gravit.conf);
#   5. deploy-ключ для GitHub, git clone репозитория, .env из .env.production.example;
#   6. cron планировщика, sudo для deploy.sh, logrotate.
#
# С рабочей машины всё это запускает deploy/bootstrap.sh: он копирует папку
# deploy/ на сервер, выполняет этот скрипт и сам добавляет deploy-ключ в GitHub.
# Руками: scp -r deploy root@<ip>:/root/gravit-deploy && ssh root@<ip> bash /root/gravit-deploy/server-setup.sh
#
# Переменные окружения для переопределения: APP_DIR, PHP_VERSION, DOMAIN, SWAP_SIZE, REPO_URL.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/gravit}"
PHP_VERSION="${PHP_VERSION:-8.5}"
DOMAIN="${DOMAIN:-erp.gravit.kz}"
SWAP_SIZE="${SWAP_SIZE:-1G}"
# Откуда брать код: приватный репозиторий на GitHub, доступ по deploy-ключу (шаг 5).
REPO_URL="${REPO_URL:-git@github.com:baproger/gravitdoors.kz.git}"
HERE="$(cd "$(dirname "$0")" && pwd)"

if [ "$(id -u)" -ne 0 ]; then
  echo "✗ Запускать от root: sudo bash $0"; exit 1
fi

echo "→ 1/6 Пакеты"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq software-properties-common ca-certificates curl gnupg >/dev/null
if ! apt-cache show "php${PHP_VERSION}-fpm" >/dev/null 2>&1; then
  # В штатных репозиториях Ubuntu нужной версии PHP нет — берём из PPA Ondřej Surý.
  add-apt-repository -y ppa:ondrej/php >/dev/null
  apt-get update -qq
fi
apt-get install -y -qq \
  nginx git unzip composer mysql-client certbot python3-certbot-nginx ghostscript \
  "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-sqlite3" \
  "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" \
  "php${PHP_VERSION}-gd" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-opcache" >/dev/null
# `php` в консоли должен быть той же версии, что и FPM: иначе artisan и кэши разойдутся.
update-alternatives --set php "/usr/bin/php${PHP_VERSION}" >/dev/null 2>&1 || true

echo "→ 2/6 Swap ${SWAP_SIZE}"
if [ -z "$(swapon --show --noheadings)" ]; then
  if [ ! -f /swapfile ]; then
    fallocate -l "$SWAP_SIZE" /swapfile
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
  fi
  swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  echo "   swap включён"
else
  echo "   swap уже есть: $(swapon --show --noheadings | awk '{print $1, $3}')"
fi
# swap только когда память действительно кончилась, а не для вытеснения кэша.
sysctl -q -w vm.swappiness=10
grep -q '^vm.swappiness' /etc/sysctl.conf && sed -i 's/^vm.swappiness.*/vm.swappiness=10/' /etc/sysctl.conf || echo 'vm.swappiness=10' >> /etc/sysctl.conf

echo "→ 3/6 PHP-FPM ${PHP_VERSION}"
POOL_DIR="/etc/php/${PHP_VERSION}/fpm/pool.d"
install -m 644 "$HERE/php/gravit-pool.conf" "$POOL_DIR/gravit.conf"
# Штатный пул www (dynamic, до 5 процессов) на 1 ГБ не нужен: он бы удвоил память.
[ -f "$POOL_DIR/www.conf" ] && mv "$POOL_DIR/www.conf" "$POOL_DIR/www.conf.disabled"
install -m 644 "$HERE/php/99-gravit.ini" "/etc/php/${PHP_VERSION}/fpm/conf.d/99-gravit.ini"
"php-fpm${PHP_VERSION}" -t >/dev/null
systemctl enable -q "php${PHP_VERSION}-fpm"
systemctl restart "php${PHP_VERSION}-fpm"

echo "→ 4/6 nginx"
sed -e "s#erp\.gravit\.kz#${DOMAIN}#g" -e "s#/var/www/gravit#${APP_DIR}#g" \
  "$HERE/nginx/gravit.conf" > /etc/nginx/sites-available/gravit
ln -sf /etc/nginx/sites-available/gravit /etc/nginx/sites-enabled/gravit
rm -f /etc/nginx/sites-enabled/default
nginx -t >/dev/null
systemctl enable -q nginx
systemctl reload nginx

echo "→ 5/6 Каталог сайта, .env, права"
mkdir -p "$APP_DIR"
if [ ! -f "$APP_DIR/artisan" ]; then
  # Репозиторий приватный: сервер ходит в GitHub по deploy-ключу (только чтение).
  # Ключ живёт у www-data — от него же работают deploy.sh и cron.
  SSH_DIR=/var/www/.ssh
  mkdir -p "$SSH_DIR" && chown www-data:www-data "$SSH_DIR" && chmod 700 "$SSH_DIR"
  if [ ! -f "$SSH_DIR/id_ed25519" ]; then
    sudo -u www-data ssh-keygen -q -t ed25519 -N '' -C "gravit-erp@$(hostname)" -f "$SSH_DIR/id_ed25519"
  fi
  ssh-keyscan -t ed25519 github.com 2>/dev/null > "$SSH_DIR/known_hosts"
  chown www-data:www-data "$SSH_DIR/known_hosts"
  echo
  echo "   Deploy-ключ сервера (добавить в GitHub → репозиторий → Settings → Deploy keys, без записи):"
  echo "   $(cat "$SSH_DIR/id_ed25519.pub")"
  echo "   или с рабочей машины: gh repo deploy-key add <файл с ключом> -R baproger/gravitdoors.kz -t \"$(hostname)\""
  echo
  chown www-data:www-data "$APP_DIR"
  if sudo -u www-data git clone -q "$REPO_URL" "$APP_DIR" 2>/dev/null; then
    echo "   Код склонирован из $REPO_URL"
  else
    echo "   Клонировать пока нельзя: добавьте deploy-ключ в GitHub и запустите скрипт ещё раз."
  fi
fi
if [ -f "$APP_DIR/artisan" ]; then
  if [ ! -f "$APP_DIR/.env" ]; then
    cp "$APP_DIR/.env.production.example" "$APP_DIR/.env"
    echo "   Создан $APP_DIR/.env из .env.production.example — впишите DB_* и APP_URL."
  fi
  mkdir -p "$APP_DIR/storage/backups" "$APP_DIR/storage/logs" \
    "$APP_DIR/storage/framework/cache" "$APP_DIR/storage/framework/sessions" "$APP_DIR/storage/framework/views" \
    "$APP_DIR/storage/app/public" "$APP_DIR/bootstrap/cache"
fi
chown -R www-data:www-data "$APP_DIR"
chmod 750 "$APP_DIR"
# nginx и PHP-FPM работают от www-data; директория выше должна быть проходимой.
chmod o+x /var/www 2>/dev/null || true

echo "→ 6/6 Cron, sudo для deploy.sh, logrotate"
sed -e "s#/var/www/gravit#${APP_DIR}#g" "$HERE/cron/gravit" > /etc/cron.d/gravit
chmod 644 /etc/cron.d/gravit
# deploy.sh работает от www-data и должен перезагрузить PHP-FPM после обновления
# (opcache не проверяет файлы на изменение). Только эта одна команда, без пароля.
echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reload php${PHP_VERSION}-fpm" > /etc/sudoers.d/gravit
chmod 440 /etc/sudoers.d/gravit
visudo -cf /etc/sudoers.d/gravit >/dev/null
cat > /etc/logrotate.d/gravit <<EOF
/var/log/php-fpm-gravit-*.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
    create 640 www-data adm
    postrotate
        systemctl reload php${PHP_VERSION}-fpm >/dev/null 2>&1 || true
    endscript
}
EOF

echo
echo "✓ Сервер настроен. Дальше на сервере:"
echo "   1. nano $APP_DIR/.env — APP_URL, DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD."
echo "   2. cd $APP_DIR && sudo -u www-data bash deploy.sh        (composer, ключ, миграции, кэши)"
echo "   3. sudo -u www-data php artisan gravit:install           (справочники и директор)"
echo "   4. certbot --nginx -d ${DOMAIN}                          (HTTPS)"
echo "   Обновления потом: cd $APP_DIR && sudo -u www-data bash deploy.sh  (git pull внутри)."
