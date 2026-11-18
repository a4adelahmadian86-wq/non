#!/usr/bin/env bash
set -Eeuo pipefail

: "${FARAST_BASE_URL:?Set FARAST_BASE_URL to the HTTPS base URL of the deployed application}"

case "$FARAST_BASE_URL" in
  https://*) ;;
  http://localhost|http://localhost:*|http://127.0.0.1|http://127.0.0.1:*|http://\[::1\]|http://\[::1\]:*) ;;
  *)
    echo "FARAST_BASE_URL must use HTTPS, except for localhost/127.0.0.1/::1 development URLs." >&2
    exit 2
    ;;
esac

base_url="${FARAST_BASE_URL%/}"
response_file="$(mktemp)"
trap 'rm -f "$response_file"' EXIT

check_status() {
  local path="$1"
  local status

  if ! status="$(curl --silent --show-error \
    --connect-timeout "${FARAST_HEALTH_CONNECT_TIMEOUT:-5}" \
    --max-time "${FARAST_HEALTH_TIMEOUT:-15}" \
    --output "$response_file" \
    --write-out '%{http_code}' \
    "$base_url$path")"; then
    echo "Health request failed: $path" >&2
    exit 1
  fi

  if [[ "$status" != "200" ]]; then
    echo "Health request returned HTTP $status: $path" >&2
    exit 1
  fi
}

check_public_asset() {
  local path="$1"
  check_status "$path"
  if [[ ! -s "$response_file" ]]; then
    echo "Required public asset is empty: $path" >&2
    exit 1
  fi
}

check_status "/up"
echo "Liveness: OK"

check_status "/ready"
if ! grep -Eq '"status"[[:space:]]*:[[:space:]]*"ready"' "$response_file"; then
  echo "Readiness endpoint returned HTTP 200 without the expected ready state." >&2
  exit 1
fi

echo "Readiness: OK"

# A database-only readiness check can pass while the PDF reader is unusable.
# Verify the exact pinned runtime deployed on the same origin as the application.
check_public_asset "/vendor/pdfjs/VERSION"
if ! grep -Fxq "6.5.438" "$response_file"; then
  echo "Unexpected or missing PDF.js runtime version; expected 6.5.438." >&2
  exit 1
fi

check_public_asset "/vendor/pdfjs/NPM_INTEGRITY"
if ! grep -Fxq "sha512-vg8Pw1x+AFKy4AMDvhuWl5cOYV8d698UoEANoFFhc79hqXDnZQqnOXoxw3HG907WHOg1JYOvLhP756boddFxpA==" "$response_file"; then
  echo "Unexpected or missing PDF.js npm integrity manifest." >&2
  exit 1
fi

check_public_asset "/vendor/pdfjs/SHA256SUMS"

for asset in \
  "/vendor/pdfjs/legacy/build/pdf.mjs" \
  "/vendor/pdfjs/legacy/build/pdf.worker.mjs" \
  "/vendor/pdfjs/legacy/web/pdf_viewer.mjs" \
  "/vendor/pdfjs/legacy/web/pdf_viewer.css" \
  "/vendor/pdfjs/web/pdf_viewer.css" \
  "/vendor/pdfjs/cmaps/Adobe-GB1-UCS2.bcmap" \
  "/vendor/pdfjs/standard_fonts/LiberationSans-Regular.ttf"; do
  check_public_asset "$asset"
done

echo "PDF.js runtime assets: OK (6.5.438)"
echo "FARAST post-deploy health checks passed."
