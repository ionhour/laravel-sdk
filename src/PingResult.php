<?php

declare(strict_types=1);

namespace Ionhour\Laravel;

/**
 * Immutable outcome of a single ping.
 *
 * Transport failures are represented as data on this object (never thrown), in
 * line with the SDK contract's error philosophy: monitoring must never take
 * down the host application.
 */
final class PingResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $status = null,
        public readonly ?string $error = null,
        /** Slug mode only: true when this ping auto-created the job (HTTP 201). */
        public readonly bool $created = false,
    ) {
    }
}
