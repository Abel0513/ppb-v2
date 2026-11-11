<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PpbPublication extends Model {
    protected $table='ppb_publications';
    protected $fillable=['game_id','wp_site','wp_post_id','status','verified_at'];
    protected function casts(): array { return ['verified_at'=>'datetime']; }
}
