#!/usr/bin/env bash
set -euo pipefail
umask 077
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PHP=/www/server/php/84/bin/php
[[ -x "$PHP" ]] || PHP="$(command -v php)"
cd "$HERE"
sha256sum -c SHA256SUMS
"$PHP" -r 'foreach (["curl", "pdo_mysql", "posix", "ctype", "tokenizer"] as $e) if (!extension_loaded($e)) {fwrite(STDERR,"STOP: PHP_EXTENSION_MISSING_".$e."\n"); exit(2);}'
"$PHP" "$HERE/test.php"
"$PHP" "$HERE/run.php"
