<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Candidate only. V2 release migration/backup gate must authorize live execution. */
return new class extends Migration {
    public function up():void
    {
        foreach(['color','wp_photo','author_id','line_id','line_name','rating_level'] as $field){
            if(Schema::hasColumn('ppb_analysts',$field))throw new RuntimeException('ANALYST_MASTER_COLUMNS_ALREADY_EXIST');
        }
        Schema::table('ppb_analysts',function(Blueprint $table){
            $table->string('color',7)->nullable(); // Legacy foreground CSS, not background biography.
            $table->unsignedBigInteger('wp_photo')->nullable(); // WP attachment ID; never a URL.
            $table->unsignedBigInteger('author_id')->nullable(); // Source permits explicit zero.
            $table->string('line_id',255)->nullable(); // Opaque administrative metadata, not authentication.
            $table->string('line_name',255)->nullable();
            $table->unsignedTinyInteger('rating_level')->nullable(); // Profile metadata, not month history.
        });
    }
    public function down():void
    {
        // Dropping populated identity/media associations requires explicit review.
        throw new LogicException('ANALYST_MASTER_ROLLBACK_REQUIRES_REVIEW');
    }
};
