<?php

namespace App\Bus;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class DatabaseBatchRepository implements BatchRepository
{
    protected $factory;
    protected $connection;
    protected $table;

    public function __construct(BatchFactory $factory, Connection $connection, string $table)
    {
        $this->factory = $factory;
        $this->connection = $connection;
        $this->table = $table;
    }

    /** ------------------------------------------------------------------
     *  Core required interface methods
     *  ------------------------------------------------------------------ */

    public function find(string $batchId): ?Batch
    {
        $record = $this->connection->table($this->table)->where('id', $batchId)->first();
        return $record ? $this->toBatch($record) : null;
    }

    public function get($limit = null, $before = null)
    {
        $query = $this->connection->table($this->table)->orderByDesc('created_at');

        if ($before) {
            $query->where('created_at', '<', Carbon::parse($before));
        }

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get()->map(fn($record) => $this->toBatch($record));
    }

    public function store(PendingBatch $batch): Batch
    {
        $now = Carbon::now();
        $id = (string) Str::uuid();

        $this->connection->table($this->table)->insert([
            'id' => $id,
            'name' => $batch->name,
            'total_jobs' => 0,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'failed_job_ids' => json_encode([]),
            'options' => serialize($batch->options),
            'created_at' => $now,
            'cancelled_at' => null,
            'finished_at' => null,
        ]);

        // Return a new Batch instance using the factory
        return $this->factory->make(
            $this,
            $id,
            $batch->name,
            0,
            0,
            0,
            [],
            $batch->options,
            $now->toImmutable(),
            null,
            null
        );
    }


    public function incrementTotalJobs(string $batchId, int $amount): void
    {
        $this->connection->table($this->table)->where('id', $batchId)->increment('total_jobs', $amount);
    }

    public function decrementPendingJobs(string $batchId, string $jobId): void
    {
        $this->connection->table($this->table)
            ->where('id', $batchId)
            ->decrement('pending_jobs', 1);
    }

    public function incrementFailedJobs(string $batchId, string $jobId): void
    {
        $record = $this->connection->table($this->table)->where('id', $batchId)->first();

        $failedJobIds = $record ? json_decode($record->failed_job_ids, true) : [];
        $failedJobIds[] = $jobId;

        $this->connection->table($this->table)->where('id', $batchId)->update([
            'failed_jobs' => DB::raw('failed_jobs + 1'),
            'failed_job_ids' => json_encode($failedJobIds),
        ]);
    }

    public function markAsFinished(string $batchId): void
    {
        $this->connection->table($this->table)->where('id', $batchId)->update([
            'finished_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    public function cancel(string $batchId): void
    {
        $this->connection->table($this->table)->where('id', $batchId)->update([
            'cancelled_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    public function delete(string $batchId): void
    {
        $this->connection->table($this->table)->where('id', $batchId)->delete();
    }

    /**
     * Run the given callback within a transaction.
     */
    public function transaction(\Closure $callback)
    {
        try {
            if (! $this->connection->getPdo()) {
                $this->connection->reconnect();
            }
        } catch (\Throwable $e) {
            Log::warning('Database reconnect before transaction failed: ' . $e->getMessage());
            $this->connection->reconnect();
        }

        return $this->connection->transaction($callback);
    }


    /**
     * Roll back the most recent database transaction.
     */
    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    public function prune(Carbon $before): int
    {
        return $this->connection->table($this->table)
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $before->toDateTimeString())
            ->delete();
    }

    /** ------------------------------------------------------------------
     *  Internal helper
     *  ------------------------------------------------------------------ */

    protected function toBatch($record)
    {
        return $this->factory->make(
            $this,
            $record->id,
            $record->name,
            $record->total_jobs,
            $record->pending_jobs,
            $record->failed_jobs,
            json_decode($record->failed_job_ids, true) ?? [],
            json_decode($record->options, true) ?? [],
            Carbon::parse($record->created_at)->toImmutable(),
            $record->cancelled_at ? Carbon::parse($record->cancelled_at)->toImmutable() : null,
            $record->finished_at ? Carbon::parse($record->finished_at)->toImmutable() : null
        );
    }

}