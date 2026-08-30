# 05 — Deployment

## Server environment

Verified over SSH. Host-specific values (domain, SSH alias, username, paths) live **only**
in `deploy/deploy.conf`, which is gitignored — see `deploy/deploy.conf.example`. Nothing in
this repo names a real host.

| | |
|---|---|
| Hosting | cPanel on CloudLinux, shared, with SSH access |
| Apache | 2.4.68 |
| MariaDB | 10.5.25 (cll-lve) — JSON columns supported |
| PHP | 8.3.33 (CLI). Code targets 8.1+ so it stays runnable locally. |
| PHP extensions | `pdo_mysql`, `gd`, `fileinfo`, `mbstring`, `json`, `curl`, `openssl`, `zip` — all present |
| Remote tools | `rsync`, `mysqldump`, `mysql`, `git`. No `composer` — fine, it is dev-only. |
| SSH | Key auth via an alias in `~/.ssh/config`, so `ssh $REMOTE` needs no password |

## Paths

On this host the site directory **is** the document root — there is no `public_html`
inside it. Verified by inspecting an existing site on the same account.

| Variable | Meaning | Shape |
|---|---|---|
| `$DOCROOT` | Apache document root | `~/sites/<site-name>` |
| `$APPDIR` | PHP source, outside the docroot | `~/apps/<site-name>` |

**`$APPDIR` uses the same folder name as `$DOCROOT`**, one tree over. One folder per site
in each of `~/sites` and `~/apps`, so everything belonging to a site is findable by name in
two predictable places, and a future second app on another domain never mixes in.

`$APPDIR` sits outside `~/sites/`, so it is not reachable over HTTP by any domain on the
account. That is where the PHP source and the database credentials live.

### The domain is not a config value the application reads

The public URL appears in `deploy.conf` only, and only so `deploy.sh` can smoke-test the
site after deploying. The application itself never sees it: all frontend URLs are relative
and the CSRF check compares `Origin` against the request's own `Host`, per D17.

`SITE` is **deploy-time only**. `deploy.conf` is bash, sourced by `deploy.sh` on your
laptop; nothing on the server reads it, and `config/config.php` deliberately has no origin
key. Feeding `SITE` into the CSRF check would mean maintaining the domain in a second place
that fails closed — a stale value 403s every write with an error that looks like an
application bug rather than a config one. D17b records that this was reconsidered and kept.

---

## What actually gets compiled, and what does not

A fair question, since the backend genuinely has no build step.

| Part | Language | Compiled? | What ships |
|---|---|---|---|
| Backend | PHP 8.3 | **No.** PHP is interpreted — the server reads the source. | `app/`, `bin/`, `migrations/` copied as-is into `APPDIR` |
| Frontend | TypeScript + CSS | **Yes.** No browser can run TypeScript. | `dist/` — plain JS and CSS, into `DOCROOT` |

**Vite** is the tool that does that one compile step. It reads `frontend/src/*.ts(x)`,
strips the types, bundles everything into a couple of files, minifies them, and writes the
result to `dist/`. **It runs on your laptop, never on the server** — which is exactly why
the server needs no Node, no Bun, and no Composer. `bun run build` is one line inside
`deploy.sh`; you never invoke Vite by hand.

So "everything in PHP with no compile" is true of the half that runs on the server. The
half that runs in the browser is TypeScript, and TypeScript always needs this step.

### Could we drop the build step entirely?

Yes — modern browsers run ES modules natively, so plain JavaScript in `.js` files could be
served straight from `DOCROOT` with no Vite, no Bun, no `dist/`. The cost is losing
TypeScript.

**Recommendation: keep it.** The scoring engine and the round/dealer state machine are
where bugs in this app will actually live, and those are precisely the bugs a type checker
catches before they reach the table. The build is already automated inside one script you
run anyway. But if the toolchain ever becomes more trouble than it is worth, dropping to
plain ES modules is a contained change — nothing in the PHP, database, or API specs
depends on it.

---

## URLs: what you and your family will actually type

**The bare domain** — that is it. Nothing else.

`assets/` never appears in a URL anyone types or shares. It is an internal directory that
the browser fetches from on its own:

1. You type the site's address
2. Apache serves `index.html` (the `DirectoryIndex` default)
3. That page contains `<script src="/assets/index-a1b2c3.js">`, so the browser quietly
   fetches it. You never see the path.
4. The app boots, checks the session, and shows either the login screen or the scoreboard.

The `a1b2c3` in the filename is a content hash. It changes whenever the code changes,
which is what stops browsers serving a stale cached copy after a deploy — the classic
"I deployed but it looks the same" problem. It is the reason `assets/` exists at all.

Once inside, the address bar shows things like `example.com/#/game/41`. Everything
after the `#` is handled entirely by the browser and is **never sent to Apache** — which
has a useful consequence, below.

### Why hash routing means one less .htaccess rule

Because the server only ever sees `/`, deep links work with **no rewrite rule at all**. A
normal SPA needs a "send every unknown path to index.html" fallback; hash routing needs
nothing. Given that you maintain your own `.htaccess` with a firewall in it, that is one
fewer place for our rules and yours to interact.

---

## .htaccess: one file, managed in the project

The root `.htaccess` lives at `deploy/remote/.htaccess`, is version-controlled with
everything else, and ships on a normal `./deploy/deploy.sh`. It is not a separate deploy —
see "Is that a different deploy?" below.

**This is the right call.** The file is configuration, it is small, it changes rarely, and
version control gives it a history and a diff. Editing it on the server means the only
copy lives somewhere with no history, and drifts from what the project thinks is deployed.

### The one real risk, and how the deploy handles it

An Apache `.htaccess` with a syntax error returns **500 Internal Server Error for the whole
site**, instantly, with no warning at deploy time. `apachectl configtest` does not check
`.htaccess` files, so there is no way to validate one before it goes live.

`deploy.sh` therefore does three things around it:

1. **Backs up the live `.htaccess`** to `backups/htaccess-<timestamp>` before overwriting.
2. **Smoke-tests after deploying** — fetches `/` and `/api/health` over HTTPS.
3. **Rolls back automatically** if either check fails, restores the previous file,
   re-tests, and exits non-zero.

So the worst realistic outcome is a few seconds of 500s and a failed deploy message,
rather than a dead site you discover later. The remaining caveat: if you ever hand-edit
the file on the server in an emergency, the next deploy silently overwrites it. Pull the
change back into the project instead.

### Is that a different deploy?

No — same script, same run. The `assets/` distinction was never about what can be
deployed; it was only about which *directories* are safe to `--delete`. A single file
copied without `--delete` is fine anywhere.

### Consolidating the avatars protection into the same file

You asked for one file, and that turns out to be the more robust option here anyway.

The old plan put `php_flag engine off` in `avatars/.htaccess`. But `php_flag` is a
**mod_php** directive, and this server runs CloudLinux — where PHP is served through
PHP-FPM or LSAPI, and `php_flag` in `.htaccess` is typically ignored outright. It would
have looked like protection while doing nothing.

A mod_rewrite deny in the root file works regardless of how PHP is wired up:

```apache
RewriteRule ^avatars/.*\.(php|phtml|php[0-9]|phar|cgi|pl|py|sh)$ - [F,NC]
```

That is now the control, and `deploy/remote/` ships no subdirectory `.htaccess` at all.
It is defence in depth on top of `AvatarService`, which re-encodes every upload through GD
so no original bytes survive.

### Correction to my earlier advice on ordering

I previously said to put the `/api/` rewrite **above** the 8G block. That was wrong, and
the template below reverses it. **Block first, route second** — otherwise a hostile request
gets rewritten into the front controller before the firewall has looked at it. The
canonical order is: HTTPS redirect, firewall, app routing, headers.

### `deploy/remote/.htaccess`

```apache
# ==============================================================
#  Mahjong scoreboard - site .htaccess
#  Managed in the project at deploy/remote/.htaccess
#  Shipped by deploy/deploy.sh. Do not hand-edit on the server.
# ==============================================================

Options -Indexes
DirectoryIndex index.html

RewriteEngine On

# ---- 1. Force HTTPS ------------------------------------------
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# ---- 2. Block dotfiles, except ACME ---------------------------
RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteRule (^|/)\. - [F]

# ---- 3. Never execute anything under /avatars/ ----------------
RewriteRule ^avatars/.*\.(php|phtml|php[0-9]|phar|cgi|pl|py|sh)$ - [F,NC]

# ---- 4. 8G FIREWALL -------------------------------------------
# Paste your standard 8G blocklist here, unmodified, between the
# markers. It must stay ABOVE the app routing: block first, route
# second. Left as a marker deliberately - 8G is a long third-party
# blocklist and transcribing it from memory risks both breaking
# legitimate requests and silently weakening the protection.
# >>> 8G BEGIN
# >>> 8G END

# ---- 5. Application routing -----------------------------------
# /api/* -> PHP front controller.
# The SPA uses hash routing, so no index.html fallback is needed.
RewriteCond %{REQUEST_URI} ^/api/
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^api/.*$ /api/index.php [QSA,L]

# ---- 6. Security headers --------------------------------------
<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-Frame-Options "SAMEORIGIN"
  Header always set Referrer-Policy "same-origin"
  # HSTS is a one-year commitment: browsers will refuse plain HTTP
  # for this hostname until it expires. Drop max-age to 300 while
  # testing if that makes you nervous.
  Header always set Strict-Transport-Security "max-age=31536000" env=HTTPS
</IfModule>

# ---- 7. Caching -----------------------------------------------
<IfModule mod_headers.c>
  <FilesMatch "\.html$">
    Header set Cache-Control "no-cache, must-revalidate"
  </FilesMatch>
</IfModule>
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType application/javascript "access plus 1 year"
  ExpiresByType text/css            "access plus 1 year"
  ExpiresByType image/webp          "access plus 1 week"
</IfModule>
```

`index.html` must not be cached, or a deploy will not take effect until the browser gives
up its copy. Content-hashed asset filenames are what make the year-long cache safe.

### 8G firewall — test after enabling

The 8G blocklist inspects request URIs, query strings, user agents, and referrers. Nothing
this app sends should trip it: state-changing requests carry JSON **bodies** (which 8G does
not inspect) and read endpoints use plain parameters like `from`, `to`, `player_ids`,
`limit`, `offset`.

Verify rather than assume — a false positive appears as a `403` from Apache instead of a
JSON envelope, which the frontend surfaces as a generic error. Walk the post-deploy
checklist with the firewall **on**.

## Remote layout

```
~/
  apps/<site-name>/                        <- $APPDIR, not web-reachable
    app/  bin/  migrations/
    config/config.php                      <- DB credentials; created once, never synced
  sites/<site-name>/                       <- $DOCROOT
    index.html                             built SPA shell
    assets/                                built JS/CSS, content-hashed
    default.svg                            shipped from frontend/public/, via dist/
    avatars/                               uploads ONLY - WRITABLE 755, never deployed into
    api/
      index.php                            front controller stub
    .htaccess                              shipped from deploy/remote/.htaccess
    cgi-bin/                               cPanel - leave alone
    .well-known/                           AutoSSL - leave alone
```

### `deploy/remote/api/index.php`

```php
<?php
declare(strict_types=1);

// Derive the app directory from this file's own location rather than hardcoding
// a path: the docroot is ~/sites/<name> and the source is ~/apps/<name>, so the
// name is already known. Keeps every host-specific path out of the repo.
$docroot = dirname(__DIR__);          // ~/sites/<name>
$home    = dirname($docroot, 2);      // ~
$name    = basename($docroot);        // <name>

foreach ([
    "$home/apps/$name/app/bootstrap.php",   // production
    __DIR__ . '/../../app/bootstrap.php',   // local checkout
] as $bootstrap) {
    if (is_file($bootstrap)) { require $bootstrap; return; }
}

http_response_code(500);
header('Content-Type: application/json');
echo '{"ok":false,"error":{"code":"server_error","message":"bootstrap not found"}}';
```

This is why the `~/apps/<name>` ↔ `~/sites/<name>` naming convention matters: it lets the
stub locate the application without being told where it is.

### `config/config.php` (created once on the server, never synced)

```php
<?php
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'ACCOUNT_mahjong',    // cPanel prefixes DB names with the account
        'user' => 'ACCOUNT_mahjong',    // and usernames too
        'pass' => '...',
    ],
    'avatar_dir'   => '/home/USER/sites/SITE_NAME/avatars',
    'session_name' => 'mjsb',
    'debug'        => false,
];
```

### `deploy/deploy.conf` (gitignored — copy from `deploy.conf.example`)

```bash
REMOTE=my-server                                  # ssh alias from ~/.ssh/config
SITE=https://mahjong.example.com                  # for the post-deploy smoke test
DOCROOT=/home/USER/sites/mahjong.example.com
APPDIR=/home/USER/apps/mahjong.example.com
```

No username, hostname, or port — the SSH alias already carries all of them, along with the
identity file. **This file is the only place in the project that knows the real values.**

---

## ⚠ Never `--delete` at the document root

The site directory already contains `cgi-bin/` and `.well-known/` — the ACME directory
cPanel's AutoSSL renews your certificate through. Deleting it breaks HTTPS renewal
silently, up to 90 days later.

The fix is structural, not a longer exclude list, because exclude lists rot:

| Sync | `--delete`? | Why |
|---|---|---|
| `dist/assets/` → `DOCROOT/assets/` | **yes** | Ours alone. This is how stale hashed bundles get pruned. |
| `dist/` minus `assets/` → `DOCROOT/` | **no** | Shares the directory with `cgi-bin`, `.well-known`, `avatars`. |
| `deploy/remote/` → `DOCROOT/` | **no** | Same reason. Ships `.htaccess` and `api/index.php`. |
| `app bin migrations` → `APPDIR/` | **yes** | Ours alone; excludes `config/config.php`. |

Content-hashed filenames mean `assets/` is the only directory that accumulates garbage,
and it is exactly the one that is safe to prune.

## `deploy/deploy.sh` — code only, with rollback

```bash
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
source deploy/deploy.conf          # REMOTE SITE DOCROOT APPDIR
STAMP=$(date +%Y%m%d-%H%M%S)

check() {  # url -> 0 if it returns 200
  local code
  code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$1" || echo 000)
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
```

An `.htaccess` syntax error takes the whole site down with a 500 and cannot be validated
ahead of time — `apachectl configtest` does not read `.htaccess` files. The backup, smoke
test, and automatic rollback are what make it safe to deploy the file at all.

The rollback only restores `.htaccess`. Application code is overwrite-in-place; to revert
a bad release, `git checkout` the previous commit and deploy again.

`deploy/remote/` contains exactly two things: `.htaccess` and `api/index.php`.

## `deploy/migrate.sh` — deliberate, and separate

```bash
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
```

`bin/dbdump.php` reads credentials from `config/config.php` and execs
`mysqldump --single-transaction`, so the password lives in one place on the server and
never reaches a local file, a shell history, or a process list.

## `deploy/backup.sh` — dump plus avatar pull

Everything the server holds that is not in git: the database, and the uploaded avatars.
Safe to run at any time, and safe to run from cron. It never writes to the server.

```bash
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
ls -1t backups/db-*.sql.gz | tail -n "+$((KEEP + 1))" | xargs -r rm --

echo "backup complete: backups/db-$STAMP.sql.gz"
```

`backups/` is gitignored. The avatar pull is a plain mirror with **no `--delete`**, so a
file removed on the server stays in the local backup — which is the point. `deploy.sh` runs
the same avatar pull before every deploy; this script is what you schedule.

---

## First-time setup, in order

```bash
cp deploy/deploy.conf.example deploy/deploy.conf   # then fill it in
source deploy/deploy.conf
ssh "$REMOTE" "mkdir -p $APPDIR/config $DOCROOT/avatars && chmod 755 $DOCROOT/avatars"
```

1. cPanel → MySQL Databases: create the database and user (both get the account name as a
   prefix automatically), grant ALL PRIVILEGES.
2. Confirm AutoSSL has issued a certificate for the domain.
3. Paste your 8G blocklist between the markers in `deploy/remote/.htaccess`.
4. Create `$APPDIR/config/config.php` from `config/config.example.php`.
5. `./deploy/deploy.sh`
6. `./deploy/migrate.sh` — the first run creates the schema.
7. `ssh "$REMOTE" "cd $APPDIR && php bin/seed.php && php bin/create-user.php --username=... --admin"`
8. Open the site, log in, create players, edit the 番 table.

## Local development

```bash
bun run serve:api    # php -S localhost:8080 -t public_html public_html/router.php
bun run dev          # vite on :5173, proxying /api and /avatars to :8080
```

`php -S` serves paths literally and ignores `.htaccess`, so `/api/health` would 404 without
`public_html/router.php` — the four-line dev-only router in `03-api.md` § Layout. It is
never deployed; production routing is the one `.htaccess`.

`/default.svg` needs no special handling in either place: Vite serves `frontend/public/` at
the root in dev, and the build copies it into `dist/`.

A local `config/config.php` points at a local MariaDB. `bin/migrate.php` and `bin/seed.php`
work identically locally — never maintain two schemas. `bin/seed.php` is idempotent, so
running it twice is not an error.

## Post-deploy checklist

Run this with the 8G firewall **enabled**.

**Reachability**
- [ ] Plain HTTP redirects to HTTPS.
- [ ] Typing the bare domain loads the login screen.
- [ ] `/api/health` returns JSON, not HTML and not a `403` from the firewall.
- [ ] `/api/health` returns **200 without a session** — it is exempt from the auth
      middleware. If it 401s, every future deploy rolls itself back.
- [ ] A deep link pasted cold (`$SITE/#/history`) loads — hash routing needs no rewrite.

**Auth**
- [ ] Login succeeds and the session survives a page reload.
- [ ] An unauthenticated request to `/api/players` returns a 401 JSON envelope.
- [ ] Six bad passwords in a row return `429`, and a correct password on a *different*
      username still works — confirms throttling is per username, not global.

**The firewall does not eat the app**
- [ ] Recording a hand returns JSON — confirms the firewall does not block `POST /api/*`.
- [ ] A stats call with query parameters (`/api/stats/leaderboard?from=2026-01-01`)
      returns JSON — confirms the firewall does not object to the query string.

**Nothing was destroyed**
- [ ] **`.well-known/` and `cgi-bin/` still exist after a deploy.**
- [ ] An uploaded avatar survives a second deploy.
- [ ] `$APPDIR/config/config.php` still holds the real credentials after a deploy, and
      `$APPDIR/config/config.example.php` sits beside it — not one directory up.
- [ ] `backups/db-*.sql.gz` from `./deploy/backup.sh` gunzips to real SQL.

**Assets**
- [ ] `/default.svg` returns the SVG — a player with no avatar shows a tile, not a broken
      image.
- [ ] `$SITE/avatars/test.php` returns 403.
- [ ] A second deploy actually changes the page — `index.html` is not being cached.

**Rollback**
- [ ] A deliberately broken `.htaccess` triggers the rollback and leaves the site up.
      Worth testing once, on purpose, before you rely on it.
