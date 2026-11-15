<?php
namespace Tests\Support;
use Illuminate\Support\Facades\DB;
use App\Services\Ppb\Evaluation\LegacyScheduleCalculator;
final class EvaluationIntegrationFixture
{
 public static function create():void
 {
 $fields=implode(',',array_map(fn($f)=>$f.' SMALLINT DEFAULT 0',LegacyScheduleCalculator::RATING_FIELDS));
DB::statement('CREATE TEMPORARY TABLE ppb_evaluations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,subject_type VARCHAR(12),subject_id BIGINT UNSIGNED,month CHAR(7),'.implode(',',array_map(fn($w)=>'score_interaction_w'.$w.' DECIMAL(8,2) NULL',range(1,5))).',score_interaction_avg DECIMAL(8,2) NULL,score_interaction_feedback SMALLINT DEFAULT 0,score_initiative INT DEFAULT 0,score_extra_adjustment INT DEFAULT 0,score_winrate_bonus INT DEFAULT 0,score_delivery_time DECIMAL(8,2) NULL,score_compliance DECIMAL(8,2) NULL,score_professionalism DECIMAL(8,2) NULL,next_rating_level TINYINT NULL,revision BIGINT UNSIGNED DEFAULT 1,source_sha256 CHAR(64),actor_id BIGINT UNSIGNED NULL,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL,UNIQUE(subject_type,subject_id,month)) ENGINE=InnoDB');
DB::statement('CREATE TEMPORARY TABLE ppb_evaluation_ratings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_type VARCHAR(12),source_id BIGINT UNSIGNED,subject_type VARCHAR(12),subject_id BIGINT UNSIGNED,month CHAR(7),'.$fields.',result_state TINYINT NULL,revision BIGINT UNSIGNED,source_sha256 CHAR(64),actor_id BIGINT UNSIGNED,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL,UNIQUE(source_type,source_id)) ENGINE=InnoDB');
DB::statement('CREATE TEMPORARY TABLE ppb_evaluation_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,evaluation_id BIGINT UNSIGNED,request_id CHAR(36) UNIQUE,operation VARCHAR(32),payload_sha256 CHAR(64),actor_id BIGINT UNSIGNED,before_json JSON,after_json JSON,created_at TIMESTAMP NULL) ENGINE=InnoDB');
DB::statement('CREATE TEMPORARY TABLE ppb_evaluation_attendance (id BIGINT AUTO_INCREMENT PRIMARY KEY,subject_type VARCHAR(12),subject_id BIGINT,game_id BIGINT,attendance_status TINYINT NULL,waived_submission_id BIGINT NULL,revision BIGINT,actor_id BIGINT,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL,UNIQUE(subject_type,subject_id,game_id)) ENGINE=InnoDB');
DB::statement('CREATE TEMPORARY TABLE ppb_evaluation_videos (id BIGINT AUTO_INCREMENT PRIMARY KEY,scheduled_at DATETIME,guest_1 VARCHAR(32),guest_2 VARCHAR(32) NULL,guest_3 VARCHAR(32) NULL,cancelled TINYINT DEFAULT 0,revision BIGINT,actor_id BIGINT,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL) ENGINE=InnoDB');
 }
}
