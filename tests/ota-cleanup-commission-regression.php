<?php
declare(strict_types=1);

$root = $argv[1] ?? dirname(__DIR__);
$failures = 0;
$passes = 0;

function verify(bool $ok, string $name): void {
    global $passes, $failures;
    if ($ok) {
        $passes++;
        echo "PASS {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL {$name}\n";
}

$cleanup = file_get_contents($root . '/api/modules/finance/100_cleanup_tools.php');
$route = file_get_contents($root . '/api/routes/030_ota_cleanup_audit.php');

verify(is_string($cleanup) && $cleanup !== '', '100_cleanup_tools.php readable');
verify(is_string($route) && $route !== '', '030_ota_cleanup_audit.php readable');
verify(str_contains($cleanup, "sourceEntity='ota_disbursement' AND sourceEntityId=? AND transactionKind='ota_commission'"), 'OTA cleanup resolves the commission transaction from its disbursement owner');
verify(str_contains($cleanup, "'commissionTransactionId'=>\$commission!==''?\$commission:null"), 'OTA cleanup adjustment exposes the commission transaction');
verify(str_contains($cleanup, 'if($commission!==\'\')$affected[]=$commission;'), 'OTA cleanup impact includes the commission transaction');
verify(str_contains($route, "\$commissionTx=(string)(\$adj['commissionTransactionId']??'');"), 'cleanup executor reads commissionTransactionId');
verify(str_contains($route, "\$pairIds=array_values(array_filter([\$sourceTx,\$destTx,\$commissionTx]));"), 'full disbursement deletion includes the commission transaction');
verify(substr_count($route, '$pairIds=array_values(array_filter([$sourceTx,$destTx,$commissionTx]))') === 1, 'only the delete path removes the commission transaction');
verify(str_contains($route, "if(\$remaining<=0)throw new RuntimeException('Sisa pencairan OTA tidak valid saat cleanup.');"), 'partial cleanup still requires a positive remaining disbursement');

echo "\nOTA cleanup commission: {$passes} passed; {$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
