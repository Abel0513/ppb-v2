<?php
/** Source-token parser reused from ACF6; no evaluation of legacy PHP. */
declare(strict_types=1);
function stop(string $reason): never { throw new RuntimeException($reason); }
function txt($t):string { return is_array($t)?$t[1]:$t; }
function whitespace($t):bool { return is_array($t)&&in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true); }
function strvalPhp(string $raw):?string {
    $raw=trim($raw);$n=strlen($raw);if($n<2)return null;$q=$raw[0];if(($q!=="'"&&$q!=='"')||$raw[$n-1]!==$q)return null;
    $body=substr($raw,1,-1);
    if($q==="'")return str_replace(["\\\\","\\'"],["\\","'"],$body);
    if(str_contains($body,'$')||str_contains($body,'{'))return null;
    return stripcslashes($body);
}
function methodTokens(string $php,string $method):array {
    $a=token_get_all($php);$n=count($a);for($i=0;$i<$n;$i++){
        if(!is_array($a[$i])||$a[$i][0]!==T_FUNCTION)continue;
        $j=$i+1;while($j<$n&&(whitespace($a[$j])||txt($a[$j])==='&'))$j++;
        if($j>=$n||!is_array($a[$j])||$a[$j][0]!==T_STRING||$a[$j][1]!==$method)continue;
        $k=$j;while($k<$n&&txt($a[$k])!=='{')$k++;if($k===$n)break;
        $depth=0;$result=[];for(;$k<$n;$k++){$t=$a[$k];$result[]=$t;if(txt($t)==='{')$depth++;if(txt($t)==='}'&&--$depth===0)return $result; if(txt($t)!=='}') { /* no-op */ }}
    }
    stop('legacy publishExpert method not found');
}
// Dedicated brace extraction without evaluating source or invoking any old controller method.
function methodPart(string $php,string $method):string {
    $a=token_get_all($php);$n=count($a);
    for($i=0;$i<$n;$i++){
        if(!is_array($a[$i])||$a[$i][0]!==T_FUNCTION)continue;
        $j=$i+1;while($j<$n&&(whitespace($a[$j])||txt($a[$j])==='&'))$j++;
        if($j>=$n||!is_array($a[$j])||$a[$j][0]!==T_STRING||$a[$j][1]!==$method)continue;
        while($j<$n&&txt($a[$j])!=='{')$j++;
        if($j===$n)break;$depth=0;$out='';for(;$j<$n;$j++){
            $v=txt($a[$j]);$out.=$v;if($v==='{')$depth++;if($v==='}'&&--$depth===0)return $out;
            if($v!==' }'){} // deliberately no eval
        }
    }
    stop('legacy method body unavailable');
}
/** Parse only literal string-concatenation syntax from PHP token stream; NEVER eval source.
 * Real ACF keys may include digits, CJK, mixed scripts or leading underscores.
 * JSON object member names are not required to be English identifiers.
 */
function parseKeyExpr(array $tokens, int $position):?string {
    $tokens=array_values(array_filter($tokens,fn($v)=>!whitespace($v)));
    $str='';$needPart=true;$hasVar=false;
    foreach($tokens as $token){
        $value=txt($token);
        if($value==='.') { if($needPart)return null;$needPart=true;continue; }
        if(!$needPart)return null;
        if(is_array($token)&&$token[0]===T_CONSTANT_ENCAPSED_STRING) {
            $v=strvalPhp($value);if($v===null)return null;
            $str.=$v;
        } elseif(is_array($token)&&$token[0]===T_VARIABLE&&$value==='$newPosition') {
            $str.=(string)$position;$hasVar=true;
        } else return null;
        $needPart=false;
    }
    if($str===''||$needPart||strlen($str)>180||!preg_match('//u',$str))return null;
    if(preg_match('/[\x00-\x1F\x7F\x22\x27\x5C\x2F<>\[\]{}]/u',$str))return null;
    if(preg_match('/(?:secret|token|password|authorization|api.?key)/iu',$str))return null;
    return $str;
}
function parseFields(string $method):array {
    $a=token_get_all('<?php '.$method);$n=count($a);$assign=[];$failed=0;$dynamicSignatures=[];$sourceKeys=[];$failedKinds=[];
    for($i=0;$i<$n;$i++){
        if(!is_array($a[$i])||$a[$i][0]!==T_VARIABLE||$a[$i][1]!=='$fields')continue;
        $j=$i+1;while($j<$n&&whitespace($a[$j]))$j++;
        if($j===$n||txt($a[$j])!=='[')continue;
        $j++;$depth=1;$expr=[];
        for(;$j<$n;$j++){$v=txt($a[$j]);if($v==='[')$depth++;if($v===']'){$depth--;if($depth===0)break;}$expr[]=$a[$j];}
        $j++;while($j<$n&&whitespace($a[$j]))$j++;
        if($j>=$n||txt($a[$j])!=='=')continue;
        $eval=fn($idx)=>parseKeyExpr($expr,$idx);
        $key=$eval(0);if($key===null){$failed++;$failedKinds[]=['tokens'=>count($expr),'has_newPosition'=>str_contains(implode('',array_map('txt',$expr)),'$newPosition'),'has_literal'=>count(array_filter($expr,fn($z)=>is_array($z)&&$z[0]===T_CONSTANT_ENCAPSED_STRING))>0];continue;}
        $dyn=false;foreach($expr as $e)if(is_array($e)&&$e[0]===T_VARIABLE&&$e[1]==='$newPosition'){$dyn=true;break;}
        // Read the immediate RHS literal only; do not evaluate or execute legacy source.
        $rhsIdx=$j+1;while($rhsIdx<$n&&whitespace($a[$rhsIdx]))$rhsIdx++;
        $rhsTok=$a[$rhsIdx]??null;
        $rhsLiteral=null;
        if(is_array($rhsTok)&&$rhsTok[0]===T_CONSTANT_ENCAPSED_STRING){$rhsLiteral=strvalPhp($rhsTok[1]);}
        elseif(is_array($rhsTok)&&$rhsTok[0]===T_LNUMBER){$rhsLiteral=(int)$rhsTok[1];}
        $entry=['key'=>$key,'dynamic'=>$dyn,'expr'=>implode('',array_map('txt',$expr)),'rhs_literal'=>$rhsLiteral];
        if($dyn){
            $set=[];for($idx=0;$idx<5;$idx++){$k=$eval($idx);if($k===null)stop('unsupported dynamic ACF key expression');$set[]=$k;$sourceKeys[$k]=true;}
            $entry['keys']=$set;$dynamicSignatures[]=$set;
        }else{$sourceKeys[$key]=true;}
        $assign[]=$entry;
    }
    $keys=array_keys($sourceKeys);
    if(count($keys)<16||count($dynamicSignatures)<5){report(['version'=>VERSION,'result'=>'EXTRACTION_BLOCKED','keys_found'=>count($keys),'dynamic_groups_found'=>count($dynamicSignatures),'unsupported_expressions'=>$failed,'unsupported_shapes'=>$failedKinds,'wp_writes'=>0],OUT_PREVIEW);stop('ACF extraction insufficient keys='.count($keys).' groups='.count($dynamicSignatures).' unsupported='.$failed);}
    if(count($dynamicSignatures)!==6)stop('expected six source-defined analyst field groups; got '.count($dynamicSignatures));
    if(count(array_unique($keys))!==count($keys))stop('duplicate ACF field keys');
    // Bonus fields are fixed literal assignments after '$bonusPick =' and before '$longPick ='.
    $bonusSection=substr($method,(int)strpos($method,'$bonusPick ='));
    if(str_contains($bonusSection,'$longPick ='))$bonusSection=substr($bonusSection,0,(int)strpos($bonusSection,'$longPick ='));
    $bonusAssignments=[];if($bonusSection!==''){
       $q=token_get_all('<?php '.$bonusSection);$m=count($q);
       for($i=0;$i<$m;$i++){
          if(!is_array($q[$i])||$q[$i][0]!==T_VARIABLE||$q[$i][1]!=='$fields')continue;
          $j=$i+1;while($j<$m&&whitespace($q[$j]))$j++;if(txt($q[$j]??'')!=='[')continue;
          $j++;$expr=[];for(;$j<$m&&txt($q[$j])!==']';$j++)$expr[]=$q[$j];
          $key=parseKeyExpr($expr,0);if($key!==null&&!in_array($key,$bonusAssignments,true))$bonusAssignments[]=$key;
       }
    }
    if(count($bonusAssignments)<5)stop('cannot prove five BONUS ACF keys');
    return ['keys'=>$keys,'assignments'=>$assign,'dynamic_groups'=>$dynamicSignatures,'bonus_keys'=>array_slice($bonusAssignments,0,5),'unsupported_expressions'=>$failed];
}
function constants(string $src):array {
    $a=token_get_all($src);$n=count($a);$wanted=['WP_SITE'=>true,'WP_USER'=>true,'WP_APP_PWD'=>true];$out=[];
    for($i=0;$i<$n;$i++){
       if(!is_array($a[$i])||$a[$i][0]!==T_CONST)continue;$j=$i+1;while($j<$n&&whitespace($a[$j]))$j++;
       if(!is_array($a[$j]??null)||$a[$j][0]!==T_STRING||!isset($wanted[$a[$j][1]]))continue;
       $key=$a[$j][1];$j++;while($j<$n&&whitespace($a[$j]))$j++;
       if(txt($a[$j]??'')!=='=')continue;$j++;while($j<$n&&whitespace($a[$j]))$j++;
       if(is_array($a[$j]??null)&&$a[$j][0]===T_CONSTANT_ENCAPSED_STRING){$s=strvalPhp($a[$j][1]);if($s!==null)$out[$key]=$s;}
    }
    return $out;
}
/** Choose real source identities, not synthetic IDs; BONUS can be the sixth real eligible person,
 * or reuse the first short analyst when fewer than six are available. */
