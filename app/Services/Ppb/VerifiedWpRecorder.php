<?php
namespace App\Services\Ppb;

use App\Models\PpbGame;
use App\Models\PpbPublication;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Integration contract ONLY. There is deliberately no public route to call it.
 * The future authenticated WP adapter MUST verify the actual published status
 * from WordPress, then call recordVerifiedPublication with the verified post.
 * Do not manually claim WP publication through UI or untrusted requests.
 */
class VerifiedWpRecorder
{
    public function assertReadyBeforeSend(PpbGame $game): void {
        $current=PpbGame::findOrFail($game->id);
        if (!$current->wp_ready) {
            throw ValidationException::withMessages(['wp'=>'分析師（短文五位互不重複、BONUS、必要長文）及核稿人員尚未補齊。']);
        }
    }
    public function recordVerifiedPublication(PpbGame $game,string $wpSite,string $wpPostId): void {
        if (trim($wpSite)==='' || trim($wpPostId)==='' || strlen($wpSite)>120 || strlen($wpPostId)>60) {
            throw ValidationException::withMessages(['wp'=>'WP 已發布文章的來源或 ID 無效。']);
        }
        DB::transaction(function () use ($game,$wpSite,$wpPostId) {
            // Same month/type mutex as inserts, cancels and deletes.
            DB::table('ppb_schedule_scopes')->insertOrIgnore([
                'month_key'=>$game->month_key,'game_type'=>$game->game_type,
            ]);
            DB::table('ppb_schedule_scopes')->where('month_key',$game->month_key)
                ->where('game_type',$game->game_type)->lockForUpdate()->first();
            $locked=PpbGame::whereKey($game->id)->lockForUpdate()->firstOrFail();
            if ($locked->status==='cancelled' && !$locked->active_number) {
                throw ValidationException::withMessages(['wp'=>'已釋出編號的取消賽事出現 WP 成功紀錄，需先人工仲裁，不能自動覆寫編號。']);
            }
            $row=PpbPublication::where('wp_site',$wpSite)->where('wp_post_id',$wpPostId)->first();
            if ($row && (int)$row->game_id !== (int)$locked->id) {
                throw ValidationException::withMessages(['wp'=>'這篇 WP 文章已綁定另一個永久賽事 ID。']);
            }
            if (!$row) {
                PpbPublication::create([
                    'game_id'=>$locked->id,'wp_site'=>$wpSite,'wp_post_id'=>$wpPostId,
                    'status'=>'published','verified_at'=>now(),
                ]);
            } else {
                $row->update(['status'=>'published','verified_at'=>now()]);
            }
            // Irreversible latch. Even if WP post is later removed, never release the label.
            if (!$locked->wp_locked_at) $locked->forceFill(['wp_locked_at'=>now()])->save();
            DB::table('ppb_schedule_events')->insert([
                'game_id'=>$locked->id,'actor_id'=>Auth::guard('ppb')->id(),
                'event'=>'wp.verified_published',
                'detail_json'=>json_encode(['wp_site'=>$wpSite,'wp_post_id'=>$wpPostId],JSON_UNESCAPED_UNICODE),
                'created_at'=>now(),
            ]);
        },3);
    }
}
