<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** PPB AUTH10: usernames replace required email login, existing isolated DB only. */
return new class extends Migration {
    public function up(): void
    {
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== 'ppbaos') {
            throw new \RuntimeException('STOP_WRONG_DATABASE');
        }
        if (DB::table('ppb_accounts')->exists()) {
            throw new \RuntimeException('STOP_ACCOUNTS_NOT_EMPTY_MIGRATION_REVIEW_REQUIRED');
        }
        Schema::table('ppb_accounts', function (Blueprint $table) {
            $table->string('username', 64)->unique()->after('name');
        });
        // Retain former email field for migration compatibility, but no account requires it.
        Schema::table('ppb_accounts', function (Blueprint $table) {
            $table->string('email', 160)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Avoid silently discarding login IDs or making email mandatory again.
        throw new \RuntimeException('STOP_UNSAFE_AUTH_USERNAME_ROLLBACK');
    }
};
