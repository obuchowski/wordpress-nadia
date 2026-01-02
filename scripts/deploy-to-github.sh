#!/usr/bin/env bash
set -euo pipefail

# Resolve repo root
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Load .env if present (optional)
if [[ -f "$ROOT_DIR/.env" ]]; then
  set -o allexport
  # shellcheck disable=SC1090
  source "$ROOT_DIR/.env"
  set +o allexport
fi

STATIC_DIR="${STATIC_OUTPUT_DIR:-$ROOT_DIR/site/static-output}"
PUBLIC_DIR="$ROOT_DIR/public"
REMOTE="${GIT_REMOTE:-origin}"
BRANCH="${GIT_BRANCH:-main}"
COMMIT_MESSAGE="${COMMIT_MESSAGE:-Deploy: $(date -u +'%Y-%m-%dT%H:%M:%SZ')}"

if [[ ! -d "$STATIC_DIR" ]]; then
  echo "Static directory not found: $STATIC_DIR" >&2
  exit 1
fi

# Ensure public/ exists
mkdir -p "$PUBLIC_DIR"

# Sync static output to public/
rsync -a --delete \
  --exclude '.git' \
  "$STATIC_DIR"/ "$PUBLIC_DIR"/

# Commit and push from this repo
cd "$ROOT_DIR"
git add public/
if git diff --cached --quiet; then
  echo "No changes to deploy."
  exit 0
fi

git commit -m "$COMMIT_MESSAGE"
git push "$REMOTE" "$BRANCH"

echo "Deployment complete. Cloudflare Pages will pick up changes from public/"

