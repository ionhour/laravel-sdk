<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Ionhour\Laravel\Facades\Ionhour;
use RuntimeException;

final class RunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);
    }

    public function test_run_returns_closure_result_and_pings_success_with_duration(): void
    {
        $value = Ionhour::registerJob('tok_123')->run(fn () => 'the-result');

        $this->assertSame('the-result', $value);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && ! str_contains($request->url(), '/fail')
            && str_contains($request->url(), 'duration='));
    }

    public function test_run_pings_fail_with_error_payload_then_rethrows(): void
    {
        $job = Ionhour::registerJob('tok_123');

        $caught = null;

        try {
            $job->run(function (): void {
                throw new RuntimeException('kaboom');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'run() must rethrow the closure error.');
        $this->assertSame('kaboom', $caught->getMessage());

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/fail')
            && str_contains($request->url(), 'duration=')
            && ($request['error'] ?? null) === 'kaboom');
    }

    public function test_run_merges_user_payload_with_error_on_failure(): void
    {
        $job = Ionhour::registerJob('tok_123');

        try {
            $job->run(
                function (): void {
                    throw new RuntimeException('nope');
                },
                ['payload' => ['stage' => 'export']]
            );
        } catch (RuntimeException) {
            // expected
        }

        Http::assertSent(fn ($request) => str_contains($request->url(), '/fail')
            && ($request['stage'] ?? null) === 'export'
            && ($request['error'] ?? null) === 'nope');
    }
}
