<?php

declare(strict_types=1);

namespace App\Domain;

/** Value object over a ruleset snapshot (see docs/01-data-model.md § ruleset_snapshot JSON shape). */
final class Ruleset
{
    /** @var array<int,int> faan => base points, every row 0..tableMaxFaan present */
    private array $points;

    /** @param array<int|string,int> $points */
    public function __construct(
        public readonly string $name,
        public readonly int $tableMaxFaan,
        public readonly string $paymentRule,
        public readonly int $penaltyDefault,
        array $points
    ) {
        $normalized = [];
        foreach ($points as $faan => $basePoints) {
            $normalized[(int) $faan] = (int) $basePoints;
        }
        for ($faan = 0; $faan <= $tableMaxFaan; $faan++) {
            if (!array_key_exists($faan, $normalized)) {
                throw new DomainException("Ruleset is missing a points row for faan {$faan}");
            }
        }
        $this->points = $normalized;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['name'],
            (int) $data['table_max_faan'],
            (string) $data['payment_rule'],
            (int) $data['penalty_default'],
            $data['points']
        );
    }

    /** Clamps against table_max_faan (the extent of the table) — purely defensive. */
    public function basePoints(int $faan): int
    {
        $faan = max(0, min($faan, $this->tableMaxFaan));
        return $this->points[$faan];
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'table_max_faan' => $this->tableMaxFaan,
            'payment_rule' => $this->paymentRule,
            'penalty_default' => $this->penaltyDefault,
            'points' => $this->points,
        ];
    }
}
