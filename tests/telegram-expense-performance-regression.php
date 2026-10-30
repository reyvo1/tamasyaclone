<?php
/** Regression: expense receipt photos are stored by reference and fetched on demand. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$webhook = file_get_contents($root.'/api/routes/080_telegram_webhook.php');
$route = file_get_contents($root.'/api/routes/040_transactions_sync.php');
$telegram = file_get_contents($root.'/api/modules/comms/050_integrations_telegram_mail.php');

function tgPerfCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: '.$message);
    echo "PASS ".$message."\n";
}

$blockStart = strpos($webhook, 'elseif (is_array($photoMsg))');
tgPerfCheck($blockStart !== false, 'expense photo branch exists');
$savePoint = strpos($webhook, "\$ctx['photoFileId']=\$fid;", $blockStart);
tgPerfCheck($savePoint !== false, 'expense photo reference is persisted');
$photoBranch = substr($webhook, $blockStart, $savePoint - $blockStart);
tgPerfCheck(!str_contains($photoBranch, 'getFile'), 'photo receipt does not call Telegram getFile synchronously');
tgPerfCheck(!str_contains($photoBranch, 'file_get_contents'), 'photo receipt does not download file content synchronously');
tgPerfCheck(!str_contains($photoBranch, 'file_put_contents'), 'photo receipt does not write a local file synchronously');
tgPerfCheck(!str_contains($webhook, 'Foto nota tersimpan di server.'), 'reply no longer claims a server-side file was saved');
tgPerfCheck(str_contains($webhook, 'Referensi foto Telegram disimpan'), 'reply reports the stored Telegram reference');
tgPerfCheck(str_contains($webhook, "? 'telegram_file_id:'.\$expensePhotoFileId : null"), 'proofUrl falls back to the Telegram file_id reference');

tgPerfCheck(str_contains($route, 'tamasyaTelegramFileDataUrl'), 'transaction-proof resolves Telegram references on demand');
tgPerfCheck(str_contains($route, "str_starts_with(\$proofUrl, 'telegram_file_id:')"), 'transaction-proof detects Telegram references');
tgPerfCheck(str_contains($telegram, 'function tamasyaTelegramFileDataUrl'), 'on-demand Telegram file helper exists');
tgPerfCheck(str_contains($telegram, "\$timeout = max(2, min(10, \$timeout))"), 'on-demand fetch timeout is bounded');

echo "TOTAL: 12 PASS; 0 FAIL\n";
