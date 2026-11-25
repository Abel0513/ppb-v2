<?php
// INTERNAL PIPE ONLY: feed result into the reviewed structure parser, never export it.
// No include/require/eval of the input; the original source-tokens.php is unchanged.
$source = stream_get_contents(STDIN, 500001);
if (strlen($source) > 500000) exit(2);
$html = '';
foreach (token_get_all($source) as $token) {
    if (is_array($token) && $token[0] === T_INLINE_HTML) $html .= $token[1];
}
echo json_encode(['inline_html' => $html], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
