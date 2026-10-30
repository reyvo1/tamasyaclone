<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$module = file_get_contents($root . '/api/support/120_promotion_codes.php');
$booking = file_get_contents($root . '/api/modules/finance/030_booking_finance.php');
$pos = file_get_contents($root . '/api/routes/065_pos_minibar.php');
$db = file_get_contents($root . '/database_setup.sql');
$migration = file_get_contents($root . '/migrations/V138_PROMOTION_CODES.sql');
$autoMigration = @file_get_contents($root . '/migrations/V140_PROMOTION_CODE_AUTO_APPLY.sql');
$pass = 0; $fail = 0;
function verify($ok, $name) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }

verify(is_string($module) && $module !== '', 'promotion module readable');
verify(str_contains($module, 'function tamasyaPromotionCodeAutoApply(PDO $pdo, string $appliesTo, float $amount'), 'auto apply helper exists');
verify(str_contains($module, "auto_apply=1"), 'auto apply filter present');
verify(str_contains($module, "event_name"), 'event_name normalized/supported');
verify(str_contains($module, "'auto_apply'"), 'auto_apply normalization present');
verify(str_contains($module, "ORDER BY created_at ASC, id ASC"), 'earliest created selection present');

verify(is_string($booking) && $booking !== '', 'booking finance readable');
verify(str_contains($booking, "tamasyaPromotionCodeAutoApply(\$pdo, 'booking', \$totalAmount"), 'booking auto apply integrated');
verify(str_contains($booking, "\$promoCodeInput === ''"), 'booking auto apply only without explicit promo');
verify(str_contains($booking, "manualDiscountProvided"), 'booking manual discount guard present');

verify(is_string($pos) && $pos !== '', 'pos route readable');
verify(str_contains($pos, "tamasyaPromotionCodeAutoApply(\$pdo, 'pos', \$subtotal"), 'pos auto apply integrated');
verify(str_contains($pos, "\$promoCodeInput === ''"), 'pos auto apply only without explicit promo');
verify(str_contains($pos, "manualDiscountProvided"), 'pos manual discount guard present');

verify(str_contains($db, "`auto_apply` tinyint(1)"), 'schema auto_apply column present');
verify(str_contains($db, "`event_name` varchar(190)"), 'schema event_name column present');
verify(str_contains($migration, "auto_apply TINYINT(1)"), 'migration auto_apply column present');
verify(str_contains($migration, "event_name VARCHAR(190)"), 'migration event_name column present');
verify(is_string($autoMigration) && str_contains($autoMigration, 'auto_apply'), 'alter migration present');

echo "$pass passed; $fail failed\n";
exit($fail ? 1 : 0);
