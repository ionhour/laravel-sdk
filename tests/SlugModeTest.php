<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Ionhour\Laravel\Facades\Ionhour;
use Ionhour\Laravel\IonhourManager;

final class SlugModeTest extends TestCase
{
    private const API = 'https://api.ionhour.com/api';

    private const KEY = 'ionh_php_SECRETSECRETSECRET';

    private const TAIL = 'SECRETSECRETSECRET';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->useKey(self::KEY);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    /** Rebuild the singleton so config changes take effect. */
    private function useKey(?string $key, ?string $apiBase = null): void
    {
        config()->set('ionhour.api_key', $key);
        if ($apiBase !== null) {
            config()->set('ionhour.api_base_url', $apiBase);
        }
        $this->app->forgetInstance(IonhourManager::class);
        Ionhour::clearResolvedInstances();
    }

    public function test_success_pings_api_host_with_bearer(): void
    {
        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);

        $result = Ionhour::jobBySlug('nightly-report')->success();

        $this->assertTrue($result->ok);
        $this->assertSame(200, $result->status);
        $this->assertFalse($result->created);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === self::API.'/signals/s/nightly-report'
            && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $request->hasHeader('User-Agent', 'ionhour-php/0.1.0'));
    }

    public function test_fail_start_and_payload_routes(): void
    {
        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);
        $job = Ionhour::jobBySlug('etl_job');

        $job->fail();
        $job->start();
        $job->success(['payload' => ['rows' => 3]]);

        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r->url() === self::API.'/signals/s/etl_job/fail');
        Http::assertSent(fn ($r) => $r->url() === self::API.'/signals/s/etl_job/start');
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->url() === self::API.'/signals/s/etl_job'
            && $r['rows'] === 3);
        Http::assertNotSent(fn ($r) => ! $r->hasHeader('Authorization', 'Bearer '.self::KEY));
    }

    public function test_create_adds_query_param_alongside_telemetry(): void
    {
        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);

        Ionhour::jobBySlug('etl', ['create' => true])->fail([
            'rid' => 'r1', 'duration_ms' => 42, 'host' => 'web-1', 'env' => 'prod', 'exit_code' => 2,
        ]);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return str_starts_with($request->url(), self::API.'/signals/s/etl/fail?')
                && $q === ['create' => '1', 'rid' => 'r1', 'duration' => '42', 'host' => 'web-1', 'env' => 'prod', 'exit_code' => '2'];
        });
    }

    public function test_create_false_omits_the_param(): void
    {
        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);

        Ionhour::jobBySlug('etl', ['create' => false])->success();

        Http::assertSent(fn ($r) => $r->url() === self::API.'/signals/s/etl');
    }

    public function test_created_from_201(): void
    {
        Http::fake(['*' => Http::response(['message' => 'OK', 'created' => true], 201)]);

        $result = Ionhour::jobBySlug('etl', ['create' => true])->success();

        $this->assertTrue($result->ok);
        $this->assertTrue($result->created);
        $this->assertSame(201, $result->status);
    }

    public function test_created_from_body_on_200(): void
    {
        Http::fake(['*' => Http::response(['message' => 'OK', 'created' => true], 200)]);

        $this->assertTrue(Ionhour::jobBySlug('etl')->success()->created);
    }

    public function test_token_pings_never_send_authorization_with_api_key_configured(): void
    {
        Http::fake(['*' => Http::response(['created' => true], 201)]);

        $job = Ionhour::registerJob('tok_123');
        $result = $job->success();
        $job->fail();
        $job->start();
        Ionhour::success('tok_123');

        $this->assertFalse($result->created);
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($r) => $r->hasHeader('Authorization'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/signals/s/'));
    }

    public function test_api_base_url_is_configurable_and_trimmed(): void
    {
        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);
        $this->useKey(self::KEY, 'https://example.test/api/');

        Ionhour::jobBySlug('etl')->success();

        Http::assertSent(fn ($r) => $r->url() === 'https://example.test/api/signals/s/etl');
    }

    public function test_api_key_is_trimmed(): void
    {
        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);
        $this->useKey("  ".self::KEY."\n");

        Ionhour::jobBySlug('etl')->success();

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer '.self::KEY));
    }

    public function test_defaults_come_from_package_config(): void
    {
        $this->assertSame(self::API, config('ionhour.api_base_url'));
    }

    /** @return array<string, array{0: string}> */
    public static function invalidSlugs(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Nightly'],
            'too long' => [str_repeat('a', 65)],
            'slash' => ['a/b'],
            'space' => ['a b'],
            'dot' => ['a.b'],
            'non-ascii' => ['ü'],
            'trailing newline' => ["etl\n"],
        ];
    }

    /** @dataProvider invalidSlugs */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSlugs')]
    public function test_rejects_invalid_slug(string $slug): void
    {
        $this->expectException(InvalidArgumentException::class);
        Ionhour::jobBySlug($slug);
    }

    public function test_accepts_slugs_at_the_limits(): void
    {
        foreach (['a', str_repeat('a', 64), 'nightly_job-2'] as $slug) {
            $this->assertNotNull(Ionhour::jobBySlug($slug));
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonBoolCreate(): array
    {
        return ['int 1' => [1], 'string' => ['yes'], 'string true' => ['true'], 'null' => [null]];
    }

    /** @dataProvider nonBoolCreate */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonBoolCreate')]
    public function test_non_bool_create_is_a_usage_error(mixed $create): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/create/');
        Ionhour::jobBySlug('etl', ['create' => $create]);
    }

    public function test_api_key_parameters_are_marked_sensitive(): void
    {
        $targets = [
            [IonhourManager::class, '__construct'],
            [\Ionhour\Laravel\Job::class, '__construct'],
            [\Ionhour\Laravel\Job::class, 'forSlug'],
        ];
        foreach ($targets as [$class, $method]) {
            $param = array_values(array_filter(
                (new \ReflectionMethod($class, $method))->getParameters(),
                fn (\ReflectionParameter $p) => $p->getName() === 'apiKey',
            ))[0];
            $this->assertNotEmpty(
                $param->getAttributes(\SensitiveParameter::class),
                "{$class}::{$method}(\$apiKey) must be #[\\SensitiveParameter]",
            );
        }
    }

    public function test_token_ping_403_envelope_yields_plain_status(): void
    {
        Http::fake(['*' => Http::response(
            ['statusCode' => 403, 'error' => 'x', 'code' => 'KEY_SCOPE_DENIED', 'message' => 'no'],
            403,
        )]);

        $result = Ionhour::registerJob('tok_123')->success();

        $this->assertSame('Ionhour ping returned HTTP 403', $result->error);
    }

    public function test_missing_api_key_is_a_usage_error(): void
    {
        $this->useKey('   ');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/IONHOUR_API_KEY/');
        Ionhour::jobBySlug('etl');
    }

    public function test_malformed_api_key_is_rejected_without_echoing_it(): void
    {
        $this->useKey("ionh_abc\r\nX-Injected: 1");

        try {
            Ionhour::jobBySlug('etl');
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString('ionh_abc', $e->getMessage());
        }
    }

    public function test_token_jobs_need_no_api_key(): void
    {
        $this->useKey(null);

        $this->assertNotNull(Ionhour::registerJob('tok_123'));
    }

    public function test_retries_429_then_succeeds(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'slow down'], 429)
            ->push(['message' => 'OK'], 200);

        $this->assertTrue(Ionhour::jobBySlug('etl')->success()->ok);
        Http::assertSentCount(2);
    }

    public function test_retries_5xx_then_succeeds(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'bad gateway'], 502)
            ->push(['message' => 'OK'], 200);

        $this->assertTrue(Ionhour::jobBySlug('etl')->success()->ok);
        Http::assertSentCount(2);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function envelopeErrors(): array
    {
        return [
            'VALIDATION_FAILED' => [400, 'VALIDATION_FAILED'],
            'MISSING_BEARER' => [401, 'MISSING_BEARER'],
            'SERVICE_KEY_INACTIVE' => [401, 'SERVICE_KEY_INACTIVE'],
            'KEY_SCOPE_DENIED' => [403, 'KEY_SCOPE_DENIED'],
            'INSUFFICIENT_ROLE' => [403, 'INSUFFICIENT_ROLE'],
            'READ_ONLY_KEY' => [403, 'READ_ONLY_KEY'],
            'FEATURE_DISABLED' => [403, 'FEATURE_DISABLED'],
            'NOT_FOUND' => [404, 'NOT_FOUND'],
        ];
    }

    /** @dataProvider envelopeErrors */
    #[\PHPUnit\Framework\Attributes\DataProvider('envelopeErrors')]
    public function test_never_retries_envelope_4xx_and_surfaces_code(int $status, string $code): void
    {
        Http::fake(['*' => Http::response(
            ['statusCode' => $status, 'error' => 'x', 'code' => $code, 'message' => 'nope'],
            $status,
        )]);

        $result = Ionhour::jobBySlug('etl', ['create' => true])->success();

        $this->assertFalse($result->ok);
        $this->assertSame($status, $result->status);
        $this->assertSame("Ionhour ping returned HTTP {$status} ({$code})", $result->error);
        Http::assertSentCount(1);
    }

    public function test_ignores_a_malformed_error_code(): void
    {
        Http::fake(['*' => Http::response(['code' => 'bad '.self::KEY], 403)]);

        $result = Ionhour::jobBySlug('etl')->success();

        $this->assertSame('Ionhour ping returned HTTP 403', $result->error);
    }

    public function test_api_key_is_scrubbed_from_transport_errors_and_logs(): void
    {
        config()->set('ionhour.retries', 1);
        $this->useKey(self::KEY);
        Log::spy();
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 7: proxy rejected Authorization: Bearer '.self::KEY
        ));

        $result = Ionhour::jobBySlug('etl')->fail();

        $this->assertFalse($result->ok);
        $this->assertStringNotContainsString(self::TAIL, (string) $result->error);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => $context['slug'] === 'etl'
                && $context['kind'] === 'fail'
                && ! array_key_exists('token', $context)
                && ! str_contains(json_encode($context), self::TAIL)
        );
    }

    public function test_debug_output_never_shows_the_api_key(): void
    {
        $job = Ionhour::jobBySlug('etl', ['create' => true]);
        $manager = $this->app->make(IonhourManager::class);

        ob_start();
        var_dump($job, $manager);
        $out = (string) ob_get_clean();

        $this->assertStringNotContainsString(self::TAIL, $out);
        $this->assertStringContainsString('etl', $out);
        $this->assertArrayNotHasKey('apiKey', $job->__debugInfo());
        $this->assertArrayNotHasKey('apiKey', $manager->__debugInfo());
    }

    public function test_run_sends_bearer_on_start_and_terminal_pings(): void
    {
        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);

        $value = Ionhour::jobBySlug('etl')->run(fn () => 7);

        $this->assertSame(7, $value);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), self::API.'/signals/s/etl/start'));
        Http::assertNotSent(fn ($r) => ! $r->hasHeader('Authorization', 'Bearer '.self::KEY));
    }
}
