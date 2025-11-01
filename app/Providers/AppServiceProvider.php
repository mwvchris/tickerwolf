<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Bus\BatchRepository;
use App\Bus\DatabaseBatchRepository;
use Illuminate\Bus\BatchFactory;
use Illuminate\Support\Facades\DB;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Explicit binding for DatabaseBatchRepository
        $this->app->bind(DatabaseBatchRepository::class, function ($app) {
            return new DatabaseBatchRepository(
                $app->make(BatchFactory::class),
                DB::connection(),
                config('queue.batching.table', 'job_batches')
            );
        });

        // Override Laravel's default batch repository
        $this->app->extend(BatchRepository::class, function ($service, $app) {
            return $app->make(DatabaseBatchRepository::class);
        });
    }

    public function boot(): void
    {
        //
    }
}