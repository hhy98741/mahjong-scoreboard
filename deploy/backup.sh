#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source deploy/deploy.conf
STAMP=$(date +%Y%m%d-%H%M%S)
KEEP=${BACKUP_KEEP:-30}

mkdir -p backups/avatars

echo "-> dumping database to backups/db-$STAMP.sql.gz"
ssh "$REMOTE" "cd $APPDIR && php bin/dbdump.php" | gzip > "backups/db-$STAMP.sql.gz"
# A dump that fails halfway still leaves a valid gzip file, so check it is not a stub.
[[ $(stat -f%z "backups/db-$STAMP.sql.gz" 2>/dev/null || stat -c%s "backups/db-$STAMP.sql.gz") -gt 1024 ]] \
  || { echo "!! dump looks truncated"; exit 1; }

echo "-> pulling avatars"
rsync -avz "$REMOTE:$DOCROOT/avatars/" backups/avatars/

echo "-> pruning dumps older than the newest $KEEP"
# Portable: BSD xargs (macOS) has no -r, so it would run `rm --` on empty input and
# exit non-zero under `set -e` - failing a backup that actually succeeded. A while-read
# loop needs no such flag and behaves identically on macOS and Linux.
ls -1t backups/db-*.sql.gz 2>/dev/null | tail -n "+$((KEEP + 1))" | while read -r old; do
  rm -- "$old"
done

echo "backup complete: backups/db-$STAMP.sql.gz"
