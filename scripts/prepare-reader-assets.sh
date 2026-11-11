#!/usr/bin/env bash
set -euo pipefail

# Pin the official Mozilla legacy distribution and verify the archive before
# extracting or publishing any executable browser assets.
PDFJS_VERSION="6.5.438"
PDFJS_ARCHIVE="pdfjs-${PDFJS_VERSION}-legacy-dist.zip"
PDFJS_ARCHIVE_SHA256="55138aa33b5f1f6f68809895338b947f395f344eb6381d424b2420d6daa6a844"
PDFJS_RELEASE_URL="https://github.com/mozilla/pdf.js/releases/download/v${PDFJS_VERSION}/${PDFJS_ARCHIVE}"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

ARCHIVE_PATH="$TMP_DIR/$PDFJS_ARCHIVE"
DIST_DIR="$TMP_DIR/dist"
TARGET_DIR="$ROOT_DIR/public/vendor/pdfjs"

curl --fail --location --retry 3 --proto '=https' --tlsv1.2 "$PDFJS_RELEASE_URL" --output "$ARCHIVE_PATH"
printf '%s  %s\n' "$PDFJS_ARCHIVE_SHA256" "$ARCHIVE_PATH" | sha256sum --check --status || {
  echo "PDF.js archive checksum verification failed for $PDFJS_ARCHIVE" >&2
  exit 1
}

mkdir -p "$DIST_DIR"
unzip -q "$ARCHIVE_PATH" -d "$DIST_DIR"

BUILD_DIR=""
for candidate in "$DIST_DIR/legacy/build" "$DIST_DIR/build"; do
  if [ -s "$candidate/pdf.mjs" ] && [ -s "$candidate/pdf.worker.mjs" ]; then
    BUILD_DIR="$candidate"
    break
  fi
done
if [ -z "$BUILD_DIR" ]; then
  echo "Verified PDF.js archive does not contain the expected display and worker builds" >&2
  exit 1
fi

test -s "$DIST_DIR/LICENSE"
test -s "$DIST_DIR/web/pdf_viewer.css"

mkdir -p "$TARGET_DIR/legacy/build" "$TARGET_DIR/web"
cp "$BUILD_DIR/pdf.mjs" "$TARGET_DIR/legacy/build/pdf.mjs"
cp "$BUILD_DIR/pdf.worker.mjs" "$TARGET_DIR/legacy/build/pdf.worker.mjs"
cp "$DIST_DIR/web/pdf_viewer.css" "$TARGET_DIR/web/pdf_viewer.css"
cp "$DIST_DIR/LICENSE" "$TARGET_DIR/LICENSE"

for asset_dir in cmaps standard_fonts wasm; do
  SOURCE_DIR="$DIST_DIR/$asset_dir"
  if [ ! -d "$SOURCE_DIR" ]; then
    SOURCE_DIR="$DIST_DIR/web/$asset_dir"
  fi
  if [ -d "$SOURCE_DIR" ]; then
    rm -rf "$TARGET_DIR/$asset_dir"
    cp -a "$SOURCE_DIR" "$TARGET_DIR/$asset_dir"
  fi
done

if [ -d "$DIST_DIR/web/images" ]; then
  rm -rf "$TARGET_DIR/web/images"
  cp -a "$DIST_DIR/web/images" "$TARGET_DIR/web/images"
fi

printf '%s\n' "$PDFJS_VERSION" > "$TARGET_DIR/VERSION"
printf '%s\n' "$PDFJS_ARCHIVE_SHA256" > "$TARGET_DIR/SHA256"
echo "Prepared verified, self-hosted PDF.js $PDFJS_VERSION in public/vendor/pdfjs"
