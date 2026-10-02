<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a write is refused because the row changed underneath the
 * caller (optimistic concurrency).
 *
 * DECISIONS_PENDING.md §8 recorded medication reconciliation as last-write-wins:
 * two clinicians editing the same row ended with the later write applied and
 * the earlier one silently dropped from the row. A clinical medication list is
 * exactly the record where a silently dropped edit is dangerous, so the write
 * is now refused instead.
 *
 * The caller gets a 409 and is told to reload. The stale write is never applied
 * and never half-applied, and the losing edit remains recoverable from
 * `audit_logs`.
 */
class StaleWriteConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  Machine-readable detail, echoed
     *                                         back in the error envelope `data` so a
     *                                         client can show a useful message.
     */
    public function __construct(string $message, private readonly array $context = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
