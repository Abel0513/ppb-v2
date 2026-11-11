<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PPB A3 | 2026-11-03 | Append-only substitution evidence. */
class PpbSubstitution extends Model
{
    public $timestamps = false;
    protected $table = 'ppb_substitutions';
    protected $fillable = ['assignment_id','from_analyst_id','to_analyst_id','performed_by_account_id','reason','changed_at'];
    protected function casts(): array { return ['changed_at' => 'datetime']; }
    public function fromAnalyst(): BelongsTo { return $this->belongsTo(PpbAnalyst::class,'from_analyst_id'); }
    public function toAnalyst(): BelongsTo { return $this->belongsTo(PpbAnalyst::class,'to_analyst_id'); }
}
