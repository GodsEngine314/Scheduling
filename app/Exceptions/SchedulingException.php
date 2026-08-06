<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A scheduling invariant was violated by the caller.
 *
 * Reserved for things that must not be saved at all — a shift that ends before
 * it starts, closing a day over hours nobody approved. Scheduling CONFLICTS are
 * not this: an overlap, approved time off or a minor on a late shift are
 * warnings returned from ShiftService::conflicts() and never refuse a save.
 *
 * context() carries the machine-readable half (the blocker list, the offending
 * ids) so an HTTP layer can render it without re-deriving it from the message.
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

    /** @param array<int, array<string, mixed>> $blockers */
    public static function dayNotClosable(int $storeId, string $businessDate, array $blockers): self
    {
        $summary = implode(', ', array_map(
            fn (array $blocker): string => ($blocker['count'] ?? 0).' '.($blocker['type'] ?? 'blocker'),
            $blockers,
        ));

        return new self(
            "Store {$storeId} cannot close {$businessDate}: {$summary}.",
            [
                'store_id' => $storeId,
                'business_date' => $businessDate,
                'blockers' => $blockers,
            ],
        );
    }
}
