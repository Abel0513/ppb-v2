<?php
// Data tokenizer only; no old application include/eval/require.
$source=stream_get_contents(STDIN,500001);if(strlen($source)>500000)exit(2);
$tokens=token_get_all($source);$out='';$prev='';$beforePrev='';$prevKind=null;$frames=[];$indexes=[];$lastKey='';$sqlActive=false;$sqlQuote='';$sqlBlocked=false;
function interpolatedSqlFragment($text,&$quote,&$blocked){
    if(preg_match('/password|passwd|pwd|secret|token|credential|authorization|api.?key|\/\*|--|#/i',$text))$blocked=true;
    if($blocked)return "'[REDACTED_SQL]'";
    $safe='';
    for($i=0;$i<strlen($text);$i++){
        $c=$text[$i];
        if($quote!==''){
            if($c==='\\'){$i++;continue;}
            if($c===$quote)$quote='';
        }elseif($c===chr(39)||$c==='"'){$quote=$c;$safe.='[SQL_LITERAL]';}
        else $safe.=$c;
    }
    $safe=preg_replace('/[A-Za-z0-9_\-]{24,}|[0-9]{7,}|[^\x09\x0a\x0d\x20-\x7E]/','[REDACTED]',$safe);
    return '/* SQL FRAGMENT */ '.json_encode($safe,JSON_UNESCAPED_UNICODE);
}
function sqlStructure($literal){
    if(str_contains($literal,'\\'))return "'[REDACTED_SQL]'";
    $sql=preg_replace('~/\*.*?\*/|--[^\r\n]*|#[^\r\n]*~s','',$literal);
    if(preg_match('/password|passwd|pwd|secret|token|credential|authorization|api.?key/i',$sql))return "'[REDACTED_SQL]'";
    $sql=preg_replace_callback('/\x27[^\x27]*\x27|"[^"]*"/',function($m){
        return in_array(substr($m[0],1,-1),['%Y-%m','%Y-%m-%d','%Y','%m','%d'],true)?$m[0]:'[SQL_LITERAL]';
    },$sql);
    $check=preg_replace('/[\x27\x22]%[Ymd](?:-%[md]){0,2}[\x27\x22]/','',$sql);
    if(preg_match('/[\x27\x22]/',$check))return "'[REDACTED_SQL]'";
    $sql=preg_replace('/[A-Za-z0-9_\-]{24,}|[0-9]{7,}/','[REDACTED]',$sql);
    return '/* SQL STRUCTURE */ '.json_encode($sql,JSON_UNESCAPED_UNICODE);
}
foreach($tokens as $i=>$t){
    if(!is_array($t)){
        if($t==='"'){
            if($sqlActive){$sqlActive=false;$sqlQuote='';$sqlBlocked=false;}
            else{$sqlActive=$prev==='=>'&&$lastKey==='_string'&&in_array('where',array_column($frames,'name'),true);$sqlQuote='';$sqlBlocked=false;}
        }
        if($t==='[')$indexes[]=in_array($prevKind,[T_VARIABLE,T_STRING],true)||in_array($prev,[']',')'],true);
        if($t===']')array_pop($indexes);
        if($t==='(')$frames[]=['name'=>strtolower($prev),'arg'=>0,'arrays'=>count($indexes)];
        if($t===')')array_pop($frames);
        if($t===','&&$frames&&end($frames)['arrays']===count($indexes))$frames[count($frames)-1]['arg']++;
        $out.=$t;$beforePrev=$prev;$prev=$t;$prevKind=null;continue;
    }
    [$kind,$text]=$t;$replacement=$text;
    if($kind===T_ENCAPSED_AND_WHITESPACE&&$sqlActive)$replacement=interpolatedSqlFragment($text,$sqlQuote,$sqlBlocked);
    elseif(in_array($kind,[T_COMMENT,T_DOC_COMMENT,T_INLINE_HTML,T_ENCAPSED_AND_WHITESPACE],true))$replacement='';
    elseif($kind===T_CONSTANT_ENCAPSED_STRING){
        $literal=substr($text,1,-1);$next='';
        for($j=$i+1;$j<count($tokens);$j++){if(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)continue;$next=is_array($tokens[$j])?$tokens[$j][1]:$tokens[$j];break;}
        $frame=$frames?end($frames):['name'=>'','arg'=>-1];$call=$frame['name'];$arg=$frame['arg'];
        $position=($prev==='['&&$indexes&&end($indexes))||$next==='=>'||$prev==='('&&in_array($call,['m','d','u','a','i','c','assign','field','table','order','group','display','fetch'],true);
        $safe=$position&&preg_match('~^[A-Za-z_][A-Za-z0-9_/:., -]{0,100}$~D',$literal)&&!str_contains($literal,'://')
            &&!preg_match('/[a-f0-9]{16,}|[A-Za-z0-9]{24,}|password|passwd|pwd|secret|token|credential|authorization|api.?key|private.?key/i',$literal);
        $format=$call==='date'&&$arg===0&&in_array($literal,['Y-m','Y-m-d','Y-m-t','Y-m-t 23:59:59','Y-m-d H:i:s','Y-m-d H:i','Y','m','n','d','j','w','t','H','i','s','m-d','m/d','Y/m/d'],true);
        $relative=$call==='strtotime'&&$arg===0&&in_array($literal,['-1 month','-2 month','-2 months','-1 day','first day of this month','last day of this month'],true);
        $monthSuffix=$prev==='.'&&in_array($beforePrev,['$month','$targetMonth','$ratingMonth','$scoreMonth'],true)&&in_array($literal,['-01','-01 00:00:00'],true);
        $filter=$call==='i'&&$arg===2&&in_array($literal,['intval','floatval','trim','strval','int','float'],true);
        $operator=in_array('where',array_column($frames,'name'),true)&&in_array($literal,['in','not in','between','not between','eq','neq','gt','lt','egt','elt','like','notlike','exp'],true);
        if($literal===''||$safe||$format||$relative||$monthSuffix||$filter||$operator)$replacement=$text;
        elseif($call==='field'&&$arg===0||$prev==='=>'&&$lastKey==='_string'||$call==='where'&&$arg===0&&preg_match('/^\s*[A-Za-z_][A-Za-z0-9_.]{0,63}\s*(IN\s*\(|=|<>|!=|>=|<=|>|<|LIKE\b)/i',$literal)||preg_match('/^\s*(SELECT|UPDATE|INSERT|DELETE|WHERE|AND|OR|ORDER BY|GROUP BY|LEFT JOIN|INNER JOIN|CASE|FROM|SET)\b/i',$literal))$replacement=sqlStructure($literal);
        else $replacement="'[REDACTED_LITERAL]'";
        if($next==='=>')$lastKey=$literal;
    }elseif(($kind===T_LNUMBER||$kind===T_DNUMBER)&&preg_match('/[0-9]{7,}/',$text))$replacement='0/*REDACTED_NUMBER*/';
    $out.=$replacement.str_repeat("\n",max(0,substr_count($text,"\n")-substr_count($replacement,"\n")));
    if($kind!==T_WHITESPACE){$beforePrev=$prev;$prev=$text;$prevKind=$kind;}
}
echo json_encode(['source'=>$out,'format'=>'CONSERVATIVE_TOKEN_VIEW_NO_APPLICATION_EXECUTION'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
