<?php
// Fixed assessment-only source snapshot. Called inside the reader's READ ONLY TX.
// No request SQL, business writes, credentials or private names in the result.
declare(strict_types=1);
const EVAL_SCORES=['score_delivery_time','score_word_count','score_format','score_typos','score_semantic_clarity','score_reference_accuracy','score_logic_consistency','score_language_appropriateness','score_professional_bonus'];
function evaluationQuery(PDO $pdo,string $sql,array $params): array {
    $stmt=$pdo->prepare($sql);
    foreach(array_values($params) as $i=>$value)$stmt->bindValue($i+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    $stmt->execute();return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function evaluationSafeRows(array $rows,array $table): void {
    foreach($rows as $row)foreach($row as $key=>$value){
        must(in_array($key,$table['data_columns'],true),'SNAPSHOT_COLUMN_NOT_REVIEWED');
        if($value===null)continue;
        must(is_scalar($value)&&strlen((string)$value)<=80,'SNAPSHOT_CELL_LIMIT');
        if(isset($table['text_domains'][$key]))must((TEXT_DOMAINS[$table['name']][$key]??null)===$table['text_domains'][$key]&&domainValue($value,$table['text_domains'][$key]),'SNAPSHOT_TEXT_DOMAIN_DENIED');
        elseif(in_array($table['types'][$key],['date','datetime','timestamp','time','year'],true))must(preg_match('/^[0-9: .+\-]+$/D',(string)$value),'SNAPSHOT_DATE_DENIED');
        else must(is_numeric($value),'SNAPSHOT_NONNUMERIC_DENIED');
    }
}
function evaluationGroup(array $pick,int $id,array $markets): array {
    // SQL flag predicates distinguish NULL from 0. Never collapse those groups.
    $flags=array_map(fn($field)=>$pick[$field]===null?null:(int)$pick[$field],['is_win','is_bonus','is_long','is_substitute']);
    return [substr($pick['recommended_time'],0,7),(int)$pick['analyst_name']===$id,...$flags,
        array_key_exists((int)$pick['gid'],$markets),(int)($markets[(int)$pick['gid']]??0),
        ...array_map(fn($field)=>(int)$pick[$field],EVAL_SCORES)];
}
function evaluationSnapshot(PDO $pdo,array $q,array $catalog,array $seen): array {
    must(!empty($catalog['evaluation_read_v1']),'EVALUATION_DOMAIN_EXTENSION_REQUIRED');
    $id=$q['analyst_id']??null;$month=$q['month']??'';
    must(is_int($id)&&$id>0&&$id<=2147483647,'ANALYST_ID_DENIED');
    must(is_string($month)&&preg_match('/^[12][0-9]{3}-(0[1-9]|1[0-2])$/D',$month),'EVALUATION_MONTH_DENIED');
    $date=DateTimeImmutable::createFromFormat('!Y-m',$month);must($date&&$date->format('Y-m')===$month,'EVALUATION_MONTH_DENIED');
    $prior=$date->modify('-1 month')->format('Y-m');$next=$date->modify('+1 month')->format('Y-m');
    $start=$date->modify('-1 month')->format('Y-m-01 00:00:00');$end=$date->format('Y-m-t 23:59:59');$schema=$catalog['schema'];
    $table=static fn($name)=>ident($schema).'.'.ident($name);
    // REPEATABLE READ cannot promise an atomic snapshot over MyISAM tables.
    $names=['pay_analyst_profiles','pay_analyst_pick','pay_schedule','pay_attendance_record','pay_analyst_monthly_evaluation','pay_analyst_monthly_rating','pay_video_schedule'];
    $engines=evaluationQuery($pdo,'SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('.implode(',',array_fill(0,count($names),'?')).')',[$schema,...$names]);
    must(count($engines)===count($names),'SNAPSHOT_ENGINE_VISIBILITY_INCOMPLETE');
    foreach($engines as $engine)must($engine['ENGINE']==='InnoDB','SNAPSHOT_REQUIRES_TRANSACTIONAL_TABLES');
    $fetch=function(string $name,array $columns,string $suffix,array $params,int $cap)use($pdo,$table,$catalog,$schema,$seen):array {
        $key=$schema.'.'.$name;must(isset($catalog['tables'][$key])&&!array_diff($columns,$seen[$key]??[]),'SNAPSHOT_GRANTS_MISSING');
        $rows=evaluationQuery($pdo,'SELECT /*+ MAX_EXECUTION_TIME(1000) */ '.implode(',',array_map('ident',$columns)).' FROM '.$table($name).' '.$suffix.' LIMIT '.($cap+1),$params);
        must(count($rows)<=$cap,'SNAPSHOT_COVERAGE_LIMIT');evaluationSafeRows($rows,$catalog['tables'][$key]);return $rows;
    };
    $profiles=$fetch('pay_analyst_profiles',['id','sort_id','user_type','rating_level','analyst_level'],'WHERE id=?',[$id],1);
    must(count($profiles)===1&&in_array((int)$profiles[0]['user_type'],[0,1],true),'EVALUATION_SUBJECT_NOT_FOUND');
    $columns=array_merge(['id','gid','analyst_name','recommended_time','is_win','is_bonus','is_long','is_substitute'],EVAL_SCORES);
    $picks=$fetch('pay_analyst_pick',$columns,'WHERE (analyst_name=? OR is_substitute=?) AND recommended_time BETWEEN ? AND ? ORDER BY recommended_time,id',[$id,$id,$start,$end],2000);
    $gids=array_values(array_unique(array_map(fn($r)=>(int)$r['gid'],$picks)));$schedules=[];
    if($gids)$schedules=$fetch('pay_schedule',['id','recommended_time','market_type','reviewer','game_type','game_id'],'WHERE id IN ('.implode(',',array_fill(0,count($gids),'?')).') ORDER BY id',$gids,2000);
    $a=$schema.'.pay_attendance_record';$s=$schema.'.pay_schedule';
    must(!array_diff(['id','gid','uid','attendance_status'],$seen[$a]??[])&&!array_diff(['id','recommended_time'],$seen[$s]??[]),'ATTENDANCE_GRANTS_MISSING');
    $attendance=evaluationQuery($pdo,'SELECT /*+ MAX_EXECUTION_TIME(1000) */ a.id,a.gid,a.uid,a.attendance_status,s.recommended_time FROM '.$table('pay_attendance_record').' a INNER JOIN '.$table('pay_schedule').' s ON a.gid=s.id WHERE a.uid=? AND s.recommended_time BETWEEN ? AND ? ORDER BY s.recommended_time,a.id LIMIT 2001',[$id,$start,$end]);
    must(count($attendance)<=2000,'ATTENDANCE_COVERAGE_LIMIT');
    foreach($attendance as $row){
        $scheduleTime=$row['recommended_time'];unset($row['recommended_time']);evaluationSafeRows([$row],$catalog['tables'][$a]);
        evaluationSafeRows([['recommended_time'=>$scheduleTime]],$catalog['tables'][$s]);
        must((int)$row['uid']===$id,'ATTENDANCE_DATA_DENIED');
    }
    $evalColumns=array_merge(['id','analyst_id','eval_month','score_delivery_time','score_compliance','score_professionalism'],array_map(fn($w)=>'score_interaction_w'.$w,range(1,5)),['score_interaction_avg','score_interaction_feedback','score_initiative','score_winrate_bonus','score_extra_adjustment','updated_at']);
    $evaluations=$fetch('pay_analyst_monthly_evaluation',$evalColumns,'WHERE analyst_id=? AND eval_month IN (?,?) ORDER BY eval_month',[$id,$prior,$month],2);
    $ratings=$fetch('pay_analyst_monthly_rating',['analyst_id','rating_month','rating_level'],'WHERE analyst_id=? AND rating_month IN (?,?,?) ORDER BY rating_month',[$id,$prior,$month,$next],3);
    // Historical recent-10 and streak are global, not selected-month statistics.
    // The source streak itself is capped at 20. A top-N read is not a coverage read.
    must(!array_diff(['id','analyst_name','is_bonus','is_long','is_substitute','is_win','recommended_time'],$seen[$schema.'.pay_analyst_pick']??[]),'SNAPSHOT_GRANTS_MISSING');
    $recent=evaluationQuery($pdo,'SELECT /*+ MAX_EXECUTION_TIME(1000) */ id,is_win,recommended_time FROM '.$table('pay_analyst_pick').' WHERE analyst_name=? AND is_bonus=0 AND is_long=0 AND is_substitute=0 AND is_win IN (1,2) ORDER BY recommended_time DESC,id DESC LIMIT 20',[$id]);
    evaluationSafeRows($recent,$catalog['tables'][$schema.'.pay_analyst_pick']);
    $videos=[];
    foreach([$prior,$month] as $period){
        $d=DateTimeImmutable::createFromFormat('!Y-m',$period);
        [$sql,$params]=videoPlan(['analyst_id'=>$id,'start'=>$d->format('Y-m-01 00:00:00'),'end'=>$d->format('Y-m-t 23:59:59')],$catalog,$seen);
        $rows=evaluationQuery($pdo,$sql,$params);$videos[$period]=(int)array_values($rows[0])[0];
    }
    $payload=['format'=>'ppb-evaluation-source-v1','analyst_id'=>$id,'month'=>$month,'previous_month'=>$prior,'transactional_tables_verified'=>count($engines),'profile'=>$profiles[0],
        'pick_columns'=>$columns,'picks'=>array_map('array_values',$picks),'schedules'=>$schedules,'attendance'=>$attendance,
        'evaluations'=>$evaluations,'ratings'=>$ratings,'recent'=>$recent,'video_counts'=>$videos];
    $payload['source_sha256']=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION|JSON_UNESCAPED_SLASHES));
    if(!empty($q['summary_only'])){
        $summary=$payload;unset($summary['picks'],$summary['schedules'],$summary['attendance']);$groups=[];$counts=[];
        $markets=[];foreach($schedules as $schedule)$markets[(int)$schedule['id']]=$schedule['market_type'];
        foreach($picks as $pick){
            $key=json_encode(evaluationGroup($pick,$id,$markets),JSON_THROW_ON_ERROR);
            $groups[$key]=($groups[$key]??0)+1;
        }
        foreach($attendance as $row){$key=substr($row['recommended_time'],0,7).':'.(int)$row['attendance_status'];$counts[$key]=($counts[$key]??0)+1;}
        $summary['summary_flag_nulls_preserved']=true;
        $summary['pick_groups']=array_map(fn($key,$count)=>[...json_decode($key,true),$count],array_keys($groups),array_values($groups));
        $summary['attendance_counts']=$counts;$summary['raw_picks']=count($picks);$summary['private_names_returned']=0;return $summary;
    }
    return $payload;
}
