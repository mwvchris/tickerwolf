<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\PolygonTickerService;
use Throwable;

class PolygonTickersIngest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Supports filtering and optional continuous polling.
     */
    protected $signature = 'polygon:tickers:ingest
                            {--market= : Optionally filter by market (e.g. stocks, crypto)}
                            {--type= : Optionally filter by ticker type}
                            {--date= : Point-in-time date (YYYY-MM-DD) to fetch tickers available on that date}
                            {--poll : Run a single polling cycle (deprecated: now exits after completion)}';

    /**
     * The console command description.
     */
    protected $description = 'Fetch all tickers from Polygon.io and persist them to the database (supports filtering).';

    protected PolygonTickerService $service;

    public function __construct(PolygonTickerService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $options = [];
        if ($this->option('market')) {
            $options['market'] = $this->option('market');
        }
        if ($this->option('type')) {
            $options['type'] = $this->option('type');
        }
        if ($this->option('date')) {
            $options['date'] = $this->option('date');
        }

        $this->info('Starting Polygon tickers ingestion...');
        Log::info('Polygon tickers ingestion started', ['options' => $options]);

        try {
            $result = $this->runIngestionCycle($options);

            $this->info('All tickers successfully ingested.');
            $this->info('Exiting Polygon tickers ingestion.');
            Log::info('Polygon tickers ingestion completed successfully', [
                'pages' => $result['pages'] ?? null,
                'inserted' => $result['inserted'] ?? null,
            ]);

            return Command::SUCCESS;
        } catch (Throwable $e) {
            Log::error('Polygon tickers ingestion failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error('Ingestion failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Run a single ingestion cycle and log results.
     */
    protected function runIngestionCycle(array $options): array
    {
        $start = microtime(true);
        $result = $this->service->ingestAll($options);
        $duration = round(microtime(true) - $start, 2);

        $this->info("Pages processed: {$result['pages']}");
        $this->info("Rows inserted: {$result['inserted']}");
        $this->info("Elapsed time: {$duration} sec");
        $this->info(str_repeat('-', 50));

        Log::info('Polygon tickers ingestion cycle complete', [
            'pages' => $result['pages'],
            'inserted' => $result['inserted'],
            'duration_sec' => $duration,
        ]);

        return $result;
    }
}