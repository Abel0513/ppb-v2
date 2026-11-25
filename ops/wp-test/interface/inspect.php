<?php
    declare(strict_types=1);
    require $argv[1].'/run.php';
    try {
      $journal=json_decode(file_get_contents(JOURNAL_DIR.'/journal.json'),true,512,JSON_THROW_ON_ERROR);
      if(($journal['create_http']??0)!==201)stop('NO_CONFIRMED_CREATE_RECEIPT');
      $def=sourceDef();$ctx=['url'=>$def['url'],'user'=>$def['user'],'password'=>$def['password']];
      $me=wpCall($ctx,'/wp-json/wp/v2/users/me?context=edit','GET');
      if($me['status']!==200||$me['network_error']||(int)($me['json']['id']??0)!==($journal['author']??0))stop('CREATOR_NOT_VERIFIED');
      $posts=lookup($ctx);
      if(count($posts)!==1)stop('EXISTING_DRAFT_NOT_UNIQUE');
      $b=$posts[0];$id=(int)($b['id']??0);
      if($id<=0||$id===30436||($b['type']??'')!=='expert'||($b['status']??'')!=='draft'||($b['slug']??'')!==SLUG||($b['title']['raw']??'')!==TITLE||($b['content']['raw']??'')!=='<!-- '.MARKER.' -->')stop('EXISTING_DRAFT_MARKERS_NOT_VERIFIED');
      if(array_key_exists('author',$b)&&(int)$b['author']!==$journal['author'])stop('EXPOSED_AUTHOR_MISMATCH');
      foreach(['id','creation_receipt_id'] as $key)if(isset($journal[$key])&&(int)$journal[$key]!==$id)stop('JOURNAL_ID_MISMATCH');
      echo 'PPB_JOURNAL_RECEIPT_V1='.json_encode(['ok'=>true,'candidate_expert_id'=>$id,'post_type'=>'expert','status'=>'draft','journal_phase'=>$journal['phase'],'create_http'=>201,'get_http'=>200,'author_exposed'=>array_key_exists('author',$b),'wp_write_requests'=>0],JSON_THROW_ON_ERROR)."\n";
    }catch(Throwable $e){$c=$e->getMessage();if(!preg_match('/^[A-Z0-9_]+$/D',$c))$c='READ_ONLY_DRAFT_CHECK_FAILED';echo 'PPB_JOURNAL_RECEIPT_V1='.json_encode(['ok'=>false,'error'=>$c,'wp_write_requests'=>0])."\n";exit(2);}
    