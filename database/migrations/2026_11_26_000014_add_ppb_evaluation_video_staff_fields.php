<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ppb_evaluation_videos', function (Blueprint $table) {
            $table->string('host_name', 100)->nullable();
            $table->string('editor_name', 100)->nullable();
            $table->unsignedTinyInteger('week_no')->nullable();
            $table->string('sport_label', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ppb_evaluation_videos', function (Blueprint $table) {
            $table->dropColumn(['host_name', 'editor_name', 'week_no', 'sport_label']);
        });
    }
};
