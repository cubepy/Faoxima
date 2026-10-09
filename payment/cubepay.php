<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../lib/PaymentConfirm.php';
require_once __DIR__ . '/../lib/CubePay.php';

function cubepay_process_card_callback($authority, $orderIdHint)
{
    global $ManagePanel;
    $ManagePanel = new ManagePanel();

    if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $authority)) {
        cubepay_log_event('CUBEPAY_BAD_AUTHORITY', 'authority failed format check', [
            'authority_excerpt' => substr($authority, 0, 40),
        ]);
        http_response_code(400);
        exit('Invalid authority');
    }

    $Payment_report = cubepay_lookup_by_authority($authority);
    if ($Payment_report === null && $orderIdHint !== null && $orderIdHint !== '') {
        $byOrder = cubepay_lookup_by_order($orderIdHint);
        if ($byOrder !== null && trim((string) ($byOrder['cubepay_authority'] ?? '')) === '') {
            $Payment_report = $byOrder;
            global $connect;
            $backfill = $connect->prepare("UPDATE Payment_report SET cubepay_authority = ? WHERE id_order = ? AND (cubepay_authority IS NULL OR cubepay_authority = '')");
            $backfill->bind_param('ss', $authority, $orderIdHint);
            $backfill->execute();
            $backfill->close();
        }
    }
    if ($Payment_report === null) {
        cubepay_log_event('CUBEPAY_UNKNOWN_AUTHORITY', 'Payment_report row not found', [
            'authority' => $authority,
            'order_id_hint' => $orderIdHint,
            'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
        http_response_code(404);
        exit('Order not found');
    }
    if ($orderIdHint !== null && (string) $orderIdHint !== (string) $Payment_report['id_order']) {
        cubepay_log_event('CUBEPAY_ORDER_MISMATCH', 'order_id does not match stored value for this authority', [
            'authority' => $authority,
            'expected' => $Payment_report['id_order'],
            'received' => $orderIdHint,
        ]);
        http_response_code(409);
        exit('Order mismatch');
    }
    $orderId = (string) $Payment_report['id_order'];

    if ($Payment_report['payment_Status'] == "paid") {
        exit('Already processed');
    }

    $verify = function_exists('cubepayVerifyPayment') ? cubepayVerifyPayment($authority) : null;
    if (!is_array($verify) || (string) ($verify['order_id'] ?? '') !== $orderId) {
        cubepay_log_event('CUBEPAY_VERIFY_MISMATCH', 'Server-side verify did not confirm this order', [
            'order_id' => $orderId,
            'authority' => $authority,
            'verify' => $verify,
        ]);
        http_response_code(409);
        exit('Verification failed');
    }
    if (empty($verify['success'])) {
        $verifyStatusCode = (int) ($verify['status_code'] ?? 0);
        cubepay_log_event('CUBEPAY_NOT_PAID', 'Server-side verify reports payment not confirmed', [
            'order_id' => $orderId,
            'authority' => $authority,
            'message' => $verify['message'] ?? null,
            'status_code' => $verifyStatusCode,
        ]);
        $localStatus = (string) $Payment_report['payment_Status'];
        if ($verifyStatusCode === 410 && $localStatus !== 'expire' && $localStatus !== 'cancelled') {
            $reasonFa = (string) ($verify['message'] ?? '') !== ''
                ? (string) $verify['message']
                : 'مهلت تراکنش کارت‌به‌کارت کیوب‌پی تمام شده یا ناموفق بوده است';
            payment_notify_user_failed($orderId, $reasonFa);
        }
        exit('Not paid');
    }

    // سفارشِ expire/cancelled هم اینجا تحویل می‌شود: verify-payment فقط همین یک
    // بار success می‌دهد و اگر الان رد شود، پولِ مشتری دیگر قابل پیگیری نیست.
    if (!cubepay_finalize_paid_order($orderId, $Payment_report, 'کارت به کارت')) {
        exit('Already processed');
    }
    echo "پرداخت با موفقیت انجام شد";
}

function cubepay_process_crypto_callback($orderId, $status, $amount, $sig, $paymentId, $payCurrency)
{
    global $ManagePanel;
    $ManagePanel = new ManagePanel();

    if (!function_exists('cubepayVerifyCryptoCallbackSignature') || !cubepayVerifyCryptoCallbackSignature($orderId, $status, $amount, $sig)) {
        cubepay_log_event('CUBEPAY_CRYPTO_BAD_SIG', 'Crypto callback signature mismatch', [
            'order_id' => $orderId,
            'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
        http_response_code(403);
        exit('Invalid signature');
    }

    $Payment_report = cubepay_lookup_by_order($orderId);
    if ($Payment_report === null) {
        cubepay_log_event('CUBEPAY_UNKNOWN_ORDER', 'Payment_report row not found for crypto callback', [
            'order_id' => $orderId,
        ]);
        http_response_code(404);
        exit('Order not found');
    }
    if ($Payment_report['payment_Status'] == "paid") {
        exit('Already processed');
    }
    // callback امضاشده‌ی «paid» برای سفارشِ expire/cancelled هم تحویل می‌شود؛
    // فقط پیام‌های غیرِ paid برای این سفارش‌ها نادیده گرفته می‌شوند.
    if ($status !== 'paid' && ($Payment_report['payment_Status'] == "expire" || $Payment_report['payment_Status'] == "cancelled")) {
        return;
    }

    if ($status !== 'paid') {
        cubepay_log_event('CUBEPAY_CRYPTO_NOT_PAID', 'Crypto callback reports non-paid status', [
            'order_id' => $orderId,
            'status' => $status,
        ]);
        if ($status === 'expired') {
            payment_mark_expired($orderId);
        } elseif ($status === 'failed') {
            payment_notify_user_failed($orderId, 'پرداخت ارز دیجیتال توسط کیوب‌پی ناموفق بود');
        }
        exit('Not paid');
    }

    if (!cubepay_finalize_paid_order($orderId, $Payment_report, 'ارز دیجیتال' . ($payCurrency !== '' ? " ({$payCurrency})" : ''))) {
        exit('Already processed');
    }
    echo "پرداخت با موفقیت انجام شد";
}

function cubepay_process_webhook()
{
    $rawBody = file_get_contents("php://input");
    $bodyData = json_decode($rawBody, true);
    if (!is_array($bodyData)) {
        $bodyData = [];
    }

    $authority = $bodyData['authority'] ?? ($_REQUEST['authority'] ?? null);
    $sig = $bodyData['sig'] ?? ($_REQUEST['sig'] ?? null);
    $orderId = $bodyData['order_id'] ?? ($_REQUEST['order_id'] ?? null);

    if ($authority !== null && ($authority !== '')) {
        cubepay_process_card_callback((string) $authority, $orderId !== null ? (string) $orderId : null);
        return;
    }

    if ($sig !== null && $sig !== '') {
        $orderIdStr = trim((string) ($orderId ?? ''));
        $status = trim((string) ($bodyData['status'] ?? ($_REQUEST['status'] ?? '')));
        $amount = trim((string) ($bodyData['amount'] ?? ($_REQUEST['amount'] ?? '')));
        $paymentId = trim((string) ($bodyData['payment_id'] ?? ($_REQUEST['payment_id'] ?? '')));
        $payCurrency = trim((string) ($bodyData['pay_currency'] ?? ($_REQUEST['pay_currency'] ?? '')));

        if ($orderIdStr === '' || $status === '') {
            cubepay_log_event('CUBEPAY_CRYPTO_INCOMPLETE', 'Crypto callback missing order_id or status', [
                'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
            http_response_code(400);
            exit('Incomplete crypto callback');
        }

        cubepay_process_crypto_callback($orderIdStr, $status, $amount, (string) $sig, $paymentId, $payCurrency);
        return;
    }

    cubepay_log_event('CUBEPAY_NO_AUTHORITY', 'Callback missing authority and sig', [
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
    http_response_code(400);
    exit('Missing authority');
}

cubepay_process_webhook();
