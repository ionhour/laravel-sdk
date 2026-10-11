<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Tests;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ionhour\Laravel\Facades\Ionhour;
use Ionhour\Laravel\IonhourManager;
use Ionhour\Laravel\Job;

final class SchedulerMacroTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['message' => 'OK'], 200)]);
    }

    public function test_macro_is_registered_and_chainable(): void
    {
        $this->assertTrue(Event::hasMacro('pingIonhour'));

        $event = $this->newScheduledEvent();

        $this->assertInstanceOf(Event::class, $event->pingIonhour('tok_sched'));
    }

    public function test_macro_pings_success_on_zero_exit_code(): void
    {
        $event = $this->newScheduledEvent()->pingIonhour('tok_sched');

        $event->exitCode = 0;
        $event->callAfterCallbacks($this->app);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/signals/ping/tok_sched')
            && ! str_contains($request->url(), '/fail'));
        Http::assertSentCount(1);
    }

    public function test_macro_pings_fail_on_nonzero_exit_code(): void
    {
        $event = $this->newScheduledEvent()->pingIonhour('tok_sched');

        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/signals/ping/tok_sched/fail'));
        Http::assertSentCount(1);
    }

    public function test_slug_macro_is_registered_and_chainable(): void
    {
        $this->assertTrue(Event::hasMacro('pingIonhourBySlug'));
        $this->assertInstanceOf(Event::class, $this->newScheduledEvent()->pingIonhourBySlug('nightly'));
    }

    public function test_slug_macro_pings_success_and_fail_with_bearer(): void
    {
        $this->useApiKey('ionh_sched_key');

        $ok = $this->newScheduledEvent()->pingIonhourBySlug('nightly', ['create' => true]);
        $ok->exitCode = 0;
        $ok->callAfterCallbacks($this->app);

        $failed = $this->newScheduledEvent()->pingIonhourBySlug('nightly');
        $failed->exitCode = 1;
        $failed->callAfterCallbacks($this->app);

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.ionhour.com/api/signals/s/nightly?create=1'
            && $r->hasHeader('Authorization', 'Bearer ionh_sched_key'));
        Http::assertSent(fn ($r) => $r->url() === 'https://api.ionhour.com/api/signals/s/nightly/fail'
            && $r->hasHeader('Authorization', 'Bearer ionh_sched_key'));
    }

    public function test_slug_macro_without_api_key_warns_instead_of_throwing(): void
    {
        $this->useApiKey(null);
        Log::spy();

        $event = $this->newScheduledEvent()->pingIonhourBySlug('nightly');
        $event->exitCode = 0;
        $event->callAfterCallbacks($this->app);

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => str_contains($message, 'skipped') && $context['slug'] === 'nightly'
        );
    }

    public function test_register_job_used_by_macro_still_returns_a_job(): void
    {
        // Sanity: the macro relies on the same registration surface consumers use.
        $this->assertInstanceOf(Job::class, Ionhour::registerJob('tok_sched'));
    }

    private function useApiKey(?string $key): void
    {
        config()->set('ionhour.api_key', $key);
        $this->app->forgetInstance(IonhourManager::class);
        Ionhour::clearResolvedInstances();
    }

    private function newScheduledEvent(): Event
    {
        return $this->app->make(Schedule::class)->exec('true');
    }
}
