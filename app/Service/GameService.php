<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\DomainException;
use App\Domain\GameState;
use App\Domain\Ruleset;
use App\Domain\Scoring;
use App\Repo\GameRepo;
use App\Repo\HandRepo;
use App\Repo\PlayerRepo;
use App\Repo\RulesetRepo;
use PDO;

/**
 * Orchestrates games and hands: validation, the eight-step hand transaction
 * (docs-initial-build/03-api.md § POST /api/games/{id}/hands), and assembling the full
 * game-state payload every write returns. Repo\GameRepo and Repo\HandRepo
 * are pure data access; this is where docs-initial-build/02-scoring-engine.md and
 * docs-initial-build/01-data-model.md's integrity rules actually get enforced.
 *
 * Round/dealer state is never trusted from the client or from the stored
 * "before this hand" columns when recording a new hand — it is always
 * re-derived by replaying the game's hands with GameState::replay().
 *
 * Throws DomainException (-> 422) for malformed or rule-violating input,
 * ConflictException (-> 409) when the request is well-formed but conflicts
 * with current state, and returns null where routes.php should answer 404.
 */
final class GameService
{
    private const ROUND_NAMES = ['East', 'South', 'West', 'North'];

    private readonly GameRepo $games;
    private readonly HandRepo $hands;
    private readonly PlayerRepo $players;
    private readonly RulesetRepo $rulesets;

    public function __construct(private readonly PDO $pdo)
    {
        $this->games = new GameRepo($pdo);
        $this->hands = new HandRepo($pdo);
        $this->players = new PlayerRepo($pdo);
        $this->rulesets = new RulesetRepo($pdo);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> full game payload
     */
    public function createGame(array $input, ?int $createdByUserId): array
    {
        $rulesetId = is_int($input['ruleset_id'] ?? null) ? $input['ruleset_id'] : null;
        if ($rulesetId === null) {
            throw new DomainException('ruleset_id is required.');
        }
        $ruleset = $this->rulesets->find($rulesetId);
        if ($ruleset === null) {
            throw new DomainException('ruleset_id does not name an existing ruleset.');
        }

        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $name = $name === '' ? null : $name;

        $playerCount = is_int($input['player_count'] ?? null) ? $input['player_count'] : 4;
        $minFaan = is_int($input['min_faan'] ?? null) ? $input['min_faan'] : 2;
        $maxFaan = is_int($input['max_faan'] ?? null) ? $input['max_faan'] : 8;

        $seatsInput = $input['seats'] ?? null;
        if (!is_array($seatsInput)) {
            throw new DomainException('seats is required.');
        }

        // wind_index => player_id
        $seats = [];
        foreach ($seatsInput as $seat) {
            if (!is_array($seat) || !is_int($seat['wind'] ?? null) || !is_int($seat['player_id'] ?? null)) {
                throw new DomainException('Each seat needs an integer wind and player_id.');
            }
            $seats[$seat['wind']] = $seat['player_id'];
        }
        if (count($seats) !== count($seatsInput)) {
            throw new DomainException('seats must not repeat a wind.'); // W11 - key assignment above would silently dedupe
        }

        // V9, W11, W12 — length matches player_count, no duplicate winds, East occupied.
        GameState::normalizeSeats(array_keys($seats), $playerCount);

        $playerIds = array_values($seats);
        if (count(array_unique($playerIds)) !== count($playerIds)) {
            throw new DomainException('Player ids in seats must be distinct.');
        }
        foreach ($playerIds as $playerId) {
            $player = $this->players->find($playerId);
            if ($player === null || !$player['is_active']) {
                throw new DomainException("Player {$playerId} is not an active player.");
            }
        }

        // Rule 12: 0 <= min_faan <= max_faan <= ruleset.table_max_faan.
        if ($minFaan < 0 || $minFaan > $maxFaan || $maxFaan > $ruleset['table_max_faan']) {
            throw new DomainException(
                "min_faan/max_faan must satisfy 0 <= min_faan <= max_faan <= {$ruleset['table_max_faan']}."
            );
        }

        if ($this->games->hasInProgressGame()) {
            throw new ConflictException('A game is already in progress.'); // rule 14
        }

        $rulesetSnapshot = [
            'name' => $ruleset['name'],
            'table_max_faan' => $ruleset['table_max_faan'],
            'payment_rule' => $ruleset['payment_rule'],
            'penalty_default' => $ruleset['penalty_default'],
            'points' => (object) $ruleset['points'],
        ];

        $gameId = $this->games->create($rulesetId, $rulesetSnapshot, $name, $playerCount, $minFaan, $maxFaan, $seats, $createdByUserId);

        return $this->assemblePayload($gameId);
    }

    public function findCurrentId(): ?int
    {
        return $this->games->findCurrentId();
    }

    /**
     * @param array{status?:string, from?:string, to?:string, player_id?:int, player_count?:int, limit?:int, offset?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function listSummaries(array $filters): array
    {
        return array_map(
            fn (int $id): array => $this->summarize($id),
            $this->games->listIds($filters)
        );
    }

    /** @return array<string,mixed>|null null if the game does not exist */
    public function assemblePayload(int $gameId): ?array
    {
        $game = $this->games->find($gameId);
        if ($game === null) {
            return null;
        }

        $handRows = $this->hands->listForGame($gameId);
        $state = GameState::replay($game['seats'], $this->replayInput($handRows));

        return $this->buildPayload($game, $handRows, $state);
    }

    /**
     * The eight-step transaction (docs-initial-build/03-api.md § POST /api/games/{id}/hands).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null null if the game does not exist
     */
    public function recordHand(int $gameId, array $input, ?int $createdByUserId): ?array
    {
        $outcome = is_string($input['outcome'] ?? null) ? $input['outcome'] : '';
        if (!in_array($outcome, ['win', 'draw', 'penalty'], true)) {
            throw new DomainException("outcome must be 'win', 'draw', or 'penalty'.");
        }

        $this->pdo->beginTransaction();
        try {
            // Step 1: lock the game; reject unless in_progress.
            $game = $this->games->lockForUpdate($gameId);
            if ($game === null) {
                $this->pdo->rollBack();
                return null;
            }
            if ($game['status'] !== 'in_progress') {
                $this->pdo->rollBack();
                throw new DomainException('Hands may only be recorded on a game in progress.'); // rule 9
            }

            // Step 2: replay for the authoritative round_wind / dealer_wind_index / hand_number.
            // Never trust client-supplied state.
            $handRows = $this->hands->listForGame($gameId);
            $state = GameState::replay($game['seats'], $this->replayInput($handRows));

            $seatedIds = array_values($game['seats']);
            $ruleset = Ruleset::fromArray($game['ruleset_snapshot']);

            // Steps 3-5: validate + compute deltas. Scoring/GameState enforce
            // V1-V6, V10-V12; the outcome-shape checks below cover rules 6-7.
            [$handRow, $deltas] = $this->resolveHand($outcome, $input, $ruleset, $seatedIds, $game['min_faan'], $game['max_faan']);

            // Step 6: insert hands + hand_scores.
            $this->hands->insert($gameId, $state->handNumber, $state->roundWind, $state->dealerWindIndex, $handRow, $deltas, $createdByUserId);

            // Step 7: apply the transition; complete the game if it just finished.
            $newState = $state->applyHand($outcome, $handRow['winner_player_id'] ?? null, $deltas);
            if ($newState->isComplete) {
                $this->games->endGame($gameId, 'completed');
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Step 8: return the same payload shape as GET /api/games/{id}.
        return $this->assemblePayload($gameId);
    }

    /** @return array<string,mixed>|null null if the game does not exist */
    public function undoLastHand(int $gameId): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $game = $this->games->lockForUpdate($gameId);
            if ($game === null) {
                $this->pdo->rollBack();
                return null;
            }

            if ($this->hands->maxHandNumber($gameId) === 0) {
                $this->pdo->rollBack();
                throw new ConflictException('This game has no hands to undo.');
            }

            $this->hands->deleteLast($gameId); // V8: only the highest hand_number is ever deleted

            if ($game['status'] === 'completed') {
                $this->games->reopen($gameId);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->assemblePayload($gameId);
    }

    /** @return array<string,mixed>|null null if the game does not exist */
    public function endGame(int $gameId, string $status): ?array
    {
        if (!in_array($status, ['completed', 'abandoned'], true)) {
            throw new DomainException('status must be completed or abandoned.');
        }

        $this->pdo->beginTransaction();
        try {
            $game = $this->games->lockForUpdate($gameId);
            if ($game === null) {
                $this->pdo->rollBack();
                return null;
            }
            if ($game['status'] !== 'in_progress') {
                $this->pdo->rollBack();
                throw new ConflictException('This game has already ended.');
            }

            $this->games->endGame($gameId, $status);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->assemblePayload($gameId);
    }

    /** @return array<string,mixed>|null null if the game does not exist */
    public function renameGame(int $gameId, string $name): ?array
    {
        if ($this->games->find($gameId) === null) {
            return null;
        }

        $trimmed = trim($name);
        $this->games->updateName($gameId, $trimmed === '' ? null : $trimmed);

        return $this->assemblePayload($gameId);
    }

    /** @return bool false if the game does not exist */
    public function deleteGame(int $gameId): bool
    {
        if ($this->games->find($gameId) === null) {
            return false;
        }

        $this->games->hardDelete($gameId);

        return true;
    }

    /**
     * @param list<array{outcome:string, winner_player_id:?int, faan:?int, win_type:?string, discarder_player_id:?int, liable_player_id:?int, base_points:?int, offender_player_id:?int, penalty_per_player:?int, note:?string, scores:array<int,int>}> $handRows
     * @return list<array{outcome:string, winner_player_id:?int, deltas:array<int,int>}>
     */
    private function replayInput(array $handRows): array
    {
        return array_map(
            static fn (array $h): array => [
                'outcome' => $h['outcome'],
                'winner_player_id' => $h['winner_player_id'],
                'deltas' => $h['scores'],
            ],
            $handRows
        );
    }

    /**
     * @param array<string,mixed> $input
     * @param int[] $seatedIds
     * @return array{0: array<string,mixed>, 1: array<int,int>}
     */
    private function resolveHand(string $outcome, array $input, Ruleset $ruleset, array $seatedIds, int $minFaan, int $maxFaan): array
    {
        $note = is_string($input['note'] ?? null) ? $input['note'] : null;

        if ($outcome === 'win') {
            $winnerPlayerId = is_int($input['winner_player_id'] ?? null) ? $input['winner_player_id'] : null;
            $faan = is_int($input['faan'] ?? null) ? $input['faan'] : null;
            $winType = is_string($input['win_type'] ?? null) ? $input['win_type'] : '';
            $discarderPlayerId = is_int($input['discarder_player_id'] ?? null) ? $input['discarder_player_id'] : null;
            $liablePlayerId = is_int($input['liable_player_id'] ?? null) ? $input['liable_player_id'] : null;

            if ($winnerPlayerId === null || $faan === null) {
                throw new DomainException('A win requires winner_player_id and faan.'); // rule 5
            }

            $deltas = Scoring::win($ruleset, $seatedIds, $winnerPlayerId, $faan, $minFaan, $maxFaan, $winType, $discarderPlayerId, $liablePlayerId);

            $handRow = [
                'outcome' => 'win',
                'winner_player_id' => $winnerPlayerId,
                'faan' => $faan,
                'win_type' => $winType,
                'discarder_player_id' => $discarderPlayerId,
                'liable_player_id' => $liablePlayerId,
                'base_points' => $ruleset->basePoints($faan),
                'note' => $note,
            ];

            return [$handRow, $deltas];
        }

        if ($outcome === 'draw') {
            $deltas = Scoring::draw($seatedIds);
            $handRow = ['outcome' => 'draw', 'note' => $note];

            return [$handRow, $deltas];
        }

        // penalty
        $offenderPlayerId = is_int($input['offender_player_id'] ?? null) ? $input['offender_player_id'] : null;
        if ($offenderPlayerId === null) {
            throw new DomainException('A penalty requires offender_player_id.'); // rule 7
        }
        $penaltyPerPlayer = is_int($input['penalty_per_player'] ?? null) ? $input['penalty_per_player'] : $ruleset->penaltyDefault;
        if ($penaltyPerPlayer < 0) {
            throw new DomainException('penalty_per_player must be 0 or greater.');
        }

        $deltas = Scoring::penalty($seatedIds, $offenderPlayerId, $penaltyPerPlayer);
        $handRow = [
            'outcome' => 'penalty',
            'offender_player_id' => $offenderPlayerId,
            'penalty_per_player' => $penaltyPerPlayer,
            'note' => $note,
        ];

        return [$handRow, $deltas];
    }

    /** @return array<string,mixed> */
    private function summarize(int $gameId): array
    {
        $game = $this->games->find($gameId);
        $totals = $this->games->totals($gameId);

        $seats = [];
        foreach ($game['seats'] as $chair => $playerId) {
            $seats[] = [
                'chair' => $chair,
                'player' => $this->playerPayload($playerId),
                'total' => $totals[$playerId] ?? 0,
            ];
        }

        return [
            'id' => $game['id'],
            'name' => $game['name'],
            'status' => $game['status'],
            'player_count' => $game['player_count'],
            'started_at' => self::toIso8601($game['started_at']),
            'ended_at' => $game['ended_at'] !== null ? self::toIso8601($game['ended_at']) : null,
            'seats' => $seats,
        ];
    }

    /**
     * @param array{id:int, name:?string, ruleset_snapshot:array<string,mixed>, status:string, player_count:int, min_faan:int, max_faan:int, started_at:string, ended_at:?string, seats:array<int,int>} $game
     * @param list<array{id:int, hand_number:int, round_wind:int, dealer_wind_index:int, outcome:string, winner_player_id:?int, faan:?int, win_type:?string, discarder_player_id:?int, liable_player_id:?int, base_points:?int, offender_player_id:?int, penalty_per_player:?int, note:?string, created_at:string, scores:array<int,int>}> $handRows ascending by hand_number
     * @return array<string,mixed>
     */
    private function buildPayload(array $game, array $handRows, GameState $state): array
    {
        // Dense rank, descending by total, ties share a rank.
        $sortedTotals = $state->totals;
        arsort($sortedTotals);
        $rankByPlayer = [];
        $rank = 0;
        $previousTotal = null;
        foreach ($sortedTotals as $playerId => $total) {
            if ($previousTotal === null || $total !== $previousTotal) {
                $rank++;
                $previousTotal = $total;
            }
            $rankByPlayer[$playerId] = $rank;
        }

        $seatsPayload = [];
        foreach ($game['seats'] as $chair => $playerId) {
            $seatsPayload[] = [
                'chair' => $chair,
                'player' => $this->playerPayload($playerId),
                'current_wind_index' => GameState::currentWind($chair, $state->dealerWindIndex),
                'current_wind' => self::ROUND_NAMES[GameState::currentWind($chair, $state->dealerWindIndex)],
                'total' => $state->totals[$playerId] ?? 0,
                'rank' => $rankByPlayer[$playerId] ?? 1,
            ];
        }

        // A completed game's replayed round_wind can be 4 (wrapped past North) -
        // clamp for display; round_name has no "complete" label.
        $displayRoundWind = min($state->roundWind, 3);

        $handsPayload = [];
        foreach (array_reverse($handRows) as $h) {
            $scores = [];
            foreach ($h['scores'] as $playerId => $delta) {
                $scores[(string) $playerId] = $delta;
            }

            $handsPayload[] = [
                'id' => $h['id'],
                'hand_number' => $h['hand_number'],
                'round_wind' => $h['round_wind'],
                'dealer_wind_index' => $h['dealer_wind_index'],
                'outcome' => $h['outcome'],
                'winner_player_id' => $h['winner_player_id'],
                'faan' => $h['faan'],
                'win_type' => $h['win_type'],
                'discarder_player_id' => $h['discarder_player_id'],
                'liable_player_id' => $h['liable_player_id'],
                'base_points' => $h['base_points'],
                'offender_player_id' => $h['offender_player_id'],
                'penalty_per_player' => $h['penalty_per_player'],
                'note' => $h['note'],
                'scores' => (object) $scores,
                'created_at' => self::toIso8601($h['created_at']),
            ];
        }

        return [
            'game' => [
                'id' => $game['id'],
                'name' => $game['name'],
                'status' => $game['status'],
                'player_count' => $game['player_count'],
                'min_faan' => $game['min_faan'],
                'max_faan' => $game['max_faan'],
                'started_at' => self::toIso8601($game['started_at']),
                'ended_at' => $game['ended_at'] !== null ? self::toIso8601($game['ended_at']) : null,
            ],
            'ruleset' => [
                'name' => $game['ruleset_snapshot']['name'],
                'table_max_faan' => $game['ruleset_snapshot']['table_max_faan'],
                'penalty_default' => $game['ruleset_snapshot']['penalty_default'],
                'points' => (object) $game['ruleset_snapshot']['points'],
            ],
            'seats' => $seatsPayload,
            'state' => [
                'round_wind' => $displayRoundWind,
                'round_name' => self::ROUND_NAMES[$displayRoundWind],
                'dealer_wind_index' => $state->dealerWindIndex,
                'dealer_player_id' => $game['seats'][$state->dealerWindIndex] ?? null,
                'deal_in_round' => $state->dealPosition(),
                'next_hand_number' => $state->handNumber,
                'is_complete' => $state->isComplete,
            ],
            'hands' => $handsPayload,
        ];
    }

    /** @return array{id:int, name:string, color:string, avatar_url:string} */
    private function playerPayload(int $playerId): array
    {
        $player = $this->players->find($playerId);

        return [
            'id' => $player['id'],
            'name' => $player['name'],
            'color' => $player['color'],
            'avatar_url' => $player['avatar_path'] !== null ? '/' . $player['avatar_path'] : '/default.svg',
        ];
    }

    private static function toIso8601(string $datetime): string
    {
        return str_replace(' ', 'T', $datetime) . 'Z';
    }
}
