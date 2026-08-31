<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\DomainException;
use App\Domain\Ruleset;
use App\Domain\Scoring;
use PHPUnit\Framework\TestCase;

/**
 * Part 1 (payment math) vectors from docs-initial-build/02-scoring-engine.md § Part 4.
 *
 * P4 is deliberately retired (now rejection V11) — do not reinstate it.
 */
final class ScoringTest extends TestCase
{
    /** Hong Kong Standard seed table: faan 0..13 -> base points. table_max_faan = 13, penalty_default = 128. */
    private function hkRuleset(): Ruleset
    {
        return new Ruleset(
            'Hong Kong Standard',
            13,
            'hk_standard',
            128,
            [0 => 1, 1 => 2, 2 => 4, 3 => 8, 4 => 16, 5 => 16, 6 => 16,
             7 => 32, 8 => 32, 9 => 32, 10 => 64, 11 => 64, 12 => 64, 13 => 64]
        );
    }

    // ---- Four players — seats 0=Ann, 1=Ben, 2=Cal, 3=Dee (faan 3, B=8) ----

    public function testP1DiscardWinFourPlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 3, 0, 13, Scoring::WIN_TYPE_DISCARD, 1, null);
        $this->assertSame([0 => 32, 1 => -16, 2 => -8, 3 => -8], $deltas);
    }

    public function testP2SelfPickWinFourPlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 3, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, null);
        $this->assertSame([0 => 48, 1 => -16, 2 => -16, 3 => -16], $deltas);
    }

    public function testP3DiscardWinWithBaoFourPlayers(): void
    {
        // bao => liable = discarder = Ben
        $deltas = Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 3, 0, 13, Scoring::WIN_TYPE_DISCARD, 1, 1);
        $this->assertSame([0 => 32, 1 => -32, 2 => 0, 3 => 0], $deltas);
    }

    // P4 deliberately retired — see V11.

    public function testP5SelfPickWinWithBaoFourPlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 3, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, 3);
        $this->assertSame([0 => 48, 1 => 0, 2 => 0, 3 => -48], $deltas);
    }

    public function testP6Draw(): void
    {
        $deltas = Scoring::draw([0, 1, 2, 3]);
        $this->assertSame([0 => 0, 1 => 0, 2 => 0, 3 => 0], $deltas);
    }

    public function testP7PenaltyFourPlayers(): void
    {
        $deltas = Scoring::penalty([0, 1, 2, 3], 2, 128);
        $this->assertSame([0 => 128, 1 => 128, 2 => -384, 3 => 128], $deltas);
    }

    // ---- Three players — seats 0=Ann, 1=Ben, 2=Cal ----

    public function testP13DiscardWinThreePlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1, 2], 0, 3, 0, 13, Scoring::WIN_TYPE_DISCARD, 1, null);
        $this->assertSame([0 => 24, 1 => -16, 2 => -8], $deltas);
    }

    public function testP14SelfPickWinThreePlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1, 2], 0, 3, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, null);
        $this->assertSame([0 => 32, 1 => -16, 2 => -16], $deltas);
    }

    public function testP15DiscardWinWithBaoThreePlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1, 2], 0, 3, 0, 13, Scoring::WIN_TYPE_DISCARD, 1, 1);
        $this->assertSame([0 => 24, 1 => -24, 2 => 0], $deltas);
    }

    public function testP16SelfPickWinWithBaoThreePlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1, 2], 0, 3, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, 1);
        $this->assertSame([0 => 32, 1 => -32, 2 => 0], $deltas);
    }

    public function testP17PenaltyThreePlayers(): void
    {
        $deltas = Scoring::penalty([0, 1, 2], 2, 128);
        $this->assertSame([0 => 128, 1 => 128, 2 => -256], $deltas);
    }

    // ---- Two players — seats 0=Ann (East), 1=Ben (West) ----

    public function testP18DiscardWinTwoPlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1], 0, 3, 0, 13, Scoring::WIN_TYPE_DISCARD, 1, null);
        $this->assertSame([0 => 16, 1 => -16], $deltas);
    }

    public function testP19SelfPickWinTwoPlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1], 0, 3, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, null);
        $this->assertSame([0 => 16, 1 => -16], $deltas);
    }

    public function testP20DiscardAndSelfPickPayTheSameAtTwoPlayers(): void
    {
        $discard = Scoring::win($this->hkRuleset(), [0, 1], 0, 3, 0, 13, Scoring::WIN_TYPE_DISCARD, 1, null);
        $selfPick = Scoring::win($this->hkRuleset(), [0, 1], 0, 3, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, null);
        $this->assertSame($discard, $selfPick);
    }

    public function testP21SelfPickWinWithBaoTwoPlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1], 0, 3, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, 1);
        $this->assertSame([0 => 16, 1 => -16], $deltas);
    }

    public function testP22PenaltyTwoPlayers(): void
    {
        $deltas = Scoring::penalty([0, 1], 1, 128);
        $this->assertSame([0 => 128, 1 => -128], $deltas);
    }

    // ---- Band edges (any player count) ----

    public function testP8FaanFourFlatBandDiscardFourPlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 4, 0, 13, Scoring::WIN_TYPE_DISCARD, 1, null);
        $this->assertSame(64, $deltas[0]);
    }

    public function testP9FaanSixFlatBandDiscardFourPlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 6, 0, 13, Scoring::WIN_TYPE_DISCARD, 1, null);
        $this->assertSame(64, $deltas[0]);
    }

    public function testP10FaanSevenSelfPickFourPlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 7, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, null);
        $this->assertSame(192, $deltas[0]);
    }

    public function testP11FaanThirteenSelfPickFourPlayers(): void
    {
        $deltas = Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 13, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, null);
        $this->assertSame(384, $deltas[0]);
    }

    public function testP12FaanTwentyClampsToTableMaxFaan(): void
    {
        $ruleset = $this->hkRuleset();
        // Passed straight to basePoints(), bypassing the game's selectable min/max band.
        $this->assertSame(64, $ruleset->basePoints(20));
        $this->assertSame($ruleset->basePoints(13), $ruleset->basePoints(20));
    }

    // ---- Invariants ----

    public function testI1AllDeltasSumToZero(): void
    {
        $ruleset = $this->hkRuleset();
        $cases = [
            Scoring::win($ruleset, [0, 1], 0, 5, 0, 13, Scoring::WIN_TYPE_DISCARD, 1, null),
            Scoring::win($ruleset, [0, 1, 2], 0, 5, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, 1),
            Scoring::win($ruleset, [0, 1, 2, 3], 2, 9, 0, 13, Scoring::WIN_TYPE_DISCARD, 3, 3),
            Scoring::win($ruleset, [0, 1, 2, 3], 1, 11, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, 3),
            Scoring::draw([0, 1, 2]),
            Scoring::penalty([0, 1, 2, 3], 0, 50),
        ];
        foreach ($cases as $deltas) {
            $this->assertSame(0, array_sum($deltas));
        }
    }

    public function testI2EveryOutcomeWritesExactlyNRows(): void
    {
        $ruleset = $this->hkRuleset();
        foreach ([2, 3, 4] as $n) {
            $seats = range(0, $n - 1);
            $this->assertCount($n, Scoring::win($ruleset, $seats, 0, 5, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, null));
            $this->assertCount($n, Scoring::draw($seats));
            $this->assertCount($n, Scoring::penalty($seats, 0, 50));
        }
    }

    // ---- Validation rejections ----

    public function testV1FaanBelowGameMinFaanIsRejected(): void
    {
        $this->expectException(DomainException::class);
        Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 2, 3, 8, Scoring::WIN_TYPE_DISCARD, 1, null);
    }

    public function testV1bFaanAboveGameMaxFaanIsRejectedEvenWithAPointsRow(): void
    {
        // faan 9 has a points row (32) but the game's band tops out at 8.
        $this->expectException(DomainException::class);
        Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 9, 3, 8, Scoring::WIN_TYPE_DISCARD, 1, null);
    }

    public function testV2DiscarderEqualsWinnerIsRejected(): void
    {
        $this->expectException(DomainException::class);
        Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 3, 0, 13, Scoring::WIN_TYPE_DISCARD, 0, null);
    }

    public function testV3LiableEqualsWinnerIsRejected(): void
    {
        $this->expectException(DomainException::class);
        Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 3, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, 0);
    }

    public function testV4DiscardWinWithNoDiscarderIsRejected(): void
    {
        $this->expectException(DomainException::class);
        Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 3, 0, 13, Scoring::WIN_TYPE_DISCARD, null, null);
    }

    public function testV5SelfPickWinWithDiscarderSetIsRejected(): void
    {
        $this->expectException(DomainException::class);
        Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 3, 0, 13, Scoring::WIN_TYPE_SELF_PICK, 1, null);
    }

    public function testV6WinnerNotSeatedIsRejected(): void
    {
        $this->expectException(DomainException::class);
        Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 99, 3, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, null);
    }

    public function testV6DiscarderNotSeatedIsRejected(): void
    {
        $this->expectException(DomainException::class);
        Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 3, 0, 13, Scoring::WIN_TYPE_DISCARD, 99, null);
    }

    public function testV6LiableNotSeatedIsRejected(): void
    {
        $this->expectException(DomainException::class);
        Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 3, 0, 13, Scoring::WIN_TYPE_SELF_PICK, null, 99);
    }

    public function testV6OffenderNotSeatedIsRejected(): void
    {
        $this->expectException(DomainException::class);
        Scoring::penalty([0, 1, 2, 3], 99, 128);
    }

    public function testV10DiscardWinAtTwoPlayersWithUnseatedDiscarderIsRejected(): void
    {
        // At N=2 the discarder can only be the one other seated player.
        $this->expectException(DomainException::class);
        Scoring::win($this->hkRuleset(), [0, 1], 0, 3, 0, 13, Scoring::WIN_TYPE_DISCARD, 99, null);
    }

    public function testV11DiscardBaoLiableMustBeTheDiscarder(): void
    {
        $this->expectException(DomainException::class);
        // Ann wins by discard from Ben, bao pinned on Cal instead — rejected, was retired vector P4.
        Scoring::win($this->hkRuleset(), [0, 1, 2, 3], 0, 3, 0, 13, Scoring::WIN_TYPE_DISCARD, 1, 2);
    }

    public function testV12SelfPickBaoWithoutALiablePlayerHasNoDomainRepresentation(): void
    {
        // Per docs-initial-build/04-frontend.md ("a self-pick bao can never be recorded with nobody
        // liable") this is a UI-only constraint: the entry bar disables Record until a
        // liable player is named. The API request shape has no separate "bao intended"
        // flag for self_pick (unlike discard's optional bao:true) — liable_player_id
        // null simply means "no bao". There is nothing to reject at the domain layer.
        $this->markTestSkipped(
            'V12 is enforced by the frontend entry bar (04-frontend.md), not the domain layer.'
        );
    }
}
