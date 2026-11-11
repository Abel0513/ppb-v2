<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PpbRole extends Model
{
    protected $table = 'ppb_roles';

    protected $fillable = ['code', 'name', 'description'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(PpbPermission::class, 'ppb_role_permissions', 'role_id', 'permission_id');
    }
}
