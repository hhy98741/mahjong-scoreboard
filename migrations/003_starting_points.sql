-- D30: games optionally start each seat from a non-zero points stack, set once at
-- creation and frozen for the life of the game like the ruleset snapshot. Display only —
-- scoring/ranking (hand_scores, GameState::replay) still runs on raw net points (D13).
ALTER TABLE games
  ADD COLUMN starting_points INT UNSIGNED NOT NULL DEFAULT 1500 AFTER max_faan;
