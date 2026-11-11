<?php
/** PPB CT4 | 2026-11-05 | Permanent read-only WP manuscript archive, not a test table. */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('ppb_legacy_wp_articles', function(Blueprint $t) {
            $t->unsignedBigInteger('source_pick_id')->primary();
            $t->unsignedBigInteger('game_id')->index();
            $t->unsignedBigInteger('wp_post_id')->unique();
            $t->string('wp_site',120);
            $t->string('wp_link',500);
            $t->dateTime('published_gmt');
            $t->dateTime('modified_gmt');
            $t->char('content_sha256',64);
            $t->mediumText('body_html');
            $t->mediumText('body_text');
            $t->dateTime('verified_at');
            $t->timestamp('created_at')->useCurrent();
        });
    }
    public function down(): void {
        throw new RuntimeException('CT4_PERMANENT_ARTICLE_ARCHIVE_CANNOT_BE_DROPPED_AUTOMATICALLY');
    }
};
