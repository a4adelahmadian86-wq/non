#!/usr/bin/env bash
set -euo pipefail

# Install a pinned Mozilla PDF.js distribution at build time only. Runtime
# assets are copied into this repository and served from the application origin.
PDFJS_VERSION="6.5.438"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
TARGET_DIR="$ROOT_DIR/public/vendor/pdfjs"
PACKAGE_DIR="$TMP_DIR/node_modules/pdfjs-dist"

npm install --prefix "$TMP_DIR" --no-save --ignore-scripts --no-audit --no-fund --package-lock=true "pdfjs-dist@${PDFJS_VERSION}"

PACKAGE_VERSION="$(node -p "require('$PACKAGE_DIR/package.json').version")"
if [ "$PACKAGE_VERSION" != "$PDFJS_VERSION" ]; then
  echo "Unexpected PDF.js package version: $PACKAGE_VERSION" >&2
  exit 1
fi

PACKAGE_INTEGRITY="$(node -p "require('$TMP_DIR/package-lock.json').packages['node_modules/pdfjs-dist'].integrity")"
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
  "$PACKAGE_DIR/LICENSE"; do
  if [ ! -s "$required" ]; then
    echo "Pinned PDF.js distribution is missing required runtime asset: $required" >&2
    exit 1
  fi
done

# Rebuild the self-hosted payload deterministically; never use a CDN at runtime.
rm -rf "$TARGET_DIR/legacy/build" "$TARGET_DIR/legacy/web" "$TARGET_DIR/web/images"
rm -rf "$TARGET_DIR/cmaps" "$TARGET_DIR/standard_fonts" "$TARGET_DIR/wasm"
mkdir -p "$TARGET_DIR/legacy/build" "$TARGET_DIR/legacy/web" "$TARGET_DIR/web"

cp "$PACKAGE_DIR/legacy/build/pdf.mjs" "$TARGET_DIR/legacy/build/pdf.mjs"
cp "$PACKAGE_DIR/legacy/build/pdf.worker.mjs" "$TARGET_DIR/legacy/build/pdf.worker.mjs"
cp "$PACKAGE_DIR/legacy/web/pdf_viewer.mjs" "$TARGET_DIR/legacy/web/pdf_viewer.mjs"
cp "$PACKAGE_DIR/legacy/web/pdf_viewer.css" "$TARGET_DIR/legacy/web/pdf_viewer.css"
cp "$PACKAGE_DIR/LICENSE" "$TARGET_DIR/LICENSE"

if [ -d "$PACKAGE_DIR/legacy/web/images" ]; then
  cp -a "$PACKAGE_DIR/legacy/web/images" "$TARGET_DIR/legacy/web/images"
fi
if [ -d "$PACKAGE_DIR/web/images" ]; then
  cp -a "$PACKAGE_DIR/web/images" "$TARGET_DIR/web/images"
fi
for asset_dir in cmaps standard_fonts wasm; do
  if [ -d "$PACKAGE_DIR/$asset_dir" ]; then
    cp -a "$PACKAGE_DIR/$asset_dir" "$TARGET_DIR/$asset_dir"
  fi
done

printf '%s\\n' "$PDFJS_VERSION" > "$TARGET_DIR/VERSION"
printf '%s\\n' "$PACKAGE_INTEGRITY" > "$TARGET_DIR/NPM_INTEGRITY"
(
  cd "$TARGET_DIR"
  find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS
)
echo "Prepared verified, self-hosted PDF.js $PDFJS_VERSION in public/vendor/pdfjs"
