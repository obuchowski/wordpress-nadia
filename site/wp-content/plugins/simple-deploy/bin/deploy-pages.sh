#!/usr/bin/env bash
set -euo pipefail

STATIC_DIR="${1:-}"
ACCOUNT_ID="${2:-}"
API_TOKEN="${3:-}"
PROJECT="${4:-}"

if [[ -z "$STATIC_DIR" || -z "$ACCOUNT_ID" || -z "$API_TOKEN" || -z "$PROJECT" ]]; then
  echo "Usage: deploy-pages.sh <static_dir> <account_id> <api_token> <project>" >&2
  exit 1
fi

if [[ ! -d "$STATIC_DIR" ]]; then
  echo "Static directory not found: $STATIC_DIR" >&2
  exit 1
fi

if [[ ! -f "$STATIC_DIR/index.html" ]]; then
  echo "index.html not found in $STATIC_DIR" >&2
  exit 1
fi

export CLOUDFLARE_ACCOUNT_ID="$ACCOUNT_ID"
export CLOUDFLARE_API_TOKEN="$API_TOKEN"
export HOME="/var/www"
export WRANGLER_HOME="${WRANGLER_HOME:-/var/www/.wrangler}"
mkdir -p "$WRANGLER_HOME/tmp" "$HOME/.npm" "$HOME/.cache"
chown www-data:www-data "$WRANGLER_HOME" "$WRANGLER_HOME/tmp" "$HOME/.npm" "$HOME/.cache" >/dev/null 2>&1 || true

TMP_DIR="$(mktemp -d)"
OUT="$TMP_DIR/wrangler.log"

set +e
npx --yes wrangler@3 pages deploy "$STATIC_DIR" \
  --project-name "$PROJECT" \
  --branch main \
  --commit-dirty=true 2>&1 | tee "$OUT"
STATUS=${PIPESTATUS[0]}
set -e

if [[ $STATUS -ne 0 ]]; then
  echo "Wrangler deploy failed (exit $STATUS)" >&2
  cat "$OUT" >&2
  rm -rf "$TMP_DIR"
  exit $STATUS
fi

URL="$(python3 - "$OUT" <<'PY'
import re, sys
path = sys.argv[1]
with open(path, "rb") as f:
    data = f.read()
text = re.sub(rb"\x1b\[[0-9;]*[A-Za-z]", b"", data).decode("utf-8", "ignore")
urls = re.findall(r"https://\S+?\.pages\.dev", text)
print(urls[-1] if urls else "")
PY
)"

rm -rf "$TMP_DIR"

if [[ -z "$URL" ]]; then
  echo "Wrangler finished but no pages.dev URL was found in output" >&2
  exit 1
fi

echo "DEPLOYMENT_URL=$URL"

