<?php

declare(strict_types=1);

namespace App\Service;

/** Thrown by AvatarService for any rejected upload; the route turns it into a 422. */
final class AvatarException extends \RuntimeException
{
}
