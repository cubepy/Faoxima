<?php
/*
 * پولر کیوب‌پی: سفارش‌های پرداخت‌نشده‌ی اخیر را مستقیم از کیوب‌پی می‌پرسد و
 * اگر پرداخت شده بودند تحویل می‌دهد. جلوی گم‌شدن سفارش وقتی callback به سرور
 * نمی‌رسد را می‌گیرد (همان توصیه‌ی مستندات کیوب‌پی).
 *
 * سفارش‌های expire/cancelled هم بررسی می‌شوند، چون ممکن است مشتری بعد از
 * انقضای سمت ربات پرداخت کرده باشد یا پیامک بانک دیر رسیده باشد.
 *
 * بررسی دستی یک سفارش خاص (بدون محدودیت زمانی):
 *     php cronbot/cubepaycheck.php <id_order>
 */
require_once __DIR__ . '/_init.php';
rx_cron_boot('cubepaycheck', 120);

ini_set('error_log', 'error_log');

$ctx = rx_cron_load_payment_context();
if (empty($ctx['db_ready'])) {
    return;
}
require_once __DIR__ . '/../lib/PaymentConfirm.php';
require_once __DIR__ . '/../lib/CubePay.php';

global $pdo, $ManagePanel, $setting;
$ManagePanel = $ctx['managePanel'];
$setting = $ctx['setting'];

if (!($pdo instanceof PDO)) {
    error_log('[cubepaycheck] no PDO connection');
    return;
}
if (!function_exists('cubepayCheckOrderStatus') || !function_exists('cubepayVerifyPayment')) {
    error_log('[cubepaycheck] cubepay functions not available');
    return;
}
if (cubepayApiToken() === '') {
    return;
}

// پنجره‌ی بررسی: فاکتور کیوب‌پی ۳۰ تا ۶۰ دقیقه اعتبار دارد؛ ۶ ساعت برای
// پیامک‌های دیررس کافی است و صف را کوچک نگه می‌دارد.
$rxCubepayWindowHours = 6;

$manualOrderId = (PHP_SAPI === 'cli' && isset($argv[1])) ? trim((string) $argv[1]) : '';

try {
    if ($manualOrderId !== '') {
        $stmt = $pdo->prepare(
            "SELECT * FROM Payment_report
              WHERE id_order = :o
                AND Payment_Method = 'cubepay'
                AND payment_Status <> 'paid'
              LIMIT 1"
        );
        $stmt->execute([':o' => $manualOrderId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT * FROM Payment_report
              WHERE Payment_Method = 'cubepay'
                AND payment_Status IN ('Unpaid', 'expire', 'cancelled')
                AND time >= :since
                AND (dec_not_confirmed IS NULL OR dec_not_confirmed NOT LIKE :mark)
              ORDER BY id DESC
              LIMIT 30"
        );
        $stmt->execute([
            ':since' => date('Y/m/d H:i:s', time() - $rxCubepayWindowHours * 3600),
            ':mark' => '%' . CUBEPAY_RECHECK_MARK . '%',
        ]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[cubepaycheck] select pending failed: ' . $e->getMessage());
    return;
}

if ($manualOrderId !== '' && empty($rows)) {
    echo "order {$manualOrderId}: not found or already paid\n";
}

foreach ($rows as $row) {
    if (function_exists('rx_cron_time_up') && rx_cron_time_up()) break;

    $result = cubepay_recheck_order($row);
    if ($manualOrderId !== '') {
        echo "order {$row['id_order']}: {$result}\n";
    }
}
