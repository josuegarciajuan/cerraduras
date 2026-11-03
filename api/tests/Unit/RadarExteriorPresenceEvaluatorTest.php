<?php
declare(strict_types=1);
require __DIR__ . '/../../src/Support/Autoload.php';
use App\Domain\Sensors\RadarExteriorPresenceEvaluator;
$P=0;$F=0; function pass(string $m):void{global $P;$P++;echo "  ✅ $m\n";} function fail(string $m):void{global $F;$F++;echo "  ❌ $m\n";} function eq(string $m,mixed $a,mixed $b):void{$a===$b?pass($m):fail("$m — expected ".var_export($b,true).", got ".var_export($a,true));}
$reports=[
 ['value'=>'ABSENT','received_at'=>'2026-11-03 10:00:01.000','config_key'=>'a','marker'=>'p1|s|1|ABSENT|push'],
 ['value'=>'ABSENT','received_at'=>'2026-11-03 10:00:02.000','config_key'=>'a','marker'=>'p1|s|2|ABSENT|push'],
];
$r=RadarExteriorPresenceEvaluator::evaluate($reports,'2026-11-03 10:00:00.000','a',fn()=>strtotime('2026-11-03 10:00:03 UTC'));
eq('dos ausencias reales limpian exterior',$r['status'],'clear'); eq('silencio no es clear',RadarExteriorPresenceEvaluator::evaluate([],'2026-11-03 10:00:00.000','a')['status'],'inconclusive');
$r=RadarExteriorPresenceEvaluator::evaluate([...$reports,['value'=>'PRESENT','received_at'=>'2026-11-03 10:00:04.000','config_key'=>'a','marker'=>'p1|s|3|PRESENT|push']],'2026-11-03 10:00:00.000','a'); eq('present detectado',$r['status'],'detected');
$r=RadarExteriorPresenceEvaluator::reentry([['value'=>'PRESENT','received_at'=>'2026-11-03 10:00:05.000','config_key'=>'a','marker'=>'p1|s|4|PRESENT|push']],'2026-11-03 10:00:04.000','a'); eq('reentrada exige present posterior',$r['confirmed'],true);
$r=RadarExteriorPresenceEvaluator::reentry([['value'=>'PRESENT','received_at'=>'2026-11-03 10:00:03.000','config_key'=>'old','marker'=>'old']],'2026-11-03 10:00:04.000','a'); eq('config vieja no confirma',$r['confirmed'],false);
echo "Radar exterior F111: $P passed, $F failed\n"; exit($F?1:0);
