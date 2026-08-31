<?php

declare(strict_types=1);

namespace App\Domain;

/** Thrown by the scoring engine and round/dealer state machine for every rejection (V1-V12). */
final class DomainException extends \RuntimeException
{
}
