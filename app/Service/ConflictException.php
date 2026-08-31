<?php

declare(strict_types=1);

namespace App\Service;

/** Maps to HTTP 409 in routes.php — a request that is well-formed but conflicts with current state. */
final class ConflictException extends \RuntimeException
{
}
