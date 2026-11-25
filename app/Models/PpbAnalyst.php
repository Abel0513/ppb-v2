<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** PPB A3 | 2026-11-03 | Analyst identity is distinct from login account. */
class PpbAnalyst extends Model
{
    protected $table = 'ppb_analysts';
    protected $fillable = ['account_id', 'name', 'display_name', 'status', 'internal_notes','user_type','color','background','wp_photo','author_id','line_id','line_name','rating_level','analyst_level'];
    protected $hidden = ['line_id','line_name'];

    protected function casts(): array { return ['user_type'=>'integer','wp_photo'=>'integer','author_id'=>'integer','rating_level'=>'integer','analyst_level'=>'integer']; }
    /** Only validated CSS; preserve the existing reviewed palette when absent. */
    public function scheduleColors(?array $legacyFallback=null): ?array
    {
        $valid=static fn($value)=>is_string($value)&&preg_match('/^#[0-9a-fA-F]{6}$/D',$value);
        $ownColor=$this->getRawOriginal('color');
        $ownBackground=$this->getRawOriginal('background');
        if($valid($ownColor))return ['background'=>$valid($ownBackground)?$ownBackground:null,'color'=>$ownColor];
        $foreground=$legacyFallback['color']??null;
        if($valid($ownBackground)&&!$valid($foreground))return ['background'=>$ownBackground,'color'=>null];
        if(!$valid($foreground))return null;
        $background=$valid($ownBackground)?$ownBackground:($legacyFallback['background']??null);
        return ['background'=>$valid($background)?$background:null,'color'=>$foreground];
    }
    public function account(): BelongsTo { return $this->belongsTo(PpbAccount::class, 'account_id'); }
    public function originalAssignments(): HasMany { return $this->hasMany(PpbAssignment::class, 'original_analyst_id'); }
}
