<?php
namespace App\Services\Ppb;

use App\Models\PpbAssignment;
use App\Models\PpbGame;

/** Optimistic state tokens, not authentication or a substitute for row locks. */
final class ScheduleVersion
{
    private static function values($model,array $fields): array
    {
        return array_map(static fn($field)=>$model->getRawOriginal($field) === null ? null : (string)$model->getRawOriginal($field),$fields);
    }
    public static function assignment(PpbAssignment $assignment): string
    {
        return hash('sha256',json_encode(['assignment-v1',self::values($assignment,[
            'id','game_id','original_analyst_id','current_analyst_id','kind','slot_no','status','notes','updated_at',
        ])],JSON_THROW_ON_ERROR));
    }
    public static function game(PpbGame $game,?iterable $assignments=null): string
    {
        $assignments ??= $game->relationLoaded('assignments') ? $game->assignments : $game->assignments()->orderBy('id')->get();
        $children=[];foreach($assignments as $assignment)$children[(int)$assignment->id]=self::assignment($assignment);ksort($children);
        return hash('sha256',json_encode(['game-v1',self::values($game,[
            'id','month_key','game_type','game_number','active_number','sport','league','home_team','away_team',
            'starts_at','recommended_at','status','notes','matchup_mode','matchup_text','fixture_id',
            'play_type','play_value','market_type','reviewer_analyst_id','has_long_article',
            'wp_locked_at','cancelled_at','cancel_reason','updated_at',
        ]),$children],JSON_THROW_ON_ERROR));
    }
    public static function assert(string $expected,string $current): void
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D',$expected)&&hash_equals($current,$expected),409,'排班資料已變更，請重新讀取後操作。');
    }
}
