<?php
namespace Tests\Unit\Ppb;
use App\Services\Ppb\Evaluation\EvaluationPerformance;
use PHPUnit\Framework\TestCase;
final class EvaluationPerformanceTest extends TestCase
{
    public function test_recent_ten_requires_ten_and_streak_stops_at_first_opposite_with_source_cap20(): void
    {
        $c=new EvaluationPerformance;$s=['regular'=>[['is_win'=>1],['is_win'=>2],['is_win'=>1]],'bonus'=>[]];
        $r=$c->summarize($s,array_fill(0,9,['is_win'=>1]));self::assertNull($r['recent10']['rate']);self::assertSame(67,$r['month']['rate']);self::assertNull($r['bonus']['rate']);
        $r=$c->summarize($s,array_merge(array_fill(0,4,['is_win'=>1]),array_fill(0,10,['is_win'=>2])));self::assertSame(40,$r['recent10']['rate']);self::assertSame(['outcome'=>1,'count'=>4],$r['streak']);
        self::assertSame(20,$c->summarize($s,array_fill(0,25,['is_win'=>2]))['streak']['count']);
    }
}
