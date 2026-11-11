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
ACTUAL_SHA256="$(sha256sum "$ARCHIVE_PATH" | awk '{print $1}')"
if [ "$ACTUAL_SHA256" != "$PDFJS_ARCHIVE_SHA256" ]; then
  echo "PDF.js archive checksum mismatch: expected $PDFJS_ARCHIVE_SHA256, received $ACTUAL_SHA256" >&2
  exit 1
fi

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
  BUILD_DIR="$(find "$DIST_DIR" -type f -name pdf.mjs -print -quit | xargs -r dirname)"
fi
if [ -z "$BUILD_DIR" ] || [ ! -s "$BUILD_DIR/pdf.mjs" ] || [ ! -s "$BUILD_DIR/pdf.worker.mjs" ]; then
  echo "Verified PDF.js archive is missing its display or worker build. Archive entries:" >&2
  unzip -Z1 "$ARCHIVE_PATH" | head -80 >&2
  exit 1
fi

PDF_VIEWER_CSS="$(find "$DIST_DIR" -type f -name pdf_viewer.css -print -quit)"
LICENSE_PATH="$(find "$DIST_DIR" -maxdepth 4 -type f -name LICENSE -print -quit)"
if [ -z "$PDF_VIEWER_CSS" ] || [ -z "$LICENSE_PATH" ]; then
  echo "Verified PDF.js archive is missing viewer CSS or LICENSE. Archive entries:" >&2
  unzip -Z1 "$ARCHIVE_PATH" | grep -E 'pdf_viewer.css|LICENSE|pdf.mjs|pdf.worker.mjs' | head -80 >&2 || true
  exit 1
fi

mkdir -p "$TARGET_DIR/legacy/build" "$TARGET_DIR/web"
cp "$BUILD_DIR/pdf.mjs" "$TARGET_DIR/legacy/build/pdf.mjs"
cp "$BUILD_DIR/pdf.worker.mjs" "$TARGET_DIR/legacy/build/pdf.worker.mjs"
cp "$PDF_VIEWER_CSS" "$TARGET_DIR/web/pdf_viewer.css"
cp "$LICENSE_PATH" "$TARGET_DIR/LICENSE"

for asset_dir in cmaps standard_fonts wasm; do
  SOURCE_DIR="$(find "$DIST_DIR" -type d -name "$asset_dir" -print -quit)"
  if [ -n "$SOURCE_DIR" ]; then
    rm -rf "$TARGET_DIR/$asset_dir"
    cp -a "$SOURCE_DIR" "$TARGET_DIR/$asset_dir"
  fi
done

IMAGES_DIR="$(find "$DIST_DIR" -type d -path '*/web/images' -print -quit)"
if [ -n "$IMAGES_DIR" ]; then
  rm -rf "$TARGET_DIR/web/images"
  cp -a "$IMAGES_DIR" "$TARGET_DIR/web/images"
fi

printf '%s\n' "$PDFJS_VERSION" > "$TARGET_DIR/VERSION"
printf '%s\n' "$PDFJS_ARCHIVE_SHA256" > "$TARGET_DIR/SHA256"
echo "Prepared verified, self-hosted PDF.js $PDFJS_VERSION in public/vendor/pdfjs"
