<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PpbAssignment extends Model {
    protected $table='ppb_assignments';
    protected $fillable=['game_id','original_analyst_id','current_analyst_id','kind','slot_no','status','notes'];
    protected function casts(): array { return ['slot_no'=>'integer']; }
    public function game(): BelongsTo { return $this->belongsTo(PpbGame::class,'game_id'); }
    public function originalAnalyst(): BelongsTo { return $this->belongsTo(PpbAnalyst::class,'original_analyst_id'); }
    public function currentAnalyst(): BelongsTo { return $this->belongsTo(PpbAnalyst::class,'current_analyst_id'); }
    public function substitutions(): HasMany { return $this->hasMany(PpbSubstitution::class,'assignment_id')->orderBy('id'); }
}
