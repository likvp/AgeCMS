#!/usr/bin/env bash
# Puts a version on the GitHub "Releases" page: tag vX.Y.Z, the notes from release.json and the signed package as an
# attachment. Sites do not read it (they update from the "releases" branch, see publish-release.sh), it is the page people
# see on the repository. Safe to run again: an existing release of the version is left as it is.
#   bin/github-release.sh <version> [commit] [dist] [notes.json]
# commit: what the tag points at (default: HEAD). dist: folder with agecms-<version>.json.gz (default: dist).
# notes.json: file with "notes" and "date" (default: <dist>/release.json).
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="${1:?usage: bin/github-release.sh <version> [commit] [dist] [notes.json]}"
COMMIT="${2:-HEAD}"
DIST="${3:-dist}"
META="${4:-$DIST/release.json}"
TAG="v$VERSION"
PACKAGE="$DIST/agecms-$VERSION.json.gz"
[ -f "$PACKAGE" ] || { echo "No $PACKAGE" >&2; exit 1; }
[ -f "$META" ] || { echo "No $META" >&2; exit 1; }
command -v gh >/dev/null || { echo "gh (GitHub CLI) is not installed: the GitHub release was skipped" >&2; exit 0; }

REPO="$(git remote get-url origin | sed -E 's#^(git@github.com:|https://github.com/)##; s#\.git$##')"
if gh release view "$TAG" --repo "$REPO" >/dev/null 2>&1; then
  echo "GitHub release $TAG already exists"
  exit 0
fi

NOTES="$(mktemp)"
trap 'rm -f "$NOTES"' EXIT
php -r '$j = json_decode(file_get_contents($argv[1]), true); echo trim((string)$j["notes"]), "\n\nПакет подписан ключами разработчика (Ed25519 и ECDSA P-256), сайты ставят его кнопкой «Обновить» в разделе «Система».\n";' "$META" > "$NOTES"

SHA="$(git rev-parse "$COMMIT")"
LATEST=true
[ "${GITHUB_RELEASE_LATEST:-1}" = "0" ] && LATEST=false
ASSETS=("$PACKAGE")
[ "$DIST" != "dist" ] || ASSETS+=("$DIST/release.json")
gh release create "$TAG" "${ASSETS[@]}" --repo "$REPO" --target "$SHA" --title "AgeCMS $VERSION" --notes-file "$NOTES" --latest="$LATEST"
echo "GitHub release: https://github.com/$REPO/releases/tag/$TAG"
