<?php
/** Regression: Telegram money parsing, cash-shift coverage, and edit/correction projections. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TAMASYA_API_ENTRY', true);
$root = dirname(__DIR__);
require_once $root.'/api/modules/finance/025_financial_posting_authority.php';
require_once $root.'/api/support/060_shift_receipts.php';
require_once $root.'/api/modules/comms/050_integrations_telegram_mail.php';

$pass = 0; $fail = 0;
function correctionCheck(bool $ok, string $message): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ').$message."\n";
}

correctionCheck(function_exists('tamasyaTelegramParseMoney'), 'Telegram money parser exists');
if (function_exists('tamasyaTelegramParseMoney')) {
    correctionCheck(tamasyaTelegramParseMoney('125.000') === 125000.0, '125.000 parses as 125000');
    correctionCheck(tamasyaTelegramParseMoney('125.000,50') === 125000.5, '125.000,50 parses as 125000.50');
    correctionCheck(tamasyaTelegramParseMoney('1,5') === 1.5, '1,5 parses as 1.50');
    correctionCheck(tamasyaTelegramParseMoney('1.500.000') === 1500000.0, '1.500.000 parses as 1500000');
    correctionCheck(tamasyaTelegramParseMoney('-5') === null, 'negative nominal is rejected');
}

$webhook = file_get_contents($root.'/api/routes/080_telegram_webhook.php');
correctionCheck(str_contains($webhook, 'tamasyaTelegramParseMoney((string)$expenseMatch[1])'), 'state expense input uses the canonical money parser');
correctionCheck(!str_contains($webhook, "(float)preg_replace('/[^0-9]/'"), 'state expense input no longer strips dots/commas');
correctionCheck(!str_contains($webhook, '(float)trim($parts['), 'direct Telegram commands no longer cast raw money with (float)');
correctionCheck(substr_count($webhook, 'tamasyaTelegramParseMoney(') >= 4, 'state and direct Telegram commands share the parser');

final class ShiftGuardPDO extends PDO {
    public function __construct(public array $shiftRow, public float $cashIncome, public float $cashExpense) {}
    public function inTransaction(): bool { return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ShiftGuardStatement($this, $query);
    }
}
final class ShiftGuardStatement extends PDOStatement {
    private array $params = [];
    public function __construct(private ShiftGuardPDO $owner, private string $query) {}
    public function execute(?array $params = null): bool { $this->params = $params ?? []; return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        if (str_contains($this->query, 'FROM shift_sessions')) return $this->owner->shiftRow;
        if (str_contains($this->query, 'cash_income')) return ['cash_income'=>$this->owner->cashIncome,'cash_expense'=>$this->owner->cashExpense];
        return false;
    }
    public function fetchColumn(int $column = 0): mixed { return 0; }
}

correctionCheck(function_exists('tamasyaAssertOpenShiftCashCanCoverExpenseExcluding'), 'expense coverage guard supports excluding the edited row');
if (function_exists('tamasyaAssertOpenShiftCashCanCoverExpenseExcluding')) {
    $pdo = new ShiftGuardPDO(['id'=>'shift_1','status'=>'open','opening_cash'=>100000], 20000, 50000);
    $threw = false;
    try { tamasyaAssertOpenShiftCashCanCoverExpenseExcluding($pdo, ['id'=>'tx_1','type'=>'expense','amount'=>80000,'bankAccountId'=>null,'shiftSessionId'=>'shift_1'], 'tx_1'); }
    catch (Throwable $e) { $threw = str_contains($e->getMessage(), 'Kas shift tidak mencukupi'); }
    correctionCheck($threw, 'cash expense above available shift cash is rejected');

    $threw = false;
    try { tamasyaAssertOpenShiftCashCanCoverExpenseExcluding($pdo, ['id'=>'tx_1','type'=>'expense','amount'=>70000,'bankAccountId'=>null,'shiftSessionId'=>'shift_1'], 'tx_1'); }
    catch (Throwable $e) { $threw = true; }
    correctionCheck(!$threw, 'cash expense within available shift cash is accepted');

    $threw = false;
    try { tamasyaAssertOpenShiftCashCanCoverExpenseExcluding(new ShiftGuardPDO([], 0, 0), ['id'=>'tx_2','type'=>'expense','amount'=>1000,'bankAccountId'=>null]); }
    catch (Throwable $e) { $threw = str_contains($e->getMessage(), 'wajib'); }
    correctionCheck($threw, 'live cash expense without shift is rejected');

    $threw = false;
    try { tamasyaAssertOpenShiftCashCanCoverExpenseExcluding(new ShiftGuardPDO([], 0, 0), ['id'=>'tx_3','type'=>'expense','amount'=>1000,'bankAccountId'=>null,'shiftExempt'=>1]); }
    catch (Throwable $e) { $threw = true; }
    correctionCheck(!$threw, 'historical cash expense remains exempt');

    $threw = false;
    try { tamasyaAssertOpenShiftCashCanCoverExpenseExcluding(new ShiftGuardPDO([], 0, 0), ['id'=>'tx_4','type'=>'expense','amount'=>1000,'bankAccountId'=>null,'recordOrigin'=>'historical_import']); }
    catch (Throwable $e) { $threw = true; }
    correctionCheck(!$threw, 'historical import cash expense remains exempt');
}

correctionCheck(function_exists('tamasyaAssertOpenShiftCashProjection'), 'income edit projection guard exists');
if (function_exists('tamasyaAssertOpenShiftCashProjection')) {
    $pdo = new ShiftGuardPDO(['id'=>'shift_1','status'=>'open','opening_cash'=>100000], 0, 150000);
    $threw = false;
    try { tamasyaAssertOpenShiftCashProjection($pdo, ['id'=>'tx_1','type'=>'income','amount'=>60000,'bankAccountId'=>null,'shiftSessionId'=>'shift_1'], 'tx_1'); }
    catch (Throwable $e) { $threw = true; }
    correctionCheck(!$threw, 'cash income edit that restores non-negative cash is accepted');

    $threw = false;
    try { tamasyaAssertOpenShiftCashProjection($pdo, ['id'=>'tx_1','type'=>'income','amount'=>0,'bankAccountId'=>'bank_bca','shiftSessionId'=>'shift_1'], 'tx_1'); }
    catch (Throwable $e) { $threw = str_contains($e->getMessage(), 'negatif'); }
    correctionCheck($threw, 'removing cash income that makes shift cash negative is rejected');

    $threw = false;
    try { tamasyaAssertOpenShiftCashProjection(new ShiftGuardPDO([], 0, 0), ['id'=>'tx_2','type'=>'income','amount'=>1000,'bankAccountId'=>null]); }
    catch (Throwable $e) { $threw = str_contains($e->getMessage(), 'wajib'); }
    correctionCheck($threw, 'live cash income without shift is rejected');
}

$route = file_get_contents($root.'/api/routes/040_transactions_sync.php');
correctionCheck(str_contains($route, 'tamasyaAssertOpenShiftCashCanCoverExpenseExcluding($pdo,$editCoverageTx,$id)'), 'edit route checks the expense delta against the edited row');
correctionCheck(str_contains($route, 'tamasyaAssertOpenShiftCashProjection($pdo,$editCoverageTx,$id)'), 'edit route checks cash-income projection');

$audit = file_get_contents($root.'/api/routes/030_ota_cleanup_audit.php');
correctionCheck(str_contains($audit, 'tamasyaAssertOpenShiftCashCanCoverExpenseExcluding'), 'audit correction checks cash expense coverage');
correctionCheck(str_contains($audit, 'tamasyaAssertOpenShiftCashProjection'), 'audit correction checks cash-income projection');

$telegramCash = file_get_contents($root.'/api/modules/front_office/040_rooms_checkout_housekeeping.php');
correctionCheck(str_contains($telegramCash, 'tamasyaManualTransactionRequiresOpenShift($transaction)'), 'Telegram cash transaction resolves shift from its cash leg');
correctionCheck(str_contains($telegramCash, "\$transaction['shiftSessionId']=\$shiftSessionId"), 'Telegram cash transaction passes shift before posting');

$pos = file_get_contents($root.'/api/modules/pos_inventory/085_pos_minibar.php');
correctionCheck(str_contains($pos, "'shiftSessionId'=>\$tx['shiftSessionId']??null"), 'POS posting preserves the shift session');

$posRoute = file_get_contents($root.'/api/routes/065_pos_minibar.php');
correctionCheck(str_contains($posRoute, 'tamasyaManualTransactionRequiresOpenShift'), 'POS sale/refund gate follows the cash leg');

$booking = file_get_contents($root.'/api/modules/finance/030_booking_finance.php');
correctionCheck(str_contains($booking, '$refundShiftSessionId'), 'booking refund resolves shift before posting');
correctionCheck(str_contains($booking, 'tamasyaManualTransactionRequiresOpenShift($tx)'), 'booking workflow resolves shift per transaction cash leg');

$deposit = file_get_contents($root.'/api/modules/front_office/035_guest_security_deposits.php');
correctionCheck(str_contains($deposit, 'tamasyaManualTransactionRequiresOpenShift'), 'security deposit receive/refund follows the cash leg');

$checkout = file_get_contents($root.'/api/routes/020_hotel_booking.php');
correctionCheck(str_contains($checkout, '$bookingChargeShiftSessionId'), 'booking charge resolves shift before posting');
correctionCheck(str_contains($checkout, '$postingShiftSessionId'), 'checkout settlement resolves shift per payment leg');

echo "TOTAL: $pass PASS; $fail FAIL\n";
exit($fail ? 1 : 0);
