#!/usr/bin/env bash
# Builds dist/eventkoi-tickets-importer.zip, ready to upload in
# WordPress (Plugins → Add New → Upload Plugin).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="eventkoi-tickets-importer"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/$SLUG/assets" "$ROOT/dist"
cp "$ROOT/tec-to-eventkoi-tickets-importer.php" "$ROOT/LICENSE" "$ROOT/README.md" "$TMP/$SLUG/"
cp "$ROOT"/assets/*.css "$ROOT"/assets/*.js "$TMP/$SLUG/assets/"

rm -f "$ROOT/dist/$SLUG.zip"
(cd "$TMP" && zip -qrX "$ROOT/dist/$SLUG.zip" "$SLUG")
echo "Built dist/$SLUG.zip"
