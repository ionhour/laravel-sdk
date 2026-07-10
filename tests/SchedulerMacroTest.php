<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Tests;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Ionhour\Laravel\Facades\Ionhour;
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

    public function test_macro_forwards_api_key_to_the_ping(): void
    {
        $event = $this->newScheduledEvent()->pingIonhour('tok_sched', 'ionh_sched');

        $event->exitCode = 0;
        $event->callAfterCallbacks($this->app);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ionh_sched'));
    }

    public function test_register_job_used_by_macro_still_returns_a_job(): void
    {
        // Sanity: the macro relies on the same registration surface consumers use.
        $this->assertInstanceOf(Job::class, Ionhour::registerJob('tok_sched'));
    }

    private function newScheduledEvent(): Event
    {
        return $this->app->make(Schedule::class)->exec('true');
    }
}
