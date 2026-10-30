<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$mutation = file_get_contents($root . '/api/modules/finance/026_financial_mutation_authority.php');
$booking = file_get_contents($root . '/api/modules/finance/030_booking_finance.php');
$checkout = file_get_contents($root . '/api/modules/front_office/040_rooms_checkout_housekeeping.php');
$webCheckout = file_get_contents($root . '/api/routes/020_hotel_booking.php');
$audit = file_get_contents($root . '/api/routes/030_ota_cleanup_audit.php');
$policy = file_get_contents($root . '/api/modules/finance/018_canonical_business_policy.php');
$posting = file_get_contents($root . '/api/modules/finance/025_financial_posting_authority.php');
$schema = file_get_contents($root . '/database_setup.sql');
$migration = file_get_contents($root . '/migrations/V137_BACKFILL_DATABASE_20260827.sql');
$alignment = file_get_contents($root . '/api/support/080_schema_alignment.php');
$projection = file_get_contents($root . '/api/support/070_data_projection_scope.php');
$pass = 0; $fail = 0;
function verify($ok, $name) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }

verify(is_string($mutation) && $mutation !== '', '026_financial_mutation_authority.php readable');
verify(is_string($booking) && $booking !== '', '030_booking_finance.php readable');
verify(is_string($checkout) && $checkout !== '', '040_rooms_checkout_housekeeping.php readable');
verify(is_string($webCheckout) && $webCheckout !== '', '020_hotel_booking.php readable');
verify(is_string($audit) && $audit !== '', '030_ota_cleanup_audit.php readable');
verify(is_string($policy) && $policy !== '', '018_canonical_business_policy.php readable');
verify(is_string($posting) && $posting !== '', '025_financial_posting_authority.php readable');
verify(is_string($schema) && $schema !== '', 'database_setup.sql readable');
verify(is_string($migration) && $migration !== '', 'V137 backfill migration readable');
verify(is_string($alignment) && $alignment !== '', '080_schema_alignment.php readable');
verify(is_string($projection) && $projection !== '', '070_data_projection_scope.php readable');

verify(str_contains($mutation, "'tax_snapshot'=>['owner'=>'tax_projection','allowed'=>['baseAmount','taxAmount','taxRate','taxSnapshotStatus','taxSource','taxRuleId','taxRuleName']"), 'tax_snapshot accepts taxRuleName');
verify(str_contains($mutation, "'taxSnapshotStatus','taxSource','taxRuleId','taxRuleName','transactionKind'"), 'manual edit accepts taxRuleName');
verify(str_contains($mutation, "'taxSource','taxRuleId','taxRuleName','taxNote'"), 'audit correction accepts taxRuleName');
verify(str_contains($mutation, "SELECT id,lockedAt FROM transactions") && str_contains($mutation, "Transaksi terkunci tidak dapat diubah"), 'requireUnlocked fails closed on locked rows');

verify(str_contains($booking, "'tax_snapshot',['source'=>\$source,'lockRows'=>true,'requireAll'=>true,'requireUnlocked'=>true]"), 'booking tax projection is unlocked-only');
verify(str_contains($checkout, "'tax_snapshot',['source'=>\$source,'lockRows'=>true,'requireAll'=>true,'requireUnlocked'=>true]"), 'checkout tax projection is unlocked-only');
verify(str_contains($booking, "'taxSource'=>'booking_component_allocation'"), 'deposit identifies component-allocation tax');
verify(str_contains($booking, "array_key_exists('taxRuleName',\$tx)"), 'booking receipt closure honors supplied taxRuleName');
verify(str_contains($booking, "'taxRuleId'=>\$dpTax['taxRuleId']??null,'taxRuleName'=>\$dpTax['taxRuleName']??null"), 'down payment passes its own tax rule evidence');
verify(str_contains($booking, "'taxRuleId'=>\$roomTax['taxRuleId']??null,'taxRuleName'=>\$roomTax['taxRuleName']??null"), 'room payment passes its own tax rule evidence');
verify(str_contains($booking, "'taxRuleName'=>\$aggregateRuleName"), 'receipt allocation rewrites the aggregate rule name');
verify(str_contains($policy, 'function tamasyaTaxRuleNameById'), 'tax rule name resolver exists');
verify(str_contains($posting, "'taxNote','taxRuleId','taxRuleName','taxSnapshotStatus','taxSource'"), 'canonical posting accepts taxRuleName');
verify(str_contains($schema, '`taxRuleName` varchar(150) DEFAULT NULL'), 'baseline schema stores taxRuleName');
verify(str_contains($migration, 'ADD COLUMN `taxRuleName` varchar(150)'), 'backfill migration adds taxRuleName idempotently');
verify(str_contains($alignment, "['taxRuleName',\"VARCHAR(150) NULL\"]"), 'runtime schema alignment adds taxRuleName');
verify(str_contains($alignment, 'dynamic-historical-tax-sync.2'), 'schema alignment version reruns for existing installs');
verify(substr_count($projection, 't.taxRuleName') === 2, 'both transaction projections expose taxRuleName');
verify(str_contains($checkout, 'tamasyaTaxRuleNameById($pdo,$ruleId)'), 'checkout reconciliation resolves the aggregate rule name');
verify(str_contains($webCheckout, "'taxSource'=>'booking_component_allocation'"), 'web settlement identifies component-allocation tax');
verify(str_contains($checkout, "'taxSource'=>'booking_component_allocation'"), 'Telegram settlement identifies component-allocation tax');
verify(!str_contains($webCheckout, "resolveConfiguredTaxRule(\$pdo,\$bookingSource,'settlement'"), 'web settlement no longer labels a booking aggregate as one settlement rule');
verify(!str_contains($checkout, "resolveConfiguredTaxRule(\$pdo,\$bookingSource,'settlement'"), 'Telegram settlement no longer labels a booking aggregate as one settlement rule');
verify(str_contains($audit, "'taxRuleName'=>\$tax['taxRuleName']??(\$oldTx['taxRuleName']??null)"), 'audit correction preserves or replaces taxRuleName with the snapshot');
verify(str_contains($audit, "\$targetKind = \$targetType === 'expense' ? 'manual' : 'booking_charge';"), 'audit correction aligns transaction kind with corrected type');
verify(str_contains($audit, "'taxSnapshotStatus'=>'not_applicable','taxSource'=>'not_applicable'"), 'expense correction does not inherit income PBJT');
verify(str_contains($audit, "'taxSource'=>\$tax['taxSource']??(\$oldTx['taxSource']??'live_rule')"), 'audit correction preserves or replaces taxSource with the snapshot');

echo "$pass passed; $fail failed\n";
exit($fail ? 1 : 0);
