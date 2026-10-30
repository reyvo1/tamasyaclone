<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? dirname(__DIR__);
$booking = file_get_contents($root . '/api/modules/finance/030_booking_finance.php');
$promo = file_get_contents($root . '/api/support/120_promotion_codes.php');
$pass = 0; $fail = 0;
function verify($ok, $name) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; }

verify(is_string($booking) && $booking !== '', 'booking finance readable');
verify(is_string($promo) && $promo !== '', 'promotion support readable');
verify(str_contains($booking, "if (!function_exists('tamasyaPromotionCodesEnabled'))"), 'promotion support dependency declared');
verify(str_contains($booking, "\$promoCodeInput=trim((string)(\$payload['promoCode']"), 'promoCode input parsed');
verify(str_contains($booking, "tamasyaPromotionCodesEnabled() || !tamasyaPromotionCodesSchemaReady"), 'feature flag and schema gate checked');
verify(str_contains($booking, "tamasyaPromotionCodeValidate(\$pdo,\$promoCodeInput,'booking',\$totalAmount)"), 'promo validated for booking');
verify(str_contains($booking, "\$discountAmount=\$promoDiscountAmount;"), 'discountAmount assigned from promo');
verify(str_contains($booking, "\$totalAmount=round(\$totalAmount-\$promoDiscountAmount,2);"), 'totalAmount discounted');
verify(str_contains($booking, "totalAmount,discountAmount,status,paymentStatus"), 'discountAmount stored in booking insert');
verify(str_contains($booking, "COLUMN_NAME='promotion_code_id'"), 'promotion_code_id column existence checked');
verify(str_contains($booking, "UPDATE bookings SET promotion_code_id=?"), 'promotion_code_id stored when column exists');
verify(str_contains($booking, "tamasyaPromotionCodeMarkUsed(\$pdo,\$promoCodeId,\$operationId.':booking'"), 'promo marked used after booking');
verify(str_contains($promo, "function tamasyaPromotionCodeMarkUsed"), 'mark used helper exists');

echo "$pass passed; $fail failed\n";
exit($fail ? 1 : 0);
