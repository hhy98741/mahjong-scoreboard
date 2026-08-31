#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source deploy/deploy.conf
STAMP=$(date +%Y%m%d-%H%M%S)

echo "-> dumping database to backups/db-$STAMP.sql.gz"
mkdir -p backups
ssh "$REMOTE" "cd $APPDIR && php bin/dbdump.php" | gzip > "backups/db-$STAMP.sql.gz"

echo "-> applying migrations"
ssh "$REMOTE" "cd $APPDIR && php bin/migrate.php"
