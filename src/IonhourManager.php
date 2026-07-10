<?php

declare(strict_types=1);

namespace Ionhour\Laravel;

use InvalidArgumentException;

/**
 * Entry point for the Ionhour Laravel SDK.
 *
 * Bound as a singleton by {@see IonhourServiceProvider} and reached through the
 * {@see \Ionhour\Laravel\Facades\Ionhour} facade. Registration is local and
 * performs no network call.
 */
final class IonhourManager
{
    private readonly ?string $apiKey;

    public function __construct(
        private readonly string $baseUrl,
        ?string $apiKey,
        private readonly int $timeout,
        private readonly int $retries,
    ) {
        $this->apiKey = self::normalizeKey($apiKey);
    }

    /**
     * Register a Job by its ping token. The optional per-job API key overrides
     * the configured workspace key and is only needed for protected jobs.
     *
     * @throws InvalidArgumentException when the token is empty or contains whitespace.
     */
    public function registerJob(string $jobToken, ?string $apiKey = null): Job
    {
        if ($jobToken === '' || preg_match('/\s/', $jobToken) === 1) {
            throw new InvalidArgumentException(
                'Ionhour: job token must be a non-empty string with no whitespace.'
            );
        }

        return new Job(
            token: $jobToken,
            apiKey: self::normalizeKey($apiKey) ?? $this->apiKey,
            baseUrl: $this->baseUrl,
            timeout: $this->timeout,
            retries: $this->retries,
        );
    }

    /**
     * Trim an incoming key and treat an empty/whitespace-only result as absent
     * (null), so a padded ".env" value ("\tionh_x\n") never becomes a malformed
     * "Bearer  ionh_x\n" header that surfaces only as a silent 401.
     */
    private static function normalizeKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $trimmed = trim($key);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Redact the stored workspace key so var_dump()/dd() of the manager never
     * leaks it into logs.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'apiKey' => $this->apiKey === null ? null : '<redacted>',
            'timeout' => $this->timeout,
            'retries' => $this->retries,
        ];
    }
}
