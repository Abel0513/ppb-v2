<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PpbEvaluation extends Model
{
    protected $table='ppb_evaluations';
    protected $guarded=[];
    protected function casts(): array { return ['subject_id'=>'integer','revision'=>'integer','next_rating_level'=>'integer']; }
}
