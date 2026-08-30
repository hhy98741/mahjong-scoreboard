# 01 — Data Model

MariaDB 10.4+. Engine InnoDB, charset `utf8mb4`, collation `utf8mb4_unicode_ci`.

Migrations live in `migrations/NNN_description.sql`, applied in filename order by
`bin/migrate.php`, which records applied filenames in a `schema_migrations` table.

## Schema

```sql
CREATE TABLE schema_migrations (
  filename    VARCHAR(191) NOT NULL PRIMARY KEY,
  applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- auth

CREATE TABLE users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(64)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,   -- password_hash(), PASSWORD_DEFAULT
  display_name   VARCHAR(100) NOT NULL,
  is_admin       TINYINT(1)   NOT NULL DEFAULT 0,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at  DATETIME     NULL,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- people

-- A "player" is a person who sits at the table. Deliberately separate from `users`:
-- not every player needs a login, and one login may enter scores for everyone.
CREATE TABLE players (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(80)  NOT NULL,
  avatar_path  VARCHAR(255) NULL,          -- e.g. 'avatars/7-a1b2c3.webp'; NULL = default
  colour       CHAR(7)      NOT NULL DEFAULT '#6b7280',  -- accent used on the scoreboard
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_players_name (name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- scoring config

CREATE TABLE rulesets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(80)  NOT NULL,
  min_faan      TINYINT UNSIGNED NOT NULL DEFAULT 3,
  max_faan      TINYINT UNSIGNED NOT NULL DEFAULT 13,
  payment_rule  VARCHAR(32)  NOT NULL DEFAULT 'hk_standard',
  penalty_default INT UNSIGNED NOT NULL DEFAULT 128, -- points each victim receives on a penalty
  is_default    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rulesets_name (name)
) ENGINE=InnoDB;

-- One row per faan value, 0 .. rulesets.max_faan inclusive.
CREATE TABLE ruleset_points (
  ruleset_id   INT UNSIGNED     NOT NULL,
  faan         TINYINT UNSIGNED NOT NULL,
  base_points  INT UNSIGNED     NOT NULL,
  PRIMARY KEY (ruleset_id, faan),
  CONSTRAINT fk_rp_ruleset FOREIGN KEY (ruleset_id) REFERENCES rulesets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- games

CREATE TABLE games (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name               VARCHAR(120) NULL,          -- optional label, e.g. "Sunday night"
  ruleset_id         INT UNSIGNED NULL,          -- provenance only; may be edited/deleted later
  ruleset_snapshot   JSON         NOT NULL,      -- FROZEN copy actually used for scoring
  status             ENUM('in_progress','completed','abandoned') NOT NULL DEFAULT 'in_progress',
  started_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at           DATETIME     NULL,
  created_by_user_id INT UNSIGNED NULL,
  KEY idx_games_status_started (status, started_at),
  CONSTRAINT fk_games_ruleset FOREIGN KEY (ruleset_id) REFERENCES rulesets(id) ON DELETE SET NULL,
  CONSTRAINT fk_games_user    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- seat_index 0=East, 1=South, 2=West, 3=North, in counterclockwise seating order.
-- These are STARTING winds and never change; the dealer rotates through the indices.
CREATE TABLE game_seats (
  game_id    INT UNSIGNED     NOT NULL,
  seat_index TINYINT UNSIGNED NOT NULL,
  player_id  INT UNSIGNED     NOT NULL,
  PRIMARY KEY (game_id, seat_index),
  UNIQUE KEY uq_game_player (game_id, player_id),
  CONSTRAINT fk_gs_game   FOREIGN KEY (game_id)   REFERENCES games(id)   ON DELETE CASCADE,
  CONSTRAINT fk_gs_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Append-only log. NEVER UPDATE a row here. Undo = DELETE the highest hand_number.
CREATE TABLE hands (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  game_id             INT UNSIGNED NOT NULL,
  hand_number         SMALLINT UNSIGNED NOT NULL,   -- 1-based, contiguous

  -- Table state BEFORE this hand was played (so history renders without replaying).
  round_wind          TINYINT UNSIGNED NOT NULL,    -- 0=East .. 3=North round
  dealer_seat_index   TINYINT UNSIGNED NOT NULL,    -- which game_seats.seat_index deals

  outcome             ENUM('win','draw','penalty') NOT NULL,

  -- outcome = 'win'
  winner_player_id    INT UNSIGNED NULL,
  faan                TINYINT UNSIGNED NULL,
  win_type            ENUM('discard','self_pick') NULL,
  discarder_player_id INT UNSIGNED NULL,            -- required when win_type='discard'
  liable_player_id    INT UNSIGNED NULL,            -- bao: this player pays everything
  base_points         INT UNSIGNED NULL,            -- resolved from the snapshot table

  -- outcome = 'penalty'
  offender_player_id  INT UNSIGNED NULL,
  penalty_per_player  INT UNSIGNED NULL,            -- each of the other three receives this

  note                VARCHAR(255) NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by_user_id  INT UNSIGNED NULL,

  UNIQUE KEY uq_hands_game_number (game_id, hand_number),
  CONSTRAINT fk_hands_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Exactly four rows per hand, one per seated player. Deltas sum to zero.
CREATE TABLE hand_scores (
  hand_id      INT UNSIGNED NOT NULL,
  player_id    INT UNSIGNED NOT NULL,
  points_delta INT          NOT NULL,   -- signed
  PRIMARY KEY (hand_id, player_id),
  KEY idx_hs_player (player_id),
  CONSTRAINT fk_hs_hand FOREIGN KEY (hand_id) REFERENCES hands(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

### Why `hand_scores` exists even though deltas are derivable

Reporting. `06-history-reports.md` needs `SUM(points_delta) GROUP BY player_id` across
years of games; recomputing from rules on every query would be silly. It is a cache with
a hard invariant: **`SELECT SUM(points_delta) FROM hand_scores WHERE hand_id = ?` must
always equal 0.** Assert this in the code that writes it, and in a test.

### `ruleset_snapshot` JSON shape

```json
{
  "name": "House rules",
  "min_faan": 3,
  "max_faan": 13,
  "payment_rule": "hk_standard",
  "penalty_default": 128,
  "points": { "0": 1, "1": 2, "2": 4, "3": 8, "4": 16, "5": 16, "6": 16,
              "7": 32, "8": 32, "9": 32, "10": 64, "11": 64, "12": 64, "13": 64 }
}
```

MariaDB's `JSON` is an alias for `LONGTEXT` with a validity check — encode/decode in PHP
with `json_encode`/`json_decode`, do not rely on JSON path functions.

## Seed data

`migrations/002_seed.sql` (or a `bin/seed.php`) inserts one ruleset:

**"Hong Kong Standard"** — `min_faan = 3`, `max_faan = 13`, `penalty_default = 128`,
`is_default = 1`, with the banded doubling table transcribed from the reference PDF p.10:

| faan | 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | 11 | 12 | 13 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| base points | 1 | 2 | 4 | 8 | 16 | 16 | 16 | 32 | 32 | 32 | 64 | 64 | 64 | 64 |

The owner is expected to edit these to match their house values. Do not hardcode them
anywhere outside the seed.

The admin user is **not** seeded with a fixed password. `bin/create-user.php` prompts for
username and password on the command line (see `03-api.md` § Auth).

## Integrity rules the application must enforce

These are not all expressible as FK constraints; enforce them in PHP and cover with tests.

1. A game has exactly four `game_seats`, indices 0–3, four distinct active players.
2. `hands.hand_number` is contiguous from 1 with no gaps within a game.
3. `winner_player_id`, `discarder_player_id`, `liable_player_id`, `offender_player_id`
   must all be players seated in that game.
4. `discarder_player_id != winner_player_id`.
5. `outcome='win'` ⇒ `winner_player_id`, `faan`, `win_type`, `base_points` all non-null;
   `win_type='discard'` ⇒ `discarder_player_id` non-null; `win_type='self_pick'` ⇒
   `discarder_player_id` is null.
6. `outcome='draw'` ⇒ every win/penalty column is null and there are still four
   `hand_scores` rows, all with `points_delta = 0`.
7. `outcome='penalty'` ⇒ `offender_player_id` and `penalty_per_player` non-null.
8. `min_faan <= faan <= max_faan` per the game's **snapshot**, not the live ruleset.
9. Hands may only be added to a game with `status = 'in_progress'`.
10. Only the highest `hand_number` may be deleted.
