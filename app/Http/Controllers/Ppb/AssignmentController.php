<?php
namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Models\PpbAnalyst;
use App\Models\PpbAssignment;
use App\Models\PpbGame;
use App\Models\PpbSubstitution;
use App\Services\Ppb\ScheduleVersion;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** PPB A3 | 2026-11-03 | Explicit substitution history, no legacy mixed is_substitute. */
class AssignmentController extends Controller
{
    private function lockedAssignment(PpbAssignment $bound): array
    {
        // Match manuscript/schedule writers: never hold an assignment while
        // deciding the game state from an old nonlocking snapshot.
        $game=PpbGame::whereKey($bound->game_id)->lockForUpdate()->firstOrFail();
        $assignment=PpbAssignment::whereKey($bound->id)->lockForUpdate()->firstOrFail();
        abort_unless((int)$assignment->game_id===(int)$game->id,409,'指派所屬賽事已變更，請重新讀取。');
        return [$game,$assignment];
    }
    private function assertUnpublished(PpbGame $game): void
    {
        $publication=$game->publications()->where('status','!=','failed')->orderBy('id')->lockForUpdate()->first(['id']);
        abort_if($game->wp_locked_at!==null||$publication!==null,422,'已有 WP 發布或待查紀錄，禁止變更指派。');
    }

    public function store(Request $request, PpbGame $game): RedirectResponse
    {
        $data = $request->validate([
            'analyst_id' => ['required','integer','exists:ppb_analysts,id'],
            'kind' => ['required',Rule::in(['pick','long','video'])],
            'notes' => ['nullable','string','max:1000'],
            'revision_token'=>['required','regex:/^[a-f0-9]{64}$/D'],
        ]);
        DB::transaction(function () use ($request,$game,$data) {
            $locked = PpbGame::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $children=$locked->assignments()->orderBy('id')->lockForUpdate()->get();
            ScheduleVersion::assert($data['revision_token'],ScheduleVersion::game($locked,$children));
            $this->assertUnpublished($locked);
            abort_unless($locked->status === 'scheduled', 422, '只能新增未取消、未完成賽事的排班。');
            abort_unless(PpbAnalyst::whereKey($data['analyst_id'])->where('status','active')->exists(),422,'分析師未啟用。');
            abort_if(PpbAssignment::where('game_id',$game->id)
                ->where('original_analyst_id',$data['analyst_id'])->where('kind',$data['kind'])->orderBy('id')->lockForUpdate()->first(['id'])!==null, 422, '同一場相同類型已存在這位分析師的排班。');
            abort_if(PpbAssignment::where('game_id',$game->id)->where('current_analyst_id',$data['analyst_id'])
                ->where('kind',$data['kind'])->where('status','active')->orderBy('id')->lockForUpdate()->first(['id'])!==null,422,'同一場同一類型，該分析師已有進行中的排班。');
            $assignment = PpbAssignment::create([
                'game_id'=>$game->id, 'original_analyst_id'=>$data['analyst_id'],
                'current_analyst_id'=>$data['analyst_id'], 'kind'=>$data['kind'],
                'status'=>'active', 'notes'=>$data['notes'] ?? null,
            ]);
            $this->audit($request,'shift.created#'.$assignment->id);
        });
        return redirect()->route('ppb.games.show',$game)->with('status','排班已建立。');
    }

    public function substitute(Request $request, PpbAssignment $assignment): RedirectResponse
    {
        $data = $request->validate([
            'to_analyst_id' => ['required','integer','exists:ppb_analysts,id'],
            'reason' => ['required','string','max:255'],
            'revision_token'=>['required','regex:/^[a-f0-9]{64}$/D'],
        ]);
        DB::transaction(function () use ($request,$assignment,$data) {
            [$game,$locked]=$this->lockedAssignment($assignment);
            ScheduleVersion::assert($data['revision_token'],ScheduleVersion::assignment($locked));
            $this->assertUnpublished($game);
            abort_unless($locked->status === 'active',422,'只有進行中的排班可以代班。');
            abort_unless($game->status==='scheduled',422,'賽事已結束或取消。');
            abort_if((int)$locked->current_analyst_id === (int)$data['to_analyst_id'],422,'代班者不可與目前負責人相同。');
            abort_unless(PpbAnalyst::whereKey($data['to_analyst_id'])->where('status','active')->exists(),422,'代班者必須是啟用的分析師。');
            abort_if(PpbAssignment::where('game_id',$locked->game_id)->where('kind',$locked->kind)
                ->where('current_analyst_id',$data['to_analyst_id'])->where('status','active')->orderBy('id')->lockForUpdate()->first(['id'])!==null,422,'代班者在同場同類型已有排班。');
            PpbSubstitution::create([
                'assignment_id'=>$locked->id,
                'from_analyst_id'=>$locked->current_analyst_id,
                'to_analyst_id'=>$data['to_analyst_id'],
                'reason'=>$data['reason'],
                'performed_by_account_id'=>Auth::guard('ppb')->id(),
                'changed_at'=>now(),
            ]);
            $locked->update(['current_analyst_id'=>$data['to_analyst_id']]);
            $this->audit($request,'shift.substituted#'.$locked->id);
        });
        return redirect()->route('ppb.games.show',$assignment->game_id)->with('status','已記錄代班，原負責人資料保留。');
    }

    public function status(Request $request, PpbAssignment $assignment): RedirectResponse
    {
        $data = $request->validate(['status' => ['required',Rule::in(['active','completed','cancelled'])],
            'revision_token'=>['required','regex:/^[a-f0-9]{64}$/D']]);
        DB::transaction(function () use ($assignment,$request,$data) {
            [$game,$locked]=$this->lockedAssignment($assignment);
            ScheduleVersion::assert($data['revision_token'],ScheduleVersion::assignment($locked));
            $this->assertUnpublished($game);
            abort_if($data['status']==='active' && $game->status==='cancelled',422,'已取消的賽事不可恢復排班。');
            if($data['status']==='active'){
                // The game lock serializes all assignment writers; use a
                // current read even when a caller already owns a snapshot.
                abort_if(PpbAssignment::where('game_id',$game->id)->where('kind',$locked->kind)
                    ->where('current_analyst_id',$locked->current_analyst_id)->where('status','active')
                    ->where('id','!=',$locked->id)->orderBy('id')->lockForUpdate()->first(['id'])!==null,
                    422,'同一場同一類型，該分析師已有進行中的排班。');
            }
            $locked->update(['status'=>$data['status']]);
            $this->audit($request,'shift.status#'.$locked->id);
        });
        return redirect()->route('ppb.games.show',$assignment->game_id)->with('status','排班狀態已更新。');
    }

    private function audit(Request $request,string $event): void
    {
        DB::table('ppb_auth_audits')->insert([
            'account_id'=>Auth::guard('ppb')->id(), 'event'=>$event,
            'ip'=>substr((string)$request->ip(),0,45),
            'user_agent'=>substr((string)$request->userAgent(),0,255), 'created_at'=>now(),
        ]);
    }
}
