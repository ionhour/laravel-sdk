<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ionhour\Laravel\Facades\Ionhour;
use Ionhour\Laravel\IonhourManager;

final class RedactionTest extends TestCase
{
    private const SECRET_TOKEN = 'super-secret-ping-token';

    public function test_job_debug_info_redacts_token(): void
    {
        $debug = Ionhour::registerJob(self::SECRET_TOKEN)->__debugInfo();

        $this->assertSame('<redacted>', $debug['token']);
        $this->assertArrayNotHasKey('apiKey', $debug);
        $this->assertStringNotContainsString(self::SECRET_TOKEN, var_export($debug, true));
    }

    public function test_var_dump_of_job_does_not_leak_the_token(): void
    {
        $job = Ionhour::registerJob(self::SECRET_TOKEN);

        ob_start();
        var_dump($job);
        $out = (string) ob_get_clean();

        $this->assertStringNotContainsString(self::SECRET_TOKEN, $out);
        $this->assertStringContainsString('<redacted>', $out);
    }

    public function test_manager_debug_info_has_no_api_key(): void
    {
        $manager = $this->app->make(IonhourManager::class);

        $this->assertArrayNotHasKey('apiKey', $manager->__debugInfo());
    }

    public function test_failed_ping_logs_a_truncated_token_not_the_full_token(): void
    {
        // A failing ping triggers the automatic warning logger. The token IS the
        // job's credential, so it must be truncated in the log.
        Http::fake(['*' => Http::response(['message' => 'bad request'], 400)]);
        Log::spy();

        Ionhour::registerJob(self::SECRET_TOKEN)->success();

        // 'super-secret-ping-token' -> 8-char prefix + ellipsis, never the full token.
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => $context['token'] === 'super-se…'
                && ! str_contains($context['token'], self::SECRET_TOKEN)
        );
    }

    public function test_transport_error_scrubs_token_from_result_and_log(): void
    {
        // cURL/Guzzle transport messages embed the full request URL, which
        // carries the token. It must be scrubbed from PingResult->error and the
        // logged 'error' context, not just the dedicated 'token' field.
        config()->set('ionhour.retries', 1);           // single attempt, no backoff sleeps
        Log::spy();

        $url = self::BASE.'/signals/ping/'.self::SECRET_TOKEN;
        Http::fake(fn () => throw new ConnectionException(
            "cURL error 6: Could not resolve host for {$url}"
        ));

        $result = Ionhour::registerJob(self::SECRET_TOKEN)->success();

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('super-se…', (string) $result->error);
        $this->assertStringNotContainsString(self::SECRET_TOKEN, (string) $result->error);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => str_contains($context['error'], 'super-se…')
                && ! str_contains($context['error'], self::SECRET_TOKEN)
        );
    }

    public function test_transport_error_scrubs_url_encoded_token(): void
    {
        // A token with a URL-unsafe char appears percent-encoded in the request
        // path, so the encoded form is what a transport message embeds.
        config()->set('ionhour.retries', 1);

        $token = 'tok#secret-value';
        $encoded = rawurlencode($token);               // tok%23secret-value
        $this->assertNotSame($token, $encoded, 'sanity: token must encode differently.');

        $url = self::BASE.'/signals/ping/'.$encoded;
        Http::fake(fn () => throw new ConnectionException(
            "cURL error 7: Failed to connect to {$url}"
        ));

        $result = Ionhour::registerJob($token)->success();

        $this->assertFalse($result->ok);
        // Neither the raw token nor its URL-encoded form survives.
        $this->assertStringNotContainsString($token, (string) $result->error);
        $this->assertStringNotContainsString($encoded, (string) $result->error);
        $this->assertStringContainsString('tok#secr…', (string) $result->error);
    }
}
