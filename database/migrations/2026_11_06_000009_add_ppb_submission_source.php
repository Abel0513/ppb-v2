<?php
/** PPB CT5 2026-11-06 | Link only unambiguous historical manuscript submissions. */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('ppb_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_source_pick_id')->nullable()->unique('ppb_submission_legacy_pick_unique');
        });
    }
    public function down(): void {
        throw new RuntimeException('CT5_SOURCE_LINKS_ARE_NOT_AUTO_DROPPABLE');
    }
};
