<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** PPB A3 | 2026-11-03 | Analyst identity is distinct from login account. */
class PpbAnalyst extends Model
{
    protected $table = 'ppb_analysts';
    protected $fillable = ['account_id', 'name', 'display_name', 'status', 'internal_notes','user_type'];

    protected function casts(): array { return ['user_type'=>'integer']; }
    public function account(): BelongsTo { return $this->belongsTo(PpbAccount::class, 'account_id'); }
    public function originalAssignments(): HasMany { return $this->hasMany(PpbAssignment::class, 'original_analyst_id'); }
}
