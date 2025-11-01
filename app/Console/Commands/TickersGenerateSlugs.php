<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Ticker;
use App\Services\TickerSlugService;

class TickersGenerateSlugs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tickers:generate-slugs {--force : overwrite existing slugs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate slug field for tickers based on name';

    protected TickerSlugService $slugService;

    public function __construct(TickerSlugService $slugService)
    {
        parent::__construct();
        $this->slugService = $slugService;
    }
    
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Generating slugs for tickers...');

        $query = Ticker::query();

        if (! $this->option('force')) {
            $query->whereNull('slug');
        }

        $total = $query->count();
        $this->info("Processing {$total} records...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(500, function ($rows) use ($bar) {
            foreach ($rows as $ticker) {
                $slug = $this->slugService->slugFromName($ticker->name);
                if ($slug === null) {
                    // fallback to ticker itself as slug
                    $slug = strtolower($ticker->ticker);
                }
                $ticker->slug = $slug;
                $ticker->saveQuietly();
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info('Done. If collisions were possible you may wish to run tickers:resolve-slug-collisions.');
    }
    
}
