<?php
// Tokenize as data only: no include/eval/require of application source.
$source=stream_get_contents(STDIN,500001);if(strlen($source)>500000)exit(2);
$tokens=token_get_all($source);$out='';$prev='';$call='';
foreach($tokens as $i=>$t){
    if(!is_array($t)){$out.=$t;if($t==='(')$call=$prev;$prev=$t;continue;}
    [$kind,$text]=$t;$replacement=$text;
    if(in_array($kind,[T_COMMENT,T_DOC_COMMENT,T_INLINE_HTML,T_ENCAPSED_AND_WHITESPACE],true))$replacement='';
    elseif($kind===T_CONSTANT_ENCAPSED_STRING){
        $literal=substr($text,1,-1);$next='';
        for($j=$i+1;$j<count($tokens);$j++){if(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)continue;$next=is_array($tokens[$j])?$tokens[$j][1]:$tokens[$j];break;}
        // Only provable schema/array-key/route identifier positions retain literals.
        // Arbitrary assignment values, Chinese names/UI text, auth and HTML are redacted.
        $position=$prev==='['||$next==='=>'||in_array(strtolower($call),['m','d','u','a','field','table','order','group','display','fetch'],true)&&$prev==='(';
        $safe=$position&&preg_match('~^[A-Za-z_][A-Za-z0-9_/., ]{0,100}$~D',$literal)
          &&!preg_match('/[a-f0-9]{16,}|[A-Za-z0-9]{24,}|password|passwd|pwd|secret|token|credential|authorization|api.?key|private.?key/i',$literal);
        if($safe)$replacement=$text;
        elseif(!str_contains($literal,'\\')&&preg_match('/^\s*(SELECT|UPDATE|INSERT|DELETE|WHERE|AND|OR|ORDER BY|GROUP BY|LEFT JOIN|INNER JOIN|CASE|FROM|SET)\b/i',$literal)){
            $sql=preg_replace('~/\*.*?\*/|--[^\r\n]*|#[^\r\n]*~s','',$literal);$sql=preg_replace('/\x27[^\x27]*\x27|"[^"]*"/','[SQL_LITERAL]',$sql);
            $sql=preg_replace('/[A-Za-z0-9_\-]{24,}|[0-9]{7,}/','[REDACTED]',$sql);
            $replacement=preg_match('/[\x27\x22]/',$sql)?"'[REDACTED_SQL]'":'/* SQL STRUCTURE */ '.json_encode($sql,JSON_UNESCAPED_UNICODE);
        }else $replacement="'[REDACTED_LITERAL]'";
    }elseif(($kind===T_LNUMBER||$kind===T_DNUMBER)&&preg_match('/[0-9]{7,}/',$text))$replacement='0/*REDACTED_NUMBER*/';
    $out.=$replacement.str_repeat("\n",max(0,substr_count($text,"\n")-substr_count($replacement,"\n")));
    if($kind!==T_WHITESPACE)$prev=$text;
}
echo json_encode(['source'=>$out,'format'=>'CONSERVATIVE_TOKEN_VIEW_NO_APPLICATION_EXECUTION'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
