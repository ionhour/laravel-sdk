<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Ionhour\Laravel\Facades\Ionhour;
use Ionhour\Laravel\Job;

final class PingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);
    }

    public function test_success_sends_get_without_payload(): void
    {
        $result = Ionhour::registerJob('tok_123')->success();

        $this->assertTrue($result->ok);
        $this->assertSame(200, $result->status);
        $this->assertNull($result->error);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === self::BASE.'/signals/ping/tok_123'
            && $request->hasHeader('User-Agent', 'ionhour-php/0.1.0'));
    }

    public function test_success_with_payload_sends_post_json(): void
    {
        $result = Ionhour::registerJob('tok_123')->success([
            'payload' => ['cpu' => 42, 'note' => 'healthy'],
        ]);

        $this->assertTrue($result->ok);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_starts_with($request->url(), self::BASE.'/signals/ping/tok_123')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request['cpu'] === 42
            && $request['note'] === 'healthy');
    }

    public function test_ping_is_an_alias_for_success(): void
    {
        Ionhour::registerJob('tok_123')->ping();

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === self::BASE.'/signals/ping/tok_123');
    }

    public function test_fail_appends_fail_suffix(): void
    {
        $result = Ionhour::registerJob('tok_123')->fail();

        $this->assertTrue($result->ok);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === self::BASE.'/signals/ping/tok_123/fail');
    }

    public function test_fail_with_payload_posts_to_fail_suffix(): void
    {
        Ionhour::registerJob('tok_123')->fail([
            'payload' => ['error' => 'boom'],
        ]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_starts_with($request->url(), self::BASE.'/signals/ping/tok_123/fail')
            && $request['error'] === 'boom');
    }

    public function test_rid_and_duration_are_sent_as_query_params(): void
    {
        Ionhour::registerJob('tok_123')->success([
            'rid' => 'run-9',
            'duration_ms' => 1234,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'rid=run-9')
            && str_contains($request->url(), 'duration=1234'));
    }

    public function test_per_job_api_key_overrides_config_key(): void
    {
        config()->set('ionhour.api_key', 'ionh_config');

        Ionhour::registerJob('tok_123', 'ionh_perjob')->success();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ionh_perjob'));
    }

    public function test_config_api_key_used_when_no_per_job_key(): void
    {
        config()->set('ionhour.api_key', 'ionh_config');

        Ionhour::registerJob('tok_123')->success();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ionh_config'));
    }

    public function test_no_authorization_header_when_no_key_is_set(): void
    {
        Ionhour::registerJob('tok_123')->success();

        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
    }

    public function test_whitespace_padded_per_job_key_is_trimmed_in_header(): void
    {
        Ionhour::registerJob('tok_123', " ionh_padded\n")->success();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ionh_padded'));
    }

    public function test_whitespace_padded_config_key_is_trimmed_in_header(): void
    {
        config()->set('ionhour.api_key', "  ionh_cfg\t");

        Ionhour::registerJob('tok_123')->success();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ionh_cfg'));
    }

    public function test_whitespace_only_key_sends_no_authorization_header(): void
    {
        // A whitespace-only key (from either source) is treated as absent.
        Ionhour::registerJob('tok_123', "   ")->success();

        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
    }

    public function test_url_unsafe_token_is_percent_encoded_in_path(): void
    {
        // '#' is URL-unsafe: without encoding Guzzle would treat it as a
        // fragment delimiter and throw, degrading into a silent ok:false.
        Ionhour::registerJob('tok#123')->success();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/signals/ping/tok%23123')
            && ! str_contains($request->url(), 'tok#123'));
    }

    public function test_payload_at_exactly_the_limit_is_accepted(): void
    {
        $payload = $this->payloadOfEncodedSize(Job::MAX_PAYLOAD_BYTES);
        $this->assertSame(Job::MAX_PAYLOAD_BYTES, strlen((string) json_encode($payload)));

        $result = Ionhour::registerJob('tok_123')->success(['payload' => $payload]);

        $this->assertTrue($result->ok);
        Http::assertSentCount(1);
    }

    public function test_payload_one_byte_over_the_limit_is_rejected(): void
    {
        $payload = $this->payloadOfEncodedSize(Job::MAX_PAYLOAD_BYTES + 1);
        $this->assertSame(Job::MAX_PAYLOAD_BYTES + 1, strlen((string) json_encode($payload)));

        $job = Ionhour::registerJob('tok_123');

        try {
            $job->success(['payload' => $payload]);
            $this->fail('Expected InvalidArgumentException for oversized payload.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('10240', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * Build a payload whose JSON encoding is exactly $bytes long, computed
     * from the real encoded overhead rather than guessed string lengths.
     *
     * @return array{blob: string}
     */
    private function payloadOfEncodedSize(int $bytes): array
    {
        $overhead = strlen((string) json_encode(['blob' => '']));

        return ['blob' => str_repeat('x', $bytes - $overhead)];
    }

    public function test_non_serializable_payload_is_rejected(): void
    {
        $job = Ionhour::registerJob('tok_123');

        $this->expectException(InvalidArgumentException::class);

        // A resource is not JSON-serializable.
        $job->success(['payload' => ['stream' => \fopen('php://memory', 'r')]]);
    }
}
