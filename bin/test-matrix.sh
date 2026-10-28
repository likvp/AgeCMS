#!/bin/sh
# Прогон тестов на PHP 8.1-8.4 в Docker (официальные образы php:X-cli плюс gd, zip, intl).
# Использование: bin/test-matrix.sh [версии...]   по умолчанию: 8.1 8.2 8.3 8.4
set -e
cd "$(dirname "$0")/.."
versions="${*:-8.1 8.2 8.3 8.4}"
tmp="$(mktemp -d)"
cat > "$tmp/Dockerfile" <<'D'
ARG V
FROM php:${V}-cli
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions gd zip intl >/dev/null 2>&1
D
src="$tmp/src"
mkdir -p "$src"
tar --exclude=.git -cf - . | tar -xf - -C "$src"
fail=0
for v in $versions; do
    docker build -q --build-arg V="$v" -t "agecms-php:$v" "$tmp" >/dev/null
    if docker run --rm -v "$src":/app -w /app "agecms-php:$v" php tests/run.php > "$tmp/$v.log" 2>&1; then
        echo "PHP $v: $(grep passed "$tmp/$v.log")"
    else
        echo "PHP $v: FAIL"; grep -A3 '^FAIL' "$tmp/$v.log" | head -20; fail=1
    fi
done
exit $fail
