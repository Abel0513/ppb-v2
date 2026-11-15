<?php
namespace Tests\Unit\Ppb;

use App\Services\Ppb\Evaluation\EvaluationSalarySnapshot;
use PHPUnit\Framework\TestCase;

class EvaluationSalarySnapshotTest extends TestCase
{
    private function report(): array
    {
        return ['subject'=>['type'=>'legacy','id'=>2],'month'=>'2026-05','source_sha256'=>str_repeat('a',64),
            'native_attendance_video_supported'=>true,'current'=>[
                'salary'=>['short'=>100,'long'=>400,'video'=>0,'late'=>-50,'absent'=>-100,'total'=>350],
                'counts'=>['salary_short'=>1,'salary_long'=>1,'absences'=>1,'private_rows'=>999],
                'level'=>2,'rating'=>['total_score'=>60],'next_rating'=>['rating_level'=>2]]];
    }
    public function test_receipt_preserves_server_amounts_without_raw_records_or_identity_names(): void
    {
        $report=$this->report();$report['subject']['label']='Private name';
        $snapshot=(new EvaluationSalarySnapshot)->capture($report,['type'=>'legacy','id'=>2],'2026-05',str_repeat('a',64));
        self::assertSame($report['current']['salary'],$snapshot['salary']);
        self::assertSame(['salary_short'=>1,'salary_long'=>1,'absences'=>1],$snapshot['counts']);
        self::assertFalse($snapshot['payment_executed']);
        self::assertStringNotContainsString('Private name',json_encode($snapshot));
    }
    public function test_wrong_subject_month_or_unproven_source_is_rejected(): void
    {
        foreach(['subject','month','source_sha256','native_attendance_video_supported','amount'] as $field){
            $report=$this->report();
            if($field==='subject')$report['subject']['id']=3;
            elseif($field==='month')$report['month']='2026-06';
            elseif($field==='source_sha256')$report[$field]=str_repeat('b',64);
            elseif($field==='amount')$report['current']['salary']['total']='350';
            else $report[$field]=false;
            try{(new EvaluationSalarySnapshot)->capture($report,['type'=>'legacy','id'=>2],'2026-05',str_repeat('a',64));self::fail('Unproven snapshot must stop.');}
            catch(\RuntimeException $e){self::assertStringStartsWith('SALARY_SNAPSHOT_',$e->getMessage());}
        }
    }
}
