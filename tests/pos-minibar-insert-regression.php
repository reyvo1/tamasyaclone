<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$src = file_get_contents($root . '/api/modules/pos_inventory/085_pos_minibar.php');
$pass = 0; $fail = 0;
function verify($ok, $name) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }

verify(is_string($src) && $src !== '', '085_pos_minibar.php readable');
verify(str_contains($src, 'function tamasyaPosInsertTransaction'), 'tamasyaPosInsertTransaction exists');
// baseAmount fallback must be null (not amount) when missing.
verify(str_contains($src, "'baseAmount'=>isset(\$tx['baseAmount']) && \$tx['baseAmount'] !== '' ? tamasyaPosNumber(\$tx['baseAmount']) : null"), 'baseAmount falls back to null');
verify(!str_contains($src, "'baseAmount'=>isset(\$tx['baseAmount']) && \$tx['baseAmount'] !== '' ? tamasyaPosNumber(\$tx['baseAmount']) : tamasyaPosNumber(\$tx['amount'])"), 'no amount fallback');
verify(str_contains($src, "'taxSnapshotStatus'=>'confirmed'"), 'POS posts confirmed tax snapshot');
verify(str_contains($src, "'taxSource'=>(string)(\$tx['taxSource']??'pos_snapshot')"), 'POS posts explicit tax source');

echo "$pass passed; $fail failed\n";
exit($fail ? 1 : 0);
