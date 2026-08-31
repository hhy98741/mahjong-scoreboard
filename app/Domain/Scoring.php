<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Payment math. Pure functions — no database, no HTTP.
 *
 * Every method returns an array keyed by every seated player id, deltas summing to zero.
 * See docs/02-scoring-engine.md § Part 1.
 */
final class Scoring
{
    public const WIN_TYPE_DISCARD = 'discard';
    public const WIN_TYPE_SELF_PICK = 'self_pick';

    /**
     * @param int[] $seatedPlayerIds
     * @return array<int,int> playerId => delta
     */
    public static function win(
        Ruleset $ruleset,
        array $seatedPlayerIds,
        int $winnerPlayerId,
        int $faan,
        int $minFaan,
        int $maxFaan,
        string $winType,
        ?int $discarderPlayerId,
        ?int $liablePlayerId
    ): array {
        if (!in_array($winType, [self::WIN_TYPE_DISCARD, self::WIN_TYPE_SELF_PICK], true)) {
            throw new DomainException("Unknown win_type '{$winType}'");
        }

        if (!in_array($winnerPlayerId, $seatedPlayerIds, true)) {
            throw new DomainException('winner_player_id is not seated in this game'); // V6
        }

        if ($winType === self::WIN_TYPE_DISCARD) {
            if ($discarderPlayerId === null) {
                throw new DomainException('a discard win requires discarder_player_id'); // V4
            }
        } elseif ($discarderPlayerId !== null) {
            throw new DomainException('a self_pick win must not have discarder_player_id set'); // V5
        }

        if ($discarderPlayerId !== null) {
            if ($discarderPlayerId === $winnerPlayerId) {
                throw new DomainException('discarder_player_id cannot equal the winner'); // V2
            }
            if (!in_array($discarderPlayerId, $seatedPlayerIds, true)) {
                throw new DomainException('discarder_player_id is not seated in this game'); // V6 / V10
            }
        }

        if ($liablePlayerId !== null) {
            if ($liablePlayerId === $winnerPlayerId) {
                throw new DomainException('liable_player_id cannot equal the winner'); // V3
            }
            if (!in_array($liablePlayerId, $seatedPlayerIds, true)) {
                throw new DomainException('liable_player_id is not seated in this game'); // V6
            }
            if ($winType === self::WIN_TYPE_DISCARD && $liablePlayerId !== $discarderPlayerId) {
                throw new DomainException('on a discard win the liable player must be the discarder'); // V11
            }
        }

        if ($faan < $minFaan) {
            throw new DomainException("faan {$faan} is below this game's min_faan {$minFaan}"); // V1
        }
        if ($faan > $maxFaan) {
            throw new DomainException("faan {$faan} is above this game's max_faan {$maxFaan}"); // V1b
        }

        $n = count($seatedPlayerIds);
        $base = $ruleset->basePoints($faan);
        $deltas = array_fill_keys($seatedPlayerIds, 0);

        if ($winType === self::WIN_TYPE_DISCARD) {
            if ($liablePlayerId !== null) {
                // C. Discard + bao: D pays N*B, everyone else pays 0.
                $deltas[$discarderPlayerId] = -$n * $base;
                $deltas[$winnerPlayerId] += $n * $base;
            } else {
                // A. Discard: D pays 2B, each of the N-2 others pays B.
                $deltas[$discarderPlayerId] = -2 * $base;
                foreach ($seatedPlayerIds as $playerId) {
                    if ($playerId === $winnerPlayerId || $playerId === $discarderPlayerId) {
                        continue;
                    }
                    $deltas[$playerId] = -$base;
                }
                $deltas[$winnerPlayerId] += $n * $base;
            }
        } else {
            if ($liablePlayerId !== null) {
                // D. Self-pick + bao: L pays 2(N-1)*B, everyone else pays 0.
                $deltas[$liablePlayerId] = -2 * ($n - 1) * $base;
                $deltas[$winnerPlayerId] += 2 * ($n - 1) * $base;
            } else {
                // B. Self-pick: each of the N-1 losers pays 2B.
                foreach ($seatedPlayerIds as $playerId) {
                    if ($playerId === $winnerPlayerId) {
                        continue;
                    }
                    $deltas[$playerId] = -2 * $base;
                }
                $deltas[$winnerPlayerId] += 2 * ($n - 1) * $base;
            }
        }

        self::assertBalanced($deltas);
        return $deltas;
    }

    /**
     * @param int[] $seatedPlayerIds
     * @return array<int,int> playerId => 0
     */
    public static function draw(array $seatedPlayerIds): array
    {
        return array_fill_keys($seatedPlayerIds, 0);
    }

    /**
     * @param int[] $seatedPlayerIds
     * @return array<int,int> playerId => delta
     */
    public static function penalty(array $seatedPlayerIds, int $offenderPlayerId, int $penaltyPerPlayer): array
    {
        if (!in_array($offenderPlayerId, $seatedPlayerIds, true)) {
            throw new DomainException('offender_player_id is not seated in this game'); // V6
        }

        $n = count($seatedPlayerIds);
        $deltas = array_fill_keys($seatedPlayerIds, $penaltyPerPlayer);
        $deltas[$offenderPlayerId] = -($n - 1) * $penaltyPerPlayer;

        self::assertBalanced($deltas);
        return $deltas;
    }

    /** @param array<int,int> $deltas */
    private static function assertBalanced(array $deltas): void
    {
        if (array_sum($deltas) !== 0) {
            throw new DomainException('computed deltas do not sum to zero'); // I1
        }
    }
}
