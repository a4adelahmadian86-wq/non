#!/usr/bin/env bash
set -euo pipefail

# Keep the browser PDF engine pinned and serve it from this application origin.
PDFJS_VERSION="6.5.438"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

npm install --prefix "$TMP_DIR" --no-save --ignore-scripts --no-audit --no-fund "pdfjs-dist@${PDFJS_VERSION}"
PACKAGE_DIR="$TMP_DIR/node_modules/pdfjs-dist"
TARGET_DIR="$ROOT_DIR/public/vendor/pdfjs"

test -s "$PACKAGE_DIR/legacy/build/pdf.mjs"
test -s "$PACKAGE_DIR/legacy/build/pdf.worker.mjs"

mkdir -p "$TARGET_DIR/legacy/build" "$TARGET_DIR/web"
cp "$PACKAGE_DIR/legacy/build/pdf.mjs" "$TARGET_DIR/legacy/build/pdf.mjs"
cp "$PACKAGE_DIR/legacy/build/pdf.worker.mjs" "$TARGET_DIR/legacy/build/pdf.worker.mjs"
cp "$PACKAGE_DIR/web/pdf_viewer.css" "$TARGET_DIR/web/pdf_viewer.css"
cp "$PACKAGE_DIR/LICENSE" "$TARGET_DIR/LICENSE"

for asset_dir in cmaps standard_fonts wasm; do
  if [ -d "$PACKAGE_DIR/$asset_dir" ]; then
    rm -rf "$TARGET_DIR/$asset_dir"
    cp -a "$PACKAGE_DIR/$asset_dir" "$TARGET_DIR/$asset_dir"
  fi
done

if [ -d "$PACKAGE_DIR/web/images" ]; then
  rm -rf "$TARGET_DIR/web/images"
  cp -a "$PACKAGE_DIR/web/images" "$TARGET_DIR/web/images"
fi

printf '%s\n' "$PDFJS_VERSION" > "$TARGET_DIR/VERSION"
echo "Prepared self-hosted PDF.js $PDFJS_VERSION in public/vendor/pdfjs"
