<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** V2 production business/audit tables only; no cross-schema FK or test table. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ppb_evaluations', function (Blueprint $t) {
            $t->id();$t->string('subject_type',12);$t->unsignedBigInteger('subject_id');$t->char('month',7);
            foreach (range(1,5) as $week) $t->decimal('score_interaction_w'.$week,8,2)->nullable();
            $t->decimal('score_interaction_avg',8,2)->nullable();
            $t->smallInteger('score_interaction_feedback')->default(0);
            $t->integer('score_initiative')->default(0);$t->integer('score_extra_adjustment')->default(0);
            $t->integer('score_winrate_bonus')->default(0);
            foreach (['score_delivery_time','score_compliance','score_professionalism'] as $field) $t->decimal($field,8,2)->nullable();
            $t->unsignedTinyInteger('next_rating_level')->nullable();$t->unsignedBigInteger('revision')->default(1);
            $t->char('source_sha256',64);$t->foreignId('actor_id')->nullable()->constrained('ppb_accounts')->nullOnDelete();
            $t->timestamps();$t->unique(['subject_type','subject_id','month'],'ppb_eval_subject_month_unique');
        });
        Schema::create('ppb_evaluation_ratings', function (Blueprint $t) {
            $t->id();$t->string('source_type',12);$t->unsignedBigInteger('source_id');
            $t->string('subject_type',12);$t->unsignedBigInteger('subject_id');$t->char('month',7);
            foreach (['score_delivery_time','score_word_count','score_format','score_typos','score_semantic_clarity','score_reference_accuracy','score_logic_consistency','score_language_appropriateness','score_professional_bonus'] as $field) $t->smallInteger($field)->default(0);
            $t->tinyInteger('result_state')->nullable();$t->unsignedBigInteger('revision')->default(1);$t->char('source_sha256',64);
            $t->foreignId('actor_id')->nullable()->constrained('ppb_accounts')->nullOnDelete();$t->timestamps();
            $t->unique(['source_type','source_id'],'ppb_eval_source_rating_unique');$t->index(['subject_type','subject_id','month'],'ppb_eval_rating_subject_month');
        });
        Schema::create('ppb_evaluation_events', function (Blueprint $t) {
            $t->id();$t->foreignId('evaluation_id')->constrained('ppb_evaluations')->restrictOnDelete();
            $t->uuid('request_id')->unique();$t->string('operation',32);$t->char('payload_sha256',64);
            $t->foreignId('actor_id')->nullable()->constrained('ppb_accounts')->nullOnDelete();
            $t->json('before_json')->nullable();$t->json('after_json');$t->timestamp('created_at')->useCurrent();
        });
    }
    public function down(): void
    {
        throw new RuntimeException('PPB_EVALUATION_BUSINESS_HISTORY_REQUIRES_REVIEWED_RECOVERY');
    }
};
