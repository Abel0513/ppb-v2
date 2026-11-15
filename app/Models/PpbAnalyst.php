<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** PPB A3 | 2026-11-03 | Analyst identity is distinct from login account. */
class PpbAnalyst extends Model
{
    protected $table = 'ppb_analysts';
    protected $fillable = ['account_id', 'name', 'display_name', 'status', 'internal_notes','user_type','color','wp_photo','author_id','line_id','line_name','rating_level'];
    protected $hidden = ['line_id','line_name'];

    protected function casts(): array { return ['user_type'=>'integer','wp_photo'=>'integer','author_id'=>'integer','rating_level'=>'integer']; }
    /** Only validated CSS; preserve the existing reviewed palette when absent. */
    public function scheduleColors(?array $legacyFallback=null): ?array
    {
        $valid=static fn($value)=>is_string($value)&&preg_match('/^#[0-9a-fA-F]{6}$/D',$value);
        $own=$this->getRawOriginal('color');
        if($valid($own))return ['background'=>null,'color'=>$own];
        $foreground=$legacyFallback['color']??null;
        if(!$valid($foreground))return null;
        $background=$legacyFallback['background']??null;
        return ['background'=>$valid($background)?$background:null,'color'=>$foreground];
    }
    public function account(): BelongsTo { return $this->belongsTo(PpbAccount::class, 'account_id'); }
    public function originalAssignments(): HasMany { return $this->hasMany(PpbAssignment::class, 'original_analyst_id'); }
}
