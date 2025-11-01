<?php

namespace App\Services\Compute\Indicators;

use App\Services\Compute\BaseIndicator;

/**
 * MFIIndicator
 *
 * Computes the Money Flow Index — a volume-weighted variant of RSI.
 *
 * Math summary:
 *   TP  = (High + Low + Close) / 3
 *   MF  = TP * Volume
 *   Positive MF = sum of MF on up days
 *   Negative MF = sum of MF on down days
 *   MFI = 100 - (100 / (1 + (PosMF / NegMF)))
 *
 * Characteristics:
 * - Identifies overbought/oversold conditions using both price and volume.
 * - Range: 0–100; >80 = overbought, <20 = oversold.
 *
 * Params:
 *   - period (int): lookback period (default: 14)
 */
class MFIIndicator extends BaseIndicator
{
    public string $name = 'mfi';
    public string $displayName = 'Money Flow Index';
    public bool $multiSeries = false;

    public array $defaults = [
        'period' => 14,
    ];

    public function compute(array $bars, array $params = []): array
    {
        $bars = $this->normalizeBars($bars);
        $opts = $this->opts($params);
        $period = (int)$opts['period'];

        $rows = [];
        $tp = array_map(fn($b) => ($b['h'] + $b['l'] + $b['c']) / 3, $bars);
        $mf = [];

        // Step 1: Compute Raw Money Flow (TP * Volume)
        foreach ($bars as $i => $b) {
            $mf[$i] = $tp[$i] * $b['v'];
        }

        // Step 2: Calculate MFI based on up/down price changes
        for ($i = $period; $i < count($bars); $i++) {
            $posMF = $negMF = 0;
            for ($j = $i - $period + 1; $j <= $i; $j++) {
                if ($tp[$j] > $tp[$j - 1]) {
                    $posMF += $mf[$j];
                } elseif ($tp[$j] < $tp[$j - 1]) {
                    $negMF += $mf[$j];
                }
            }

            $ratio = $negMF == 0 ? INF : ($posMF / $negMF);
            $mfi = 100 - (100 / (1 + $ratio));

            $rows[] = [
                't' => $bars[$i]['t'],
                'indicator' => "mfi_{$period}",
                'value' => round($mfi, 6),
                'meta' => json_encode(['pos_mf' => $posMF, 'neg_mf' => $negMF]),
            ];
        }

        return $rows;
    }
}