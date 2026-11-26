<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// First release attempt (3e29744) failed on MySQL: the composite index's default
// name was 66 chars (> 64). MySQL DDL autocommits, so the table and its unique
// indexes may already exist without this migration being recorded. up() is
// therefore idempotent and only adds what is missing. V2-owned table only.
return new class extends Migration
{
    private const TABLE = 'ppb_attendance_deletions';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('legacy_record_id');
                $table->unsignedBigInteger('legacy_gid');
                $table->string('subject_type', 12);
                $table->unsignedBigInteger('subject_id');
                $table->tinyInteger('attendance_status');
                $table->dateTime('recommended_time');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->uuid('request_id');
                $table->timestamp('deleted_at')->nullable();
                $table->timestamp('restored_at')->nullable();
                $table->timestamps();
            });
        }
        $indexes = [
            ['ppb_attendance_deletions_legacy_record_id_unique', 'unique', ['legacy_record_id']],
            ['ppb_attendance_deletions_request_id_unique', 'unique', ['request_id']],
            ['ppb_att_del_subject_restored_idx', 'index', ['subject_type', 'subject_id', 'restored_at']],
        ];
        foreach ($indexes as [$name, $kind, $columns]) {
            if (Schema::hasIndex(self::TABLE, $name)) {
                continue;
            }
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->{$kind}($columns, $name));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
