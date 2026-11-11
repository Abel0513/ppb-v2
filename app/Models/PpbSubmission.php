<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PpbSubmission extends Model {
    protected $table='ppb_submissions';
    protected $fillable=['game_id','assignment_id','kind','submitted_analyst_id','content_body','status','submitted_at'];
    protected function casts(): array { return ['submitted_at'=>'datetime']; }
}
