<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Candidate only. Live execution still requires the isolated backup/migrate gate. */
return new class extends Migration {
    public function up():void
    {
        foreach(['background','analyst_level'] as $field){
            if(Schema::hasColumn('ppb_analysts',$field))throw new RuntimeException('ANALYST_MASTER_COLUMNS_ALREADY_EXIST');
        }
        Schema::table('ppb_analysts',function(Blueprint $table){
            $table->string('background',7)->nullable(); // Legacy CSS background on pay_analyst_profiles, not biography.
            $table->unsignedTinyInteger('analyst_level')->nullable(); // Legacy tinyint 0/1 flag, distinct from rating_level and user_type. Labels remain redacted.
        });
    }
    public function down():void
    {
        throw new LogicException('ANALYST_MASTER_ROLLBACK_REQUIRES_REVIEW');
    }
};
