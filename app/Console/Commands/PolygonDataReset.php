<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PolygonDataReset extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage:
     * php artisan polygon:data:reset
     * php artisan polygon:data:reset --force
     */
    protected $signature = 'polygon:data:reset
                            {--force : Run without confirmation prompt (useful for automation)}';

    /**
     * The console command description.
     */
    protected $description = 'Completely reset Polygon-related tables (tickers, overviews, histories, etc.) for a clean re-ingestion.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $force = $this->option('force');

        $this->warn('This will DELETE ALL Polygon-related data:');
        $this->line('- tickers');
        $this->line('- ticker_overviews');
        $this->line('- ticker_price_histories');
        $this->line('- ticker_news_items (if exists)');
        $this->line('- failed_ticker_overviews (if exists)');
        $this->newLine();

        if (!$force && !$this->confirm('Are you sure you want to continue?')) {
            $this->info('Aborted.');
            return Command::SUCCESS;
        }

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            $tables = [
                'ticker_price_histories',
                'ticker_news_items',
                'ticker_overviews',
                'failed_ticker_overviews',
                'tickers',
            ];

            foreach ($tables as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->truncate();
                    $this->info("Truncated: {$table}");
                    Log::channel('polygon')->info("Truncated table: {$table}");
                } else {
                    $this->warn("Table not found: {$table}");
                }
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $this->newLine();
            $this->info('Polygon data reset complete.');

        } catch (\Throwable $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $this->error('Error during reset: ' . $e->getMessage());
            Log::channel('polygon')->error('Polygon data reset failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
