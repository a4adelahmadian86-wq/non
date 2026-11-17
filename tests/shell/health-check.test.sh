#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
mkdir -p "$TMP_DIR/bin"

cat > "$TMP_DIR/bin/curl" <<'CURL'
#!/usr/bin/env bash
set -Eeuo pipefail
output=""
url=""
while (($#)); do
  case "$1" in
    --output) output="$2"; shift 2 ;;
    --write-out|--connect-timeout|--max-time) shift 2 ;;
    --silent|--show-error) shift ;;
    *) url="$1"; shift ;;
  esac
done
[[ "${FAKE_CURL_ERROR:-0}" != "1" ]] || exit 7
path="${url#https://health.example}"
path="${path#http://127.0.0.1:8001}"
status=200
body="asset"
case "$path" in
  /up) body="ok" ;;
  /ready)
    if [[ "${FAKE_NOT_READY:-0}" == "1" ]]; then body='{"status":"not_ready"}'; else body='{"status":"ready"}'; fi
    ;;
  /vendor/pdfjs/VERSION) body="${FAKE_PDFJS_VERSION:-6.5.438}" ;;
esac
if [[ "${FAKE_FAIL_PATH:-}" == "$path" ]]; then status=404; body="missing"; fi
printf '%s' "$body" > "$output"
printf '%s' "$status"
CURL
chmod +x "$TMP_DIR/bin/curl"

run_health_check() {
  PATH="$TMP_DIR/bin:$PATH" FARAST_BASE_URL="https://health.example" "$@" bash "$ROOT_DIR/scripts/ops/health-check.sh"
}

run_health_check
echo "PASS: healthy application and complete PDF.js runtime"

PATH="$TMP_DIR/bin:$PATH" FARAST_BASE_URL="http://127.0.0.1:8001" bash "$ROOT_DIR/scripts/ops/health-check.sh"
echo "PASS: localhost HTTP is allowed for development only"

if FAKE_FAIL_PATH="/vendor/pdfjs/legacy/build/pdf.worker.mjs" run_health_check >/dev/null 2>&1; then
  echo "FAIL: missing PDF.js worker was accepted" >&2
  exit 1
fi
echo "PASS: missing worker is rejected"

if FAKE_PDFJS_VERSION="0.0.0" run_health_check >/dev/null 2>&1; then
  echo "FAIL: unexpected PDF.js version was accepted" >&2
  exit 1
fi
echo "PASS: wrong PDF.js version is rejected"

if FAKE_NOT_READY=1 run_health_check >/dev/null 2>&1; then
  echo "FAIL: database readiness failure was accepted" >&2
  exit 1
fi
echo "PASS: application readiness failure is rejected"

if FAKE_CURL_ERROR=1 run_health_check >/dev/null 2>&1; then
  echo "FAIL: network failure was accepted" >&2
  exit 1
fi
echo "PASS: network failure is rejected"
