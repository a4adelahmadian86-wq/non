#!/usr/bin/env bash
set -euo pipefail

# Install a pinned Mozilla PDF.js distribution at build time only. Runtime
# assets are copied into this repository and served from the application origin.
PDFJS_VERSION="6.5.438"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
TARGET_DIR="$ROOT_DIR/public/vendor/pdfjs"

PACK_JSON="$(npm pack --ignore-scripts --json --pack-destination "$TMP_DIR" "pdfjs-dist@${PDFJS_VERSION}")"
PACKAGE_INTEGRITY="$(node -e 'const result=JSON.parse(process.argv[1]); const value=result[0]?.integrity; if (!value) process.exit(1); process.stdout.write(value);' "$PACK_JSON")"
tar -xzf "$TMP_DIR/pdfjs-dist-${PDFJS_VERSION}.tgz" -C "$TMP_DIR"
PACKAGE_DIR="$TMP_DIR/package"

PACKAGE_VERSION="$(node -p "require('$PACKAGE_DIR/package.json').version")"
if [ "$PACKAGE_VERSION" != "$PDFJS_VERSION" ]; then
  echo "Unexpected PDF.js package version: $PACKAGE_VERSION" >&2
  exit 1
fi

case "$PACKAGE_INTEGRITY" in
  sha512-*) ;;
  *) echo "PDF.js package integrity metadata is missing or unsupported: $PACKAGE_INTEGRITY" >&2; exit 1 ;;
esac
echo "Verified npm integrity for PDF.js $PDFJS_VERSION: $PACKAGE_INTEGRITY"

for required in \
  "$PACKAGE_DIR/legacy/build/pdf.mjs" \
  "$PACKAGE_DIR/legacy/build/pdf.worker.mjs" \
  "$PACKAGE_DIR/legacy/web/pdf_viewer.mjs" \
  "$PACKAGE_DIR/legacy/web/pdf_viewer.css" \
  "$PACKAGE_DIR/web/pdf_viewer.css" \
  "$PACKAGE_DIR/LICENSE"; do
  if [ ! -s "$required" ]; then
    echo "Pinned PDF.js distribution is missing required runtime asset: $required" >&2
    exit 1
  fi
done

# Assemble the complete runtime payload away from the live directory first.
# PDF.js viewer modules import sibling modules and assets; copying only the entrypoint
# silently breaks optional-content, annotations, and other viewer features.
STAGING_DIR="$TMP_DIR/pdfjs-runtime"
mkdir -p "$STAGING_DIR/legacy/build" "$STAGING_DIR/legacy/web" "$STAGING_DIR/web"

cp -a "$PACKAGE_DIR/legacy/build/." "$STAGING_DIR/legacy/build/"
cp -a "$PACKAGE_DIR/legacy/web/." "$STAGING_DIR/legacy/web/"
cp -a "$PACKAGE_DIR/web/." "$STAGING_DIR/web/"
cp "$PACKAGE_DIR/LICENSE" "$STAGING_DIR/LICENSE"

for asset_dir in cmaps standard_fonts wasm image_decoders iccs; do
  if [ -d "$PACKAGE_DIR/$asset_dir" ]; then
    mkdir -p "$STAGING_DIR/$asset_dir"
    cp -a "$PACKAGE_DIR/$asset_dir/." "$STAGING_DIR/$asset_dir/"
  fi
done

for required in \
  "$STAGING_DIR/legacy/build/pdf.mjs" \
  "$STAGING_DIR/legacy/build/pdf.worker.mjs" \
  "$STAGING_DIR/legacy/web/pdf_viewer.mjs" \
  "$STAGING_DIR/legacy/web/pdf_viewer.css" \
  "$STAGING_DIR/web/pdf_viewer.css" \
  "$STAGING_DIR/LICENSE"; do
  if [ ! -s "$required" ]; then
    echo "Staged PDF.js runtime is incomplete: $required" >&2
    exit 1
  fi
done

printf '%s\\n' "$PDFJS_VERSION" > "$STAGING_DIR/VERSION"
printf '%s\\n' "$PACKAGE_INTEGRITY" > "$STAGING_DIR/NPM_INTEGRITY"
(
  cd "$STAGING_DIR"
  find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS
)

# Swap only after the full, verified payload is ready. Restore the previous
# runtime if the final move fails, rather than leaving a partial asset tree.
BACKUP_DIR="$TMP_DIR/pdfjs-previous"
if [ -d "$TARGET_DIR" ]; then
  mv "$TARGET_DIR" "$BACKUP_DIR"
fi
if ! mv "$STAGING_DIR" "$TARGET_DIR"; then
  if [ -d "$BACKUP_DIR" ]; then mv "$BACKUP_DIR" "$TARGET_DIR"; fi
  echo "Could not activate the verified PDF.js runtime payload" >&2
  exit 1
fi
echo "Prepared verified, self-hosted PDF.js $PDFJS_VERSION in public/vendor/pdfjs"
