<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PPB B1: new PPB database only; no legacy tables or external WP touched. */
return new class extends Migration {
    public function up(): void {
        Schema::table('ppb_games', function (Blueprint $table) {
            $table->dateTime('starts_at')->nullable()->change();
            $table->char('month_key', 7)->nullable()->after('id');
            $table->unsignedTinyInteger('game_type')->nullable()->after('month_key');
            $table->unsignedInteger('game_number')->nullable()->after('game_type');
            $table->unsignedInteger('active_number')->nullable()->after('game_number');
            $table->dateTime('recommended_at')->nullable()->after('active_number');
            $table->dateTime('wp_locked_at')->nullable()->after('recommended_at');
            $table->string('matchup_mode', 16)->nullable();
            $table->string('matchup_text', 255)->nullable();
            $table->unsignedBigInteger('fixture_id')->nullable();
            $table->unsignedTinyInteger('play_type')->nullable();
            $table->string('play_value', 160)->nullable();
            $table->unsignedTinyInteger('market_type')->nullable();
            $table->foreignId('reviewer_analyst_id')->nullable()->constrained('ppb_analysts')->nullOnDelete();
            $table->boolean('has_long_article')->default(false);
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->unique(['month_key', 'game_type', 'active_number'], 'ppb_game_active_number_unique');
            $table->index(['month_key', 'game_type', 'game_number'], 'ppb_game_display_search');
        });
        Schema::table('ppb_analysts', function (Blueprint $table) {
            // Legacy analyst_profiles.user_type: 0/1 analyst, 1/2 reviewer.
            $table->unsignedTinyInteger('user_type')->default(0);
        });
        Schema::table('ppb_assignments', function (Blueprint $table) {
            $table->unsignedTinyInteger('slot_no')->nullable()->after('kind');
            $table->unique(['game_id', 'kind', 'slot_no'], 'ppb_assignment_slot_unique');
        });
        Schema::create('ppb_schedule_scopes', function (Blueprint $table) {
            $table->char('month_key', 7);
            $table->unsignedTinyInteger('game_type');
            $table->primary(['month_key', 'game_type']);
        });
        Schema::create('ppb_schedule_events', function (Blueprint $table) {
            $table->id();
            // Intentionally no FK: retain original game ID after safe hard-deletion.
            $table->unsignedBigInteger('game_id');
            $table->foreignId('actor_id')->nullable()->constrained('ppb_accounts')->nullOnDelete();
            $table->string('event', 40);
            $table->text('detail_json')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['game_id', 'created_at']);
        });
        Schema::create('ppb_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('ppb_games')->restrictOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('ppb_assignments')->restrictOnDelete();
            $table->string('kind', 20);
            $table->foreignId('submitted_analyst_id')->nullable()->constrained('ppb_analysts')->nullOnDelete();
            $table->longText('content_body')->nullable();
            $table->string('status', 24)->default('submitted');
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['game_id', 'status']);
        });
        Schema::create('ppb_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('ppb_games')->restrictOnDelete();
            $table->string('wp_site', 120);
            $table->string('wp_post_id', 60);
            $table->string('status', 24);
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['wp_site', 'wp_post_id'], 'ppb_wp_post_unique');
            $table->index(['game_id', 'status', 'verified_at'], 'ppb_wp_game_status');
        });
        Schema::create('ppb_sport_fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 30);
            $table->string('league', 80)->nullable();
            $table->string('matchup_text', 255);
            $table->string('home_team', 100)->nullable();
            $table->string('away_team', 100)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->string('source', 40)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->timestamps();
            $table->index(['starts_at', 'sport']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('ppb_sport_fixtures');
        Schema::dropIfExists('ppb_publications');
        Schema::dropIfExists('ppb_submissions');
        Schema::dropIfExists('ppb_schedule_events');
        Schema::dropIfExists('ppb_schedule_scopes');
        Schema::table('ppb_analysts', function (Blueprint $table) { $table->dropColumn('user_type'); });
        Schema::table('ppb_assignments', function (Blueprint $table) {
            $table->dropUnique('ppb_assignment_slot_unique');
            $table->dropColumn('slot_no');
        });
        Schema::table('ppb_games', function (Blueprint $table) {
            $table->dropUnique('ppb_game_active_number_unique');
            $table->dropIndex('ppb_game_display_search');
            $table->dropForeign(['reviewer_analyst_id']);
            $table->dropColumn(['month_key','game_type','game_number','active_number','recommended_at',
                'wp_locked_at','matchup_mode','matchup_text','fixture_id','play_type','play_value','market_type',
                'reviewer_analyst_id','has_long_article','cancelled_at','cancel_reason']);
            $table->dateTime('starts_at')->nullable(false)->change();
        });
    }
};
