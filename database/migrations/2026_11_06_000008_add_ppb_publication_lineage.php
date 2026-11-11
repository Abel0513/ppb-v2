<?php
/** PPB CT5 2026-11-06 | Source lineage: no replacement of authoritative WP publication status. */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('ppb_publications', function (Blueprint $table) {
            $table->string('publication_kind', 16)->nullable();  // game, long
            $table->string('legacy_source_field', 60)->nullable(); // pay_schedule.expert_id / pay_analyst_pick.short_analysis
            $table->unsignedBigInteger('legacy_source_id')->nullable();
            $table->string('legacy_wp_post_id', 60)->nullable(); // candidate is NOT publication evidence
        });
    }
    public function down(): void {
        throw new RuntimeException('CT5_PRODUCTION_LINEAGE_IS_NOT_AUTO_DROPPABLE');
    }
};
