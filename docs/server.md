# Сервер: 1 ГБ памяти, 2 vCPU, 15 ГБ диска

> **Бой сейчас — виртуальный хостинг hoster.kz с Plesk, а не VPS.** Там нет
> root, nginx-конфигов, swap и systemd: пошаговая инструкция под панель —
> `docs/plesk.md`. Этот документ — про то же железо, но на своём VPS: бюджет
> памяти, фон и диск здесь верны для обоих случаев, а разделы про пакеты,
> PHP-FPM, nginx и cron пригодятся при переезде.

Как разложить систему на маленькой машине, чтобы она не упиралась в память
и не тормозила. Всё ниже проверено на составе проекта: Laravel 12 + Filament 4,
MySQL на стороне hoster.kz (или SQLite на своём VPS), без очередей и без Redis.

## Бюджет памяти

| Что | Сколько | Зачем столько |
|---|---|---|
| Система + SSH + cron | ~120 МБ | остаётся ОС |
| nginx | ~10 МБ | статика и проксирование в PHP |
| PHP-FPM: **3 процесса × ~75 МБ** | ~230 МБ | одна страница панели занимает 65–75 МБ на пике (замерено) |
| opcache | 64 МБ | скомпилированный код, общий для всех процессов |
| MySQL | 0 на VPS | база на стороне hoster.kz; SQLite, если своя, — внутри процесса PHP |
| Запас | ~500 МБ | на всплески и кэш файловой системы |

Три PHP-процесса — это три одновременных запроса. Для отдела продаж, цеха и
бухгалтерии этого достаточно: планшет цеха опрашивает сервер раз в 15 секунд,
остальные работают руками. Четвёртый и пятый запрос встанут в очередь на
доли секунды, а не уронят сервер в swap.

## Фоновая нагрузка: что сервер делает без людей

Опасность маленькой машины не в пиках, а в фоне, который никто не видит.
Всё, что опрашивает сервер само, посчитано и ограничено:

| Кто | Как часто | Что делает |
|---|---|---|
| Планшет цеха `/shop` | раз в 15 с на планшет | 22 запроса к базе, ~30 мс; Livewire сам приостанавливает опрос, когда вкладка в фоне |
| Уведомления в панели | раз в 2 мин на вкладку | один лёгкий запрос. По умолчанию Filament опрашивает каждые 30 с, и десять открытых вкладок давали 20 запросов в минуту впустую — интервал поднят в `AdminPanelProvider` |
| Планировщик | раз в минуту | `schedule:run` стартует PHP на ~100 мс и, кроме 03:00, 08:00 и 08:30, ничего не делает |

Замер на демо-данных: ни одна страница панели не тяжелее 53 запросов к базе
и 100 мс; инфопанель — 53, канбаны — 22–30, остальное 2–34.

## Swap: страховка от OOM

Без swap ядро при нехватке памяти убивает самый большой процесс — это будет
PHP-FPM или MySQL, и сайт ляжет. Файл подкачки на 1 ГБ превращает это в
замедление на минуту, которое переживается. Один раз на сервере:

```bash
fallocate -l 1G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
sysctl vm.swappiness=10 && echo 'vm.swappiness=10' >> /etc/sysctl.conf
```

`swappiness=10`: swap используется только когда память действительно
кончилась, а не для вытеснения кэша. Если `free -m` показывает постоянно
занятый swap — это сигнал переходить на 2 ГБ, а не норма.

## Что поставить

Всё ниже (пакеты, swap, PHP-FPM, opcache, nginx, cron, sudo для deploy.sh,
logrotate) ставит один скрипт, и повторный запуск безопасен:

```bash
sudo bash /var/www/gravit/deploy/server-setup.sh
```

Готовые конфиги лежат в `deploy/`: `nginx/gravit.conf`, `php/gravit-pool.conf`,
`php/99-gravit.ini`, `cron/gravit`. Разделы ниже объясняют, что в них и почему,
чтобы править осознанно.

Пакеты, если руками:

```bash
apt install nginx php8.5-fpm php8.5-mysql php8.5-sqlite3 php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip php8.5-gd php8.5-intl php8.5-bcmath mysql-client composer git certbot python3-certbot-nginx
```

PHP 8.5 в штатных репозиториях Ubuntu нет — скрипт подключает PPA `ondrej/php`.

Node на сервере **не нужен**: фронтенд собран локально (`npm run build`) и лежит
в git в `public/build`. Это осознанно: Vite на 1 ГБ памяти падает по OOM.

## База данных: MySQL от hoster.kz

Проект работает и на SQLite, и на MySQL: набор тестов проходит на обоих
(`php artisan test` — SQLite, `composer test:mysql` — MySQL). В коде нет
SQL, привязанного к движку. На hoster.kz база — MySQL, и это лучший вариант
для маленького сервера: она живёт на стороне хостинга и не ест память VPS.

В `.env`:

```
DB_CONNECTION=mysql
DB_HOST=<хост из панели hoster.kz>
DB_PORT=3306
DB_DATABASE=<имя базы>
DB_USERNAME=<логин>
DB_PASSWORD=<пароль>
```

Кодировка `utf8mb4_unicode_ci` задаётся самим проектом (`config/database.php`);
в панели хостинга базу тоже создавать в `utf8mb4`. Нужна версия MySQL 8.0 или
новее либо MariaDB 10.6+.

Бэкап (`php artisan gravit:backup`) на MySQL делает `mysqldump` в одной
транзакции — таблицы не блокируются. На сервере должен быть доступен
`mysqldump` (`apt install mysql-client`).

Если MySQL всё же ставится на сам VPS (не рекомендуется при 1 ГБ), обязательно
урезать аппетит в `/etc/mysql/mysql.conf.d/mysqld.cnf`:

```ini
innodb_buffer_pool_size = 64M   # по умолчанию 128M+
performance_schema = OFF        # экономит ~150 МБ
max_connections = 20
skip-name-resolve
```

Тогда бюджет памяти ниже уменьшается на ~200 МБ, и PHP-FPM лучше держать
на двух процессах.

## PHP-FPM — `/etc/php/8.5/fpm/pool.d/www.conf`

```ini
pm = static
pm.max_children = 3
pm.max_requests = 500          ; перезапуск процесса от утечек памяти
request_terminate_timeout = 60s
php_admin_value[memory_limit] = 128M
```

`static` вместо `dynamic`: на 1 ГБ лучше знать заранее, сколько памяти займёт
PHP, чем дать ему разрастись под нагрузкой.

Туда же, в `/etc/php/8.5/fpm/php.ini`:

```ini
realpath_cache_size = 4096k       ; Laravel + Filament — тысячи файлов на запрос; кэш путей экономит stat()
realpath_cache_ttl = 600
```

## opcache — `/etc/php/8.5/fpm/conf.d/10-opcache.ini`

```ini
opcache.enable = 1
opcache.memory_consumption = 64
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0   ; код меняется только через deploy.sh, который делает reload
opcache.save_comments = 1         ; Filament и Livewire читают атрибуты из комментариев
opcache.jit = off                 ; JIT на панели даёт единицы процентов, а буфер берёт память
opcache.jit_buffer_size = 0
```

## nginx — сайт

```nginx
# Лимиты на публичные адреса: у них нет пароля, и бот, перебирающий хэши
# страницы клиента или код планшета, занял бы все три PHP-процесса.
# 10 МБ зоны хватает на ~160 000 адресов.
limit_req_zone $binary_remote_addr zone=public:10m rate=30r/m;
limit_req_zone $binary_remote_addr zone=login:10m rate=10r/m;

server {
    listen 80;
    server_name erp.gravit.kz;
    root /var/www/gravit/public;
    index index.php;

    client_max_body_size 12m;   # чеки и договоры до 10 МБ
    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    # Страница клиента по QR и планшет цеха: 30 запросов в минуту с адреса,
    # короткий всплеск (burst) — без задержки. Планшетов в цеху 1–2, им хватает.
    location ~ ^/(track|shop) {
        limit_req zone=public burst=20 nodelay;
        try_files $uri /index.php?$query_string;
    }

    # Вход в панель: перебор паролей упирается сюда раньше, чем в PHP.
    location = /admin/login {
        limit_req zone=login burst=5 nodelay;
        try_files $uri /index.php?$query_string;
    }

    # Сборка и шрифты — на год в кэш браузера: имена файлов содержат хэш.
    # Лог по статике выключен: он только пишет диск.
    location ~* \.(css|js|woff2?|svg|png|ico)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
        try_files $uri =404;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_read_timeout 60s;
        # Ответ панели — 100–300 КБ HTML; буферы в памяти, чтобы nginx не писал
        # его во временный файл на диск.
        fastcgi_buffers 16 32k;
        fastcgi_buffer_size 64k;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

Логи nginx ротирует штатный `logrotate` (`/etc/logrotate.d/nginx`, 14 дней,
сжатие) — проверить, что он есть, если nginx ставился не из пакета.

HTTPS — через certbot (`apt install certbot python3-certbot-nginx`).

## Cron — планировщик Laravel

```
* * * * * cd /var/www/gravit && php artisan schedule:run >> /dev/null 2>&1
```

Он запускает `gravit:backup` в 03:00, `gravit:server-check` в 08:00 и
`gravit:daily-check` в 08:30; все три с `withoutOverlapping`, чтобы затянувшийся
архив не запустился вторым поверх первого. Отдельного воркера очереди нет:
`QUEUE_CONNECTION=sync`.

`gravit:server-check` — единственный, кто смотрит на саму машину: свободный
диск, доступная память (`/proc/meminfo`), возраст последней копии базы. Ниже
порога (`GRAVIT_MIN_FREE_DISK_MB=1500`, `GRAVIT_MIN_FREE_MEMORY_MB=150`) или
если ночной бэкап не отработал больше 36 часов — директору приходит
уведомление в панель. Руками: `php artisan gravit:server-check --dry`;
`deploy.sh` печатает его в конце каждого обновления.

## Диск: что и сколько занимает

| Что | Размер | Как удерживается |
|---|---|---|
| Код + vendor без dev | ~130 МБ | `composer install --no-dev` |
| База | на hoster.kz (MySQL) — 0 на диске VPS; SQLite — ~1 МБ на 1000 сделок | — |
| Чеки и договоры | до 10 МБ на файл | `client_max_body_size 12m` |
| Логи | ≤ 7 дней | `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS=7`, уровень `warning` |
| Бэкапы базы | 14 копий × мегабайты | ротация внутри `gravit:backup` (`--keep=14`) |
| Бэкапы файлов | 3 копии × размер `storage/app/public` | `--keep-files=3`; архив пропускается, если после него на диске осталось бы меньше 1,5 ГБ |

Почему файлов три копии, а базы 14: каждый архив — полный вес папки с чеками
и договорами, и она только растёт. При 1 ГБ документов 14 копий заняли бы
14 ГБ — весь диск. Три копии покрывают выходные; содержимое у них общее.

Раз в квартал стоит проверить `du -sh storage/app/public` — файлы не удаляются
вместе со сделками, чтобы не потерять чек по спору с клиентом. Когда папка
перевалит за 3 ГБ, копии файлов лучше увозить с сервера (`GRAVIT_BACKUP_DIR`
на примонтированный диск или `rsync` на другую машину).

## Развёртывание

Код живёт в приватном репозитории `github.com/baproger/gravitdoors.kz`
(ветка `main`). Сервер клонирует его по deploy-ключу — SSH-ключу только на
чтение, который лежит у `www-data` в `/var/www/.ssh` — и обновляется
через `git pull` внутри `deploy.sh`. Сборка фронтенда `public/build`
закоммичена, поэтому Node на сервере не нужен.

Первый раз, с рабочей машины (нужен `gh auth login`):

```bash
bash deploy/bootstrap.sh root@<ip сервера>
```

Скрипт копирует `deploy/` на сервер, запускает `server-setup.sh` (пакеты,
swap, PHP-FPM, nginx, cron), добавляет напечатанный deploy-ключ в GitHub
через `gh` и клонирует репозиторий в `/var/www/gravit`.

Дальше на сервере:

```bash
nano /var/www/gravit/.env                          # APP_URL, DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD
cd /var/www/gravit && sudo -u www-data bash deploy.sh   # composer, APP_KEY, миграции, кэши
sudo -u www-data php artisan gravit:install        # справочники без демо-данных + директор
certbot --nginx -d erp.gravit.kz                   # HTTPS
```

`gravit:install` накатывает миграции, заполняет справочники (этапы обеих
воронок, 13 этапов цеха, склад, касса и банк, прайс) и спрашивает имя, почту
и пароль директора. Демо-сделок и демо-сотрудников на бою нет. Повторный
запуск справочники не трогает: их правит владелец в панели.

Обновление: закоммитить и запушить в `main`, затем на сервере
`cd /var/www/gravit && sudo -u www-data bash deploy.sh`. Скрипт проверяет
место на диске, делает `git pull`, ставит composer без dev-пакетов, делает
бэкап перед миграциями, кэширует конфиг, маршруты, представления и
компоненты Filament, перезагружает PHP-FPM (иначе opcache исполняет старый
код) и в конце печатает состояние сервера.

Запасной путь без GitHub — `bash deploy/push.sh root@<ip>`: rsync кода с
рабочей машины и тот же `deploy.sh` без `git pull`. Пригодится, если надо
проверить незакоммиченную правку прямо на сервере.

## Когда сервер станет мал

Признаки: в `top` PHP-процессы упираются в 100 % на двух ядрах дольше минуты,
в логе nginx появляются 502/504, или `free -m` показывает постоянно занятый
swap. Тогда по порядку:
1. Поднять `pm.max_children` до 5, если в памяти остаётся больше 300 МБ свободных.
2. Перейти на 2 ГБ памяти — это дешевле любой переделки кода.
3. Только после этого — Redis для кэша и сессий и вынос PHP-FPM на второй сервер.
