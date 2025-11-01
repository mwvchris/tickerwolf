<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * MACDIndicator
 *
 * Computes the MACD oscillator — difference between two EMAs of price.
 *
 * Math summary:
 *   MACD = EMA_fast(C) - EMA_slow(C)
 *   Signal = EMA(MACD, signal_period)
 *   Histogram = MACD - Signal
 *
 * Characteristics:
 * - Tracks the convergence/divergence of short vs long EMAs.
 * - Histogram visually represents momentum.
 * - Common settings: fast=12, slow=26, signal=9.
 */
class MACDIndicator extends BaseIndicator
{
    public string $name = 'macd';
    public string $displayName = 'Moving Average Convergence Divergence';
    public bool $multiSeries = true;

    public array $defaults = [
        'fast'   => 12,
        'slow'   => 26,
        'signal' => 9,
    ];

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);

        $fast = (int)$opts['fast'];
        $slow = (int)$opts['slow'];
        $sig  = (int)$opts['signal'];

        $closes = array_column($bars, 'c');

        // Compute fast and slow EMAs
        $emaFast = $this->ema($closes, $fast);
        $emaSlow = $this->ema($closes, $slow);

        $n = count($closes);
        $macd = array_fill(0, $n, null);

        // MACD = EMA_fast - EMA_slow
        for ($i = 0; $i < $n; $i++) {
            if ($emaFast[$i] === null || $emaSlow[$i] === null) continue;
            $macd[$i] = $emaFast[$i] - $emaSlow[$i];
        }

        // Determine first valid MACD index
        $startIdx = null;
        for ($i = 0; $i < $n; $i++) {
            if ($macd[$i] !== null) {
                $startIdx = $i;
                break;
            }
        }

        $signalArr = array_fill(0, $n, null);
        $histArr   = array_fill(0, $n, null);

        // Compute signal line (EMA of MACD) and histogram
        if ($startIdx !== null) {
            $macdValid = array_slice($macd, $startIdx);
            $sigSeries = $this->ema($macdValid, $sig);
            foreach ($sigSeries as $k => $sv) {
                $i = $k + $startIdx;
                if ($sv === null || $macd[$i] === null) continue;
                $signalArr[$i] = $sv;
                $histArr[$i]   = $macd[$i] - $sv;
            }
        }

        // Build output rows (MACD in value, signal/histo in meta)
        $outRows = [];
        for ($i = 0; $i < $n; $i++) {
            if ($macd[$i] === null) continue;
            $meta = json_encode([
                'signal'    => $signalArr[$i],
                'histogram' => $histArr[$i],
            ]);
            $outRows[] = [
                't' => $bars[$i]['t'],
                'indicator' => 'macd',
                'value' => (float)$macd[$i],
                'meta' => $meta,
            ];
        }

        return $outRows;
    }
}