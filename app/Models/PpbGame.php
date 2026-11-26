<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Stable game ID: G/E labels are mutable until the first verified WP publication. */
class PpbGame extends Model
{
    protected $table = 'ppb_games';
    protected $fillable = [
        'sport','league','home_team','away_team','starts_at','status','notes',
        'month_key','game_type','game_number','active_number','recommended_at',
        'matchup_mode','matchup_text','fixture_id','play_type','play_value','market_type',
        'reviewer_analyst_id','has_long_article','cancelled_at','cancel_reason',
    ];
    protected function casts(): array {
        return [
            'starts_at'=>'datetime', 'recommended_at'=>'datetime','cancelled_at'=>'datetime',
            'wp_locked_at'=>'datetime',
            'has_long_article'=>'boolean', 'game_number'=>'integer', 'active_number'=>'integer',
            'game_type'=>'integer','play_type'=>'integer','market_type'=>'integer',
        ];
    }
    public function assignments(): HasMany { return $this->hasMany(PpbAssignment::class,'game_id'); }
    public function submissions(): HasMany { return $this->hasMany(PpbSubmission::class,'game_id'); }
    public function publications(): HasMany { return $this->hasMany(PpbPublication::class,'game_id'); }
    public function hasVerifiedWp(): bool {
        return $this->wp_locked_at !== null || $this->publications()->where('status','published')->whereNotNull('verified_at')->exists();
    }
    /** Upload outcome unresolved: hold all renumber/cancel operations until reconciled. */
    public function hasUnresolvedWp(): bool {
        return $this->publications()->where('status','!=','failed')
            ->where(function ($q) {
                $q->where('status','!=','published')->orWhereNull('verified_at');
            })->exists();
    }
    /** PPB INS1 2026-11-05 | These relations forbid renumbering even when WP is absent. */
    public function hasRenumberBlockingHistory(): bool {
        return $this->submissions()->exists()
            || $this->assignments()->whereHas('substitutions')->exists()
            || \Illuminate\Support\Facades\DB::table('ppb_schedule_events')
                ->where('game_id',$this->id)->where('event','legacy_archived_pick')->exists();
    }
    public function lotteryNumber(): ?string {
        $notes = json_decode((string) $this->notes, true);
        $value = $notes['lottery_no'] ?? null;
        return (is_int($value) || is_string($value)) && trim((string) $value) !== '' ? (string) $value : null;
    }
    public function legacyPrediction(): ?string
    {
        $notes = json_decode((string) $this->notes, true);
        $prediction = $notes['legacy_prediction'] ?? null;
        return ($notes['_ppb_legacy_game_v1'] ?? false) && is_string($prediction) ? $prediction : null;
    }
    public function getDisplayCodeAttribute(): string {
        return ($this->game_type === 1 ? 'G' : 'E').($this->game_number ?? '?');
    }
    public function getWpReadyAttribute(): bool {
        if ($this->status === 'cancelled' || !$this->reviewer_analyst_id) return false;
        $rows = $this->assignments()->where('status','active')->get();
        $short = $rows->where('kind','pick');
        if ($short->count() !== 5 || $short->pluck('slot_no')->sort()->values()->all() !== [1,2,3,4,5] ||
            $short->pluck('current_analyst_id')->unique()->count() !== 5) return false;
        if (!\Illuminate\Support\Facades\DB::table('ppb_analysts')->where('id',$this->reviewer_analyst_id)->where('status','active')->whereIn('user_type',[1,2])->exists()) return false;
        foreach ($rows as $assignment) {
            if (!\Illuminate\Support\Facades\DB::table('ppb_analysts')->where('id',$assignment->current_analyst_id)->where('status','active')->exists()) return false;
        }
        if ($rows->where('kind','bonus')->count() !== 1) return false;
        if ($this->has_long_article && $rows->where('kind','long')->count() !== 1) return false;
        return true;
    }
}
