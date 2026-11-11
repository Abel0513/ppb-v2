<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Ppb A1: deliberately separate from the legacy site's analyst profiles. */
class PpbAccount extends Authenticatable
{
    protected $table = 'ppb_accounts';

    protected $fillable = ['name', 'username', 'email', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(PpbRole::class, 'ppb_account_roles', 'account_id', 'role_id');
    }

    public function hasPpbPermission(string $permission): bool
    {
        if (!$this->is_active) {
            return false;
        }

        return $this->roles()->where(function ($query) use ($permission) {
            $query->where('ppb_roles.code', 'owner')
                ->orWhereHas('permissions', function ($permissions) use ($permission) {
                    $permissions->where('ppb_permissions.code', $permission);
                });
        })->exists();
    }
}
