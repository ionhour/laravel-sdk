<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Ionhour\Laravel\Facades\Ionhour;
use RuntimeException;

/**
 * The token-first facade conveniences: register the job and ping in one call,
 * mirroring the NestJS service's `success()`/`fail()`/`run()` surface.
 */
final class FacadeConvenienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);
    }

    public function test_success_registers_and_pings_in_one_call(): void
    {
        $result = Ionhour::success('tok_123');

        $this->assertTrue($result->ok);
        $this->assertSame(200, $result->status);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === self::BASE.'/signals/ping/tok_123');
    }

    public function test_ping_is_an_alias_for_success(): void
    {
        $result = Ionhour::ping('tok_123');

        $this->assertTrue($result->ok);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === self::BASE.'/signals/ping/tok_123');
    }

    public function test_fail_registers_and_pings_the_fail_suffix(): void
    {
        $result = Ionhour::fail('tok_123');

        $this->assertTrue($result->ok);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === self::BASE.'/signals/ping/tok_123/fail');
    }

    public function test_success_forwards_ping_options(): void
    {
        Ionhour::success('tok_123', [
            'rid' => 'run-9',
            'payload' => ['cpu' => 42],
        ]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'rid=run-9')
            && $request['cpu'] === 42);
    }

    public function test_run_returns_closure_result_and_pings_success_with_duration(): void
    {
        $value = Ionhour::run('tok_123', fn () => 'the-result');

        $this->assertSame('the-result', $value);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && ! str_contains($request->url(), '/fail')
            && str_contains($request->url(), 'duration='));
    }

    public function test_run_pings_fail_then_rethrows_the_closure_error(): void
    {
        $caught = null;

        try {
            Ionhour::run('tok_123', function (): void {
                throw new RuntimeException('kaboom');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'run() must rethrow the closure error.');
        $this->assertSame('kaboom', $caught->getMessage());

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/fail')
            && ($request['error'] ?? null) === 'kaboom');
    }

    public function test_success_does_not_leak_api_key_into_query_or_payload(): void
    {
        Ionhour::success('tok_123', ['api_key' => 'ionh_secret', 'payload' => ['ok' => true]]);

        $this->assertApiKeyNotLeaked();
        Http::assertSent(fn ($request) => $request['ok'] === true);
    }

    public function test_fail_does_not_leak_api_key_into_query_or_payload(): void
    {
        Ionhour::fail('tok_123', ['api_key' => 'ionh_secret', 'payload' => ['ok' => true]]);

        $this->assertApiKeyNotLeaked();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/fail')
            && $request['ok'] === true);
    }

    public function test_run_does_not_leak_api_key_into_query_or_payload(): void
    {
        Ionhour::run('tok_123', fn () => null, ['api_key' => 'ionh_secret']);

        $this->assertApiKeyNotLeaked();
    }

    /**
     * A legacy `api_key` option is ignored entirely: it never travels as a
     * query param, a JSON body field, or an Authorization header.
     */
    private function assertApiKeyNotLeaked(): void
    {
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'ionh_secret')
            && ! str_contains($request->url(), 'api_key')
            && ($request['api_key'] ?? null) === null);
        Http::assertNotSent(fn ($request) => $request->hasHeader('Authorization'));
    }
}
