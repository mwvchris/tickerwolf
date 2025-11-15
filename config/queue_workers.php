<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Worker Settings (Hybrid Option C)
    |--------------------------------------------------------------------------
    |
    | These settings define how your primary "default" queue worker should run.
    | They are *not* executed automatically from here — instead, they:
    |
    |   • Drive the recommended `queue:work` flags (for Docker / local)
    |   • Are used by QueueSupervisor to report / log your effective config
    |
    | In Docker, you’ll typically mirror these values in the
    | `tickerwolf-queue` service’s command line.
    |
    */

    'default' => [
        // Which connection + queue this worker is responsible for.
        'connection' => env('QUEUE_CONNECTION', 'database'),
        'queue'      => env('QUEUE_WORKER_QUEUE', 'default'),

        // Worker tuning knobs (mirrored in `php artisan queue:work` flags).
        'sleep'      => (int) env('QUEUE_WORKER_SLEEP', 3),
        'backoff'    => (int) env('QUEUE_WORKER_BACKOFF', 5),
        'max_jobs'   => (int) env('QUEUE_WORKER_MAX_JOBS', 25),
        'max_time'   => (int) env('QUEUE_WORKER_MAX_TIME', 240),
        'tries'      => (int) env('QUEUE_WORKER_TRIES', 3),
        'timeout'    => (int) env('QUEUE_WORKER_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supervisor Thresholds & Policies
    |--------------------------------------------------------------------------
    |
    | The QueueSupervisor command (queue:supervisor) uses these thresholds to:
    |
    |   • Log queue health periodically (via scheduler)
    |   • Decide when to call `queue:restart` automatically
    |
    | This is the "hybrid" piece – you still have a long-running worker in a
    | Docker service, but this supervisor nudges it to restart when backlog
    | grows too large or it’s been alive too long.
    |
    */

    'supervisor' => [
        // Soft backlog threshold: when exceeded, we log a warning.
        'backlog_soft' => (int) env('QUEUE_BACKLOG_SOFT', 1000),

        // Hard backlog threshold: when exceeded, we also trigger queue:restart.
        'backlog_hard' => (int) env('QUEUE_BACKLOG_HARD', 5000),

        // If last restart is older than this many minutes (and backlog is high),
        // the supervisor will call `queue:restart`.
        'restart_minutes' => (int) env('QUEUE_RESTART_MINUTES', 60),
    ],

];