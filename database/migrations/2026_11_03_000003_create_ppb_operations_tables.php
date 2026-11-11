<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PPB A3 | 2026-11-03 | Isolated analyst, match, assignment and substitution schema. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ppb_analysts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->unique()->constrained('ppb_accounts')->nullOnDelete();
            $table->string('name', 100);
            $table->string('display_name', 100)->nullable();
            $table->string('status', 16)->default('active');
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'name']);
        });

        Schema::create('ppb_games', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 20);
            $table->string('league', 50)->nullable();
            $table->string('home_team', 100);
            $table->string('away_team', 100);
            $table->dateTime('starts_at'); // Stored UTC; UI uses Asia/Taipei.
            $table->string('status', 16)->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['starts_at', 'sport']);
        });

        Schema::create('ppb_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('ppb_games')->restrictOnDelete();
            $table->foreignId('original_analyst_id')->constrained('ppb_analysts')->restrictOnDelete();
            $table->foreignId('current_analyst_id')->constrained('ppb_analysts')->restrictOnDelete();
            $table->string('kind', 16); // pick, long, video
            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['game_id', 'original_analyst_id', 'kind'], 'ppb_assign_unique_origin');
            $table->index(['current_analyst_id', 'status']);
        });

        Schema::create('ppb_substitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('ppb_assignments')->restrictOnDelete();
            $table->foreignId('from_analyst_id')->constrained('ppb_analysts')->restrictOnDelete();
            $table->foreignId('to_analyst_id')->constrained('ppb_analysts')->restrictOnDelete();
            $table->foreignId('performed_by_account_id')->nullable()->constrained('ppb_accounts')->nullOnDelete();
            $table->string('reason', 255);
            $table->timestamp('changed_at')->useCurrent();
            $table->index(['assignment_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppb_substitutions');
        Schema::dropIfExists('ppb_assignments');
        Schema::dropIfExists('ppb_games');
        Schema::dropIfExists('ppb_analysts');
    }
};
