<?php
/** PPB CT2 | 2026-11-05 | Permanent archival business records, NOT a test table. */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('ppb_legacy_pick_contents', function (Blueprint $table) {
            $table->unsignedBigInteger('source_pick_id')->primary();
            $table->unsignedBigInteger('game_id');
            $table->unsignedBigInteger('legacy_analyst_id');
            $table->string('kind', 16);
            $table->string('legacy_game_code', 30)->nullable();
            $table->text('recommend_pick')->nullable();
            $table->text('short_analysis')->nullable();
            $table->integer('legacy_is_substitute');
            $table->tinyInteger('legacy_is_win')->nullable();
            $table->dateTime('legacy_recommended_time');
            $table->string('wp_post_candidate', 60)->nullable();
            $table->char('source_sha256', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['game_id', 'kind'], 'ppb_legacy_pick_game_kind');
        });
    }
    public function down(): void {
        // Destructive reversal is intentionally forbidden: historical manuscript data.
        // Never drop these production records automatically from a generic rollback.
        throw new RuntimeException('PPB_CT2_ARCHIVE_NEEDS_EXPLICIT_REVIEW_BEFORE_DROP');
    }
};
