<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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

    public function test_run_sends_a_start_ping_before_the_success_ping(): void
    {
        Ionhour::registerJob('tok_123')->run(fn () => 'ok');

        // Exactly two pings: a start ping, then the terminal success ping.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/signals/ping/tok_123/start'));
        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/start')
            && ! str_contains($request->url(), '/fail')
            && str_contains($request->url(), 'duration='));
    }

    public function test_run_generates_a_rid_shared_by_start_and_terminal_pings(): void
    {
        Ionhour::registerJob('tok_123')->run(fn () => null);

        $rids = $this->ridsFromRecorded();

        $this->assertCount(2, $rids, 'start + terminal should each carry a rid.');
        $this->assertCount(1, array_unique($rids), 'the generated rid must be shared.');
        $this->assertNotSame('', $rids[0]);
    }

    public function test_run_reuses_a_caller_supplied_rid_on_both_pings(): void
    {
        Ionhour::registerJob('tok_123')->run(fn () => null, ['rid' => 'my-run-id']);

        // Order is start-first, then terminal.
        $this->assertSame(['my-run-id', 'my-run-id'], $this->ridsFromRecorded());
    }

    public function test_run_shares_the_rid_between_start_and_fail_on_error(): void
    {
        try {
            Ionhour::registerJob('tok_123')->run(function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        $rids = $this->ridsFromRecorded();

        $this->assertCount(2, $rids, 'start + fail should each carry a rid.');
        $this->assertCount(1, array_unique($rids));
    }

    public function test_run_start_ping_carries_only_rid_host_env(): void
    {
        Ionhour::registerJob('tok_123')->run(
            fn () => null,
            ['host' => 'web-01', 'env' => 'prod', 'exit_code' => 0, 'payload' => ['k' => 'v']]
        );

        $start = $this->recordedStartRequest();

        $this->assertNotNull($start);
        $this->assertSame('GET', $start->method());                 // no payload -> GET
        $this->assertStringContainsString('host=web-01', $start->url());
        $this->assertStringContainsString('env=prod', $start->url());
        $this->assertStringContainsString('rid=', $start->url());
        $this->assertStringNotContainsString('exit_code', $start->url());
        $this->assertStringNotContainsString('duration', $start->url());
    }

    public function test_run_start_ping_can_be_disabled(): void
    {
        Ionhour::registerJob('tok_123')->run(fn () => null, ['start_ping' => false]);

        // Only the terminal ping — no start ping, and start_ping never leaks out.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/start')
            && ! str_contains($request->url(), 'start_ping'));
    }

    public function test_run_start_ping_failure_does_not_affect_closure_or_terminal(): void
    {
        // Single attempt keeps it fast; the start ping fails, the terminal succeeds.
        config()->set('ionhour.retries', 1);
        Sleep::fake();

        Http::fakeSequence()
            ->push(['message' => 'server error'], 500)                 // start ping fails
            ->whenEmpty(Http::response(['message' => 'OK'], 200));      // terminal succeeds

        $value = Ionhour::registerJob('tok_123')->run(fn () => 'result');

        $this->assertSame('result', $value, 'a failed start ping must not affect the result.');
        Http::assertSent(fn ($request) => ! str_contains($request->url(), '/start')
            && str_contains($request->url(), 'duration='));

        Sleep::fake(false);
    }

    /**
     * The rid query param from every recorded request, in send order.
     *
     * @return list<string>
     */
    private function ridsFromRecorded(): array
    {
        $rids = [];

        foreach (Http::recorded() as [$request]) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (isset($query['rid'])) {
                $rids[] = (string) $query['rid'];
            }
        }

        return $rids;
    }

    private function recordedStartRequest(): ?\Illuminate\Http\Client\Request
    {
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), '/start')) {
                return $request;
            }
        }

        return null;
    }
}
