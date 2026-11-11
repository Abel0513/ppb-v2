<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PpbPermission extends Model
{
    protected $table = 'ppb_permissions';

    protected $fillable = ['code', 'name', 'category'];
}
