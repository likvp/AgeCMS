#!/bin/sh
# Прогон тестов на PHP 8.1-8.4 в Docker (официальные образы php:X-cli плюс gd, zip, intl).
# Использование: bin/test-matrix.sh [версии...]   по умолчанию: 8.1 8.2 8.3 8.4
# SQLITE=3.26 bin/test-matrix.sh 8.1   то же на старой библиотеке SQLite (как на REG.RU): собирается из исходников
# с sqlite.org вместе с FTS5 и подкладывается PHP вместо системной. Минимальная версия, на которой ядро обязано работать.
set -e
cd "$(dirname "$0")/.."
versions="${*:-8.1 8.2 8.3 8.4}"
tmp="$(mktemp -d)"
case "${SQLITE:-}" in
    '') sqlite_build='' ;;
    3.26) sqlite_build='2018/sqlite-autoconf-3260000' ;;
    *) echo "SQLITE=${SQLITE}: known only 3.26" >&2; exit 1 ;;
esac
cat > "$tmp/Dockerfile" <<'D'
ARG V
FROM php:${V}-cli
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions gd zip intl >/dev/null 2>&1
ARG SQLITE_BUILD=
RUN if [ -n "$SQLITE_BUILD" ]; then \
        curl -fsSL "https://www.sqlite.org/${SQLITE_BUILD}.tar.gz" | tar -xz -C /tmp \
        && cd /tmp/sqlite-autoconf-* \
        && CFLAGS="-O2 -DSQLITE_ENABLE_FTS5 -DSQLITE_ENABLE_JSON1" ./configure --prefix=/opt/sqlite --disable-static >/dev/null \
        && make -j4 >/dev/null 2>&1 && make install >/dev/null \
        && echo /opt/sqlite/lib > /etc/ld.so.conf.d/00-sqlite.conf && ldconfig; \
    fi
D
src="$tmp/src"
mkdir -p "$src"
tar --exclude=.git -cf - . | tar -xf - -C "$src"
fail=0
for v in $versions; do
    tag="agecms-php:$v${SQLITE:+-sqlite$SQLITE}"
    docker build -q --build-arg V="$v" --build-arg SQLITE_BUILD="$sqlite_build" -t "$tag" "$tmp" >/dev/null
    lib="$(docker run --rm "$tag" php -r 'echo (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn();')"
    if docker run --rm -v "$src":/app -w /app "$tag" php tests/run.php > "$tmp/$v.log" 2>&1; then
        echo "PHP $v, SQLite $lib: $(grep passed "$tmp/$v.log")"
    else
        echo "PHP $v, SQLite $lib: FAIL"; grep -A3 '^FAIL' "$tmp/$v.log" | head -40; fail=1
    fi
done
echo "Logs: $tmp"
exit $fail
