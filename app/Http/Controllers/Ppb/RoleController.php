<?php

namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Models\PpbPermission;
use App\Models\PpbRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Owner-only role/permission matrix. The owner role is permanently read-only. */
class RoleController extends Controller
{
    private function ownerOnly(): void
    {
        abort_unless(Auth::guard('ppb')->user()?->roles()->where('code', 'owner')->exists(), 403);
    }

    public function index(): View
    {
        $this->ownerOnly();
        return view('ppb.roles-index', ['roles' => PpbRole::withCount('permissions')->orderBy('id')->get()]);
    }

    public function edit(PpbRole $role): View
    {
        $this->ownerOnly();
        abort_if($role->code === 'owner', 403, '系統管理員權限不可在此調整。');
        abort_unless(in_array($role->code, ['manager', 'analyst'], true), 404);
        return view('ppb.roles-edit', [
            'role' => $role,
            'permissions' => PpbPermission::orderBy('category')->orderBy('id')->get()->groupBy('category'),
            'selected' => $role->permissions()->pluck('ppb_permissions.id')->all(),
        ]);
    }

    public function update(Request $request, PpbRole $role): RedirectResponse
    {
        $this->ownerOnly();
        abort_if($role->code === 'owner', 403);
        abort_unless(in_array($role->code, ['manager', 'analyst'], true), 404);
        $data = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'integer', 'distinct', 'exists:ppb_permissions,id'],
        ]);
        DB::transaction(function () use ($request, $role, $data) {
            $role->permissions()->sync($data['permissions'] ?? []);
            DB::table('ppb_auth_audits')->insert([
                'account_id' => Auth::guard('ppb')->id(),
                'event' => 'role.permissions_updated#'.$role->id,
                'ip' => substr((string) $request->ip(), 0, 45),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        });
        return redirect()->route('ppb.roles.index')->with('status', '角色權限已更新。');
    }
}
