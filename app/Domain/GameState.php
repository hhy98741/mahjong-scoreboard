<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Replays a list of hands into current scores + table state.
 * See docs/02-scoring-engine.md § Part 2 and § Part 3.
 *
 * Immutable: applyHand() and replay() return a new instance.
 */
final class GameState
{
    /**
     * @param array<int,int> $seats wind_index (chair) => player_id
     * @param int[] $occupied sorted occupied chairs
     * @param array<int,int> $totals player_id => running total
     */
    private function __construct(
        private readonly array $seats,
        private readonly array $occupied,
        public readonly array $totals,
        public readonly int $roundWind,
        public readonly int $dealerWindIndex,
        public readonly int $handNumber,
        public readonly bool $isComplete
    ) {
    }

    /** @param array<int,int> $seats wind_index => player_id */
    public static function initial(array $seats): self
    {
        $occupied = self::normalizeSeats(array_keys($seats), count($seats));
        $totals = array_fill_keys(array_values($seats), 0);

        return new self($seats, $occupied, $totals, 0, 0, 1, false);
    }

    /**
     * @param array<int,int> $seats wind_index => player_id
     * @param array<int,array{outcome:string,winner_player_id:?int,deltas:array<int,int>}> $hands
     */
    public static function replay(array $seats, array $hands): self
    {
        $state = self::initial($seats);
        foreach ($hands as $hand) {
            $state = $state->applyHand(
                $hand['outcome'],
                $hand['winner_player_id'] ?? null,
                $hand['deltas'] ?? []
            );
        }
        return $state;
    }

    /** @param array<int,int> $deltas playerId => delta, from Scoring */
    public function applyHand(string $outcome, ?int $winnerPlayerId, array $deltas): self
    {
        if ($this->isComplete) {
            throw new DomainException('cannot record a hand on a completed game'); // V7
        }

        $totals = $this->totals;
        foreach ($deltas as $playerId => $delta) {
            $totals[$playerId] = ($totals[$playerId] ?? 0) + $delta;
        }

        $dealerStays = $outcome === 'draw'
            || $outcome === 'penalty'
            || ($outcome === 'win' && $this->chairOf($winnerPlayerId) === $this->dealerWindIndex);

        $roundWind = $this->roundWind;
        $dealerWindIndex = $this->dealerWindIndex;
        $isComplete = false;

        if (!$dealerStays) {
            $dealerWindIndex = self::nextDealer($this->dealerWindIndex, $this->occupied);
            if ($dealerWindIndex === 0) {
                $roundWind++;
                if ($roundWind === 4) {
                    $isComplete = true;
                }
            }
        }

        return new self(
            $this->seats,
            $this->occupied,
            $totals,
            $roundWind,
            $dealerWindIndex,
            $this->handNumber + 1,
            $isComplete
        );
    }

    private function chairOf(?int $playerId): ?int
    {
        if ($playerId === null) {
            return null;
        }
        $chair = array_search($playerId, $this->seats, true);
        return $chair === false ? null : $chair;
    }

    /** @return int[] sorted occupied chairs */
    public function occupied(): array
    {
        return $this->occupied;
    }

    /** 1-based rank of the current dealer's chair within the occupied chairs ("Deal k of N"). */
    public function dealPosition(): int
    {
        return array_search($this->dealerWindIndex, $this->occupied, true) + 1;
    }

    public static function currentWind(int $chairWindIndex, int $dealerWindIndex): int
    {
        return ($chairWindIndex - $dealerWindIndex + 4) % 4;
    }

    /** @param int[] $occupied */
    public static function nextDealer(int $dealerWindIndex, array $occupied): int
    {
        for ($step = 1; $step <= 4; $step++) {
            $chair = ($dealerWindIndex + $step) % 4;
            if (in_array($chair, $occupied, true)) {
                return $chair;
            }
        }
        throw new DomainException('no occupied chair found'); // unreachable when occupied is valid
    }

    /**
     * Normalises and validates a set of occupied chairs (rule 1 / V9, W11, W12).
     *
     * @param int[] $chairs
     * @return int[] sorted, deduplicated chairs
     */
    public static function normalizeSeats(array $chairs, int $playerCount): array
    {
        if ($playerCount < 2 || $playerCount > 4) {
            throw new DomainException("player_count must be 2-4, got {$playerCount}"); // V9
        }
        if (count($chairs) !== $playerCount) {
            throw new DomainException('seats length must equal player_count'); // V9
        }

        $unique = array_values(array_unique($chairs));
        if (count($unique) !== count($chairs)) {
            throw new DomainException('seats must not contain duplicates'); // W11
        }

        foreach ($unique as $chair) {
            if ($chair < 0 || $chair > 3) {
                throw new DomainException("wind_index {$chair} out of range 0-3");
            }
        }

        sort($unique);

        if (!in_array(0, $unique, true)) {
            throw new DomainException('East (wind_index 0) must always be occupied'); // W12
        }

        return $unique;
    }
}
