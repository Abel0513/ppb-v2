<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PPB W2 | Real business fields; existing legacy submissions are not rewritten. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ppb_submissions', function (Blueprint $table) {
            $table->text('recommend_pick')->nullable();
            $table->longText('analysis_text')->nullable();
            $table->unsignedBigInteger('last_edited_by_account_id')->nullable();
            $table->unsignedBigInteger('reviewed_by_account_id')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
        });
    }

    public function down(): void
    {
        // Destructive rollback is intentionally unavailable in production:
        // it would erase real manuscript/reviewer data.
        throw new \RuntimeException('PPB_W2_DESTRUCTIVE_SCHEMA_ROLLBACK_FORBIDDEN');
    }
};
