#!/usr/bin/env bash
# AgeCMS installer for Debian and Ubuntu: PHP with every extension, nginx (or the Apache already running),
# the site itself, file ownership, the virtual host, a Let's Encrypt certificate and a daily backup.
#
#   curl -fsSL https://raw.githubusercontent.com/likvp/AgeCMS/main/install.sh | sudo bash -s -- example.com
#
# Options (after the domain): --name="Мой сайт"  --email=you@example.com  --admin=admin  --password=...
#   --dir=/var/www/example.com  --web=nginx|apache  --php=8.3  --no-ssl  --no-demo  --branch=main
# Without a domain the site is set up on the server's IP over plain http.
# The whole script is one function, so `curl | bash` reads it completely before anything runs.

main() {
    set -euo pipefail

    local REPO="${AGECMS_REPO:-https://github.com/likvp/AgeCMS.git}"
    local BRANCH="main" DOMAIN="" NAME="" EMAIL="" ADMIN="admin" PASSWORD="" DIR="" WEB="" PHPV="" SSL=1 DEMO=1
    local LOG=/var/log/agecms-install.log

    local arg
    for arg in "$@"; do
        case "$arg" in
            --name=*) NAME="${arg#*=}" ;;
            --email=*) EMAIL="${arg#*=}" ;;
            --admin=*) ADMIN="${arg#*=}" ;;
            --password=*) PASSWORD="${arg#*=}" ;;
            --dir=*) DIR="${arg#*=}" ;;
            --web=*) WEB="${arg#*=}" ;;
            --php=*) PHPV="${arg#*=}" ;;
            --branch=*) BRANCH="${arg#*=}" ;;
            --no-ssl) SSL=0 ;;
            --no-demo) DEMO=0 ;;
            -h|--help) sed -n '2,10p' "${BASH_SOURCE[0]:-/dev/null}" 2>/dev/null || true; return 0 ;;
            -*) die "неизвестный параметр $arg" ;;
            *) DOMAIN="$arg" ;;
        esac
    done

    [ "$(id -u)" -eq 0 ] || die "запустите от root: ... | sudo bash -s -- ваш-домен"
    command -v apt-get >/dev/null || die "установщик поддерживает Debian и Ubuntu. На другой системе поставьте вручную по AGENTS.md"
    . /etc/os-release
    : > "$LOG"
    export DEBIAN_FRONTEND=noninteractive

    # Domain or URL to a bare host name; no domain means the server's own IP.
    DOMAIN="${DOMAIN#http://}"; DOMAIN="${DOMAIN#https://}"; DOMAIN="${DOMAIN%%/*}"
    local IS_IP=0
    if [ -z "$DOMAIN" ]; then
        DOMAIN="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") print $(i + 1)}' || true)"
        [ -n "$DOMAIN" ] || DOMAIN="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
        [ -n "$DOMAIN" ] || die "не удалось узнать IP сервера, передайте домен: ... | sudo bash -s -- example.com"
    fi
    [[ "$DOMAIN" =~ ^[0-9.]+$ ]] && IS_IP=1 && SSL=0
    [[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || die "странный домен: $DOMAIN"
    [ -n "$NAME" ] || NAME="$DOMAIN"
    [ -n "$DIR" ] || { [ "$IS_IP" -eq 1 ] && DIR=/var/www/agecms || DIR="/var/www/$DOMAIN"; }

    if [ -f "$DIR/data/install.lock" ]; then
        say "AgeCMS уже установлена в $DIR. Новые версии ставятся кнопкой «Обновить» в админке, раздел «Система»."
        return 0
    fi
    if [ -e "$DIR" ] && [ -n "$(ls -A "$DIR" 2>/dev/null)" ] && [ ! -d "$DIR/.git" ]; then
        die "каталог $DIR не пустой. Укажите другой: --dir=/var/www/новый"
    fi

    step "Пакеты системы ($PRETTY_NAME)"
    apt_install ca-certificates curl git cron

    # PHP: the one already installed if it is 8.1+, else the distribution's, else the sury/ondrej repository.
    if [ -z "$PHPV" ] && command -v php >/dev/null; then
        PHPV="$(php -r 'echo PHP_VERSION_ID >= 80100 ? PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION : "";' 2>/dev/null || true)"
    fi
    if [ -z "$PHPV" ]; then
        local cand
        cand="$(apt-cache policy php-cli 2>/dev/null | awk '/Candidate:/ {print $2}' | sed -E 's/^[0-9]+://; s/^([0-9]+\.[0-9]+).*/\1/')"
        if [[ "$cand" =~ ^[0-9]+\.[0-9]+$ ]] && [ "$(ver "$cand")" -ge 801 ]; then
            PHPV="$cand"
        else
            add_php_repo
            PHPV=8.3
        fi
    fi
    if ! apt-cache show "php$PHPV-fpm" >/dev/null 2>&1; then
        add_php_repo
    fi

    step "PHP $PHPV и расширения"
    apt_install "php$PHPV-cli" "php$PHPV-fpm" "php$PHPV-sqlite3" "php$PHPV-mbstring" "php$PHPV-gd" "php$PHPV-curl" "php$PHPV-zip" "php$PHPV-xml"
    local PHP="/usr/bin/php$PHPV"
    [ -x "$PHP" ] || PHP="$(command -v php)"
    svc_start "php$PHPV-fpm"
    local SOCK="/run/php/php$PHPV-fpm.sock"

    # Web server: keep an Apache that already owns port 80, otherwise nginx.
    if [ -z "$WEB" ]; then
        if command -v apache2 >/dev/null && ! command -v nginx >/dev/null; then WEB=apache; else WEB=nginx; fi
    fi
    step "Веб-сервер $WEB"
    if [ "$WEB" = nginx ]; then
        apt_install nginx
        svc_start nginx
    elif [ "$WEB" = apache ]; then
        apt_install apache2
        run a2enmod rewrite headers proxy_fcgi setenvif
        run a2enconf "php$PHPV-fpm"
        svc_start apache2
    else
        die "--web бывает nginx или apache"
    fi

    step "AgeCMS в $DIR"
    if [ -d "$DIR/.git" ]; then
        run git -c safe.directory="$DIR" -C "$DIR" pull --ff-only
    else
        mkdir -p "$(dirname "$DIR")"
        run git clone --depth 1 --branch "$BRANCH" "$REPO" "$DIR"
    fi
    chown -R www-data:www-data "$DIR"

    step "Проверка среды"
    local code=0
    runuser -u www-data -- "$PHP" "$DIR/bin/requirements.php" >>"$LOG" 2>&1 || code=$?
    if [ "$code" -ne 0 ]; then
        runuser -u www-data -- "$PHP" "$DIR/bin/requirements.php" | grep -E '^(НЕТ|СОВЕТ)' >&2 || true
        [ "$code" -eq 2 ] || die "не хватает обязательного, см. строки выше"
        warn "часть рекомендуемого не поставилась, сайт будет работать без этих возможностей"
    fi

    step "Настройка $WEB для $DOMAIN"
    if [ "$WEB" = nginx ]; then
        local conf="/etc/nginx/sites-available/agecms-$DOMAIN"
        [ -f "$conf" ] && cp "$conf" "$conf.bak-$(date +%Y%m%d%H%M%S)"
        sed -e "s|server_name example.com;|server_name $DOMAIN;|" \
            -e "s|root /var/www/agecms;|root $DIR;|" \
            -e "s|unix:/run/php/php[0-9.]*-fpm.sock|unix:$SOCK|" \
            "$DIR/nginx.vhost.example" > "$conf"
        ln -sf "$conf" "/etc/nginx/sites-enabled/agecms-$DOMAIN"
        nginx -t >>"$LOG" 2>&1 || { tail -5 "$LOG" >&2; die "nginx не принял конфиг $conf"; }
        svc_reload nginx
    else
        local conf="/etc/apache2/sites-available/agecms-$DOMAIN.conf"
        [ -f "$conf" ] && cp "$conf" "$conf.bak-$(date +%Y%m%d%H%M%S)"
        cat > "$conf" <<APACHE
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $DIR
    <Directory $DIR>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
APACHE
        run a2ensite "agecms-$DOMAIN"
        apache2ctl configtest >>"$LOG" 2>&1 || { tail -5 "$LOG" >&2; die "Apache не принял конфиг $conf"; }
        svc_reload apache2
    fi
    if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q "Status: active"; then
        run ufw allow 80/tcp
        run ufw allow 443/tcp
    fi

    local SCHEME=http
    if [ "$SSL" -eq 1 ]; then
        step "Сертификат Let's Encrypt"
        if [ "$WEB" = nginx ]; then apt_install certbot python3-certbot-nginx; else apt_install certbot python3-certbot-apache; fi
        local mail=(--register-unsafely-without-email)
        [ -n "$EMAIL" ] && mail=(-m "$EMAIL")
        if certbot "--$WEB" -d "$DOMAIN" --non-interactive --agree-tos --redirect "${mail[@]}" >>"$LOG" 2>&1; then
            SCHEME=https
        else
            warn "сертификат не получен (домен ещё не смотрит на этот сервер?). Сайт работает по http, позже: certbot --$WEB -d $DOMAIN"
        fi
    fi

    step "Установка сайта"
    local opts=(--url="$SCHEME://$DOMAIN" --name="$NAME" --admin="$ADMIN")
    [ -n "$PASSWORD" ] && opts+=(--password="$PASSWORD")
    [ "$DEMO" -eq 0 ] && opts+=(--no-demo)
    local out
    out="$(cd "$DIR" && runuser -u www-data -- "$PHP" bin/install.php "${opts[@]}" 2>>"$LOG")" || { tail -5 "$LOG" >&2; die "установка не прошла"; }
    local pass
    pass="$(printf '%s\n' "$out" | awk '/^Password:/ {print $2}')"
    [ -n "$pass" ] || pass="$PASSWORD"

    if [ -d /etc/cron.d ]; then
        printf '%s\n' "# AgeCMS $DOMAIN: daily database backup" "17 3 * * * www-data $PHP $DIR/bin/backup.php >/dev/null 2>&1" > "/etc/cron.d/agecms-${DOMAIN//./-}"
    fi

    step "Проверка"
    local status
    status="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $DOMAIN" http://127.0.0.1/ || true)"
    [ "$SCHEME" = https ] && status="$(curl -s -o /dev/null -w '%{http_code}' --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/" || true)"
    [ "$status" = 200 ] || warn "главная ответила кодом $status, журнал установки: $LOG"

    printf '\n\033[1;32m  AgeCMS установлена\033[0m\n\n'
    printf '  Сайт:     %s://%s/\n' "$SCHEME" "$DOMAIN"
    printf '  Админка:  %s://%s/admin/\n' "$SCHEME" "$DOMAIN"
    printf '  Логин:    %s\n' "$ADMIN"
    printf '  Пароль:   \033[1m%s\033[0m   (показан один раз, сохраните сейчас)\n\n' "$pass"
    printf '  Файлы %s, PHP %s, %s, бэкап базы каждую ночь.\n' "$DIR" "$PHPV" "$WEB"
    printf '  Подключить ИИ-агента: админка, «Система», «Получить текст для агента».\n\n'
}

say() { printf '%s\n' "$*"; }
step() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m  ! %s\033[0m\n' "$*" >&2; }
die() { printf '\033[1;31mОшибка: %s\033[0m\n' "$*" >&2; exit 1; }
ver() { echo "$1" | awk -F. '{print $1 * 100 + $2}'; }
run() { "$@" </dev/null >>"${LOG:-/dev/null}" 2>&1 || { tail -15 "${LOG:-/dev/null}" >&2; die "не выполнилось: $*"; }; }

apt_install() {
    if [ -z "${APT_UPDATED:-}" ]; then run apt-get -o DPkg::Lock::Timeout=300 update; APT_UPDATED=1; fi
    run apt-get -o DPkg::Lock::Timeout=300 install -y --no-install-recommends "$@"
}

# PHP 8.1+ for distributions that ship an older one: ppa:ondrej/php on Ubuntu, packages.sury.org on Debian.
add_php_repo() {
    step "Подключаю репозиторий PHP"
    if [ "${ID:-}" = ubuntu ]; then
        apt_install software-properties-common
        run add-apt-repository -y ppa:ondrej/php
    else
        apt_install ca-certificates curl
        run curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
        run dpkg -i /tmp/debsuryorg-archive-keyring.deb
        echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" > /etc/apt/sources.list.d/php.list
    fi
    run apt-get -o DPkg::Lock::Timeout=300 update
}

# systemd on a normal server, plain init scripts inside containers.
svc_start() {
    if [ -d /run/systemd/system ]; then run systemctl enable --now "$1"; else run service "$1" start; fi
}
svc_reload() {
    if [ -d /run/systemd/system ]; then run systemctl reload "$1"; else run service "$1" reload; fi
}

main "$@"
