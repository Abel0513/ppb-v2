<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppb_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email', 160)->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('ppb_roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('ppb_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 100);
            $table->string('category', 48);
            $table->timestamps();
        });

        Schema::create('ppb_account_roles', function (Blueprint $table) {
            $table->foreignId('account_id')->constrained('ppb_accounts')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('ppb_roles')->cascadeOnDelete();
            $table->primary(['account_id', 'role_id']);
        });

        Schema::create('ppb_role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('ppb_roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('ppb_permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('ppb_auth_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained('ppb_accounts')->nullOnDelete();
            $table->string('event', 48);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppb_auth_audits');
        Schema::dropIfExists('ppb_role_permissions');
        Schema::dropIfExists('ppb_account_roles');
        Schema::dropIfExists('ppb_permissions');
        Schema::dropIfExists('ppb_roles');
        Schema::dropIfExists('ppb_accounts');
    }
};
