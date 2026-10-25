<?php

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('auth:prune-password-challenges')->daily();
Schedule::command('documents:process')->everyMinute()->withoutOverlapping();

Event::listen([ScheduledTaskSkipped::class, ScheduledTaskFinished::class], function (ScheduledTaskSkipped|ScheduledTaskFinished $event): void {
    if (! str_contains($event->task->command ?? '', 'documents:process')
        || ($event instanceof ScheduledTaskFinished && ! $event->task->skippedBecauseOverlapping)) {
        return;
    }

    Log::warning('Document scheduler execution skipped.', [
        'reason' => $event instanceof ScheduledTaskSkipped ? 'filter_or_pause' : 'overlap',
        'mutex_ttl_minutes' => $event->task->expiresAt,
    ]);
});

Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
    if (str_contains($event->task->command ?? '', 'documents:process')) {
        Log::error('Document scheduler execution failed.', [
            'exit_code' => $event->task->exitCode,
            'error' => class_basename($event->exception),
        ]);
    }
});
