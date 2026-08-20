<?php
require_once __DIR__ . '/_init.php';
rx_cron_boot('deliveryretry', 120);

ini_set('error_log', 'error_log');

$ctx = rx_cron_load_payment_context();
if (empty($ctx['db_ready'])) {
    return;
}

global $pdo, $setting;
$setting        = $ctx['setting'];
$errorreport    = select('topicid', 'idreport', 'report', 'errorreport', 'select')['idreport'] ?? null;

if (!($pdo instanceof PDO)) {
    error_log('[deliveryretry] no PDO connection');
    return;
}

$logFile = __DIR__ . '/../logs/delivery_retry.log';
$log = static function (string $level, string $msg, array $ctx = []) use ($logFile): void {
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] [deliveryretry] ' . $msg;
    if (!empty($ctx)) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
    if ($level === 'ERROR' || $level === 'WARN') {
        error_log($line);
    }
};

// چرا این کرون لازمه:
// توی همه‌ی گیت‌وی‌ها (کیوب‌پی/CubePay، آقای‌پرداخت، ایران‌پی، کارت‌به‌کارت خودکار و ...) اول
// Payment_report.payment_Status روی 'paid' ثبت می‌شه و بعد DirectPayment() صدا زده می‌شه که
// سرویس رو واقعاً می‌سازه و تحویل می‌ده. اگه DirectPayment به هر دلیلی abort کنه (فاکتور پیدا
// نشد، پنل پیدا نشد، createUser fail کنه و ...)، payment_Status از حالت paid برنمی‌گرده و تا
// قبل از این کرون هیچ retry خودکاری وجود نداشت — فقط یه پیام به ادمین می‌رفت و ادمین باید کاملاً
// دستی سرویس رو می‌ساخت (دقیقاً چیزی که برای سفارش cafde65036 افتاد: پرداخت ساعت ۱:۵۰ paid شد
// ولی سرویس تا ۲:۱۱ که ادمین دستی ساخت، تحویل نشده بود).
//
// این کرون هر Payment_report ای که paid شده ولی هیچ نشونه‌ی تحویل (service-created) یا
// بازگشت‌وجه (auto-refund) نداره رو پیدا می‌کنه و DirectPayment رو خودش دوباره صدا می‌زنه —
// بدون اینکه منتظر کلیک ادمین روی دکمه‌ی «تلاش مجدد» بمونه. سفارش‌های 'arze digital offline'
// عمداً کنار گذاشته شدن چون cryptocheck.php از قبل خودش یه retry pass مشابه برای اون‌ها داره؛
// اینجا دوباره پردازششون نمی‌کنیم که crypto_check_count دوبار در یک دور بالا نره.

$stmt = $pdo->prepare(
    "SELECT id_order, id_user, id_invoice, Payment_Method, price, time, crypto_check_count
       FROM Payment_report
      WHERE payment_Status = 'paid'
        AND id_invoice LIKE 'getconfigafterpay|%'
        AND Payment_Method <> 'arze digital offline'
        AND (dec_not_confirmed IS NULL OR (
              dec_not_confirmed NOT LIKE '%auto-refund%'
          AND dec_not_confirmed NOT LIKE '%service-created%'
          AND dec_not_confirmed NOT LIKE '%service-creation-pending-decision%'
        ))
        AND COALESCE(crypto_check_count, 0) < 5
      ORDER BY id ASC
      LIMIT 30"
);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    return;
}

// [grace period] فیلد `time` مثل بقیه‌ی این پروژه با فرمت 'Y/m/d H:i:s' (نه یه DATETIME واقعی
// MySQL) ذخیره می‌شه، پس مقایسه‌ی زمان رو توی PHP با strtotime() انجام می‌دیم — همون کاری که
// cronbot/croncard.php هم برای at_updated می‌کنه — نه با DATE_SUB/NOW() توی SQL.
// حداقل ۳ دقیقه صبر می‌کنیم که مسابقه با فراخوانی sync خودِ callback گیت‌وی (که همین الان ممکنه
// در حال اجرا باشه) پیش نیاد؛ بعد از ۶ ساعت هم دیگه دنبالش نمی‌گردیم — اون حالت باید دستی رسیدگی بشه.
$graceSeconds  = 3 * 60;
$maxAgeSeconds = 6 * 60 * 60;
$now = time();

foreach ($rows as $row) {
    $orderId = (string) $row['id_order'];
    $ts = strtotime((string) $row['time']);
    if ($ts === false) {
        continue;
    }
    $age = $now - $ts;
    if ($age < $graceSeconds || $age > $maxAgeSeconds) {
        continue;
    }

    $tryBefore = (int) ($row['crypto_check_count'] ?? 0);
    $tryNow    = $tryBefore + 1;

    $log('INFO', 'retrying undelivered paid order', [
        'order_id' => $orderId,
        'method'   => $row['Payment_Method'],
        'try'      => $tryNow,
        'age_sec'  => $age,
    ]);

    // Claim this attempt before making it, using the counter itself as the
    // token. The rows were selected on "no delivery marker yet", and that
    // marker is only written after the service has been created — so two
    // runners that read the same row both pass the filter and both create a
    // service, and the customer has two for one payment while the reseller
    // pays the panel twice. Bumping unconditionally, as this did, gave the
    // count no say in who proceeds.
    //
    // The compare-and-swap needs no cleanup: whoever moves the count from N to
    // N+1 owns attempt N+1, and a loser simply skips to the next row. A failed
    // delivery still leaves the count raised, which is the existing behaviour
    // and what stops an unfixable order retrying forever.
    try {
        $claim = $pdo->prepare(
            "UPDATE Payment_report
                SET crypto_check_count = COALESCE(crypto_check_count,0) + 1
              WHERE id_order = :o
                AND COALESCE(crypto_check_count,0) = :expected"
        );
        $claim->execute([':o' => $orderId, ':expected' => $tryBefore]);
        if ($claim->rowCount() !== 1) {
            $log('INFO', 'another runner already took this attempt', ['order_id' => $orderId]);
            continue;
        }
    } catch (Throwable $e) {
        // Could not claim — do not deliver on a maybe.
        $log('WARN', 'could not claim delivery attempt', ['order_id' => $orderId, 'err' => $e->getMessage()]);
        continue;
    }

    @set_time_limit(90);
    try {
        // Guarded on the name that is called. Testing DirectPayment() and
        // calling nm_safe_direct_payment() meant this job — the one whose whole
        // purpose is to rescue an undelivered order — failed identically on
        // every one of its five attempts and then gave up.
        if (function_exists('nm_safe_direct_payment')) {
            nm_safe_direct_payment($orderId, __DIR__ . '/../images.jpeg');
        } else {
            $log('ERROR', 'delivery skipped: nm_safe_direct_payment is not loaded', ['order_id' => $orderId]);
        }
    } catch (Throwable $e) {
        $log('ERROR', 'DirectPayment threw during retry', [
            'order_id' => $orderId,
            'error'    => $e->getMessage(),
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
        ]);
    }

    // [give up after 5 attempts] اگه این آخرین تلاش مجازه و هنوز نشونه‌ی تحویل/بازگشت‌وجه
    // نیست، یه هشدار یک‌باره با دکمه‌ی retry/refund دستی به ادمین بده و کرون دیگه سراغش نره
    // (چون crypto_check_count دیگه از ۵ کمتر نیست و از WHERE بالا خارج می‌شه).
    if ($tryNow >= 5) {
        try {
            $__pr   = select('Payment_report', 'dec_not_confirmed', 'id_order', $orderId, 'select');
            $__note = is_array($__pr) ? (string) ($__pr['dec_not_confirmed'] ?? '') : '';
        } catch (Throwable $e) {
            $__note = '';
        }

        $__delivered = (stripos($__note, 'service-created') !== false) || (stripos($__note, 'auto-refund') !== false);
        if (!$__delivered && !empty($setting['Channel_Report']) && function_exists('telegram')) {
            $__kb = json_encode([
                'inline_keyboard' => [
                    [['text' => '🔄 تلاش دوباره برای صدور اشتراک', 'callback_data' => 'retryconfig_' . $orderId]],
                    [['text' => '💸 لغو سفارش و استرداد وجه', 'callback_data' => 'refundconfig_' . $orderId]],
                ]
            ]);
            @telegram('sendmessage', [
                'chat_id'            => $setting['Channel_Report'],
                'message_thread_id'  => $errorreport,
                'text'               => "🔁 <b>تحویل خودکار بعد از ۵ تلاش موفق نشد</b>\n"
                                       . "🛒 کد سفارش: <code>{$orderId}</code>\n"
                                       . "💳 روش پرداخت: " . htmlspecialchars((string) $row['Payment_Method']) . "\n"
                                       . "ℹ️ کرون deliveryretry دیگه خودکار تلاش نمی‌کنه؛ لطفاً دستی بررسی کنید.",
                'reply_markup'       => $__kb,
                'parse_mode'         => 'HTML',
            ]);
            $log('WARN', 'gave up after max retries, alerted admin', ['order_id' => $orderId]);
        }
    }
}
