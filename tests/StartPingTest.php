<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Ionhour\Laravel\Facades\Ionhour;

/**
 * The start ping (run-started telemetry) and the host/env/exit_code telemetry
 * query params shared by every ping kind.
 */
final class StartPingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);
    }

    public function test_start_sends_get_to_the_start_suffix(): void
    {
        $result = Ionhour::registerJob('tok_123')->start();

        $this->assertTrue($result->ok);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === self::BASE.'/signals/ping/tok_123/start');
    }

    public function test_start_with_payload_posts_to_the_start_suffix(): void
    {
        Ionhour::registerJob('tok_123')->start(['payload' => ['pid' => 4242]]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_starts_with($request->url(), self::BASE.'/signals/ping/tok_123/start')
            && $request['pid'] === 4242);
    }

    public function test_token_first_start_registers_and_pings(): void
    {
        Ionhour::start('tok_123');

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === self::BASE.'/signals/ping/tok_123/start');
    }

    public function test_host_env_and_exit_code_are_sent_as_query_params(): void
    {
        Ionhour::registerJob('tok_123')->success([
            'host' => 'web-01',
            'env' => 'production',
            'exit_code' => 0,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'host=web-01')
            && str_contains($request->url(), 'env=production')
            && str_contains($request->url(), 'exit_code=0'));
    }

    public function test_exit_code_boundaries_0_and_255_are_accepted(): void
    {
        Ionhour::registerJob('tok_123')->success(['exit_code' => 0]);
        Ionhour::registerJob('tok_123')->fail(['exit_code' => 255]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'exit_code=0'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'exit_code=255'));
    }

    public function test_exit_code_above_255_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Ionhour::registerJob('tok_123')->success(['exit_code' => 256]);
    }

    public function test_negative_exit_code_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Ionhour::registerJob('tok_123')->success(['exit_code' => -1]);
    }

    public function test_non_integer_exit_code_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // A numeric string is NOT an integer — programmer errors stay loud.
        Ionhour::registerJob('tok_123')->success(['exit_code' => '0']);
    }

    public function test_invalid_exit_code_sends_no_request(): void
    {
        try {
            Ionhour::registerJob('tok_123')->success(['exit_code' => 999]);
            $this->fail('Expected InvalidArgumentException for an out-of-range exit code.');
        } catch (InvalidArgumentException) {
            // expected
        }

        Http::assertNothingSent();
    }

    public function test_host_longer_than_the_limit_is_truncated(): void
    {
        Ionhour::registerJob('tok_123')->success(['host' => str_repeat('a', 300)]);

        // Truncated to exactly MAX_HOST_CHARS, never a byte longer.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'host='.str_repeat('a', 253))
            && ! str_contains($request->url(), 'host='.str_repeat('a', 254)));
    }

    public function test_env_longer_than_the_limit_is_truncated(): void
    {
        Ionhour::registerJob('tok_123')->success(['env' => str_repeat('b', 50)]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'env='.str_repeat('b', 32))
            && ! str_contains($request->url(), 'env='.str_repeat('b', 33)));
    }
}
