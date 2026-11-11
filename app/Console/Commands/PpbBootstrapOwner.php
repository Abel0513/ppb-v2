<?php

namespace App\Console\Commands;

use App\Models\PpbAccount;
use App\Models\PpbRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/** CLI-only, one-time, interactive bootstrap. Does not accept password parameters. */
class PpbBootstrapOwner extends Command
{
    protected $signature = 'ppb:owner:create';

    protected $description = 'Create the first owner using an interactive hidden password prompt';

    public function handle(): int
    {
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'ppbaos') {
            $this->error('STOP_WRONG_DATABASE');
            return self::FAILURE;
        }

        $owner = PpbRole::where('code', 'owner')->first();
        if (!$owner || PpbAccount::whereHas('roles', fn ($q) => $q->where('code', 'owner'))->exists()) {
            $this->error('STOP_OWNER_ALREADY_EXISTS_OR_ROLE_MISSING');
            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('STOP_INTERACTIVE_TTY_REQUIRED');
            return self::FAILURE;
        }

        // One login identifier; no email is collected or required.
        $username = strtolower(trim((string) $this->ask('管理員帳號（3～64 個英數字，可用 . _ -）')));
        $displayName = trim((string) $this->ask('顯示名稱（直接 Enter 使用帳號名稱）'));
        $name = $displayName !== '' ? $displayName : $username;
        $password = (string) $this->secret('管理員密碼（至少 10 字元，不會顯示）');
        $confirm = (string) $this->secret('再次輸入密碼');

        $v = Validator::make(compact('name', 'username', 'password'), [
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_.-]{3,64}\z/', 'unique:ppb_accounts,username'],
            'password' => ['required', 'string', 'min:10', 'max:128'],
        ]);
        if ($v->fails() || !hash_equals($password, $confirm)) {
            $this->error('STOP_INVALID_INPUT_OR_PASSWORD_CONFIRMATION');
            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $username, $password, $owner) {
            // Recheck before transaction write; only the new PPB database is touched.
            if (PpbAccount::whereHas('roles', fn ($q) => $q->where('code', 'owner'))->exists()) {
                throw new \RuntimeException('STOP_OWNER_EXISTS');
            }
            $account = PpbAccount::create([
                'name' => $name,
                'username' => $username,
                'password' => Hash::make($password),
                'is_active' => true,
            ]);
            $account->roles()->sync([$owner->id]);
            DB::table('ppb_auth_audits')->insert([
                'account_id' => $account->id,
                'event' => 'owner.cli_created',
                'created_at' => now(),
            ]);
        });

        $this->info('PPB_OWNER_CREATED');
        $this->comment('請先完成自有域名 HTTPS，再進行網頁登入。');
        return self::SUCCESS;
    }
}
