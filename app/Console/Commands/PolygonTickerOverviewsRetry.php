<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\PolygonTickerOverviewService;
use App\Models\Ticker;

class PolygonTickerOverviewsRetry extends Command
{
    /**
     * The name and signature of the console command.
     *
     * You can run it with:
     * php artisan polygon:ticker-overviews:retry-failed
     */
    protected $signature = 'polygon:ticker-overviews:retry-failed
                            {--clear : clear the failed_ticker_overviews table after successful retries}
                            {--limit=0 : maximum number of failed tickers to retry (0 = all)}';

    /**
     * The console command description.
     */
    protected $description = 'Retry failed Polygon ticker overview ingestions from failed_ticker_overviews table.';

    protected PolygonTickerOverviewService $service;

    public function __construct(PolygonTickerOverviewService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $clearAfter = $this->option('clear');

        $failedTickers = DB::table('failed_ticker_overviews')
            ->orderBy('id')
            ->when($limit > 0, fn($q) => $q->limit($limit))
            ->get();

        if ($failedTickers->isEmpty()) {
            $this->warn('No failed ticker log found. Exiting.');
            return Command::SUCCESS;
        }

        $this->info("Retrying {$failedTickers->count()} failed ticker overviews...");

        $bar = $this->output->createProgressBar($failedTickers->count());
        $bar->start();

        $retryCount = 0;
        foreach ($failedTickers as $failed) {
            $ticker = Ticker::where('ticker', $failed->ticker)->first();

            if (!$ticker) {
                Log::warning("Retry skipped: ticker not found in DB [{$failed->ticker}]");
                $bar->advance();
                continue;
            }

            try {
                $this->service->processSingleTicker($ticker);
                $retryCount++;

                // remove from failed log if successful
                DB::table('failed_ticker_overviews')->where('id', $failed->id)->delete();

            } catch (\Throwable $e) {
                Log::error("Retry failed for {$ticker->ticker}: " . $e->getMessage());
            }

            $bar->advance();
            usleep(200000); // 0.2s pause between retries to avoid rapid API hits
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Successfully retried {$retryCount} tickers.");

        if ($clearAfter) {
            DB::table('failed_ticker_overviews')->truncate();
            $this->info('Cleared failed_ticker_overviews table after retries.');
        }

        return Command::SUCCESS;
    }
}