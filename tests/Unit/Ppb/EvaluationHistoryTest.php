<?php
namespace Tests\Unit\Ppb;
use App\Services\Ppb\Evaluation\{EvaluationService,EvaluationSubjects,LegacyReader,LegacyMonthSelector,EvaluationReport,LegacyScheduleCalculator,LegacyRatingCalculator};
use PHPUnit\Framework\TestCase;
final class EvaluationHistoryTest extends TestCase
{
    public function test_overlapping_history_drift_stops_before_any_v2_query_or_write(): void
    {
        $first=['profile'=>['id'=>2,'rating_level'=>2],'pick_columns'=>['id','recommended_time','score_delivery_time'],'picks'=>[[51,'2026-04-15 12:00:00',60]],'attendance'=>[],'evaluations'=>[],'video_counts'=>['2026-04'=>0]];
        $second=$first;$second['picks'][0][2]=50;
        $reader=new class([$first,$second]) extends LegacyReader{
            public function __construct(private array $responses){}
            public function query(array $query):array{return ['transaction_isolation'=>'REPEATABLE-READ','snapshot'=>array_replace(array_shift($this->responses),['format'=>'ppb-evaluation-source-v1','analyst_id'=>$query['analyst_id'],'month'=>$query['month'],'transactional_tables_verified'=>7,'source_sha256'=>str_repeat('a',64)])];}
        };
        $service=new EvaluationService($reader,new EvaluationSubjects($reader),new LegacyMonthSelector,new EvaluationReport(new LegacyScheduleCalculator,new LegacyRatingCalculator),new LegacyRatingCalculator);
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('LEGACY_HISTORY_CHANGED_DURING_READ');
        $service->load(['type'=>'legacy','id'=>2,'native_id'=>null],'2026-05');
    }
    public function test_wrong_analyst_or_month_response_is_not_accepted_as_a_source_snapshot(): void
    {
        $reader=new class extends LegacyReader{
            public function query(array $query):array{return ['transaction_isolation'=>'REPEATABLE-READ','snapshot'=>['format'=>'ppb-evaluation-source-v1','analyst_id'=>99,'month'=>'2026-05','profile'=>['id'=>99],'transactional_tables_verified'=>7,'source_sha256'=>str_repeat('a',64)]];}
        };
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('LEGACY_SNAPSHOT_SCOPE_NOT_PROVEN');$reader->snapshot(2,'2026-05');
    }

}
