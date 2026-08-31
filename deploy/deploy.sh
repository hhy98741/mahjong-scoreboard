#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source deploy/deploy.conf          # REMOTE SITE DOCROOT APPDIR
STAMP=$(date +%Y%m%d-%H%M%S)

check() {  # url -> 0 if it returns 200
  local code
  # Browser-like UA: the 8G firewall in deploy/remote/.htaccess blocks the
  # HTTP_USER_AGENT curl sends by default, which would fail this check against
  # our own firewall rather than reveal an actual deploy problem.
  code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 \
    -A 'Mozilla/5.0 (compatible; deploy-smoke-test)' "$1" || echo 000)
  [[ "$code" == "200" ]] || { echo "   FAILED $1 -> $code"; return 1; }
  echo "   ok $1"
}

echo "-> backing up avatars and the live .htaccess"
mkdir -p backups/avatars
rsync -avz "$REMOTE:$DOCROOT/avatars/" backups/avatars/ || true
if ssh "$REMOTE" "test -f $DOCROOT/.htaccess"; then
  scp -q "$REMOTE:$DOCROOT/.htaccess" "backups/htaccess-$STAMP"
  HAD_HTACCESS=1
else
  HAD_HTACCESS=0    # first deploy
fi

echo "-> building frontend"
bun install --frozen-lockfile
bun run build                      # -> dist/

echo "-> syncing PHP application"
rsync -avz --delete --exclude '.DS_Store' \
  app bin migrations \
  "$REMOTE:$APPDIR/"

# config/ is synced separately: no --delete, and config.php excluded, so the
# server's credentials survive. Folded into the command above, the exclude would
# have been a no-op (config/ was not in the source list) and the example file
# would have landed at $APPDIR/config.example.php instead of $APPDIR/config/.
rsync -avz --exclude 'config.php' --exclude '.DS_Store' \
  config/ "$REMOTE:$APPDIR/config/"

echo "-> syncing hashed assets (safe to prune)"
rsync -avz --delete dist/assets/ "$REMOTE:$DOCROOT/assets/"

echo "-> syncing the rest of the build (NO --delete: shared directory)"
rsync -avz --exclude 'assets/' dist/ "$REMOTE:$DOCROOT/"

echo "-> syncing .htaccess and the api stub"
rsync -avz deploy/remote/ "$REMOTE:$DOCROOT/"

echo "-> smoke testing"
if check "$SITE/" && check "$SITE/api/health"; then
  echo "deployed. schema unchanged - run ./deploy/migrate.sh if this release needs it."
else
  echo "!! smoke test failed - rolling back .htaccess"
  if [[ "$HAD_HTACCESS" == "1" ]]; then
    scp -q "backups/htaccess-$STAMP" "$REMOTE:$DOCROOT/.htaccess"
  else
    # first deploy: move the new file aside rather than deleting it, so it
    # can still be inspected
    ssh "$REMOTE" "mv -f $DOCROOT/.htaccess $DOCROOT/htaccess.broken-$STAMP"
  fi
  check "$SITE/" && echo "   rolled back; site is up. Fix deploy/remote/.htaccess." \
                 || echo "   STILL DOWN - ssh in and investigate."
  exit 1
fi
