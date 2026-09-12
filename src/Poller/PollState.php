<?php

declare(strict_types=1);

namespace App\Poller;

/**
 * Non-sensitive poller bookkeeping: a rolling set of sensor timestamps already
 * emitted into a batch. Holds only timestamps — never glucose values — so the
 * poller never has to read history back to do its job.
 *
 * A set (not a single high-water mark) is used so that a reading which reaches
 * the upstream graph late, out of order, is still emitted instead of being
 * silently dropped.
 */
final class PollState
{
    /** Rolling window of emitted timestamps, newest last. */
    public const WINDOW = 2000;

    /**
     * @param list<int> $seen
     */
    public function __construct(
        public readonly array $seen = [],
        public readonly ?int $firstReadingAt = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function latest(): ?int
    {
        return $this->seen === [] ? null : max($this->seen);
    }

    public function hasSeen(int $timestamp): bool
    {
        return in_array($timestamp, $this->seen, true);
    }

    /**
     * Records timestamps as emitted, widening the earliest-seen bound.
     *
     * @param list<int> $timestamps
     */
    public function emitted(array $timestamps): self
    {
        if ($timestamps === []) {
            return $this;
        }

        $seen = array_values(array_unique(array_merge($this->seen, $timestamps)));
        sort($seen);
        if (count($seen) > self::WINDOW) {
            $seen = array_slice($seen, -self::WINDOW);
        }

        $oldest = min($timestamps);

        return new self(
            $seen,
            $this->firstReadingAt === null ? $oldest : min($this->firstReadingAt, $oldest),
        );
    }
}
