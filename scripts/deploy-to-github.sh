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
REPO_DIR="${STATIC_REPO_PATH:-$ROOT_DIR/static-site-repo}"
REMOTE="${GIT_REMOTE:-origin}"
BRANCH="${GIT_BRANCH:-main}"
COMMIT_MESSAGE="${COMMIT_MESSAGE:-Deploy: $(date -u +'%Y-%m-%dT%H:%M:%SZ')}"

if [[ ! -d "$STATIC_DIR" ]]; then
  echo "Static directory not found: $STATIC_DIR" >&2
  exit 1
fi

if [[ ! -d "$REPO_DIR/.git" ]]; then
  echo "Repo not found or not a git repo: $REPO_DIR" >&2
  exit 1
fi

pushd "$REPO_DIR" >/dev/null
git fetch "$REMOTE" --prune
git checkout "$BRANCH"
git pull "$REMOTE" "$BRANCH"
popd >/dev/null

rsync -a --delete \
  --exclude '.git' \
  --exclude '.github' \
  --exclude '.gitignore' \
  --exclude '.gitattributes' \
  "$STATIC_DIR"/ "$REPO_DIR"/

pushd "$REPO_DIR" >/dev/null
git add .
if git diff --cached --quiet; then
  echo "No changes to deploy."
  exit 0
fi

git commit -m "$COMMIT_MESSAGE"
git push "$REMOTE" "$BRANCH"
popd >/dev/null

echo "Deployment complete."

