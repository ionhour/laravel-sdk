<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Tests;

use Ionhour\Laravel\Facades\Ionhour;
use Ionhour\Laravel\IonhourManager;

final class RedactionTest extends TestCase
{
    private const SECRET_TOKEN = 'super-secret-ping-token';

    private const SECRET_KEY = 'ionh_super_secret_key';

    public function test_job_debug_info_redacts_token_and_key(): void
    {
        $job = Ionhour::registerJob(self::SECRET_TOKEN, self::SECRET_KEY);

        $debug = $job->__debugInfo();

        $this->assertSame('<redacted>', $debug['token']);
        $this->assertSame('<redacted>', $debug['apiKey']);

        $exported = var_export($debug, true);
        $this->assertStringNotContainsString(self::SECRET_TOKEN, $exported);
        $this->assertStringNotContainsString(self::SECRET_KEY, $exported);
    }

    public function test_var_dump_of_job_does_not_leak_credentials(): void
    {
        $job = Ionhour::registerJob(self::SECRET_TOKEN, self::SECRET_KEY);

        ob_start();
        var_dump($job);
        $out = (string) ob_get_clean();

        $this->assertStringNotContainsString(self::SECRET_TOKEN, $out);
        $this->assertStringNotContainsString(self::SECRET_KEY, $out);
        $this->assertStringContainsString('<redacted>', $out);
    }

    public function test_job_debug_info_shows_null_key_when_absent(): void
    {
        $debug = Ionhour::registerJob(self::SECRET_TOKEN)->__debugInfo();

        $this->assertSame('<redacted>', $debug['token']);
        $this->assertNull($debug['apiKey']);
    }

    public function test_manager_debug_info_redacts_config_key(): void
    {
        config()->set('ionhour.api_key', self::SECRET_KEY);

        $manager = $this->app->make(IonhourManager::class);

        $this->assertSame('<redacted>', $manager->__debugInfo()['apiKey']);

        ob_start();
        var_dump($manager);
        $out = (string) ob_get_clean();

        $this->assertStringNotContainsString(self::SECRET_KEY, $out);
        $this->assertStringContainsString('<redacted>', $out);
    }

    public function test_manager_debug_info_shows_null_key_when_absent(): void
    {
        $manager = $this->app->make(IonhourManager::class);

        $this->assertNull($manager->__debugInfo()['apiKey']);
    }
}
