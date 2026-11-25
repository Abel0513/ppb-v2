<?php
namespace Tests\Unit\Ppb;
use App\Models\PpbAnalyst;
use PHPUnit\Framework\TestCase;
class AnalystMasterTest extends TestCase
{
    public function test_stored_color_cannot_inject_css_or_borrow_another_profiles_background():void
    {
        $model=new PpbAnalyst;$model->setRawAttributes(['color'=>'#123ABC'],true);
        self::assertSame(['background'=>null,'color'=>'#123ABC'],$model->scheduleColors(['background'=>'#FF0000','color'=>'#000000']));
        $model->setRawAttributes(['color'=>'#123ABC','background'=>'#00FF00'],true);
        self::assertSame(['background'=>'#00FF00','color'=>'#123ABC'],$model->scheduleColors(['background'=>'#FF0000','color'=>'#000000']));
        $model->setRawAttributes(['color'=>'red;display:none'],true);self::assertNull($model->scheduleColors());
        self::assertSame(['background'=>'#FF0000','color'=>'#000000'],$model->scheduleColors(['background'=>'#FF0000','color'=>'#000000']));
        $model->setRawAttributes(['background'=>'#00AA00'],true);
        self::assertSame(['background'=>'#00AA00','color'=>'#000000'],$model->scheduleColors(['background'=>'#FF0000','color'=>'#000000']));
        $model->setRawAttributes(['background'=>'red;display:none'],true);self::assertNull($model->scheduleColors());
    }
    public function test_distinct_confirmed_master_fields_are_retained_without_login_account():void
    {
        $model=new PpbAnalyst(['name'=>'Synthetic analyst','display_name'=>'Public label','user_type'=>1,'account_id'=>null,
            'color'=>'#12Ab34','background'=>'#FFE5A0','wp_photo'=>123,'author_id'=>0,'line_id'=>'synthetic-private-id','line_name'=>'Synthetic private name','rating_level'=>3,'analyst_level'=>1]);
        self::assertSame('#12Ab34',$model->color);self::assertSame('#FFE5A0',$model->background);
        self::assertSame(123,$model->wp_photo);self::assertSame(0,$model->author_id);self::assertSame(3,$model->rating_level);self::assertSame(1,$model->analyst_level);
        self::assertNull($model->account_id);self::assertSame('Public label',$model->display_name);
        self::assertSame('synthetic-private-id',$model->line_id);self::assertSame('Synthetic private name',$model->line_name);
        self::assertArrayNotHasKey('line_id',$model->toArray());self::assertArrayNotHasKey('line_name',$model->toArray());
    }
}
