<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A scheduling invariant was violated by the caller.
 *
 * Reserved for things that must not be saved at all — a shift that ends before
 * it starts, approving a punch nobody has clocked out of. Scheduling CONFLICTS
 * are not this: an overlap, approved time off or a minor on a late shift are
 * warnings returned from ShiftService::conflicts() and never refuse a save.
 *
 * context() carries the machine-readable half (the offending ids) so an HTTP
 * layer can render it without re-deriving it from the message.
 */
class SchedulingException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly array $context = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
