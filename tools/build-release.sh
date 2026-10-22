#!/usr/bin/env bash
#
# Build the release archive build/ticketnumbering-<version>.tar.gz from the working tree.
# It contains only what GLPI needs at runtime (plus READMEs, CHANGELOG and LICENSE):
# no tests or development tooling.
#
#   tools/build-release.sh

set -euo pipefail

PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd)
cd "$PLUGIN_DIR"

VERSION=$(sed -n "s/^define('PLUGIN_TICKETNUMBERING_VERSION', '\([^']*\)');/\1/p" setup.php)
[ -n "$VERSION" ] || { echo "Cannot read PLUGIN_TICKETNUMBERING_VERSION from setup.php" >&2; exit 1; }

RUNTIME=(setup.php hook.php composer.json README.md README.en.md CHANGELOG.md LICENSE locales public src templates)
for entry in "${RUNTIME[@]}"; do
    [ -e "$entry" ] || { echo "Missing $entry" >&2; exit 1; }
done

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/ticketnumbering" build
tar -cf - "${RUNTIME[@]}" | tar -xf - -C "$STAGE/ticketnumbering"

ARCHIVE="build/ticketnumbering-$VERSION.tar.gz"
tar -czf "$ARCHIVE" -C "$STAGE" --owner=0 --group=0 ticketnumbering
(cd build && sha256sum "ticketnumbering-$VERSION.tar.gz" > "ticketnumbering-$VERSION.tar.gz.sha256")
echo "Built $ARCHIVE ($(du -h "$ARCHIVE" | cut -f1))"
