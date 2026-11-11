<?php

namespace Database\Seeders;

use App\Models\PpbPermission;
use App\Models\PpbRole;
use Illuminate\Database\Seeder;

class PpbSecuritySeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            'dashboard.view' => ['查看後台', 'dashboard'],
            'accounts.view' => ['查看帳號', 'accounts'],
            'accounts.manage' => ['管理帳號', 'accounts'],
            'roles.manage' => ['管理角色權限', 'accounts'],
            'schedules.view' => ['查看班表', 'schedules'],
            'schedules.manage' => ['管理班表', 'schedules'],
            'picks.view' => ['查看投稿', 'picks'],
            'picks.manage' => ['管理投稿', 'picks'],
            'attendance.view' => ['查看出勤', 'attendance'],
            'attendance.manage' => ['管理出勤', 'attendance'],
            'evaluations.view' => ['查看評鑑', 'evaluations'],
            'evaluations.manage' => ['管理評鑑', 'evaluations'],
            'payroll.view' => ['查看薪酬', 'payroll'],
            'payroll.manage' => ['管理薪酬', 'payroll'],
            'integrations.view' => ['查看整合設定', 'integrations'],
            'integrations.manage' => ['管理整合設定', 'integrations'],
        ];

        foreach ($definitions as $code => [$name, $category]) {
            PpbPermission::updateOrCreate(['code' => $code], compact('name', 'category'));
        }

        $roles = [
            'owner' => ['系統管理員', '完整管理權限，僅交由協會核定管理員。'],
            'manager' => ['管理人員', '初始僅能查看後台，其他權限須核定後開啟。'],
            'analyst' => ['分析師', '初始僅能查看後台，投稿等權限須核定後開啟。'],
        ];

        foreach ($roles as $code => [$name, $description]) {
            $role = PpbRole::updateOrCreate(['code' => $code], compact('name', 'description'));
            if ($code === 'owner') {
                $role->permissions()->sync(PpbPermission::pluck('id')->all());
            } else {
                $id = PpbPermission::where('code', 'dashboard.view')->value('id');
                if ($id) {
                    $role->permissions()->syncWithoutDetaching([$id]);
                }
            }
        }
    }
}
