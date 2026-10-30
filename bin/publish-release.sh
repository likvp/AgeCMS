#!/usr/bin/env bash
# Publishes a release built by bin/release.php: commits <dist>/release.json and <dist>/agecms-*.json.gz to the
# "releases" branch of the repository and pushes it. Sites read
# https://raw.githubusercontent.com/likvp/AgeCMS/releases/release.json (the default updates.url).
# Older packages stay in the branch, so a site that missed a version can still download the newest one.
# Every published version also goes to the GitHub "Releases" page (bin/github-release.sh: tag vX.Y.Z, notes, package).
# The tag points at HEAD, so commit core/release.json and push main before running this script.
#   bin/publish-release.sh [dist]
set -euo pipefail
cd "$(dirname "$0")/.."
DIST="${1:-dist}"
[ -f "$DIST/release.json" ] || { echo "No $DIST/release.json: run bin/release.php first" >&2; exit 1; }
VERSION="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"];' "$DIST/release.json")"
PACKAGE="$DIST/agecms-$VERSION.json.gz"
[ -f "$PACKAGE" ] || { echo "No $PACKAGE" >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'git worktree remove --force "$WORK" >/dev/null 2>&1 || true; rm -rf "$WORK"; git branch -D releases-new >/dev/null 2>&1 || true' EXIT
git fetch -q origin releases 2>/dev/null || true
if git rev-parse -q --verify origin/releases >/dev/null; then
  git worktree add -q --detach "$WORK" origin/releases
else
  git worktree add -q --detach "$WORK"
  git -C "$WORK" checkout -q --orphan releases-new
  git -C "$WORK" read-tree --empty
  git -C "$WORK" clean -fdxq
  printf '# AgeCMS releases\n\nSigned core packages for the update button in «Система». The newest version is described by `release.json`; `agecms-<version>.json.gz` are the packages. Built by `bin/release.php`, published by `bin/publish-release.sh` from the main branch.\n' > "$WORK/README.md"
fi
cp "$DIST/release.json" "$PACKAGE" "$WORK/"
git -C "$WORK" add README.md release.json "agecms-$VERSION.json.gz"
git -C "$WORK" commit -q -m "версия $VERSION"
git -C "$WORK" push -q origin HEAD:refs/heads/releases
echo "Published $VERSION: https://raw.githubusercontent.com/likvp/AgeCMS/releases/release.json"
bin/github-release.sh "$VERSION" HEAD "$DIST" "$DIST/release.json" || echo "The GitHub release of $VERSION was not created: push main, then run bin/github-release.sh $VERSION HEAD $DIST" >&2
