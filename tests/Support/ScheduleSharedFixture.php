<?php
namespace Tests\Support;
final class ScheduleSharedFixture
{
    public static function create(\PDO $pdo,string $nonce):void
    {
        if((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='mysql' AND TABLE_NAME LIKE 'ppb_%'")->fetchColumn()!==0)throw new \RuntimeException('DISPOSABLE_NOT_FRESH');
        $pdo->exec('CREATE TABLE ppb_concurrency_fixture (id INT PRIMARY KEY,nonce CHAR(32)) ENGINE=InnoDB');
        $stmt=$pdo->prepare('INSERT INTO ppb_concurrency_fixture VALUES(1,?)');$stmt->execute([$nonce]);
        $pdo->exec('CREATE TABLE ppb_accounts (id BIGINT PRIMARY KEY,name VARCHAR(80),username VARCHAR(80),is_active TINYINT) ENGINE=InnoDB');
        $sqls=[
            'CREATE TABLE ppb_schedule_scopes (month_key CHAR(7),game_type INT,PRIMARY KEY(month_key,game_type)) ENGINE=InnoDB',
            "CREATE TABLE ppb_games (id BIGINT AUTO_INCREMENT PRIMARY KEY,month_key CHAR(7) DEFAULT '2026-11',game_type INT DEFAULT 1,game_number INT,active_number INT,status VARCHAR(24) DEFAULT 'scheduled',sport VARCHAR(30),league VARCHAR(50),home_team VARCHAR(50),away_team VARCHAR(50),matchup_mode VARCHAR(24),matchup_text VARCHAR(255),fixture_id BIGINT NULL,play_type INT,play_value VARCHAR(160),market_type INT,reviewer_analyst_id BIGINT NULL,has_long_article TINYINT DEFAULT 0,recommended_at DATETIME,starts_at DATETIME NULL,notes TEXT NULL,wp_locked_at DATETIME NULL,cancelled_at DATETIME NULL,cancel_reason VARCHAR(255) NULL,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE(month_key,game_type,active_number)) ENGINE=InnoDB",
            'CREATE TABLE ppb_assignments (id BIGINT AUTO_INCREMENT PRIMARY KEY,game_id BIGINT,original_analyst_id BIGINT,current_analyst_id BIGINT,kind VARCHAR(20),slot_no INT,status VARCHAR(24),notes TEXT NULL,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE(game_id,kind,slot_no),UNIQUE(game_id,original_analyst_id,kind)) ENGINE=InnoDB',
            'CREATE TABLE ppb_analysts (id BIGINT PRIMARY KEY,account_id BIGINT NULL,name VARCHAR(80),display_name VARCHAR(80),status VARCHAR(24),user_type INT) ENGINE=InnoDB',
            'CREATE TABLE ppb_substitutions (id BIGINT AUTO_INCREMENT PRIMARY KEY,assignment_id BIGINT,from_analyst_id BIGINT,to_analyst_id BIGINT,performed_by_account_id BIGINT,reason VARCHAR(255),changed_at DATETIME) ENGINE=InnoDB',
            'CREATE TABLE ppb_submissions (id BIGINT AUTO_INCREMENT PRIMARY KEY,game_id BIGINT,assignment_id BIGINT,kind VARCHAR(20),submitted_analyst_id BIGINT NULL,status VARCHAR(24),content_body LONGTEXT NULL,recommend_pick TEXT NULL,analysis_text LONGTEXT NULL,submitted_at DATETIME NULL,last_edited_by_account_id BIGINT NULL,reviewed_by_account_id BIGINT NULL,reviewed_at DATETIME NULL,review_note TEXT NULL,legacy_source_pick_id BIGINT NULL,created_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB',
            'CREATE TABLE ppb_publications (id BIGINT AUTO_INCREMENT PRIMARY KEY,game_id BIGINT,status VARCHAR(24),verified_at DATETIME NULL) ENGINE=InnoDB',
            'CREATE TABLE ppb_schedule_events (id BIGINT AUTO_INCREMENT PRIMARY KEY,game_id BIGINT,actor_id BIGINT,event VARCHAR(40),detail_json JSON,created_at DATETIME) ENGINE=InnoDB',
            'CREATE TABLE ppb_auth_audits (id BIGINT AUTO_INCREMENT PRIMARY KEY,account_id BIGINT,event VARCHAR(120),ip VARCHAR(45),user_agent VARCHAR(255),created_at DATETIME) ENGINE=InnoDB',
            'CREATE TABLE ppb_sport_fixtures (id BIGINT PRIMARY KEY,sport VARCHAR(30),starts_at DATETIME NULL) ENGINE=InnoDB',
            'CREATE TABLE ppb_legacy_pick_contents (source_pick_id BIGINT PRIMARY KEY,game_id BIGINT,kind VARCHAR(20)) ENGINE=InnoDB',
            'CREATE TABLE ppb_legacy_wp_articles (source_pick_id BIGINT PRIMARY KEY,game_id BIGINT) ENGINE=InnoDB',
        ];
        foreach($sqls as $sql){
            if(str_contains($sql,'CREATE TABLE ppb_publications '))$sql=str_replace('status VARCHAR(24),','wp_site VARCHAR(120),wp_post_id VARCHAR(60),status VARCHAR(24),created_at DATETIME NULL,updated_at DATETIME NULL,',$sql);
            $pdo->exec($sql);
        }
        $pdo->exec('ALTER TABLE ppb_publications ADD UNIQUE(wp_site,wp_post_id), ADD INDEX(game_id,status,verified_at)');
        // Mirror relevant deployed FK/unique contracts (all inside disposable RAM).
        foreach([
            'ppb_games'=>['reviewer_analyst_id REFERENCES ppb_analysts(id)'],
            'ppb_assignments'=>['game_id REFERENCES ppb_games(id)','original_analyst_id REFERENCES ppb_analysts(id)','current_analyst_id REFERENCES ppb_analysts(id)'],
            'ppb_submissions'=>['game_id REFERENCES ppb_games(id)','assignment_id REFERENCES ppb_assignments(id)','submitted_analyst_id REFERENCES ppb_analysts(id)'],
            'ppb_substitutions'=>['assignment_id REFERENCES ppb_assignments(id)','from_analyst_id REFERENCES ppb_analysts(id)','to_analyst_id REFERENCES ppb_analysts(id)','performed_by_account_id REFERENCES ppb_accounts(id)'],
            'ppb_publications'=>['game_id REFERENCES ppb_games(id)'],
            'ppb_schedule_events'=>['actor_id REFERENCES ppb_accounts(id)'],
            'ppb_auth_audits'=>['account_id REFERENCES ppb_accounts(id)'],
        ] as $table=>$keys)foreach($keys as $i=>$key){[$column,$reference]=explode(' REFERENCES ',$key);$pdo->exec('ALTER TABLE '.$table.' ADD CONSTRAINT fk_'.$table.'_'.$i.' FOREIGN KEY ('.$column.') REFERENCES '.$reference);}
    }
    public static function reset(\PDO $pdo):void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach(['ppb_auth_audits','ppb_schedule_events','ppb_publications','ppb_substitutions','ppb_submissions','ppb_assignments','ppb_games','ppb_analysts','ppb_accounts','ppb_schedule_scopes'] as $table)$pdo->exec('TRUNCATE '.$table);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $pdo->exec("INSERT INTO ppb_accounts VALUES(41,'Fixture A','fixture-a',1),(42,'Fixture B','fixture-b',1)");
        foreach([9,10,11,12] as $id)$pdo->exec("INSERT INTO ppb_analysts (id,name,display_name,status,user_type) VALUES($id,'Fixture','Fixture','active',1)");
        $pdo->exec("INSERT INTO ppb_schedule_scopes VALUES('2026-11',1),('2026-11',2)");
        foreach([[1,1,1],[2,1,2],[3,2,1]] as [$id,$type,$number])$pdo->exec("INSERT INTO ppb_games (id,game_type,game_number,active_number,reviewer_analyst_id,matchup_text,recommended_at) VALUES($id,$type,$number,$number,9,'Fixture match','2026-11-20 03:00:00')");
        $pdo->exec("INSERT INTO ppb_assignments(id,game_id,original_analyst_id,current_analyst_id,kind,slot_no,status) VALUES(3,2,12,12,'pick',1,'active')");
        foreach([[1,9],[2,11]] as [$id,$author])$pdo->exec("INSERT INTO ppb_assignments(id,game_id,original_analyst_id,current_analyst_id,kind,slot_no,status) VALUES($id,1,$author,$author,'pick',$id,'active')");
    }
}
