<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Ionhour\Laravel\Facades\Ionhour;

final class RetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The Http client's retry backoff sleeps via Illuminate\Support\Sleep;
        // faking it keeps the suite fast without changing retry behaviour.
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    public function test_retries_on_500_then_succeeds(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'server error'], 500)
            ->push(['message' => 'OK'], 200);

        $result = Ionhour::registerJob('tok_123')->success();

        $this->assertTrue($result->ok);
        $this->assertSame(200, $result->status);
        Http::assertSentCount(2);
    }

    public function test_retries_on_429_then_succeeds(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'slow down'], 429)
            ->push(['message' => 'OK'], 200);

        $result = Ionhour::registerJob('tok_123')->success();

        $this->assertTrue($result->ok);
        Http::assertSentCount(2);
    }

    public function test_does_not_retry_on_400(): void
    {
        Http::fake(['*' => Http::response(['message' => 'bad request'], 400)]);

        $result = Ionhour::registerJob('tok_123')->success();

        $this->assertFalse($result->ok);
        $this->assertSame(400, $result->status);
        $this->assertNotNull($result->error);
        Http::assertSentCount(1);
    }

    public function test_connection_error_returns_not_ok_without_throwing(): void
    {
        // Single attempt keeps the test fast (no backoff sleeps).
        config()->set('ionhour.retries', 1);

        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $result = Ionhour::registerJob('tok_123')->success();

        $this->assertFalse($result->ok);
        $this->assertNull($result->status);
        $this->assertNotNull($result->error);
    }
}
