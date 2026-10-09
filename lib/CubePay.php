<?php

/*
 * منطق مشترک تحویل سفارش کیوب‌پی؛ هم callback (payment/cubepay.php) و هم
 * کران بررسی مجدد (cronbot/cubepaycheck.php) از همین‌جا استفاده می‌کنند تا
 * سفارش فقط یک بار تحویل شود، از هر مسیری که اول برسد.
 */

if (!defined('CUBEPAY_PAID_STATUSES')) {
    // وضعیت‌هایی از check-order-status.php که یعنی «پول قطعاً رسیده».
    // verified = کارت، finished = کریپتو، paid/held_for_review = مسیر VIP.
    define('CUBEPAY_PAID_STATUSES', ['verified', 'finished', 'paid', 'held_for_review']);
}
if (!defined('CUBEPAY_DEAD_STATUSES')) {
    define('CUBEPAY_DEAD_STATUSES', ['expired', 'failed', 'canceled', 'cancelled']);
}
if (!defined('CUBEPAY_RECHECK_MARK')) {
    define('CUBEPAY_RECHECK_MARK', '[cubepay-recheck:');
}

if (!function_exists('cubepay_log_event')) {
    function cubepay_log_event($type, $message, array $context = [])
    {
        if (function_exists('rx_log_event')) {
            rx_log_event($type, $message, $context);
            return;
        }
        $line = $type . ': ' . $message;
        if (!empty($context)) {
            $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        error_log('[cubepay] ' . $line);
    }
}

if (!function_exists('cubepay_lookup_by_order')) {
    function cubepay_lookup_by_order($orderId)
    {
        global $connect;
        $stmt = $connect->prepare("SELECT * FROM Payment_report WHERE id_order = ? AND Payment_Method = 'cubepay' LIMIT 1");
        $stmt->bind_param('s', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('cubepay_lookup_by_authority')) {
    function cubepay_lookup_by_authority($authority)
    {
        global $connect;
        $stmt = $connect->prepare("SELECT * FROM Payment_report WHERE cubepay_authority = ? LIMIT 1");
        $stmt->bind_param('s', $authority);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('cubepay_finalize_paid_order')) {
    /**
     * سفارشی را که کیوب‌پی پرداختش را تایید کرده paid می‌کند و سرویس/شارژ را
     * تحویل می‌دهد. فقط اولین فراخوانی برای هر سفارش کاری انجام می‌دهد و true
     * برمی‌گرداند؛ بقیه false می‌گیرند.
     *
     * وضعیت قبلی expire/cancelled/reject مانع تحویل نیست: verify-payment
     * کیوب‌پی فقط یک بار success می‌دهد، پس اگر اینجا تحویل ندهیم پولِ مشتری
     * برای همیشه گم می‌شود.
     */
    function cubepay_finalize_paid_order($orderId, $Payment_report, $methodLabel, $via = 'callback')
    {
        global $connect;

        $atomic = $connect->prepare(
            "UPDATE Payment_report SET payment_Status = ? WHERE id_order = ? AND payment_Status <> 'paid'"
        );
        $statusPaid = 'paid';
        $atomic->bind_param('ss', $statusPaid, $orderId);
        $atomic->execute();
        $affected = $atomic->affected_rows;
        $atomic->close();
        if ($affected < 1) {
            cubepay_log_event('CUBEPAY_DUPLICATE', 'Duplicate or already-paid confirmation ignored', [
                'order_id' => $orderId,
                'via' => $via,
            ]);
            return false;
        }

        $previousStatus = (string) ($Payment_report['payment_Status'] ?? '');
        if ($previousStatus !== '' && $previousStatus !== 'Unpaid') {
            cubepay_log_event('CUBEPAY_LATE_PAYMENT', 'Paid order recovered from non-pending status', [
                'order_id' => $orderId,
                'previous_status' => $previousStatus,
                'via' => $via,
            ]);
        }
        if (function_exists('rx_redis_del') && isset($Payment_report['id_user'])) {
            rx_redis_del('faoxima:paystatus:' . $orderId . ':' . (string) $Payment_report['id_user']);
        }

        $setting = mysqli_fetch_assoc(mysqli_query($connect, "SELECT * FROM setting"));
        $price = $Payment_report['price'];

        $datatextbotget = select("textbot", "*", null, null, "fetchAll");
        $datatxtbot = array();
        foreach ($datatextbotget as $row) {
            $datatxtbot[] = array(
                'id_text' => $row['id_text'],
                'text' => $row['text']
            );
        }
        $datatextbot = array(
            'textafterpay' => '',
            'textaftertext' => '',
            'textmanual' => '',
            'textselectlocation' => '',
            'text_wgdashboard' => ''
        );
        foreach ($datatxtbot as $item) {
            if (array_key_exists($item['id_text'], $datatextbot) || (is_string($item['text']) && trim($item['text']) !== '')) {
                $datatextbot[$item['id_text']] = $item['text'];
            }
        }
        $GLOBALS['textbotlang'] = languagechange(__DIR__ . '/../text.json');
        // DirectPayment() این آرایه را با global می‌خواند، پس باید در دامنه‌ی
        // سراسری باشد؛ اینجا داخل تابع ساخته می‌شود و بدون این خط، متنِ
        // «سرویس با موفقیت ایجاد شد» خالی می‌ماند و تلگرام پیام را رد می‌کند.
        $GLOBALS['datatextbot'] = $datatextbot;

        $imagePath = __DIR__ . '/../images.jpeg';
        if (!is_file($imagePath)) {
            $imagePath = __DIR__ . '/../images.jpg';
        }
        DirectPayment($orderId, $imagePath);

        $pricecashback = select("PaySetting", "ValuePay", "NamePay", "chashbackcubepay", "select")['ValuePay'];
        $balanceLookup = $connect->prepare("SELECT * FROM user WHERE id = ? LIMIT 1");
        $balanceLookup->bind_param('s', $Payment_report['id_user']);
        $balanceLookup->execute();
        $Balance_id = $balanceLookup->get_result()->fetch_assoc();
        $balanceLookup->close();
        if (!is_array($Balance_id)) {
            cubepay_log_event('CUBEPAY_USER_MISSING', 'Linked user row not found after paid update', [
                'order_id' => $orderId,
                'id_user' => $Payment_report['id_user'] ?? null,
            ]);
            $Balance_id = ['id' => $Payment_report['id_user'] ?? '', 'username' => '—', 'Balance' => 0];
        }
        $cashbackEligible = !function_exists('rx_cashbackEligibleForKey')
            || rx_cashbackEligibleForKey("chashbackcubepay", $Balance_id['register'] ?? null, $Payment_report['id_invoice'] ?? null, $Balance_id['id'] ?? null, $Payment_report['id_order'] ?? null);
        if ($cashbackEligible && $pricecashback != "0") {
            $result = (int) floor(($Payment_report['price'] * $pricecashback) / 100);
            if (rx_cashback_credit_once($Payment_report['id_order'], $Balance_id['id'], $result, 'chashbackcubepay', 'هدیه بازگشت وجه کیوب‌پی') === 'credited') {
                $pricecashback = number_format($pricecashback);
                $text_report = "🎁 کاربر عزیز مبلغ " . rxFormatToman($result) . " تومان به عنوان هدیه واریز به حساب شما واریز گردید.";
                sendmessage($Balance_id['id'], $text_report, null, 'HTML');
            }
        }

        $paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];
        $usernameEsc = htmlspecialchars((string) $Balance_id['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $userIdEsc = htmlspecialchars((string) $Balance_id['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $orderIdEsc = htmlspecialchars((string) $orderId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rlm = "\xE2\x80\x8F";
        $rxFmtPrice = rxFormatToman($price);
        $text_reportpayment = "💵 پرداخت جدید
<blockquote>- 👤 نام کاربری کاربر : @{$usernameEsc}</blockquote>
<blockquote>- 👤 آیدی عددی کاربر : {$rlm}<code>{$userIdEsc}</code></blockquote>
<blockquote>- 🛒 کد سفارش : <code>{$orderIdEsc}</code></blockquote>
<blockquote>- 💰 مبلغ اعتباردهی : {$rxFmtPrice} تومان</blockquote>
<blockquote>- 💳 روش پرداخت : کیوب‌پی ({$methodLabel})</blockquote>";
        if ($via === 'recheck') {
            $text_reportpayment .= "\n<blockquote>- 🔁 تایید از طریق بررسی مجدد خودکار (callback نرسیده بود)</blockquote>";
        }
        if ($previousStatus !== '' && $previousStatus !== 'Unpaid') {
            $prevEsc = htmlspecialchars($previousStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $text_reportpayment .= "\n<blockquote>- ⏰ پرداخت دیرهنگام — وضعیت قبلی سفارش: {$prevEsc}</blockquote>";
        }
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $paymentreports,
                'text' => $text_reportpayment,
                'parse_mode' => "HTML"
            ]);
        }

        return true;
    }
}

if (!function_exists('cubepay_mark_recheck_done')) {
    /** سفارشی که کیوب‌پی قطعاً پرداخت‌نشده اعلامش کرده را از صف بررسی مجدد خارج می‌کند. */
    function cubepay_mark_recheck_done($orderId, $reason)
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return;
        }
        $note = CUBEPAY_RECHECK_MARK . ' ' . $reason . ' at ' . date('Y-m-d H:i:s') . ']';
        try {
            $stmt = $pdo->prepare(
                "UPDATE Payment_report
                    SET dec_not_confirmed = CASE WHEN dec_not_confirmed IS NULL OR dec_not_confirmed = '' THEN :n1 ELSE CONCAT(dec_not_confirmed, ' | ', :n2) END
                  WHERE id_order = :o AND payment_Status <> 'paid'"
            );
            $stmt->execute([':n1' => $note, ':n2' => $note, ':o' => (string) $orderId]);
        } catch (Throwable $e) {
            error_log('[cubepay] mark recheck done failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('cubepay_recheck_order')) {
    /**
     * وضعیت یک سفارش را مستقیم از کیوب‌پی می‌پرسد و اگر پرداخت شده بود
     * تحویلش می‌دهد. برای وقتی است که callback به سرور نرسیده یا رد شده.
     *
     * خروجی: fulfilled | already | pending | dead | mismatch | error
     */
    function cubepay_recheck_order(array $row)
    {
        $orderId = (string) ($row['id_order'] ?? '');
        if ($orderId === '') {
            return 'error';
        }
        $authority = trim((string) ($row['cubepay_authority'] ?? ''));

        $check = function_exists('cubepayCheckOrderStatus') ? cubepayCheckOrderStatus($orderId) : null;
        if (is_array($check) && !empty($check['success'])) {
            $checkOrderId = trim((string) ($check['order_id'] ?? ''));
            if ($checkOrderId !== '' && $checkOrderId !== $orderId) {
                cubepay_log_event('CUBEPAY_RECHECK_MISMATCH', 'check-order-status returned another order_id', [
                    'order_id' => $orderId,
                    'received' => $checkOrderId,
                ]);
                return 'mismatch';
            }
            $status = strtolower(trim((string) ($check['status'] ?? '')));
            if (in_array($status, CUBEPAY_PAID_STATUSES, true)) {
                $label = ((string) ($check['method'] ?? '')) === 'crypto' ? 'ارز دیجیتال' : 'کارت به کارت';
                return cubepay_finalize_paid_order($orderId, $row, $label, 'recheck') ? 'fulfilled' : 'already';
            }
            if (in_array($status, CUBEPAY_DEAD_STATUSES, true)) {
                cubepay_mark_recheck_done($orderId, $status);
                return 'dead';
            }
            return 'pending';
        }

        // مسیر کارتیِ مستقیم در check-order-status ثبت نمی‌شود (404)؛ برای آن
        // فقط verify-payment با authority جواب قطعی می‌دهد.
        if ($authority === '') {
            if (is_array($check) && (int) ($check['status_code'] ?? 0) === 404) {
                cubepay_mark_recheck_done($orderId, 'not-found');
                return 'dead';
            }
            return is_array($check) ? 'pending' : 'error';
        }

        $verify = function_exists('cubepayVerifyPayment') ? cubepayVerifyPayment($authority) : null;
        if (!is_array($verify)) {
            return 'error';
        }
        $verifyOrderId = trim((string) ($verify['order_id'] ?? ''));
        if ($verifyOrderId !== '' && $verifyOrderId !== $orderId) {
            cubepay_log_event('CUBEPAY_RECHECK_MISMATCH', 'verify-payment returned another order_id', [
                'order_id' => $orderId,
                'authority' => $authority,
                'received' => $verifyOrderId,
            ]);
            return 'mismatch';
        }
        $code = (int) ($verify['status_code'] ?? 0);
        // 409 یعنی این authority قبلاً یک بار verify شده؛ اگر سفارش هنوز paid
        // نیست یعنی آن verify به تحویل نرسیده (مثلاً callback وسط کار قطع شده).
        // ادعای atomic داخل finalize جلوی تحویل دوباره را می‌گیرد.
        if (!empty($verify['success']) || $code === 409) {
            return cubepay_finalize_paid_order($orderId, $row, 'کارت به کارت', 'recheck') ? 'fulfilled' : 'already';
        }
        if ($code === 410 || $code === 404) {
            cubepay_mark_recheck_done($orderId, $code === 410 ? 'expired' : 'not-found');
            return 'dead';
        }
        return 'pending';
    }
}
