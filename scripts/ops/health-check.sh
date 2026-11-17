#!/usr/bin/env bash
set -Eeuo pipefail

: "${FARAST_BASE_URL:?Set FARAST_BASE_URL to the HTTPS base URL of the deployed application}"

case "$FARAST_BASE_URL" in
  https://*) ;;
  *)
    echo "FARAST_BASE_URL must use HTTPS." >&2
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

check_status "/up"
echo "Liveness: OK"

check_status "/ready"
if ! grep -Eq '"status"[[:space:]]*:[[:space:]]*"ready"' "$response_file"; then
  echo "Readiness endpoint returned HTTP 200 without the expected ready state." >&2
  exit 1
fi

echo "Readiness: OK"

# Verify the exact same-origin PDF.js runtime files that the reader's
# server-side readiness gate requires. A healthy Laravel process alone does
# not prove that the release contains the browser assets needed to open PDFs.
for asset in \
  "/vendor/pdfjs/legacy/build/pdf.mjs" \
  "/vendor/pdfjs/legacy/build/pdf.worker.mjs" \
  "/vendor/pdfjs/cmaps/Adobe-GB1-UCS2.bcmap" \
  "/vendor/pdfjs/standard_fonts/LiberationSans-Regular.ttf"; do
  check_status "$asset"
  echo "Reader runtime asset: OK ($asset)"
done

echo "FARAST post-deploy health checks passed."
