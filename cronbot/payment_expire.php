<?php
require_once __DIR__ . '/_init.php';
rx_cron_boot('payment_expire', 180);

ini_set('error_log', 'error_log');
if (!rx_cron_require_or_skip('payment_expire', [
    __DIR__ . '/../config.php',
    __DIR__ . '/../botapi.php',
    __DIR__ . '/../panels.php',
    __DIR__ . '/../function.php',
])) {
    return;
}
if (!rx_cron_db_ready('payment_expire')) {
    return;
}
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}
$ManagePanel = new ManagePanel();
$setting = select("setting", "*");
$stmt = $pdo->prepare("SHOW TABLES LIKE 'textbot'");
$stmt->execute();
$result = $stmt->fetchAll();
$table_exists = count($result) > 0;
$datatextbot = array(
    'carttocart' => '',
    'textnowpayment' => '',
    'textnowpaymenttron' => '',
    'iranpay1' => '',
    'iranpay2' => '',
    'iranpay3' => '',
    'aqayepardakht' => '',
    'zarinpal' => '',
    'zarinpay' => '',
    'perfectmoney' => '',
    'text_fq' => '',
    'textpaymentnotverify' =>"",
    'textrequestagent' => '',
    'textpanelagent' => '',
    'text_wheel_luck' => '',
    'text_star_telegram' => '',
    'textsnowpayment' => '',

);
if ($table_exists) {
    $textdatabot =  select("textbot", "*", null, null,"fetchAll");
    $data_text_bot = array();
    foreach ($textdatabot as $row) {
        $data_text_bot[] = array(
            'id_text' => $row['id_text'],
            'text' => $row['text']
        );
    }
    foreach ($data_text_bot as $item) {
        if (isset($datatextbot[$item['id_text']])) {
            $datatextbot[$item['id_text']] = $item['text'];
        }
    }
}
$month_date_time_start = date('Y/m/d H:i:s', time() - 1800);
$stmt = $pdo->prepare("SELECT * FROM Payment_report WHERE time < :cutoff AND payment_Status = 'Unpaid' ORDER BY id ASC LIMIT 200");
$stmt->execute([':cutoff' => $month_date_time_start]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$expireStmt = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'expire' WHERE id_order = :o AND payment_Status = 'Unpaid'");

$zarinpayLastChanceToken = null;
foreach ($rows as $result) {
    // آخرین شانس قبل از انقضای دائمی: اگه این فاکتور زرین‌پی/CubePay هست،
    // یه بار دیگه مستقیم از درگاه استعلام می‌گیریم. کاربرهایی که با اسکن QR
    // مستقیم از اپ بانک پرداخت کرده‌ن هیچ‌وقت به مرورگر برنمی‌گردن، پس
    // zarinpaycheck.php (کرون پولر هر ۱ دقیقه) معمولاً قبل از این مرحله
    // گرفته‌تش؛ این فقط یه fallback برای وقتیه که اون کرون بنا به دلیلی
    // (مثلاً قطعی موقت) این فاکتور رو رد کرده باشه.
    if (($result['Payment_Method'] ?? '') === 'zarinpay') {
        if ($zarinpayLastChanceToken === null) {
            $zarinpayLastChanceToken = getPaySettingValue('token_zarinpey') ?: '';
        }
        if ($zarinpayLastChanceToken !== '') {
            $note = trim((string) ($result['dec_not_confirmed'] ?? ''));
            $decodedNote = json_decode($note, true);
            $authority = (is_array($decodedNote) && !empty($decodedNote['authority'])) ? (string) $decodedNote['authority'] : $note;
            if ($authority !== '') {
                $ch = curl_init('https://cubevps.ir/smspay/api/verify-payment.php');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['authority' => $authority], JSON_UNESCAPED_UNICODE));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $zarinpayLastChanceToken,
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $resp = @curl_exec($ch);
                curl_close($ch);
                $verifyResult = json_decode((string) $resp, true);
                $isVerified = is_array($verifyResult) && (!empty($verifyResult['success']) || (($verifyResult['status'] ?? '') === 'verified'));
                if ($isVerified) {
                    if (is_file(__DIR__ . '/../lib/PaymentConfirm.php')) {
                        require_once __DIR__ . '/../lib/PaymentConfirm.php';
                    }
                    if (function_exists('payment_confirm_paid')) {
                        payment_confirm_paid((string) $result['id_order'], '', [
                            'method' => 'زرین پی',
                            'extra_lines' => ['🔁 تایید در آخرین لحظه قبل از انقضا'],
                        ]);
                    }
                    continue;
                }
            }
        }
    }

    $status_var_map = [
        'cart to cart' =>  $datatextbot['carttocart'],
        'aqayepardakht' => $datatextbot['aqayepardakht'],
        'zarinpal' => $datatextbot['zarinpal'],
        'zarinpay' => !empty($datatextbot['zarinpay']) ? $datatextbot['zarinpay'] : $datatextbot['zarinpal'],
        'plisio' => $datatextbot['textnowpayment'],
        'arze digital offline' => $datatextbot['textnowpaymenttron'],
        'Currency Rial 1' => $datatextbot['iranpay2'],
        'Currency Rial 2' => $datatextbot['iranpay3'],
        'Currency Rial 3' => $datatextbot['iranpay1'],
        'Currency Rial tow' => "پرداخت ارزی ریالی",
        'Currency Rial gateway3' => "پرداخت ارزی ریالی دوم",
        'perfect' => "پرفکت مانی",
        'paymentnotverify' => $datatextbot['textpaymentnotverify'],
        'Star Telegram' => $datatextbot['text_star_telegram'],
        'nowpayment' => $datatextbot['textsnowpayment']
    ];

    $status_var = $status_var_map[$result['Payment_Method']] ?? $result['Payment_Method'];
    $textexpire = "⭕️ کاربر گرامی ، فاکتور زیر به دلیل عدم پرداخت در مدت زمان مشخص شده منقضی شد .
❗️لطفاً به هیچ عنوان وجهی بابت این فاکتور  پرداخت نکنید و مجدداً فاکتور ایجاد نمایید ‌‌.

🛒 روش پرداختی شما : $status_var
📌 کد فاکتور : <code>{$result['id_order']}</code>
🪙 مبلغ فاکتور :  {$result['price']} تومان";

    $expireStmt->execute([':o' => $result['id_order']]);
    if ($expireStmt->rowCount() !== 1) {
        continue;
    }
    // [FIX] card-to-card ("cart to cart") rows that never even reached the
    // receipt-submitted ("waiting") stage have no admin review pending on them —
    // there's nothing left to look at, so leaving them sitting as "expire" forever
    // just means the admin has to find and delete each one by hand. Deleting them
    // outright here closes that gap. Rows that DID reach "waiting" (a receipt/photo
    // was actually submitted and needs human review) are untouched by this whole
    // loop in the first place, since this query only ever matches payment_Status =
    // 'Unpaid' — so a real pending receipt is never silently discarded.
    // [FIX] این ردیف‌ها دیگر حذف نمی‌شوند — فقط 'expire' می‌مانند.
    //
    // حذفِ فوری یک حالت واقعی را خراب می‌کرد: مشتری فاکتور کارت‌به‌کارت می‌سازد،
    // می‌رود اپ بانک، واریز می‌کند و با عکس رسید برمی‌گردد. اگر این رفت‌وبرگشت
    // بیشتر از ۳۰ دقیقه طول بکشد (که کاملاً عادی است)، این کرون ردیف را قبل از
    // برگشتنِ او پاک کرده بود. بعد دکمه‌ی «ارسال رسید» در مینی‌اپ به ردیفی
    // می‌رسید که دیگر وجود ندارد و از دید مشتری اصلاً کار نمی‌کرد — با اینکه پول
    // واقعاً واریز شده بود و هیچ ردی هم برای ادمین باقی نمی‌ماند.
    //
    // نگه داشتن ردیف با وضعیت 'expire' هزینه‌ای ندارد: مسیر ارسال رسید فقط
    // فاکتورهای 'paid' را رد می‌کند، پس مشتری هنوز می‌تواند رسیدش را بفرستد و
    // ادمین تصمیم بگیرد. پاکسازی هم انجام می‌شود، فقط با تاخیر: بلوکِ انتهای همین
    // فایل بعد از setting.purgepaymentdays روز آن‌ها را خودکار حذف می‌کند، و دکمه‌ی
    // «بهینه سازی ربات» هم مثل قبل دستی همه را یکجا پاک می‌کند.
    if (function_exists('rx_release_unpaid_discount')) {
        $rxRefTime = isValidDate($result['time'] ?? '') ? strtotime(str_replace('/', '-', (string)$result['time'])) : null;
        rx_release_unpaid_discount((string)$result['id_user'], null, $rxRefTime ?: null);
    }
    deletemessage($result['id_user'], $result['message_id']);
}

/**
 * پاکسازی خودکار فاکتورهای منقضی/ردشده.
 *
 * ردیف‌های 'expire' و 'reject' دیگر کاری با آن‌ها نیست، ولی چند روزی نگه داشته
 * می‌شوند تا اگر مشتری با رسیدِ دیرهنگام برگشت، فاکتورش هنوز موجود باشد. بعد از
 * setting.purgepaymentdays روز (پیش‌فرض ۷) خودکار حذف می‌شوند.
 *
 * فقط 'expire' و 'reject' — فاکتورهای 'paid' و 'waiting' (که رسید فرستاده شده و
 * منتظر بررسی ادمین‌اند) هیچ‌وقت اینجا لمس نمی‌شوند.
 */
try {
    $rxPurgeSetting = select("setting", "*", null, null, "select");
    $rxPurgeDays = intval($rxPurgeSetting['purgepaymentdays'] ?? 7);
    if ($rxPurgeDays > 0) {
        // ستون time با فرمت 'Y/m/d H:i:s' ذخیره می‌شود و صفر-پد است، پس مقایسه‌ی
        // رشته‌ای همان ترتیب زمانی را می‌دهد — دقیقاً مثل کوئری انقضای بالا.
        $rxPurgeCutoff = date('Y/m/d H:i:s', time() - ($rxPurgeDays * 86400));
        $rxPurgeStmt = $pdo->prepare(
            "DELETE FROM Payment_report
              WHERE payment_Status IN ('expire', 'reject')
                AND time < :cutoff
              LIMIT 500"
        );
        $rxPurgeStmt->execute([':cutoff' => $rxPurgeCutoff]);
        $rxPurged = $rxPurgeStmt->rowCount();
        if ($rxPurged > 0) {
            if (function_exists('clearSelectCache')) {
                clearSelectCache('Payment_report');
            }
            error_log('[payment_expire] purged ' . $rxPurged . ' expired/rejected payment rows older than ' . $rxPurgeDays . ' days');
        }
    }
} catch (Throwable $rxPurgeError) {
    error_log('[payment_expire] purge failed: ' . $rxPurgeError->getMessage());
}
