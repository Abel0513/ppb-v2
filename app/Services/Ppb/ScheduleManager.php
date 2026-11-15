<?php
namespace App\Services\Ppb;

use App\Models\PpbAssignment;
use App\Models\PpbGame;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * PPB B1. Every number operation holds a scope-row lock and a DB transaction.
 * Old submissions are linked to ppb_games.id, NEVER the display label G/E number.
 * No WordPress network action is taken here.
 */
class ScheduleManager
{
    private function reject(string $message): never {
        throw ValidationException::withMessages(['schedule'=>$message]);
    }
    private function scope(string $month, int $type): void {
        DB::table('ppb_schedule_scopes')->insertOrIgnore(['month_key'=>$month,'game_type'=>$type]);
        DB::table('ppb_schedule_scopes')->where('month_key',$month)
            ->where('game_type',$type)->lockForUpdate()->first();
    }
    private function active(string $month,int $type) {
        return PpbGame::where('month_key',$month)->where('game_type',$type)
            ->whereNotNull('active_number');
    }
    // Current reads are required even when a caller already opened a
    // REPEATABLE READ transaction before invoking this nested service.
    private function verifiedWp(PpbGame $game): bool {
        return $game->wp_locked_at!==null || $game->publications()->where('status','published')
            ->whereNotNull('verified_at')->orderBy('id')->lockForUpdate()->first(['id'])!==null;
    }
    private function unresolvedWp(PpbGame $game): bool {
        return $game->publications()->where('status','!=','failed')
            ->where(fn($q)=>$q->where('status','!=','published')->orWhereNull('verified_at'))
            ->orderBy('id')->lockForUpdate()->first(['id'])!==null;
    }
    private function assignmentHistory(PpbAssignment $assignment): bool {
        return $assignment->substitutions()->orderBy('id')->lockForUpdate()->first(['id'])!==null
            || DB::table('ppb_submissions')->where('assignment_id',$assignment->id)
                ->orderBy('id')->lockForUpdate()->first(['id'])!==null;
    }
    private function attachedHistory(PpbGame $game): bool {
        $assignments=$game->assignments()->orderBy('id')->lockForUpdate()->get(['id']);
        if ($game->submissions()->orderBy('id')->lockForUpdate()->first(['id'])!==null) return true;
        if ($assignments->isNotEmpty() && DB::table('ppb_substitutions')->whereIn('assignment_id',$assignments->pluck('id')->all())
            ->orderBy('id')->lockForUpdate()->first(['id'])!==null) return true;
        return DB::table('ppb_schedule_events')->where('game_id',$game->id)->where('event','legacy_archived_pick')
            ->orderBy('id')->lockForUpdate()->first(['id'])!==null;
    }
    private function event(int $gameId,string $action,array $details=[]): void {
        DB::table('ppb_schedule_events')->insert([
            'game_id'=>$gameId,'actor_id'=>Auth::guard('ppb')->id(),
            'event'=>$action,'detail_json'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'created_at'=>now(),
        ]);
    }
    /** Inserts before next occupied slot, stopping at a released number or any locked WP row. */
    private function openNumber(string $month,int $type,int $target): void {
        if ($target < 1) $this->reject('插入位置無效。');
        $occupied = $this->active($month,$type)
            ->where('active_number','>=',$target)->orderBy('active_number')->lockForUpdate()->get();
        $toShift=[];
        $cursor=$target;
        foreach ($occupied as $game) {
            if ($game->active_number !== $cursor) break; // available gap
            if ($this->verifiedWp($game) || $this->unresolvedWp($game)) {
                $this->reject('插入位置後有已上傳或待查 WordPress 的賽事，無法順延；請新增至最後。');
            }
            // PPB INS1: submitted, substituted and archived legacy exception rows never move.
            if ($this->attachedHistory($game)) {
                $this->reject('插入位置後存在已有投稿、代班或歷史例外的賽事，無法安全順延；請新增至最後。');
            }
            $toShift[]=$game;
            $cursor++;
        }
        // Descending updates keep unique(month,type,active_number) satisfied.
        for ($n=count($toShift)-1;$n>=0;$n--) {
            $item=$toShift[$n]; $from=(int)$item->game_number;
            $item->update(['game_number'=>$from+1,'active_number'=>$from+1]);
            $this->event($item->id,'renumber.insert',['old'=>$from,'new'=>$from+1]);
        }
    }
    /** Pull the next contiguous non-WP entry into a vacated slot; stop at gaps or locks. */
    private function compactAfter(string $month,int $type,int $vacated): void {
        for ($target=$vacated;$target<100000;$target++) {
            if ($this->active($month,$type)->where('active_number',$target)->exists()) break;
            $next=$this->active($month,$type)->where('active_number',$target+1)->lockForUpdate()->first();
            if (!$next || $this->verifiedWp($next) || $this->unresolvedWp($next)
                || $this->attachedHistory($next)) break; // PPB INS1: preserve all attached history.
            $old=(int)$next->game_number;
            $next->update(['game_number'=>$target,'active_number'=>$target]);
            $this->event($next->id,'renumber.compact',['old'=>$old,'new'=>$target]);
        }
    }
    private function nextNumber(string $month,int $type): int {
        $last=$this->active($month,$type)->orderByDesc('active_number')->lockForUpdate()->first(['active_number']);
        return 1+(int)($last?->active_number ?? 0);
    }
    private function resolveNumber(string $month,int $type,string $position,?int $anchorId): int {
        if ($position==='append') return $this->nextNumber($month,$type);
        if (!in_array($position,['before','after'],true) || !$anchorId) $this->reject('請選擇有效的插入位置。');
        $anchor=PpbGame::whereKey($anchorId)->lockForUpdate()->first();
        if (!$anchor || $anchor->month_key!==$month || $anchor->game_type!==$type ||
            $anchor->status==='cancelled' || !$anchor->active_number) {
            $this->reject('插入參考賽事不在同月、同類型的現行班表內。');
        }
        $number=$anchor->active_number+($position==='after'?1:0);
        $this->openNumber($month,$type,$number);
        return $number;
    }
    public function create(array $data,array $slots,string $position='append',?int $anchorId=null): PpbGame {
        return DB::transaction(function () use ($data,$slots,$position,$anchorId) {
            $month=$data['month_key']; $type=$data['game_type'];
            $this->scope($month,$type);
            $num=$this->resolveNumber($month,$type,$position,$anchorId);
            $game=PpbGame::create($data+['game_number'=>$num,'active_number'=>$num,'status'=>'scheduled']);
            $this->syncSlots($game,$slots);
            $this->event($game->id,'game.created',['number'=>$game->display_code,'position'=>$position]);
            return $game;
        },3);
    }
    public function update(PpbGame $game,array $data,array $slots,?string $expectedVersion=null): PpbGame {
        $expectedVersion ??= ScheduleVersion::game($game);
        return DB::transaction(function () use ($game,$data,$slots,$expectedVersion) {
            // Scope lock precedes game lock (consistent with insert/delete/cancel).
            $this->scope((string)$game->month_key,(int)$game->game_type);
            $locked=PpbGame::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $children=$locked->assignments()->orderBy('id')->lockForUpdate()->get();
            ScheduleVersion::assert($expectedVersion,ScheduleVersion::game($locked,$children));
            if ($locked->status==='cancelled') $this->reject('已取消的賽事只能查看歷史。');
            if ($this->verifiedWp($locked) || $this->unresolvedWp($locked)) $this->reject('已有 WP 發布或待查紀錄，先確認 WP 狀態才可編輯。');
            if ($data['month_key']!==$locked->month_key || $data['game_type']!==$locked->game_type) {
                $this->reject('既有賽事不可直接跨月份或 G/E 類型搬移，請使用取消與重新建立。');
            }
            unset($data['game_number'],$data['active_number'],$data['status']);
            $locked->update($data);
            $this->syncSlots($locked,$slots);
            $this->event($locked->id,'game.updated',['number'=>$locked->display_code]);
            return $locked;
        },3);
    }
    /** Replace only unsubmitted/unsubstituted slots, preserving immutable posted history. */
    private function syncSlots(PpbGame $game,array $slots): void {
        $shortIds=[];
        foreach (range(1,5) as $n) if (!empty($slots['short'][$n])) $shortIds[]=(int)$slots['short'][$n];
        if (count($shortIds)!==count(array_unique($shortIds))) {
            $this->reject('短文五個名額不得重複指派同一位分析師。');
        }
        $wanted=[];
        foreach (range(1,5) as $n) {
            if (!empty($slots['short'][$n])) $wanted['pick:'.$n]=['kind'=>'pick','slot_no'=>$n,'analyst'=>(int)$slots['short'][$n]];
        }
        if (!empty($slots['bonus'])) $wanted['bonus:1']=['kind'=>'bonus','slot_no'=>1,'analyst'=>(int)$slots['bonus']];
        if ($game->has_long_article && !empty($slots['long'])) {
            $wanted['long:1']=['kind'=>'long','slot_no'=>1,'analyst'=>(int)$slots['long']];
        }
        $existing=PpbAssignment::where('game_id',$game->id)->whereIn('kind',['pick','bonus','long'])->lockForUpdate()->get();
        $keep=[];
        foreach ($existing as $assignment) {
            $key=$assignment->kind.':'.$assignment->slot_no;
            $new=$wanted[$key] ?? null;
            if ($new && (int)$assignment->current_analyst_id===$new['analyst'] && $assignment->status==='active') {
                $keep[$key]=true; continue;
            }
            if ($this->assignmentHistory($assignment)) {
                $this->reject('此排班已有投稿或代班歷史，不可移除或更換原分析師。');
            }
            $assignment->delete();
        }
        foreach ($wanted as $key=>$row) {
            if (isset($keep[$key])) continue;
            PpbAssignment::create([
                'game_id'=>$game->id, 'original_analyst_id'=>$row['analyst'],
                'current_analyst_id'=>$row['analyst'],'kind'=>$row['kind'],
                'slot_no'=>$row['slot_no'],'status'=>'active',
            ]);
        }
    }
    public function delete(PpbGame $game,?string $expectedVersion=null): void {
        $expectedVersion ??= ScheduleVersion::game($game);
        DB::transaction(function () use ($game,$expectedVersion) {
            $this->scope((string)$game->month_key,(int)$game->game_type);
            $locked=PpbGame::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $children=$locked->assignments()->orderBy('id')->lockForUpdate()->get();
            ScheduleVersion::assert($expectedVersion,ScheduleVersion::game($locked,$children));
            if ($this->verifiedWp($locked) || $locked->publications()->orderBy('id')->lockForUpdate()->first(['id'])!==null || $locked->submissions()->orderBy('id')->lockForUpdate()->first(['id'])!==null) {
                $this->reject('已有 WP 紀錄或投稿的賽事不能刪除，請使用「取消賽事」。');
            }
            if ($this->attachedHistory($locked)) {
                $this->reject('已有代班或歷史例外關聯的賽事不得直接刪除。');
            }
            if ($locked->status==='cancelled') $this->reject('已取消賽事保留歷史，不再刪除。');
            $num=$locked->active_number;
            $this->event($locked->id,'game.deleted',['original_number'=>$locked->display_code]);
            $locked->assignments()->delete();
            $locked->delete();
            if ($num) $this->compactAfter($locked->month_key,(int)$locked->game_type,(int)$num);
        },3);
    }
    public function cancel(PpbGame $game,string $reason,?string $expectedVersion=null): void {
        $expectedVersion ??= ScheduleVersion::game($game);
        DB::transaction(function () use ($game,$reason,$expectedVersion) {
            $this->scope((string)$game->month_key,(int)$game->game_type);
            $locked=PpbGame::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $children=$locked->assignments()->orderBy('id')->lockForUpdate()->get();
            ScheduleVersion::assert($expectedVersion,ScheduleVersion::game($locked,$children));
            if ($locked->status==='cancelled') $this->reject('賽事已取消。');
            $published=$this->verifiedWp($locked);
            if (!$published && $this->unresolvedWp($locked)) $this->reject('WP 上傳結果待查，先確認發布狀態才能取消。');
            $num=$locked->active_number;
            // A published WP article permanently reserves the number.
            $locked->update([
                'status'=>'cancelled','cancel_reason'=>$reason,'cancelled_at'=>now(),
                'active_number'=>$published?$num:null,
            ]);
            $this->event($locked->id,'game.cancelled',[
                'old_number'=>$locked->display_code,'number_released'=>!$published,'reason'=>$reason,
            ]);
            if (!$published && $num) $this->compactAfter($locked->month_key,(int)$locked->game_type,(int)$num);
        },3);
    }
}
