<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppb_attendance_deletions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('legacy_record_id')->unique();
            $table->unsignedBigInteger('legacy_gid');
            $table->string('subject_type', 12);
            $table->unsignedBigInteger('subject_id');
            $table->tinyInteger('attendance_status');
            $table->dateTime('recommended_time');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->uuid('request_id')->unique();
            $table->timestamp('deleted_at');
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'restored_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppb_attendance_deletions');
    }
};
