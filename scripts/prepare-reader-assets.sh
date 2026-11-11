#!/usr/bin/env bash
set -euo pipefail

# Pin the official Mozilla legacy distribution and verify the archive before
# extracting or publishing executable browser assets.
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
echo "Verified PDF.js release archive: $PDFJS_VERSION ($ACTUAL_SHA256)"

mkdir -p "$DIST_DIR"
unzip -q "$ARCHIVE_PATH" -d "$DIST_DIR"

# The release archive contains the verified display build; the version-pinned
# npm distribution supplies the legacy viewer modules and auxiliary assets.
npm install --prefix "$TMP_DIR/npm" --no-save --ignore-scripts --no-audit --no-fund --package-lock=true "pdfjs-dist@${PDFJS_VERSION}"
PACKAGE_DIR="$TMP_DIR/npm/node_modules/pdfjs-dist"
PACKAGE_VERSION="$(node -p "require('$TMP_DIR/npm/node_modules/pdfjs-dist/package.json').version")"
if [ "$PACKAGE_VERSION" != "$PDFJS_VERSION" ]; then
  echo "Unexpected PDF.js npm package version: $PACKAGE_VERSION" >&2
  exit 1
fi

BUILD_FILE="$(find "$DIST_DIR" -type f \( -path '*/legacy/build/pdf.mjs' -o -path '*/build/pdf.mjs' \) -print -quit)"
BUILD_DIR="${BUILD_FILE%/pdf.mjs}"
if [ -z "$BUILD_FILE" ] || [ ! -s "$BUILD_DIR/pdf.mjs" ] || [ ! -s "$BUILD_DIR/pdf.worker.mjs" ]; then
  echo "Verified PDF.js archive is missing its legacy display or worker build." >&2
  unzip -Z1 "$ARCHIVE_PATH" | grep -E 'build/pdf\.mjs|build/pdf\.worker\.mjs' | head -80 >&2 || true
  exit 1
fi

# The official legacy-dist archive places build/ and web/ side by side;
# npm package layouts may nest them under legacy/.
LEGACY_WEB_DIR="$PACKAGE_DIR/legacy/web"
if [ ! -s "$LEGACY_WEB_DIR/pdf_viewer.mjs" ] || [ ! -s "$LEGACY_WEB_DIR/pdf_viewer.css" ]; then
  echo "Pinned PDF.js npm distribution is missing the legacy PDF viewer module or stylesheet." >&2
  unzip -Z1 "$ARCHIVE_PATH" | grep -E 'legacy/web/pdf_viewer\.(mjs|css)' | head -80 >&2 || true
  exit 1
fi

LICENSE_PATH="$PACKAGE_DIR/LICENSE"
if [ ! -s "$LICENSE_PATH" ]; then
  echo "Pinned PDF.js npm distribution is missing LICENSE." >&2
  exit 1
fi

# Rebuild the self-hosted payload deterministically; no CDN or runtime fetch.
rm -rf "$TARGET_DIR/legacy/build" "$TARGET_DIR/legacy/web" "$TARGET_DIR/web/images"
rm -rf "$TARGET_DIR/cmaps" "$TARGET_DIR/standard_fonts" "$TARGET_DIR/wasm"
mkdir -p "$TARGET_DIR/legacy/build" "$TARGET_DIR/legacy/web" "$TARGET_DIR/web"

cp "$BUILD_DIR/pdf.mjs" "$TARGET_DIR/legacy/build/pdf.mjs"
cp "$BUILD_DIR/pdf.worker.mjs" "$TARGET_DIR/legacy/build/pdf.worker.mjs"
# Require the official release archive and npm package to ship identical
# legacy display/worker payloads before combining their complementary assets.
if ! cmp -s "$BUILD_DIR/pdf.mjs" "$PACKAGE_DIR/legacy/build/pdf.mjs" || ! cmp -s "$BUILD_DIR/pdf.worker.mjs" "$PACKAGE_DIR/legacy/build/pdf.worker.mjs"; then
  echo "PDF.js official release archive and npm package payloads do not match." >&2
  exit 1
fi
cp "$LEGACY_WEB_DIR/pdf_viewer.mjs" "$TARGET_DIR/legacy/web/pdf_viewer.mjs"
cp "$LEGACY_WEB_DIR/pdf_viewer.css" "$TARGET_DIR/legacy/web/pdf_viewer.css"
if [ -d "$LEGACY_WEB_DIR/images" ]; then
  cp -a "$LEGACY_WEB_DIR/images" "$TARGET_DIR/legacy/web/images"
fi
cp "$LICENSE_PATH" "$TARGET_DIR/LICENSE"

for asset_dir in cmaps standard_fonts wasm; do
  if [ -d "$PACKAGE_DIR/$asset_dir" ]; then
    cp -a "$PACKAGE_DIR/$asset_dir" "$TARGET_DIR/$asset_dir"
  fi
done

if [ -d "$PACKAGE_DIR/web/images" ]; then
  cp -a "$PACKAGE_DIR/web/images" "$TARGET_DIR/web/images"
fi

printf '%s\\n' "$PDFJS_VERSION" > "$TARGET_DIR/VERSION"
printf '%s\\n' "$PDFJS_ARCHIVE_SHA256" > "$TARGET_DIR/SHA256"
PACKAGE_INTEGRITY="$(node -p "require('$TMP_DIR/npm/package-lock.json').packages['node_modules/pdfjs-dist'].integrity")"
printf '%s\\n' "$PACKAGE_INTEGRITY" > "$TARGET_DIR/NPM_INTEGRITY"
echo "Prepared verified, self-hosted PDF.js $PDFJS_VERSION in public/vendor/pdfjs"
