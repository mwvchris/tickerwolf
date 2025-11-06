<?php

namespace App\Services\Validation\Validators;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

/**
 * ============================================================================
 *  MetricsValidator  (v1.2 — Schema-Aware: ticker_feature_metrics)
 * ============================================================================
 *
 * 🔍 Purpose:
 *   Validate numerical and relational integrity of aggregated
 *   ticker metrics from the `ticker_feature_metrics` table.
 *
 * 🧩 Table:
 *   - ticker_feature_metrics
 *     • ticker_id (FK)
 *     • t (date)
 *     • sharpe_60, volatility_30, drawdown, beta_60, momentum_10
 *
 * ⚙️ Evaluation Dimensions:
 * ----------------------------------------------------------------------------
 *   1️⃣ Completeness — null or NaN values
 *   2️⃣ Flatlines — zero variance over time
 *   3️⃣ Outliers — extreme values via z-score
 *   4️⃣ Correlation Consistency — expected positive relationships
 *
 * 📊 Scoring:
 *   • Health = 1 - Σ(weighted severity)
 *   • Weights configurable via config/data_validation.metrics
 *   • Status thresholds:
 *       success ≥ 0.9, warning 0.7–0.9, error < 0.7
 * ============================================================================
 */
class MetricsValidator
{
    public function run(array $context): array
    {
        $tickerId = $context['ticker_id'] ?? null;
        if (!$tickerId) {
            return ['status'=>'error','health'=>0.0,'issues'=>['missing_context'=>true]];
        }

        $rows = DB::table('ticker_feature_metrics')
            ->where('ticker_id', $tickerId)
            ->select(['t','sharpe_60','volatility_30','drawdown','beta_60','momentum_10'])
            ->orderBy('t','asc')
            ->get();

        $count = $rows->count();
        $cfg = Config::get('data_validation.metrics', [
            'min_rows'            => 10,
            'outlier_z_threshold' => 6.0,
            'weights'             => [
                'missing'     => 0.3,
                'flat'        => 0.3,
                'outliers'    => 0.3,
                'correlation' => 0.1,
            ],
        ]);

        if ($count === 0) {
            return [
                'ticker_id' => $tickerId,
                'status'    => 'error',
                'health'    => 0.0,
                'issues'    => ['no_metrics'=>true],
            ];
        }

        if ($count < $cfg['min_rows']) {
            return [
                'ticker_id'=>$tickerId,
                'status'=>'insufficient',
                'health'=>0.0,
                'issues'=>['insufficient_rows'=>$count],
            ];
        }

        // Collect metric arrays
        $columns = ['sharpe_60','volatility_30','drawdown','beta_60','momentum_10'];
        $values = [];
        foreach ($columns as $col) {
            $values[$col] = $rows->pluck($col)->map(fn($v)=>is_numeric($v)?(float)$v:null)->all();
        }

        $issues = ['missing'=>[],'flat'=>[],'outliers'=>[],'correlation'=>[]];

        // Missing
        foreach ($values as $k=>$vals){
            if (in_array(null,$vals,true)) $issues['missing'][]=$k;
        }

        // Flatlines
        foreach ($values as $k=>$vals){
            $unique=array_unique(array_filter($vals,'is_numeric'));
            if(count($unique)<=1) $issues['flat'][]=$k;
        }

        // Outliers
        foreach ($values as $k=>$vals){
            $vals=array_filter($vals,'is_numeric');
            if(count($vals)<5) continue;
            $mean=array_sum($vals)/count($vals);
            $var=array_sum(array_map(fn($v)=>pow($v-$mean,2),$vals))/count($vals);
            $std=sqrt($var);
            if($std==0) continue;
            foreach($vals as $v){
                if(abs(($v-$mean)/$std)>$cfg['outlier_z_threshold']){
                    $issues['outliers'][]=$k;
                    break;
                }
            }
        }

        // Correlation heuristics
        $pairs=[['sharpe_60','volatility_30'],['momentum_10','drawdown']];
        foreach($pairs as[$a,$b]){
            if(!isset($values[$a],$values[$b]))continue;
            $A=$values[$a];$B=$values[$b];
            $n=min(count($A),count($B));
            if($n<5)continue;
            $corr=$this->pearsonCorrelation($A,$B,$n);
            if($corr<-0.5)$issues['correlation'][]="$a<->$b";
        }

        // Health computation
        $weights=$cfg['weights'];$severity=[];
        foreach(['missing','flat','outliers','correlation']as$t){
            $c=count(array_unique($issues[$t]));
            $severity[$t]=min(1.0,$c/max(1,count($values)))*($weights[$t]??0);
        }
        $health=max(0.0,round(1-array_sum($severity),4));
        $status=$health<0.7?'error':($health<0.9?'warning':'success');

        return[
            'ticker_id'=>$tickerId,
            'status'=>$status,
            'health'=>$health,
            'issues'=>array_filter($issues,fn($v)=>!empty($v)),
            'severity'=>$severity,
        ];
    }

    protected function pearsonCorrelation(array $a,array $b,int $n):float
    {
        $meanA=array_sum($a)/$n;
        $meanB=array_sum($b)/$n;
        $num=0;$denA=0;$denB=0;
        for($i=0;$i<$n;$i++){
            $da=$a[$i]-$meanA;
            $db=$b[$i]-$meanB;
            $num+=$da*$db;
            $denA+=$da**2;
            $denB+=$db**2;
        }
        return($denA>0&&$denB>0)?$num/sqrt($denA*$denB):0.0;
    }
}