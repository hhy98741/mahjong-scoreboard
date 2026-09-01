-- D29: a player may optionally be linked to a login, so that person can log in
-- and see their own history later. One-to-one when present (a login maps to at
-- most one player); NULL means "no login" and multiple NULLs are fine - MariaDB
-- unique keys only constrain non-NULL values.
ALTER TABLE players
  ADD COLUMN user_id INT UNSIGNED NULL,
  ADD CONSTRAINT fk_players_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD UNIQUE KEY uq_players_user_id (user_id);
