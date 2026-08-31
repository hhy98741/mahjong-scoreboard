<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\DomainException;
use App\Domain\GameState;
use PHPUnit\Framework\TestCase;

/**
 * Part 2 (round/dealer state machine) vectors from docs/02-scoring-engine.md § Part 4.
 *
 * S5 is deliberately retired — do not reinstate it.
 *
 * Throughout, player ids are chosen equal to their chair (wind_index) for readability,
 * matching the doc's "seat N" phrasing. GameState never assumes that identity — seats
 * are always addressed through the wind_index => player_id map.
 */
final class GameStateTest extends TestCase
{
    /** @param array<int,int> $deltas */
    private function winHand(int $winnerPlayerId, array $deltas = []): array
    {
        return ['outcome' => 'win', 'winner_player_id' => $winnerPlayerId, 'deltas' => $deltas];
    }

    private function drawHand(): array
    {
        return ['outcome' => 'draw', 'winner_player_id' => null, 'deltas' => []];
    }

    private function penaltyHand(): array
    {
        return ['outcome' => 'penalty', 'winner_player_id' => null, 'deltas' => []];
    }

    /**
     * Builds a sequence of "non-dealer win" hands that rotate the deal through every
     * occupied chair. Any non-dealer winner produces the same transition, so picking
     * the next scheduled dealer as the winner (verified independently by the W-vector
     * tests below) is a safe, deterministic way to build fixtures.
     *
     * @param int[] $occupied
     * @return array<int,array>
     */
    private function buildRotationHands(array $occupied, int $count, int $startDealer = 0): array
    {
        $hands = [];
        $dealer = $startDealer;
        for ($i = 0; $i < $count; $i++) {
            $winner = GameState::nextDealer($dealer, $occupied);
            $hands[] = $this->winHand($winner);
            $dealer = $winner;
        }
        return $hands;
    }

    /**
     * Same rotation as buildRotationHands(), but with an extra dealer-stays win hand
     * inserted before each rotation number listed in $insertBefore (1-based).
     *
     * @param int[] $occupied
     * @param int[] $insertBefore
     * @return array<int,array>
     */
    private function buildRotationHandsWithDealerWins(array $occupied, int $rotations, array $insertBefore): array
    {
        $hands = [];
        $dealer = 0;
        for ($i = 1; $i <= $rotations; $i++) {
            if (in_array($i, $insertBefore, true)) {
                $hands[] = $this->winHand($dealer);
            }
            $winner = GameState::nextDealer($dealer, $occupied);
            $hands[] = $this->winHand($winner);
            $dealer = $winner;
        }
        return $hands;
    }

    private function seatsFor(array $occupiedChairs): array
    {
        $seats = [];
        foreach ($occupiedChairs as $chair) {
            $seats[$chair] = $chair;
        }
        return $seats;
    }

    // ---- W: winds in use ----

    public function testW1FourPlayersDealerWest(): void
    {
        $this->assertSame(2, GameState::currentWind(0, 2)); // E chair -> West
        $this->assertSame(3, GameState::currentWind(1, 2)); // S chair -> North
        $this->assertSame(0, GameState::currentWind(2, 2)); // W chair -> East
        $this->assertSame(1, GameState::currentWind(3, 2)); // N chair -> South
    }

    public function testW2EastSouthDealerEast(): void
    {
        $this->assertSame(0, GameState::currentWind(0, 0)); // E chair -> East
        $this->assertSame(1, GameState::currentWind(1, 0)); // S chair -> South
    }

    public function testW3EastSouthDealerSouthTheOwnersWorkedExample(): void
    {
        $this->assertSame(3, GameState::currentWind(0, 1)); // E chair -> North
        $this->assertSame(0, GameState::currentWind(1, 1)); // S chair -> East
    }

    public function testW4EastWestDealerWest(): void
    {
        $this->assertSame(2, GameState::currentWind(0, 2)); // E chair -> West
        $this->assertSame(0, GameState::currentWind(2, 2)); // W chair -> East
    }

    public function testW4bEastWestDealerEast(): void
    {
        $this->assertSame(0, GameState::currentWind(0, 0)); // E chair -> East
        $this->assertSame(2, GameState::currentWind(2, 0)); // W chair -> West
    }

    public function testW5EastNorthDealerNorth(): void
    {
        $this->assertSame(1, GameState::currentWind(0, 3)); // E chair -> South
        $this->assertSame(0, GameState::currentWind(3, 3)); // N chair -> East
    }

    public function testW6EastSouthWestDealerSouth(): void
    {
        $this->assertSame(3, GameState::currentWind(0, 1)); // E -> North
        $this->assertSame(0, GameState::currentWind(1, 1)); // S -> East
        $this->assertSame(1, GameState::currentWind(2, 1)); // W -> South
    }

    public function testW7EastSouthWestDealerWestAllFourWindsSeen(): void
    {
        $this->assertSame(2, GameState::currentWind(0, 2)); // E -> West
        $this->assertSame(3, GameState::currentWind(1, 2)); // S -> North
        $this->assertSame(0, GameState::currentWind(2, 2)); // W -> East
    }

    public function testW8NextDealerFromSouthSkipsWestAndNorth(): void
    {
        $this->assertSame(0, GameState::nextDealer(1, [0, 1]));
    }

    public function testW9NextDealerFromEastSkipsSouthAndWest(): void
    {
        $this->assertSame(3, GameState::nextDealer(0, [0, 3]));
    }

    public function testW10NextDealerFromNorthReturnsEast(): void
    {
        $this->assertSame(0, GameState::nextDealer(3, [0, 1, 2, 3]));
    }

    public function testW11UnsortedSeatsAreNormalisedDuplicatesOrWrongLengthRejected(): void
    {
        $this->assertSame([0, 1, 2], GameState::normalizeSeats([2, 0, 1], 3));

        $this->expectException(DomainException::class);
        GameState::normalizeSeats([0, 0, 1], 3);
    }

    public function testW11WrongLengthIsRejected(): void
    {
        $this->expectException(DomainException::class);
        GameState::normalizeSeats([0, 1], 3);
    }

    public function testW12NoEastChairIsRejected(): void
    {
        $this->expectException(DomainException::class);
        GameState::normalizeSeats([1, 2], 2);
    }

    // ---- S: state machine ----

    public function testS1DealerWinStays(): void
    {
        $state = GameState::replay($this->seatsFor([0, 1, 2, 3]), [$this->winHand(0)]);
        $this->assertSame(0, $state->roundWind);
        $this->assertSame(0, $state->dealerWindIndex);
        $this->assertSame(2, $state->handNumber);
    }

    public function testS2DrawKeepsDealer(): void
    {
        $state = GameState::replay($this->seatsFor([0, 1, 2, 3]), [$this->winHand(0), $this->drawHand()]);
        $this->assertSame(0, $state->roundWind);
        $this->assertSame(0, $state->dealerWindIndex);
        $this->assertSame(3, $state->handNumber);
    }

    public function testS3PenaltyKeepsDealer(): void
    {
        $state = GameState::replay(
            $this->seatsFor([0, 1, 2, 3]),
            [$this->winHand(0), $this->drawHand(), $this->penaltyHand()]
        );
        $this->assertSame(0, $state->roundWind);
        $this->assertSame(0, $state->dealerWindIndex);
        $this->assertSame(4, $state->handNumber);
    }

    public function testS4NonDealerWinMovesDealer(): void
    {
        $state = GameState::replay(
            $this->seatsFor([0, 1, 2, 3]),
            [$this->winHand(0), $this->drawHand(), $this->penaltyHand(), $this->winHand(1)]
        );
        $this->assertSame(0, $state->roundWind);
        $this->assertSame(1, $state->dealerWindIndex);
        $this->assertSame(5, $state->handNumber);
    }

    public function testS6FourPlayersFourConsecutiveNonDealerWins(): void
    {
        $occupied = [0, 1, 2, 3];
        $hands = $this->buildRotationHands($occupied, 4);
        $state = GameState::replay($this->seatsFor($occupied), $hands);
        $this->assertSame(1, $state->roundWind); // South
        $this->assertSame(0, $state->dealerWindIndex);
    }

    public function testS7FourPlayersSixteenConsecutiveNonDealerWinsCompletes(): void
    {
        $occupied = [0, 1, 2, 3];
        $hands = $this->buildRotationHands($occupied, 16);
        $state = GameState::replay($this->seatsFor($occupied), $hands);
        $this->assertTrue($state->isComplete);

        $notYet = GameState::replay($this->seatsFor($occupied), array_slice($hands, 0, 15));
        $this->assertFalse($notYet->isComplete);
    }

    public function testS8FourPlayersEightDealerWinsInterleavedStillNeedsSixteenRotations(): void
    {
        $occupied = [0, 1, 2, 3];
        $hands = $this->buildRotationHandsWithDealerWins($occupied, 16, [1, 3, 5, 7, 9, 11, 13, 15]);
        $this->assertCount(24, $hands);

        $state = GameState::replay($this->seatsFor($occupied), $hands);
        $this->assertTrue($state->isComplete);

        $notYet = GameState::replay($this->seatsFor($occupied), array_slice($hands, 0, 23));
        $this->assertFalse($notYet->isComplete);
    }

    public function testS9UndoTheCompletingHandReopensTheGame(): void
    {
        $occupied = [0, 1, 2, 3];
        $hands = $this->buildRotationHands($occupied, 16);
        $completed = GameState::replay($this->seatsFor($occupied), $hands);
        $this->assertTrue($completed->isComplete);

        $undone = GameState::replay($this->seatsFor($occupied), array_slice($hands, 0, -1));
        $this->assertFalse($undone->isComplete);
        $this->assertSame(3, $undone->roundWind); // North
        $this->assertSame(3, $undone->dealerWindIndex);
    }

    public function testS10ThreePlayersThreeConsecutiveNonDealerWins(): void
    {
        $occupied = [0, 1, 2];
        $hands = $this->buildRotationHands($occupied, 3);
        $state = GameState::replay($this->seatsFor($occupied), $hands);
        $this->assertSame(1, $state->roundWind); // South
        $this->assertSame(0, $state->dealerWindIndex);
    }

    public function testS11ThreePlayersTwelveConsecutiveNonDealerWinsCompletes(): void
    {
        $occupied = [0, 1, 2];
        $hands = $this->buildRotationHands($occupied, 12);
        $state = GameState::replay($this->seatsFor($occupied), $hands);
        $this->assertTrue($state->isComplete);
    }

    public function testS12ThreePlayersRoundWindReachesNorthWithNobodySeatedThere(): void
    {
        $occupied = [0, 1, 2];
        // 9 hands = 3 full rounds of 3 deals each; the 4th round (North) is starting.
        $hands = $this->buildRotationHands($occupied, 9);
        $state = GameState::replay($this->seatsFor($occupied), $hands);
        $this->assertSame(3, $state->roundWind); // North round, though nobody sits North
        $this->assertFalse($state->isComplete);
    }

    public function testS13TwoPlayersTwoConsecutiveNonDealerWins(): void
    {
        $occupied = [0, 2]; // East + West
        $hands = $this->buildRotationHands($occupied, 2);
        $state = GameState::replay($this->seatsFor($occupied), $hands);
        $this->assertSame(1, $state->roundWind); // South
        $this->assertSame(0, $state->dealerWindIndex);
    }

    public function testS14TwoPlayersEightConsecutiveNonDealerWinsCompletes(): void
    {
        $occupied = [0, 2];
        $hands = $this->buildRotationHands($occupied, 8);
        $state = GameState::replay($this->seatsFor($occupied), $hands);
        $this->assertTrue($state->isComplete);

        $notYet = GameState::replay($this->seatsFor($occupied), array_slice($hands, 0, 7));
        $this->assertFalse($notYet->isComplete);
    }

    public function testS15DealPositionMatchesRankOfDealerInOccupied(): void
    {
        $state = GameState::replay($this->seatsFor([0, 1, 2, 3]), $this->buildRotationHands([0, 1, 2, 3], 2));
        $this->assertSame(2, $state->dealerWindIndex);
        $this->assertSame(3, $state->dealPosition()); // Deal 3 of 4

        $state2 = GameState::replay($this->seatsFor([0, 2]), $this->buildRotationHands([0, 2], 1));
        $this->assertSame(2, $state2->dealerWindIndex);
        $this->assertSame(2, $state2->dealPosition()); // Deal 2 of 2
    }

    // ---- Invariants ----

    public function testI3AGameCannotCompleteBeforeFourNHandsAcrossAllPlayerCounts(): void
    {
        $configs = [
            2 => [0, 2],
            3 => [0, 1, 2],
            4 => [0, 1, 2, 3],
        ];
        foreach ($configs as $n => $occupied) {
            $rotations = 4 * $n;
            $insertBefore = [];
            for ($i = 1; $i <= $rotations; $i += 2) {
                $insertBefore[] = $i;
            }
            $hands = $this->buildRotationHandsWithDealerWins($occupied, $rotations, $insertBefore);
            $this->assertGreaterThan(4 * $n, count($hands));

            $complete = GameState::replay($this->seatsFor($occupied), $hands);
            $this->assertTrue($complete->isComplete, "N={$n} should be complete after all hands");

            $notYet = GameState::replay($this->seatsFor($occupied), array_slice($hands, 0, -1));
            $this->assertFalse($notYet->isComplete, "N={$n} should not be complete one hand early");
        }
    }

    public function testI4ReplayReproducesPerHandRoundAndDealerState(): void
    {
        $seats = $this->seatsFor([0, 1, 2, 3]);
        $hands = [
            $this->winHand(0),    // S1: dealer wins, stays
            $this->drawHand(),    // S2
            $this->penaltyHand(), // S3
            $this->winHand(1),    // S4: dealer moves
        ];

        $state = GameState::initial($seats);
        $recorded = [];
        foreach ($hands as $hand) {
            $state = $state->applyHand($hand['outcome'], $hand['winner_player_id'], $hand['deltas']);
            $recorded[] = [$state->roundWind, $state->dealerWindIndex, $state->handNumber];
        }

        foreach ($recorded as $i => [$round, $dealer, $handNumber]) {
            $replayed = GameState::replay($seats, array_slice($hands, 0, $i + 1));
            $this->assertSame($round, $replayed->roundWind);
            $this->assertSame($dealer, $replayed->dealerWindIndex);
            $this->assertSame($handNumber, $replayed->handNumber);
        }
    }

    // ---- Validation rejections ----

    public function testV7RecordingAHandOnACompletedGameIsRejected(): void
    {
        $occupied = [0, 2];
        $hands = $this->buildRotationHands($occupied, 8);
        $state = GameState::replay($this->seatsFor($occupied), $hands);
        $this->assertTrue($state->isComplete);

        $this->expectException(DomainException::class);
        $state->applyHand('win', 0, []);
    }

    public function testV8NotApplicableAtDomainLayer(): void
    {
        // "V8: deleting a hand that is not the last" is explicitly scoped to the undo
        // HTTP route in docs/03-api.md ("V8 belongs to the undo route"). The domain
        // layer only ever sees an ordered hand list to replay; there is no hand
        // identity to delete by, so this rule has no equivalent here.
        $this->markTestSkipped(
            'V8 is enforced by the repo/API undo route, not the domain layer (see 03-api.md).'
        );
    }

    public function testV9PlayerCountOutsideTwoToFourIsRejected(): void
    {
        $this->expectException(DomainException::class);
        GameState::normalizeSeats([0, 1], 1);
    }

    public function testV9PlayerCountAboveFourIsRejected(): void
    {
        $this->expectException(DomainException::class);
        GameState::normalizeSeats([0, 1, 2, 3, 4], 5);
    }

    public function testV9SeatsLengthNotMatchingPlayerCountIsRejected(): void
    {
        $this->expectException(DomainException::class);
        GameState::normalizeSeats([0, 1], 3);
    }
}
