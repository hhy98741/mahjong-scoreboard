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

-- Login throttling state (03-api.md: 5 failures per username per 15 minutes).
-- Cannot live in the session - the attacker controls their own. Rows are keyed by
-- username as TYPED, so attempts against a non-existent account are throttled too and
-- the endpoint cannot be used to enumerate valid usernames.
CREATE TABLE login_attempts (
  username       VARCHAR(64) NOT NULL,
  attempted_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_la_username_time (username, attempted_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- people

-- A "player" is a person who sits at the table. Deliberately separate from `users`:
-- not every player needs a login, and one login may enter scores for everyone.
CREATE TABLE players (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(80)  NOT NULL,
  avatar_path  VARCHAR(255) NULL,          -- e.g. 'avatars/7-a1b2c3d4.webp'; NULL = default
                                            -- {player_id}-{random8}.webp, see 03-api.md
  color        CHAR(7)      NOT NULL DEFAULT '#6b7280',  -- '#rrggbb' accent on the scoreboard.
                                                        -- PlayerRepo always assigns one from
                                                        -- the tile cycle (D26); this neutral
                                                        -- grey is only a direct-insert fallback.
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_players_name (name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- scoring config

CREATE TABLE rulesets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(80)  NOT NULL,
  -- How far the points table extends. Rows exist for every faan 0..table_max_faan.
  -- The SELECTABLE band is not here - it is per game (games.min_faan/max_faan).
  table_max_faan TINYINT UNSIGNED NOT NULL DEFAULT 13,
  payment_rule  VARCHAR(32)  NOT NULL DEFAULT 'hk_standard',
  penalty_default INT UNSIGNED NOT NULL DEFAULT 128, -- points each victim receives on a penalty
  is_default    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rulesets_name (name)
) ENGINE=InnoDB;

-- One row per faan value, 0 .. rulesets.table_max_faan inclusive.
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
  player_count       TINYINT UNSIGNED NOT NULL DEFAULT 4,   -- 2, 3 or 4
  min_faan           TINYINT UNSIGNED NOT NULL DEFAULT 2,   -- selectable band,
  max_faan           TINYINT UNSIGNED NOT NULL DEFAULT 8,   -- set per game
  started_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at           DATETIME     NULL,
  created_by_user_id INT UNSIGNED NULL,
  KEY idx_games_status_started (status, started_at),
  CONSTRAINT fk_games_ruleset FOREIGN KEY (ruleset_id) REFERENCES rulesets(id) ON DELETE SET NULL,
  CONSTRAINT fk_games_user    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- A seat IS a chair at one of the four compass positions. Which chairs are occupied is
-- chosen per game on the New Game screen; East is always occupied (the opening dealer).
--   wind_index  0=East 1=South 2=West 3=North (ascending = counterclockwise)
-- The chair never changes. A player's CURRENT wind for a hand is always
--   (wind_index - hands.dealer_wind_index + 4) % 4     -- mod 4, never mod player_count
-- All four winds exist even when chairs are empty; the deal skips empty chairs but the
-- wind labels do not. See 02-scoring-engine.md.
CREATE TABLE game_seats (
  game_id    INT UNSIGNED     NOT NULL,
  wind_index TINYINT UNSIGNED NOT NULL,   -- 0..3, the chair this player occupies
  player_id  INT UNSIGNED     NOT NULL,
  PRIMARY KEY (game_id, wind_index),
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
  dealer_wind_index   TINYINT UNSIGNED NOT NULL,    -- 0..3, which chair dealt this hand

  outcome             ENUM('win','draw','penalty') NOT NULL,

  -- outcome = 'win'
  winner_player_id    INT UNSIGNED NULL,
  faan                TINYINT UNSIGNED NULL,
  win_type            ENUM('discard','self_pick') NULL,
  discarder_player_id INT UNSIGNED NULL,            -- required when win_type='discard'
  liable_player_id    INT UNSIGNED NULL,            -- bao: this player pays everything.
                                                  -- On a discard win this ALWAYS equals
                                                  -- discarder_player_id (rule 16); on a
                                                  -- self-pick win it is named explicitly.
                                                  -- NULL = not a bao hand, either way.
  base_points         INT UNSIGNED NULL,            -- resolved from the snapshot table

  -- outcome = 'penalty'
  offender_player_id  INT UNSIGNED NULL,
  penalty_per_player  INT UNSIGNED NULL,            -- each of the other N-1 players receives this

  note                VARCHAR(255) NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by_user_id  INT UNSIGNED NULL,

  UNIQUE KEY uq_hands_game_number (game_id, hand_number),
  CONSTRAINT fk_hands_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Exactly games.player_count rows per hand, one per seated player. Deltas sum to zero.
CREATE TABLE hand_scores (
  hand_id      INT UNSIGNED NOT NULL,
  player_id    INT UNSIGNED NOT NULL,
  points_delta INT          NOT NULL,   -- signed
  PRIMARY KEY (hand_id, player_id),
  KEY idx_hs_player (player_id),
  CONSTRAINT fk_hs_hand FOREIGN KEY (hand_id) REFERENCES hands(id) ON DELETE CASCADE
) ENGINE=InnoDB;
