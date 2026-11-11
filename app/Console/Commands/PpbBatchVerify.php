<?php
namespace App\Console\Commands;

use App\Models\PpbGame;
use App\Models\PpbPublication;
use App\Models\PpbSubmission;
use App\Models\PpbAccount;
use App\Models\PpbAnalyst;
use App\Models\PpbRole;
use App\Services\Ppb\ScheduleManager;
use App\Services\Ppb\VerifiedWpRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** One-pass offline DB transaction tests; ALL synthetic rows rolled back. */
class PpbBatchVerify extends Command {
    protected $signature='ppb:batch:verify {--transactional : Run synthetic schedule tests inside a rollback-only transaction}';
    protected $description='Read-only auth/route checks; optional rollback-only PPB DB schedule tests';

    private function pass(bool $condition,string $name): void {
        if (!$condition) throw new RuntimeException('TEST_FAILED_'.$name);
        $this->line('PASS '.$name);
    }
    public function handle(ScheduleManager $manager,VerifiedWpRecorder $recorder): int {
        try {
            $database=DB::selectOne('SELECT DATABASE() AS db')->db;
            $this->pass($database==='ppbaos','NEW_DB_ONLY');
            $this->pass(Auth::guard('ppb')->getProvider()->getModel()===PpbAccount::class,'AUTH_PPB_PROVIDER');
            $secret='Example-Test-Pass-2026!';
            $this->pass(Hash::check($secret,Hash::make($secret)),'PASSWORD_HASH_CHECK');
            $this->pass(!Hash::check('wrong',Hash::make($secret)),'PASSWORD_WRONG_REJECT');
            $this->pass(Validator::make(['password'=>'abcdefghij'],['password'=>['required','min:10','max:128']])->passes(),'PASSWORD_TEN_ALLOWED');
            $this->pass(Validator::make(['password'=>'abcdefghi'],['password'=>['required','min:10','max:128']])->fails(),'PASSWORD_NINE_REJECT');
            $this->pass(\Illuminate\Support\Facades\Schema::hasColumn('ppb_accounts','username'),'SCHEMA_USERNAME');
            foreach(['ppb.login','ppb.logout','ppb.games.index','ppb.games.store','ppb.games.update',
                'ppb.games.destroy','ppb.games.cancel','ppb.games.anchors'] as $name) {
                $this->pass(Route::has($name),'ROUTE_'.str_replace('.','_',$name));
            }
            $this->line('AUTH_USERS='.DB::table('ppb_accounts')->count());
            $this->line('AUTH_OWNERS='.DB::table('ppb_account_roles as r')->join('ppb_roles as p','p.id','=','r.role_id')
                ->where('p.code','owner')->distinct()->count('r.account_id'));
            if (!$this->option('transactional')) {
                $this->info('PPB_BATCH_STATIC_VERIFY_PASS');return self::SUCCESS;
            }
            foreach(['ppb_games','ppb_assignments','ppb_submissions','ppb_publications',
                'ppb_schedule_scopes','ppb_schedule_events'] as $t) {
                $this->pass(\Illuminate\Support\Facades\Schema::hasTable($t),'SCHEMA_'.$t);
            }
            $original=DB::table('ppb_games')->count();
            // Reusing one fixed far-future scope; refuse to touch existing records in it.
            $this->pass(!DB::table('ppb_games')->whereIn('month_key',['2097-11','2097-12'])->exists(),'EMPTY_TEST_SCOPE');
            DB::beginTransaction();
            try {
                // Use real Eloquent provider and role permission logic; no HTTP/CSRF bypass.
                $ownerRole=PpbRole::where('code','owner')->firstOrFail();
                $username='ppb-batch-auth-2097';
                $owner=PpbAccount::create([
                    'name'=>'合成管理員（驗證後回滾）','username'=>$username,
                    'password'=>Hash::make($secret),'is_active'=>true,
                ]);
                $owner->roles()->attach($ownerRole->id);
                $this->pass(Auth::guard('ppb')->validate([
                    'username'=>$username,'password'=>$secret,'is_active'=>1,
                ]),'AUTH_PROVIDER_CORRECT_PASSWORD');
                $this->pass(!Auth::guard('ppb')->validate([
                    'username'=>$username,'password'=>'bad-pass','is_active'=>1,
                ]),'AUTH_PROVIDER_WRONG_PASSWORD');
                $this->pass($owner->hasPpbPermission('schedules.manage'),'OWNER_HAS_SCHEDULE_PERMISSION');
                $owner->forceFill(['is_active'=>false])->save();
                $this->pass(!Auth::guard('ppb')->validate([
                    'username'=>$username,'password'=>$secret,'is_active'=>1,
                ]),'DISABLED_OWNER_CANNOT_LOGIN');
                $base=[
                    'month_key'=>'2097-11','game_type'=>1,'recommended_at'=>'2097-11-01 03:00:00',
                    'starts_at'=>null,'matchup_mode'=>'manual','matchup_text'=>'合成測試賽事',
                    'sport'=>'other','home_team'=>'乙','away_team'=>'甲','play_type'=>2,
                    'market_type'=>1,'has_long_article'=>false,'status'=>'scheduled',
                ];
                $empty=['short'=>[1=>null,2=>null,3=>null,4=>null,5=>null],'bonus'=>null,'long'=>null];
                $g1=$manager->create($base,$empty);
                $g2=$manager->create($base,$empty);
                $g3=$manager->create($base,$empty);
                $this->pass($g1->game_number===1 && $g2->game_number===2 && $g3->game_number===3,'APPEND_G1_G2_G3');
                $insert=$manager->create($base,$empty,'before',$g2->id);
                $this->pass($insert->game_number===2 && $g2->fresh()->game_number===3 && $g3->fresh()->game_number===4,'INSERT_SHIFT_SAFE');
                $recorder->recordVerifiedPublication($g2,'verify.invalid','batch-verified-only');
                $this->pass($g2->fresh()->hasVerifiedWp() && $g2->fresh()->wp_locked_at!==null,'ANY_PUBLISHED_LOCK');
                PpbPublication::where('game_id',$g2->id)->update(['status'=>'removed']);
                $this->pass($g2->fresh()->hasVerifiedWp(),'HISTORICAL_WP_LOCK_IRREVERSIBLE');
                $blocked=false;
                try{$manager->create($base,$empty,'before',$g2->id);}catch(ValidationException $e){$blocked=true;}
                $this->pass($blocked,'LOCKED_INSERT_REJECT');
                $manager->cancel($insert,'合成取消測試');
                $this->pass($insert->fresh()->active_number===null && $g2->fresh()->game_number===3,'CANCEL_RELEASE_WITH_LOCK');
                $manager->delete($g1);
                $this->pass(!PpbGame::whereKey($g1->id)->exists(),'DELETE_NO_SUBMISSION');
                PpbSubmission::create(['game_id'=>$g3->id,'kind'=>'pick','status'=>'submitted','submitted_at'=>now()]);
                $blocked=false;
                try{$manager->delete($g3);}catch(ValidationException $e){$blocked=true;}
                $this->pass($blocked,'SUBMITTED_DELETE_REJECT');
                $manager->cancel($g3,'保留投稿測試');
                $this->pass($g3->fresh()->submissions()->count()===1,'SUBMISSION_HISTORY_PRESERVED');
                $this->pass($g2->fresh()->game_number===3,'WP_NUMBER_UNCHANGED');
                // Per-month/type isolation and safe compaction with no WP locks.
                $other=$base;
                $other['month_key']='2097-12';
                $other['recommended_at']='2097-12-01 03:00:00';
                $a=$manager->create($other,$empty);
                $b=$manager->create($other,$empty);
                $c=$manager->create($other,$empty);
                $manager->delete($b);
                $this->pass($c->fresh()->game_number===2,'DELETE_COMPACTS_UNLOCKED');
                $this->pass($g2->fresh()->game_number===3,'MONTH_ISOLATION');
                $e=$other;$e['game_type']=2;
                $extra=$manager->create($e,$empty);
                $this->pass($extra->display_code==='E1','G_E_SCOPE_ISOLATION');
                // The actual assignee role rules: 5 unique short; BONUS/long may reuse one.
                $people=[];
                foreach(range(1,5) as $i) {
                    $people[$i]=PpbAnalyst::create([
                        'name'=>'合成分析師'.$i,'status'=>'active','user_type'=>0,
                    ]);
                }
                $reviewer=PpbAnalyst::create([
                    'name'=>'合成核稿人','status'=>'active','user_type'=>2,
                ]);
                $filled=$other;
                $filled['reviewer_analyst_id']=$reviewer->id;
                $filled['has_long_article']=true;
                $peopleSlots=['short'=>[], 'bonus'=>$people[1]->id,'long'=>$people[1]->id];
                foreach(range(1,5) as $i) $peopleSlots['short'][$i]=$people[$i]->id;
                $full=$manager->create($filled,$peopleSlots);
                $this->pass($full->fresh()->wp_ready,'WP_GATE_FULL_ROSTER_CROSS_ROLE_ALLOWED');
                $invalid=$peopleSlots;$invalid['short'][5]=$people[1]->id;
                $blocked=false;
                try{$manager->create($filled,$invalid);}catch(ValidationException $e){$blocked=true;}
                $this->pass($blocked,'DUPLICATE_SHORT_REJECT');
                $this->pass(!$a->fresh()->wp_ready,'WP_GATE_INCOMPLETE_ROSTER_BLOCKED');
            } finally {
                DB::rollBack();
            }
            $this->pass(DB::table('ppb_games')->count()===$original,'ROLLBACK_NO_PERSISTENT_TEST_ROWS');
            $this->info('PPB_BATCH_TRANSACTION_VERIFY_PASS');
            return self::SUCCESS;
        } catch(\Throwable $e) {
            $this->error('PPB_BATCH_VERIFY_FAILED '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
