#!/usr/bin/env bash
# Installs what the "exact copy" mode of the site importer needs on a Debian or Ubuntu server: fonts (Cyrillic, emoji), Node.js,
# Google Chrome (Chromium from Playwright where Google has no Chrome, i.e. arm64) and the dependencies of tools/capture.
# Called by install.sh and by the "Install what is missing" button in the admin (core/ServerSetup.php); safe to run again, a
# second run only brings the packages up to date.
#
#   sudo bash bin/install-capture.sh [--dir=/var/www/site] [--user=www-data] [--php=/usr/bin/php] [--status-file=FILE]
#                                    [--browser=auto|chrome|chromium] [--node-major=24] [--configure-only]
#
#   --dir             the site (default: the folder above bin/)
#   --user            the user PHP-FPM runs as (default: owner of the site's index.php, else www-data)
#   --php             command-line PHP used to write capture.node and capture.chrome into the site config
#   --status-file     progress for the admin: "running", "ready", "failed", "skipped" or "unsupported", a time and a sentence
#   --browser         auto: Google Chrome on amd64, Chromium from Playwright elsewhere; chromium: Playwright's Chromium everywhere
#   --configure-only  install nothing, only write the config and run the self-test (install.sh calls it after the site exists)
#
# Exit code: 0 ready, 1 failed, 2 this system is not supported, 3 skipped (less than 2 GB free). The last line printed is
# CAPTURE_RESULT=ready|failed|unsupported|skipped.
# Environment: AGECMS_NODE_MAJOR (default 24), AGECMS_CAPTURE_MIN_MB (free space needed, default 2048),
# AGECMS_BROWSERS_DIR (default /opt/agecms/browsers).

main() {
    set -uo pipefail

    local DIR USER_NAME="" PHP="" STATUS_FILE="" BROWSER=auto NODE_MAJOR="${AGECMS_NODE_MAJOR:-24}" CONFIGURE_ONLY=0
    local MIN_MB="${AGECMS_CAPTURE_MIN_MB:-2048}"
    BROWSERS_DIR="${AGECMS_BROWSERS_DIR:-/opt/agecms/browsers}"
    LOG="${AGECMS_CAPTURE_LOG:-/var/log/agecms-install-capture.log}"
    REASON=""
    DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

    local arg
    for arg in "$@"; do
        case "$arg" in
            --dir=*) DIR="${arg#*=}" ;;
            --user=*) USER_NAME="${arg#*=}" ;;
            --php=*) PHP="${arg#*=}" ;;
            --status-file=*) STATUS_FILE="${arg#*=}" ;;
            --browser=*) BROWSER="${arg#*=}" ;;
            --node-major=*) NODE_MAJOR="${arg#*=}" ;;
            --configure-only) CONFIGURE_ONLY=1 ;;
            -h|--help) sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; return 0 ;;
            *) printf 'Неизвестный параметр %s\n' "$arg" >&2; return 1 ;;
        esac
    done
    STATUS_PATH="$STATUS_FILE"
    TOOL="$DIR/tools/capture"
    umask 022
    export DEBIAN_FRONTEND=noninteractive LC_ALL=C

    [[ "$NODE_MAJOR" =~ ^[0-9]+$ ]] || { finish failed "--node-major должен быть числом"; return $?; }
    case "$BROWSER" in auto|chrome|chromium) ;; *) finish failed "--browser бывает auto, chrome или chromium"; return $?; esac
    [ "$(id -u)" -eq 0 ] || { finish failed "нужны права root: sudo bash $0"; return $?; }
    [ -d "$DIR" ] || { finish failed "нет каталога сайта $DIR"; return $?; }
    : > "$LOG" 2>/dev/null || LOG=/dev/null
    if [ -z "$USER_NAME" ]; then
        USER_NAME="$(stat -c %U "$DIR/index.php" 2>/dev/null || true)"
        { [ -n "$USER_NAME" ] && [ "$USER_NAME" != root ] && [ "$USER_NAME" != UNKNOWN ]; } || USER_NAME=www-data
    fi
    id "$USER_NAME" >/dev/null 2>&1 || { finish failed "нет пользователя $USER_NAME (укажите --user=)"; return $?; }
    [ -f "$TOOL/capture.mjs" ] || { finish failed "в сайте нет программы захвата ($TOOL/capture.mjs)"; return $?; }
    if [ -z "$PHP" ]; then PHP="$(command -v php 2>/dev/null || true)"; fi

    if [ "$CONFIGURE_ONLY" -eq 0 ]; then
        command -v apt-get >/dev/null 2>&1 || { finish unsupported "поддерживаются Debian и Ubuntu (нужен apt). На другой системе поставьте Node.js 20+ и Chrome сами и выполните в каталоге сайта: php bin/config.php set capture.node /путь/к/node; php bin/config.php set capture.chrome /путь/к/chrome"; return $?; }
        local ARCH
        ARCH="$(dpkg --print-architecture 2>/dev/null || true)"
        case "$ARCH" in amd64|arm64) ;; *) finish unsupported "архитектура $ARCH не поддерживается (нужна amd64 или arm64)"; return $?; esac

        status running "идёт установка"
        step "Node.js и Chrome для режима «Точная копия»"
        say "  Займёт несколько минут (на медленном канале до 15) и около 1 ГБ на диске: шрифты, Node.js, Chrome (или Chromium), библиотеки программы захвата."
        local free
        free="$(free_mb "$DIR" /usr /opt /var)"
        if [ "$free" -lt "$MIN_MB" ]; then
            finish skipped "мало места на диске: свободно $free МБ, нужно не меньше $MIN_MB МБ"
            return $?
        fi
        local mem
        mem="$(awk '/^MemTotal:/ {print int($2 / 1024)}' /proc/meminfo 2>/dev/null || echo 0)"
        if [ "${mem:-0}" -gt 0 ] && [ "$mem" -lt 900 ]; then
            warn "оперативной памяти $mem МБ: Chrome может не запускаться, добавьте swap или 1 ГБ памяти"
        fi
        local lockdir=/run/lock
        [ -d "$lockdir" ] && [ -w "$lockdir" ] || lockdir=/tmp
        exec 9>"$lockdir/agecms-install-capture.lock"
        flock -n 9 || { finish failed "установка уже идёт (другой запуск этого скрипта)"; return $?; }

        step_fonts || { finish failed "$REASON"; return $?; }
        step_node "$NODE_MAJOR" || { finish failed "$REASON"; return $?; }
        step_deps || { finish failed "$REASON"; return $?; }
        step_browser "$BROWSER" "$ARCH" || { finish failed "$REASON"; return $?; }
        # The downloaded packages are several hundred megabytes that nothing needs any more.
        apt-get clean >>"$LOG" 2>&1 || true
    else
        step "Настройка захвата сайтов"
    fi

    local NODE CHROME
    NODE="$(find_node)" || { finish failed "Node.js 20 или новее не найден"; return $?; }
    CHROME="$(find_chrome)" || { finish failed "Chrome или Chromium не найден"; return $?; }

    # The paths go into the site config, so PHP does not depend on its own PATH (PHP-FPM clears it).
    if [ -f "$DIR/data/install.lock" ] && [ -n "$PHP" ]; then
        (cd "$DIR" && runuser -u "$USER_NAME" -- "$PHP" bin/config.php set capture.node "$NODE" && runuser -u "$USER_NAME" -- "$PHP" bin/config.php set capture.chrome "$CHROME") >>"$LOG" 2>&1 \
            || warn "не удалось записать пути в настройки сайта; выполните: php bin/config.php set capture.node $NODE и capture.chrome $CHROME"
    else
        say "  Пути к Node.js и Chrome в настройки сайта запишутся, когда сайт будет установлен."
    fi

    step "Самопроверка захвата (от пользователя $USER_NAME)"
    local out rc
    out="$(self_test "$NODE" "$CHROME" "$USER_NAME")"
    rc=$?
    if [ "$rc" -ne 0 ]; then
        hints "$out"
        finish failed "Chrome не запустился от пользователя $USER_NAME: $(printf '%s' "$out" | tail -n 2 | tr '\n' ' ')"
        return $?
    fi
    # What the tool itself says (browser version, whether its sandbox is on, warnings) is worth showing.
    printf '%s\n' "$out" | grep -v '^ok$' | sed 's/^/  /' || true
    if printf '%s' "$out" | grep -qi 'без песочницы'; then
        warn "Chrome работает без песочницы (защита от вредных страниц слабее): включите пользовательские пространства имён ядра, подробности выше"
    fi
    finish ready "Node.js $("$NODE" --version), $(browser_version "$CHROME"), шрифты и зависимости на месте"
    return $?
}

say() { printf '%s\n' "$*"; }
step() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m  ! %s\033[0m\n' "$*" >&2; }

# status STATE MESSAGE: the file the admin reads while the installation runs in the background.
status() {
    [ -n "${STATUS_PATH:-}" ] || return 0
    printf '%s\t%s\t%s\n' "$1" "$(date +%s)" "$2" > "$STATUS_PATH" 2>/dev/null || true
    chmod 644 "$STATUS_PATH" 2>/dev/null || true
}

# finish STATE MESSAGE: prints the verdict and the machine-readable last line, returns the exit code.
finish() {
    local state="$1" msg="$2" code=0
    case "$state" in
        ready) printf '\033[1;32m  Захват сайтов: готов\033[0m (%s)\n' "$msg" ;;
        skipped) code=3; printf '\033[1;33m  Захват сайтов: пропущен: %s\033[0m\n' "$msg"
                 say "  Сайт работает без него: импорт по адресу делает страницы AgeCMS, пакет «Точной копии» можно загрузить файлом. Когда освободите место: sudo bash bin/install-capture.sh" ;;
        unsupported) code=2; printf '\033[1;33m  Захват сайтов: не поддерживается: %s\033[0m\n' "$msg" ;;
        *) state=failed; code=1; printf '\033[1;31m  Захват сайтов: не готов: %s\033[0m\n' "$msg" >&2
           say "  Подробности: ${LOG:-/dev/null}. Повторить: sudo bash ${BASH_SOURCE[0]:-bin/install-capture.sh}. Сайт работает и без захвата: импорт по адресу делает страницы AgeCMS, пакет «Точной копии» можно загрузить файлом." ;;
    esac
    status "$state" "$msg"
    printf 'CAPTURE_RESULT=%s\n' "$state"
    return "$code"
}

# run CMD...: output goes to the log; on failure the tail is shown and REASON names the command.
run() {
    if "$@" </dev/null >>"$LOG" 2>&1; then return 0; fi
    tail -n 12 "$LOG" >&2 2>/dev/null || true
    REASON="не выполнилось: $*"
    return 1
}

apt_update() { run apt-get -o DPkg::Lock::Timeout=300 update; }
apt_install() {
    if [ -z "${APT_UPDATED:-}" ]; then apt_update || return 1; APT_UPDATED=1; fi
    run apt-get -o DPkg::Lock::Timeout=300 install -y --no-install-recommends "$@"
}

# Free megabytes on the emptiest of the given folders' file systems.
free_mb() {
    local min="" d kb
    for d in "$@"; do
        [ -d "$d" ] || continue
        kb="$(df -Pk "$d" 2>/dev/null | awk 'NR == 2 {print $4}')"
        [[ "$kb" =~ ^[0-9]+$ ]] || continue
        if [ -z "$min" ] || [ "$kb" -lt "$min" ]; then min="$kb"; fi
    done
    echo $(( ${min:-0} / 1024 ))
}

step_fonts() {
    step "Шрифты (кириллица, эмодзи)"
    apt_install ca-certificates curl gnupg fontconfig fonts-liberation fonts-dejavu-core fonts-noto-core fonts-noto-color-emoji
}

# Node.js of at least version 20: the one already there if it is new enough, else the NodeSource apt repository
# (signed key in /etc/apt/keyrings; security updates come with the usual apt upgrade).
step_node() {
    local major="$1" have=""
    have="$(node_major)"
    if [ "${have:-0}" -ge 20 ]; then
        step "Node.js уже есть (версия $have), оставляю"
        return 0
    fi
    step "Node.js $major (репозиторий NodeSource)"
    install -d -m 0755 /etc/apt/keyrings || { REASON="нет /etc/apt/keyrings"; return 1; }
    local tmp
    tmp="$(mktemp)"
    run curl -fsSL --retry 3 -o "$tmp" https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key || { rm -f "$tmp"; REASON="не скачался ключ репозитория NodeSource (нет доступа в интернет?)"; return 1; }
    gpg --dearmor < "$tmp" > /etc/apt/keyrings/nodesource.gpg 2>>"$LOG" || { rm -f "$tmp"; REASON="ключ NodeSource не разобрался"; return 1; }
    rm -f "$tmp"
    chmod 644 /etc/apt/keyrings/nodesource.gpg
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${major}.x nodistro main" > /etc/apt/sources.list.d/nodesource.list
    printf 'Package: nodejs\nPin: origin deb.nodesource.com\nPin-Priority: 600\n' > /etc/apt/preferences.d/nodejs-nodesource
    apt_update || { REASON="репозиторий NodeSource недоступен или не поддерживает эту систему"; return 1; }
    APT_UPDATED=1
    run apt-get -o DPkg::Lock::Timeout=300 install -y --no-install-recommends nodejs || { REASON="пакет nodejs не поставился"; return 1; }
    have="$(node_major)"
    [ "${have:-0}" -ge 20 ] || { REASON="после установки Node.js версия ${have:-?}, нужна 20 или новее"; return 1; }
}

step_deps() {
    step "Библиотеки программы захвата (npm ci в tools/capture)"
    local lock="$TOOL/package-lock.json" node
    node="$(find_node)" || { REASON="Node.js не найден"; return 1; }
    local npmbin
    npmbin="$(dirname "$node")/npm"
    [ -x "$npmbin" ] || npmbin="$(command -v npm 2>/dev/null || true)"
    [ -n "$npmbin" ] || { REASON="не найден npm"; return 1; }
    (
        cd "$TOOL" || exit 1
        PATH="$(dirname "$node"):$PATH"
        export PATH npm_config_update_notifier=false npm_config_audit=false npm_config_fund=false PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
        if [ -f "$lock" ]; then
            "$npmbin" ci --omit=dev --ignore-scripts || "$npmbin" install --omit=dev --ignore-scripts
        else
            "$npmbin" install --omit=dev --ignore-scripts
        fi
    ) </dev/null >>"$LOG" 2>&1 || { tail -n 12 "$LOG" >&2; REASON="npm не поставил зависимости (нет доступа к registry.npmjs.org?)"; return 1; }
    [ -d "$TOOL/node_modules/playwright-core" ] || { REASON="в tools/capture/node_modules нет playwright-core"; return 1; }
    # PHP-FPM only needs to read the dependencies; the tool itself writes nothing into its own folder.
    chown -R "$USER_NAME:$(id -gn "$USER_NAME")" "$TOOL/node_modules"
}

step_browser() {
    local want="$1" arch="$2"
    if [ "$want" = auto ]; then
        if [ "$arch" = amd64 ]; then want=chrome; else want=chromium; fi
    fi
    if [ "$want" = chrome ] && [ "$arch" != amd64 ]; then
        warn "для $arch у Google нет Chrome, ставлю Chromium"
        want=chromium
    fi
    if [ "$want" = chrome ]; then
        step "Google Chrome (репозиторий Google)"
        if browser_chrome; then return 0; fi
        warn "Google Chrome не поставился ($REASON), пробую Chromium"
    fi
    step "Chromium (Playwright, $BROWSERS_DIR)"
    browser_chromium
}

browser_chrome() {
    install -d -m 0755 /etc/apt/keyrings || { REASON="нет /etc/apt/keyrings"; return 1; }
    local tmp
    tmp="$(mktemp)"
    run curl -fsSL --retry 3 -o "$tmp" https://dl.google.com/linux/linux_signing_key.pub || { rm -f "$tmp"; REASON="не скачался ключ Google"; return 1; }
    gpg --dearmor < "$tmp" > /etc/apt/keyrings/google-chrome.gpg 2>>"$LOG" || { rm -f "$tmp"; REASON="ключ Google не разобрался"; return 1; }
    rm -f "$tmp"
    chmod 644 /etc/apt/keyrings/google-chrome.gpg
    echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/google-chrome.gpg] https://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list
    apt_update || { REASON="репозиторий Google недоступен"; return 1; }
    APT_UPDATED=1
    run apt-get -o DPkg::Lock::Timeout=300 install -y --no-install-recommends google-chrome-stable || { REASON="пакет google-chrome-stable не поставился"; return 1; }
    [ -x /opt/google/chrome/chrome ] || { REASON="после установки нет /opt/google/chrome/chrome"; return 1; }
}

# Chromium that Playwright downloads (the build Playwright is tested with), in a folder every user can read. The distribution's
# own chromium package is a snap on Ubuntu and cannot be started by the web server's user, so it is not used.
browser_chromium() {
    local node
    node="$(find_node)" || { REASON="Node.js не найден"; return 1; }
    install -d -m 0755 "$BROWSERS_DIR" || { REASON="нет $BROWSERS_DIR"; return 1; }
    (
        cd "$TOOL" || exit 1
        PATH="$(dirname "$node"):$PATH"
        export PATH PLAYWRIGHT_BROWSERS_PATH="$BROWSERS_DIR"
        "$node" node_modules/playwright-core/cli.js install --with-deps --no-shell chromium \
            || "$node" node_modules/playwright-core/cli.js install --no-shell chromium
    ) </dev/null >>"$LOG" 2>&1 || { tail -n 12 "$LOG" >&2; REASON="Chromium от Playwright не поставился (нет доступа к cdn.playwright.dev или к apt?)"; return 1; }
    chmod -R a+rX "$BROWSERS_DIR"
    [ -n "$(playwright_chromium)" ] || { REASON="Chromium скачан, но исполняемый файл не найден в $BROWSERS_DIR"; return 1; }
    apparmor_userns
}

# Ubuntu 23.10+ forbids user namespaces to programs without an AppArmor profile, and Chrome's sandbox needs them.
# Google Chrome ships with a profile there; Chromium from Playwright gets one that allows only this.
apparmor_userns() {
    [ "$(cat /proc/sys/kernel/apparmor_restrict_unprivileged_userns 2>/dev/null || echo 0)" = 1 ] || return 0
    command -v apparmor_parser >/dev/null 2>&1 || return 0
    cat > /etc/apparmor.d/agecms-chromium <<PROFILE
abi <abi/4.0>,
include <tunables/global>

profile agecms-chromium $BROWSERS_DIR/**/chrome flags=(unconfined) {
  userns,

  include if exists <local/agecms-chromium>
}
PROFILE
    apparmor_parser -r /etc/apparmor.d/agecms-chromium >>"$LOG" 2>&1 \
        || warn "профиль AppArmor для Chromium не загрузился, если самопроверка ниже не пройдёт, причина в нём"
    return 0
}

playwright_chromium() {
    local node
    node="$(find_node)" || return 0
    (cd "$TOOL" 2>/dev/null && PLAYWRIGHT_BROWSERS_PATH="$BROWSERS_DIR" "$node" -e "const p=require('playwright-core').chromium.executablePath();if(require('fs').existsSync(p))console.log(p)" 2>/dev/null) || true
}

node_major() {
    local n
    n="$(find_node_any)" || { echo 0; return 0; }
    "$n" -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0
}

find_node_any() {
    local c
    for c in "$(command -v node 2>/dev/null || true)" /usr/bin/node /usr/local/bin/node; do
        [ -n "$c" ] && [ -x "$c" ] && { echo "$c"; return 0; }
    done
    return 1
}

# Absolute path of a Node.js 20+.
find_node() {
    local n
    n="$(find_node_any)" || return 1
    [ "$("$n" -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)" -ge 20 ] || return 1
    echo "$n"
}

# Absolute path of the browser: Google Chrome, else Playwright's Chromium, else a plain chromium package (not a snap).
find_chrome() {
    local c
    if [ -x /opt/google/chrome/chrome ]; then echo /opt/google/chrome/chrome; return 0; fi
    c="$(playwright_chromium)"
    if [ -n "$c" ]; then echo "$c"; return 0; fi
    for c in /usr/bin/chromium /usr/lib/chromium/chromium; do
        if [ -x "$c" ] && ! readlink -f "$c" | grep -q '^/snap/'; then echo "$c"; return 0; fi
    done
    return 1
}

browser_version() {
    "$1" --version 2>/dev/null | head -n 1 | sed 's/ *$//' || echo "$1"
}

# The self-test the way PHP-FPM runs it: the site's user, a bare environment, no HOME.
self_test() {
    local node="$1" chrome="$2" user="$3" out code
    if grep -q -- '--self-test' "$TOOL/capture.mjs" 2>/dev/null; then
        out="$(cd "$TOOL" && runuser -u "$user" -- env -i PATH=/usr/local/bin:/usr/bin:/bin "$node" capture.mjs --self-test "--chrome=$chrome" 2>&1 </dev/null)"
        code=$?
    else
        # An older capture.mjs without --self-test: open about:blank directly.
        out="$(cd "$TOOL" && runuser -u "$user" -- env -i PATH=/usr/local/bin:/usr/bin:/bin "$node" -e "
const { chromium } = require('playwright-core');
(async () => {
  const b = await chromium.launch({ executablePath: process.argv[1], headless: true });
  const p = await b.newPage(); await p.goto('about:blank'); await b.close(); console.log('ok');
})().catch((e) => { console.error(String(e.message || e).split('\n').slice(0, 6).join('\n')); process.exit(1); });
" "$chrome" 2>&1 </dev/null)"
        code=$?
    fi
    printf '%s\n' "$out" >>"$LOG"
    printf '%s\n' "$out"
    return "$code"
}

# Why Chrome often does not start on a server, in words.
hints() {
    local out="$1"
    if printf '%s' "$out" | grep -qiE 'sandbox|user namespace|No usable sandbox|clone'; then
        warn "Chrome не смог включить песочницу. Причины: ядро запрещает пользовательские пространства имён (sysctl kernel.unprivileged_userns_clone=1 и user.max_user_namespaces больше 0), в Ubuntu 24.04 это ещё kernel.apparmor_restrict_unprivileged_userns, в Docker нужен seccomp без ограничений (--security-opt seccomp=unconfined)."
    fi
    if printf '%s' "$out" | grep -qiE 'error while loading shared libraries|cannot open shared object'; then
        warn "не хватает системных библиотек Chrome: повторите sudo bash bin/install-capture.sh"
    fi
    if printf '%s' "$out" | grep -qiE 'Target page, context or browser has been closed|SIGTRAP|SIGSEGV|Out of memory|ENOMEM'; then
        warn "Chrome упал сразу после запуска: проверьте свободную память (нужен хотя бы 1 ГБ или swap)"
    fi
}

main "$@"
