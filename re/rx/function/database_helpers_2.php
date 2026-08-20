<?php


if (!function_exists('rx_release_unpaid_discount')) {

    function rx_release_unpaid_discount($userId, $discountCode = null, $referenceTime = null) {
        global $pdo;
        $userId = trim((string)$userId);
        if ($userId === '' || !isset($pdo)) return false;
        try {
            if ($referenceTime !== null && (int)$referenceTime > 0) {
                $low = (string)((int)$referenceTime - 900);
                $high = (string)((int)$referenceTime + 900);
            } else {
                $low = (string)(time() - 1800);
                $high = (string)(time() + 60);
            }
            $params = [':u' => $userId, ':lo' => $low, ':hi' => $high];
            $codeClause = '';
            if ($discountCode !== null && trim((string)$discountCode) !== '') {
                $codeClause = ' AND code = :c';
                $params[':c'] = trim((string)$discountCode);
            }
            $stmt = $pdo->prepare(
                "SELECT id, code FROM Giftcodeconsumed
                  WHERE id_user = :u
                    AND kind = 'sell'
                    AND (released IS NULL OR released = 0)
                    AND consumed_at <> ''
                    AND CAST(consumed_at AS UNSIGNED) BETWEEN :lo AND :hi" . $codeClause . "
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || empty($row['code'])) return false;

            $code = (string)$row['code'];
            $rowId = (int)$row['id'];

            $marked = $pdo->prepare('UPDATE Giftcodeconsumed SET released = 1 WHERE id = :id AND (released IS NULL OR released = 0)');
            $marked->execute([':id' => $rowId]);
            if ($marked->rowCount() < 1) return false;

            $ds = $pdo->prepare('SELECT usedDiscount FROM DiscountSell WHERE codeDiscount = :c LIMIT 1');
            $ds->execute([':c' => $code]);
            $dsRow = $ds->fetch(PDO::FETCH_ASSOC);
            if (is_array($dsRow)) {
                $used = (int)($dsRow['usedDiscount'] ?? 0) - 1;
                if ($used < 0) $used = 0;
                $pdo->prepare('UPDATE DiscountSell SET usedDiscount = :v WHERE codeDiscount = :c')
                    ->execute([':v' => (string)$used, ':c' => $code]);
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}


if (!function_exists('balance_atomic_charge')) {

    function balance_atomic_charge($userId, $delta, $allowNegativeUpTo = 0) {
        global $pdo;
        $delta = (float) $delta;
        if ($delta <= 0) return ['ok' => false, 'reason' => 'invalid-delta', 'new_balance' => null];
        $allowNegativeUpTo = max(0.0, (float) $allowNegativeUpTo);


        $minBalance = $delta - $allowNegativeUpTo;
        try {
            $stmt = $pdo->prepare("UPDATE user SET Balance = Balance - :d WHERE id = :u AND Balance >= :m");
            $stmt->execute([':d' => $delta, ':u' => $userId, ':m' => $minBalance]);
            if ($stmt->rowCount() < 1) {
                return ['ok' => false, 'reason' => 'insufficient-or-stale', 'new_balance' => null];
            }
            $sel = $pdo->prepare("SELECT Balance FROM user WHERE id = :u");
            $sel->execute([':u' => $userId]);
            $newBal = $sel->fetchColumn();
            return ['ok' => true, 'reason' => 'charged', 'new_balance' => (float) $newBal];
        } catch (Throwable $e) {
            error_log('balance_atomic_charge failed: ' . $e->getMessage());
            return ['ok' => false, 'reason' => 'db-error', 'new_balance' => null];
        }
    }
}


if (!function_exists('nm_validateSellDiscount')) {
    function nm_validateSellDiscount($code, $section, $codeProduct, $codePanel, $user, $from_id)
    {
        global $pdo;
        $code = trim((string)$code);
        $sections = ['buy', 'extend', 'volume', 'time', 'charge', 'all'];
        $section = in_array($section, $sections, true) ? $section : 'all';
        $res = ['ok' => false, 'reason' => '', 'row' => null, 'value_type' => 'percent', 'value' => 0.0, 'label' => ''];

        if ($code === '') {
            $res['reason'] = '❌ کد تخفیف را وارد کنید.';
            return $res;
        }

        if ($section !== 'charge' && intval($user['pricediscount'] ?? 0) != 0) {
            $res['reason'] = '❌ شما تخفیف اختصاصی دارید و امکان استفاده از کد تخفیف وجود ندارد.';
            return $res;
        }

        $agent       = (string)($user['agent'] ?? 'f');
        $codeProduct = ($codeProduct === '' || $codeProduct === null) ? 'all' : $codeProduct;
        $codePanel   = ($codePanel === '' || $codePanel === null) ? '/all' : $codePanel;

        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM DiscountSell
                  WHERE codeDiscount = :code
                    AND (code_product = :cp OR code_product = 'all')
                    AND (code_panel = :cpan OR code_panel = '/all')
                    AND (agent = :agent OR agent = 'allusers' OR agent = 'all')
                    AND (COALESCE(NULLIF(section, ''), type, 'all') = :section
                         OR COALESCE(NULLIF(section, ''), type, 'all') = 'all')
                    AND (status IS NULL OR status = '' OR status = 'active')
                    AND (target_user IS NULL OR target_user = '' OR target_user = :uid)
                  LIMIT 1"
            );
            $stmt->execute([
                ':code'   => $code,
                ':cp'     => $codeProduct,
                ':cpan'   => $codePanel,
                ':agent'  => $agent,
                ':section' => $section,
                ':uid'    => (string)$from_id,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('nm_validateSellDiscount query failed: ' . $e->getMessage());
            $res['reason'] = '❌ خطا در بررسی کد تخفیف.';
            return $res;
        }

        if (!$row) {
            $res['reason'] = '❌ کد تخفیف نامعتبر است یا برای این بخش فعال نیست.';
            return $res;
        }

        if (intval($row['time']) != 0 && time() >= intval($row['time'])) {
            $res['reason'] = '❌ زمان کد تخفیف به پایان رسیده است.';
            return $res;
        }

        if (intval($row['limitDiscount']) > 0 && intval($row['usedDiscount']) >= intval($row['limitDiscount'])) {
            $res['reason'] = '❌ ظرفیت استفاده از این کد تخفیف به پایان رسیده است.';
            return $res;
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM Giftcodeconsumed WHERE id_user = :u AND code = :c");
            $stmt->execute([':u' => (string)$from_id, ':c' => $code]);
            $usedByUser = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            $usedByUser = 0;
        }
        $useUser = intval($row['useuser']);
        if ($useUser > 0 && $usedByUser >= $useUser) {
            $res['reason'] = '⭕️ سقف استفاده شما از این کد تخفیف پر شده است.';
            return $res;
        }

        if ((string)($row['usefirst'] ?? '') === '1') {
            $invoiceCount = select("invoice", "*", "id_user", $from_id, "count");
            if (intval($invoiceCount) != 0) {
                $res['reason'] = '❌ این کد تخفیف فقط برای اولین خرید قابل استفاده است.';
                return $res;
            }
        }

        $vt = strtolower(trim((string)($row['value_type'] ?? '')));
        if (!in_array($vt, ['percent', 'amount', 'free'], true)) {
            $tcol = strtolower(trim((string)($row['type'] ?? '')));
            $vt = in_array($tcol, ['percent', 'amount', 'free'], true) ? $tcol : 'percent';
        }
        $val = (float)$row['price'];
        if ($vt === 'percent' && ($val <= 0 || $val > 100)) {
            $res['reason'] = '❌ درصد کد تخفیف نامعتبر است.';
            return $res;
        }
        if ($vt === 'amount' && $val <= 0) {
            $res['reason'] = '❌ مبلغ کد تخفیف نامعتبر است.';
            return $res;
        }

        $label = $vt === 'free'
            ? 'رایگان'
            : ($vt === 'amount' ? number_format($val) . ' تومان' : (string)$row['price'] . ' درصد');

        $res['ok']         = true;
        $res['row']        = $row;
        $res['value_type'] = $vt;
        $res['value']      = $val;
        $res['label']      = $label;
        return $res;
    }
}

if (!function_exists('nm_applySellDiscountToPrice')) {
    function nm_applySellDiscountToPrice($row, $price)
    {
        $price = (float)$price;
        $vt = strtolower(trim((string)($row['value_type'] ?? '')));
        if (!in_array($vt, ['percent', 'amount', 'free'], true)) {
            $tcol = strtolower(trim((string)($row['type'] ?? '')));
            $vt = in_array($tcol, ['percent', 'amount', 'free'], true) ? $tcol : 'percent';
        }
        $val = (float)($row['price'] ?? 0);
        if ($vt === 'free')   return 0.0;
        if ($vt === 'amount') return max(0.0, $price - $val);
        return max(0.0, $price - ($price * $val / 100));
    }
}

if (!function_exists('nm_markSellDiscountUsed')) {
    function nm_markSellDiscountUsed($code, $from_id, $username = '', $reportContext = '')
    {
        global $connect, $setting, $otherreport;
        $code = trim((string)$code);
        if ($code === '') return;

        try {
            $row = select("DiscountSell", "*", "codeDiscount", $code, "select");
            if ($row != false) {
                $value = intval($row['usedDiscount']) + 1;
                update("DiscountSell", "usedDiscount", $value, "codeDiscount", $code);
            }
        } catch (Throwable $e) {
            error_log('nm_markSellDiscountUsed update failed: ' . $e->getMessage());
        }

        try {
            $now  = (string)time();
            $kind = 'sell';
            $stmt = $connect->prepare("INSERT INTO Giftcodeconsumed (id_user, code, kind, consumed_at) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $from_id, $code, $kind, $now);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            try {
                $stmt = $connect->prepare("INSERT INTO Giftcodeconsumed (id_user, code) VALUES (?, ?)");
                $stmt->bind_param("ss", $from_id, $code);
                $stmt->execute();
                $stmt->close();
            } catch (Throwable $e2) {
            }
        }

        if (isset($setting['Channel_Report']) && strlen((string)$setting['Channel_Report']) > 0) {
            $uname = $username !== '' ? "@{$username} " : '';
            $ctx   = $reportContext !== '' ? " (بخش: {$reportContext})" : '';
            $text_report = "⭕️ کاربر {$uname}با آیدی عددی {$from_id} از کد تخفیف {$code}{$ctx} استفاده کرد.";
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherreport ?? null,
                'text' => $text_report,
                'parse_mode' => "HTML",
            ]);
        }
    }
}

if (!function_exists('nm_pending_charge_bonus')) {
    function nm_pending_charge_bonus($user)
    {
        $pv4 = (string)($user['Processing_value_four'] ?? '');
        if (strpos($pv4, 'chg|') !== 0) return 0;
        $parts = explode('|', $pv4);
        $bonus = isset($parts[1]) ? intval($parts[1]) : 0;
        return max(0, $bonus);
    }
}

if (!function_exists('balance_atomic_credit')) {

    function balance_atomic_credit($userId, $delta) {
        global $pdo;
        $delta = (float) $delta;
        if ($delta <= 0) return false;
        try {
            $stmt = $pdo->prepare("UPDATE user SET Balance = Balance + :d WHERE id = :u");
            $stmt->execute([':d' => $delta, ':u' => $userId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('balance_atomic_credit failed: ' . $e->getMessage());
            return false;
        }
    }
}

// [FIX] کسرِ اتمیکِ سهمِ کیف پول.
// شاخه‌های تمدید/حجم اضافه/زمان اضافه موجودی را با عددِ ثابتِ صفر بازنویسی
// می‌کردند («کیف پول همه‌اش خرج شد»)، ولی این عدد در لحظه‌ی «تایید» نوشته می‌شد
// نه لحظه‌ی صدور فاکتور. فاصله‌ی بین ثبت رسید و تایید ادمین ساعت‌ها است و هر
// شارژ/هدیه/کش‌بک/پورسانتی که در این فاصله برسد نابود می‌شد.
// این تابع فقط سهمِ واقعی را کم می‌کند و هیچ‌وقت موجودی را منفی نمی‌کند.
if (!function_exists('balance_atomic_deduct')) {

    function balance_atomic_deduct($userId, $amount) {
        global $pdo;
        $amount = (float) $amount;
        $userId = (string) $userId;
        if ($userId === '' || $amount <= 0 || !isset($pdo)) return false;
        try {
            $stmt = $pdo->prepare(
                "UPDATE user SET Balance = GREATEST(CAST(Balance AS DECIMAL(20,2)) - :d, 0) WHERE id = :u"
            );
            $stmt->execute([':d' => $amount, ':u' => $userId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('balance_atomic_deduct failed: ' . $e->getMessage());
            return false;
        }
    }
}

// [FIX] محاسبه‌ی امنِ «سهمِ کیف پول» برای شاخه‌های حجم/زمانِ اضافه.
// در آن شاخه‌ها قیمتِ کلِ سفارش مستقیماً در دسترس نیست (از جدولِ قیمتِ پنل
// بازسازی می‌شود). این تابع محافظه‌کارانه عمل می‌کند:
//   • اگر سهمِ واقعی معلوم باشد، همان کم می‌شود؛
//   • اگر معلوم نباشد، دقیقاً مثل قبل کلِ موجودی کم می‌شود (پس هیچ سفارشی
//     کم‌تر از قبل حساب نمی‌شود و ضرری متوجه فروشنده نیست)؛
//   • هیچ‌وقت بیشتر از موجودیِ فعلی کم نمی‌کند.
if (!function_exists('rx_wallet_share_paid')) {

    function rx_wallet_share_paid($recorded, $reconstructed, $balanceNow) {
        $balanceNow = (float) $balanceNow;
        if ($balanceNow <= 0) {
            return 0.0;
        }
        $share = null;
        if ($recorded !== null && $recorded !== '' && is_numeric($recorded)) {
            $share = (float) $recorded;
        } elseif ($reconstructed !== null && is_numeric($reconstructed)) {
            $share = (float) $reconstructed;
        }
        if ($share === null) {
            return $balanceNow;
        }
        if ($share < 0) {
            $share = 0.0;
        }
        if ($share > $balanceNow) {
            $share = $balanceNow;
        }
        return $share;
    }
}


function StatusPayment($paymentid)
{
    $row = select("PaySetting", "ValuePay", "NamePay", "api_nowpayment", "select");
    $apinowpayments = is_array($row) ? trim((string)($row['ValuePay'] ?? '')) : '';
    if ($apinowpayments === '' || $apinowpayments === '0') {
        $row = select("PaySetting", "ValuePay", "NamePay", "marchent_tronseller", "select");
        $apinowpayments = is_array($row) ? trim((string)($row['ValuePay'] ?? '')) : '';
    }
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.nowpayments.io/v1/payment/' . $paymentid,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apinowpayments
        ),
    ));
    $response = curl_exec($curl);
    $response = json_decode($response, true);
    curl_close($curl);
    return $response;
}
function channel(array $id_channel)
{
    global $from_id;
    $channel_link = array();
    foreach ($id_channel as $channel) {
        $response = telegram('getChatMember', [
            'chat_id' => $channel,
            'user_id' => $from_id
        ]);
        if ($response['ok']) {
            if (!in_array($response['result']['status'], ['member', 'creator', 'administrator'])) {
                $channel_link[] = $channel;
            }
        }
    }
    if (count($channel_link) == 0) {
        return [];
    } else {
        return $channel_link;
    }
}
function isValidDate($date)
{
    return (strtotime($date) != false);
}
function rxGatewayTruthy($value)
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }
    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 'true', 'success', 'successful', 'ok', 'yes'], true);
    }
    return false;
}

function tronadoExtractPaymentToken($payment)
{
    if (!is_array($payment)) {
        return '';
    }
    $candidates = [
        $payment['Data']['Token'] ?? null,
        $payment['data']['token'] ?? null,
        $payment['Data']['token'] ?? null,
        $payment['Token'] ?? null,
        $payment['token'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }
    return '';
}

function trnado($order_id, $price)
{
    global $domainhosts;

    $apitronseller = select("PaySetting", "*", "NamePay", "apiternado", "select")['ValuePay'];
    $walletSetting = select("PaySetting", "*", "NamePay", "walletaddress", "select");
    $walletaddress = trim((string) ($walletSetting['ValuePay'] ?? ''));
    $configuredUrl = trim((string) (select("PaySetting", "*", "NamePay", "urlpaymenttron", "select")['ValuePay'] ?? ''));

    $defaultEndpoints = defined('TRONADO_ORDER_TOKEN_ENDPOINTS') ? TRONADO_ORDER_TOKEN_ENDPOINTS : [];

    if (empty($defaultEndpoints)) {
        $defaultEndpoints = ['https://bot.tronado.cloud/api/v1/Order/GetOrderToken'];
    }

    $endpoints = $defaultEndpoints;
    if ($configuredUrl !== '') {
        array_unshift($endpoints, $configuredUrl);
        $endpoints = array_values(array_unique($endpoints));
    }

    $callbackUrl = 'https://' . $domainhosts . '/payment/tronado.php';
    $requestPayload = [
        'PaymentID' => (string) $order_id,
        'Amount' => is_numeric($price) ? (float) $price : $price,
        'Wallet' => $walletaddress,
        'CallbackUrl' => $callbackUrl,
        'OrderId' => (string) $order_id,
        'Metadata' => [
            'PaymentID' => (string) $order_id,
        ],
    ];

    if (trim((string) $apitronseller) === '') {
        return [
            'success' => false,
            'error' => 'کلید API ترنادو تنظیم نشده است',
        ];
    }

    if ($walletaddress === '') {
        return [
            'success' => false,
            'error' => 'آدرس کیف پول تنظیم نشده است',
        ];
    }

    $payloadJson = json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $lastErrorPayload = [
        'success' => false,
        'error' => 'Failed to contact Tronado gateway',
    ];

    foreach ($endpoints as $endpoint) {
        if ($endpoint === '') {
            continue;
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_HTTPHEADER => array(
                'x-api-key: ' . $apitronseller,
                'Content-Type: application/json',
            ),
        ));

        $response = curl_exec($curl);
        $curlErrno = curl_errno($curl);
        $curlError = curl_error($curl);
        $curlInfo = curl_getinfo($curl);
        $statusCode = $curlInfo['http_code'] ?? null;

        $responseExcerpt = '';
        if ($response !== false && $response !== null) {
            $responseExcerpt = function_exists('mb_substr') ? mb_substr($response, 0, 500) : substr($response, 0, 500);
        }

        $attemptLog = [
            'method' => 'POST',
            'url' => $endpoint,
            'http_code' => $statusCode,
        ];
        if ($responseExcerpt !== '') {
            $attemptLog['response_excerpt'] = $responseExcerpt;
        }
        if ($curlError !== '') {
            $attemptLog['curl_error'] = $curlError;
        }

        error_log('Tronado request attempt: ' . json_encode($attemptLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($response === false) {
            $lastErrorPayload = [
                'success' => false,
                'error' => $curlError !== '' ? $curlError : 'cURL execution failed',
                'status_code' => $statusCode,
                'errno' => $curlErrno,
                'url' => $endpoint,
            ];
            curl_close($curl);
            continue;
        }

        if ($statusCode !== null && $statusCode >= 400) {
            $lastErrorPayload = [
                'success' => false,
                'error' => 'Unexpected HTTP status code returned',
                'status_code' => $statusCode,
                'raw_response' => $response,
                'url' => $endpoint,
            ];

            curl_close($curl);

            if ($statusCode === 404) {
                continue;
            }

            error_log('Tronado payment request failed: ' . json_encode($lastErrorPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $lastErrorPayload;
        }

        $decodedResponse = json_decode($response, true);
        if (!is_array($decodedResponse) || !array_key_exists('IsSuccessful', $decodedResponse) || !array_key_exists('Data', $decodedResponse)) {
            $errorPayload = [
                'success' => false,
                'error' => 'Invalid response structure received from Tronado gateway',
                'status_code' => $statusCode,
                'raw_response' => $response,
                'url' => $endpoint,
            ];

            error_log('Tronado payment invalid response: ' . json_encode($errorPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            curl_close($curl);

            return $errorPayload;
        }

        curl_close($curl);

        return $decodedResponse;
    }

    error_log('Tronado payment request failed: ' . json_encode($lastErrorPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $lastErrorPayload;
}
function formatBytes($bytes, $precision = 2): string
{
    $base = log($bytes, 1024);
    $power = $bytes > 0 ? floor($base) : 0;
    $suffixes = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت'];
    return round(pow(1024, $base - $power), $precision) . ' ' . $suffixes[$power];
}
function formatOnlineAtLabel($onlineAt, $isOnline = null)
{
    if ($isOnline === true && (empty($onlineAt) || $onlineAt === null)) {
        return "Online (بدون زمان)";
    }

    if ($onlineAt === null || $onlineAt === '') {
        return "—";
    }

    if (is_string($onlineAt)) {
        $onlineAt = trim($onlineAt);
        if ($onlineAt === '') {
            return "—";
        }
        $lowered = strtolower($onlineAt);
        if ($lowered === 'online') {
            return 'آنلاین';
        }
        if ($lowered === 'offline') {
            return 'آفلاین';
        }
    }

    try {
        if (is_numeric($onlineAt)) {
            $dateTime = new DateTime('@' . intval($onlineAt));
            $dateTime->setTimezone(new DateTimeZone('Asia/Tehran'));
        } else {
            $dateTime = new DateTime((string) $onlineAt, new DateTimeZone('UTC'));
            $dateTime->setTimezone(new DateTimeZone('Asia/Tehran'));
        }
        return jdate('Y/m/d H:i:s', $dateTime->getTimestamp());
    } catch (Exception $e) {
        return (string) $onlineAt;
    }
}
function generateUsername($from_id, $Metode, $username, $randomString, $text, $namecustome, $usernamecustom)
{
    $setting = select("setting", "*", null, null, "select");
    $user = select("user", "*", "id", $from_id, "select");
    if ($user == false) {
        $user = array();
        $user = array(
            'number_username' => '',
        );
    }
    if ($Metode == "آیدی عددی + حروف و عدد رندوم") {
        return $from_id . "_" . $randomString;
    } elseif ($Metode == "نام کاربری + عدد به ترتیب") {
        if ($username == "NOT_USERNAME") {
            if (preg_match('/^\w{3,32}$/', $namecustome)) {
                $username = $namecustome;
            }
        }
        return $username . "_" . $user['number_username'];
    } elseif ($Metode == "نام کاربری دلخواه")
        return $text;
    elseif ($Metode == "نام کاربری دلخواه + عدد رندوم") {
        $random_number = rand(1000000, 9999999);
        return $text . "_" . $random_number;
    } elseif ($Metode == "متن دلخواه + عدد رندوم") {
        return $namecustome . "_" . $randomString;
    } elseif ($Metode == "متن دلخواه + عدد ترتیبی") {
        return $namecustome . "_" . $setting['numbercount'];
    } elseif ($Metode == "آیدی عددی+عدد ترتیبی") {
        return $from_id . "_" . $user['number_username'];
    } elseif ($Metode == "متن دلخواه نماینده + عدد ترتیبی") {
        if ($usernamecustom == "none") {
            return $namecustome . "_" . $setting['numbercount'];
        }
        return $usernamecustom . "_" . $user['number_username'];
    }
}
function outputlunk($text)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $text);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 6000);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        return null;
    } else {
        return $response;
    }

    curl_close($ch);
}
function outputlunksub($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$url/info");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);


    $headers = array();
    $headers[] = 'Accept: application/json';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    return $result;
    curl_close($ch);
}
function normalizeServiceConfigs($configs, $subscriptionUrl = null)
{
    $normalized = [];

    if (is_array($configs)) {
        foreach ($configs as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $normalized[] = $item;
        }
    } elseif (is_string($configs)) {
        $parts = preg_split("/\r\n|\n|\r/", $configs);
        if (is_array($parts)) {
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $normalized[] = $part;
            }
        }
    }

    $subscriptionUrl = is_string($subscriptionUrl) ? trim($subscriptionUrl) : '';
    if (empty($normalized) && $subscriptionUrl !== '') {
        if (preg_match('/^https?:/i', $subscriptionUrl)) {
            $fetched = outputlunk($subscriptionUrl);
            if (is_string($fetched) && $fetched !== '') {
                if (isBase64($fetched)) {
                    $fetched = base64_decode($fetched);
                }
                $parts = preg_split("/\r\n|\n|\r/", $fetched);
                if (is_array($parts)) {
                    foreach ($parts as $part) {
                        $part = trim($part);
                        if ($part === '') {
                            continue;
                        }
                        $normalized[] = $part;
                    }
                }
            }
        } else {
            $normalized[] = $subscriptionUrl;
        }
    }

    return array_values($normalized);
}
function DirectPayment($order_id, $image = 'images.jpg')
{
    if (function_exists('cubepay_log')) cubepay_log('DP: enter', ['order' => $order_id]);
    global $pdo, $ManagePanel, $textbotlang, $keyboardextendfnished, $keyboard, $Confirm_pay, $from_id, $message_id, $datatextbot;
    $buyreport = select("topicid", "idreport", "report", "buyreport", "select")['idreport'];
    $admin_ids = select("admin", "id_admin", null, null, "FETCH_COLUMN");
    $otherservice = select("topicid", "idreport", "report", "otherservice", "select")['idreport'];
    $otherreport = select("topicid", "idreport", "report", "otherreport", "select")['idreport'];
    $errorreport = select("topicid", "idreport", "report", "errorreport", "select")['idreport'];
    $porsantreport = select("topicid", "idreport", "report", "porsantreport", "select")['idreport'];
    $setting = select("setting", "*");
    $Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
    $paymentNote = formatPaymentReportNote($Payment_report['dec_not_confirmed'] ?? null);
    $format_price_cart = number_format($Payment_report['price']);
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
    $steppay = explode("|", $Payment_report['id_invoice']);
    update("user", "Processing_value", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_one", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_tow", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_four", "0", "id", $Balance_id['id']);
    if (function_exists('cubepay_log')) cubepay_log('DP: flow', ['step' => $steppay[0] ?? '?']);
    if ($steppay[0] == "getconfigafterpay") {
        // [invoice lookup with fallbacks] گاهی به‌خاطر race/cleanup/timing بین crypto-pay و DirectPayment،
        // فاکتور با username + Status='unpaid' پیدا نمیشه. چندتا fallback می‌گذاریم تا قبل از refund همه گزینه‌ها تست بشن.
        $__invUsername = isset($steppay[1]) ? trim((string)$steppay[1]) : '';
        $get_invoice = false;
        if ($__invUsername !== '') {
            // [FIX بحرانی — فاکتورِ کاربرِ دیگری تحویل داده می‌شد]
            // نام کاربریِ سرویس در کلِ فروشگاه یکتا نیست: چکِ تکراری‌بودن فقط روی
            // «یک» پنل انجام می‌شود و در حالت «نام کاربری دلخواه» خودِ مشتری اسم را
            // تایپ می‌کند. پس دو مشتری روی دو پنل می‌توانند هر دو "ali" داشته باشند.
            // سه جستجوی زیر id_user نداشتند، یعنی پرداختِ مشتری A می‌توانست فاکتورِ
            // پرداخت‌نشده‌ی مشتری B را پیدا کند، سرویسِ B را بسازد و فاکتورِ B را
            // پرداخت‌شده علامت بزند — و A هیچ چیزی نگیرد.
            // ORDER BY id_invoice هم تفکیک‌کننده نبود چون id_invoice هگزِ تصادفی است
            // (نه ترتیبِ زمانی)؛ با دو کاندید، انتخاب عملاً شیر یا خط بود.
            // Payment_report.id_user در همه‌ی مسیرها (ربات، مینی‌اپ، تاییدِ دستیِ ادمین)
            // صاحبِ فاکتور است، پس محدودکردن به id_user چیزی را از دست نمی‌دهد.
            try {
                // 1) username + Status='unpaid' — محدود به همین کاربر
                $stmt = $pdo->prepare("SELECT * FROM invoice WHERE username = :u AND id_user = :uid AND Status = 'unpaid' ORDER BY time_sell DESC LIMIT 1");
                $stmt->execute([':u' => $__invUsername, ':uid' => $Balance_id['id']]);
                $get_invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $__e) { $get_invoice = false; }
            if (!$get_invoice) {
                try {
                    // 2) بدون فیلتر Status (در صورت تفاوت case یا تغییر status توسط cron دیگه)
                    $stmt = $pdo->prepare("SELECT * FROM invoice WHERE username = :u AND id_user = :uid ORDER BY time_sell DESC LIMIT 1");
                    $stmt->execute([':u' => $__invUsername, ':uid' => $Balance_id['id']]);
                    $get_invoice = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $__e) { $get_invoice = false; }
            }
            if (!$get_invoice) {
                try {
                    // 3) case-insensitive روی username — اگه collation داره فرق می‌کنه
                    $stmt = $pdo->prepare("SELECT * FROM invoice WHERE LOWER(username) = LOWER(:u) AND id_user = :uid ORDER BY time_sell DESC LIMIT 1");
                    $stmt->execute([':u' => $__invUsername, ':uid' => $Balance_id['id']]);
                    $get_invoice = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $__e) { $get_invoice = false; }
            }
        }
        if (!$get_invoice) {
            try {
                // 4) آخرین چاره: آخرین فاکتور unpaid این کاربر که usernameش با user_id شروع میشه (پترن مرسوم)
                $stmt = $pdo->prepare("SELECT * FROM invoice WHERE id_user = :uid AND (Status = 'unpaid' OR Status = 'Unpaid') AND username LIKE :prefix ORDER BY time_sell DESC LIMIT 1");
                $stmt->execute([':uid' => (string)$Balance_id['id'], ':prefix' => $Balance_id['id'] . '_%']);
                $get_invoice = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$get_invoice) {
                    // اگه پیدا نشد، بدون prefix filter
                    $stmt = $pdo->prepare("SELECT * FROM invoice WHERE id_user = :uid AND (Status = 'unpaid' OR Status = 'Unpaid') ORDER BY time_sell DESC LIMIT 1");
                    $stmt->execute([':uid' => (string)$Balance_id['id']]);
                    $get_invoice = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                // [NOTE] قبلاً اینجا username رو به مقدار اصلی (`$__invUsername`) برمی‌گردوندیم،
                // ولی این باعث می‌شد cycle شکست-retry بی‌نهایت بشه: هر retry با username اصلی duplicate می‌خورد،
                // یوزرنیم تازه می‌ساخت تو پنل (zombie)، بعد restore دوباره به اصلی، دوباره duplicate، الی آخر.
                // الان username رو همون که DB داره نگه می‌داریم. اگه retry قبلی یوزر تو پنل ساخته، zombie-rescue
                // اون رو پیدا می‌کنه و استفاده می‌کنه؛ اگه نساخته، duplicate-retry با random جدید موفق میشه.
            } catch (Throwable $__e) { $get_invoice = false; }
        }
        // اگه با هیچ روشی پیدا نشد، قبل از اینکه به refund برسیم به ادمین گزارش بدیم و مستقیم برگردیم
        if (!$get_invoice) {
            if (function_exists('cubepay_log')) cubepay_log('DP: invoice NOT FOUND - aborting');
            if (function_exists('error_log')) {
                @error_log("[DirectPayment] invoice NOT FOUND for order={$order_id} user={$Balance_id['id']} steppay[1]={$__invUsername} — aborting WITHOUT refund (so cryptocheck stuck-refund can handle it cleanly)");
            }
            $__setting = function_exists('select') ? select('setting', '*', null, null, 'select') : [];
            $__errReport = function_exists('select') ? (select('topicid', 'idreport', 'report', 'errorreport', 'select')['idreport'] ?? null) : null;
            $__txt = "⚠️ <b>فاکتور پیدا نشد برای ساخت سرویس</b>\n"
                   . "🛒 کد سفارش: <code>{$order_id}</code>\n"
                   . "👤 کاربر: <code>{$Balance_id['id']}</code>\n"
                   . "🔎 username موردنظر: <code>" . htmlspecialchars($__invUsername) . "</code>\n"
                   . "ℹ️ DirectPayment بدون refund برگشت — لطفاً دستی بررسی کنید.";
            if (!empty($__setting['Channel_Report']) && function_exists('telegram')) {
                @telegram('sendmessage', [
                    'chat_id' => $__setting['Channel_Report'],
                    'message_thread_id' => $__errReport,
                    'text' => $__txt,
                    'parse_mode' => 'HTML',
                ]);
            }
            return false;
        }
        $userAgent = $Balance_id['agent'] ?? 'f';
        $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :name AND (Location = :loc OR Location = '/all') AND (agent = :agent OR agent = 'all')");
        $stmt->execute([':name' => $get_invoice['name_product'], ':loc' => $get_invoice['Service_location'], ':agent' => $userAgent]);
        $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($get_invoice['name_product'] == "🛍 حجم دلخواه" || $get_invoice['name_product'] == "⚙️ سرویس دلخواه") {
            $info_product['data_limit_reset'] = "no_reset";
            $info_product['Volume_constraint'] = $get_invoice['Volume'];
            $info_product['name_product'] = $textbotlang['users']['customsellvolume']['title'];
            $info_product['code_product'] = "customvolume";
            $info_product['Service_time'] = $get_invoice['Service_time'];
            $info_product['price_product'] = $get_invoice['price_product'];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :name AND (Location = :loc OR Location = '/all') AND (agent = :agent OR agent = 'all')");
            $stmt->execute([':name' => $get_invoice['name_product'], ':loc' => $get_invoice['Service_location'], ':agent' => $userAgent]);
            $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $username_ac = $get_invoice['username'];
        $randomString = bin2hex(random_bytes(2));
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $get_invoice['Service_location'], "select");

        // [panel-missing guard] اگه پنل پیدا نشد، اصلاً نباید بریم سراغ createUser چون قطعاً "Panel Not Found" برمی‌گردونه.
        // قبل از refund، به ادمین گزارش بدیم تا دلیل واقعی (مثلاً پنل حذف شده، نام عوض شده، Service_location خراب) مشخص بشه.
        if (!is_array($marzban_list_get) || empty($marzban_list_get['name_panel'])) {
            if (function_exists('error_log')) {
                @error_log("[DirectPayment] panel missing for order={$order_id} user={$Balance_id['id']} location='" . (string)($get_invoice['Service_location'] ?? '') . "' invoice={$get_invoice['id_invoice']} — aborting WITHOUT refund");
            }
            $__setting2 = function_exists('select') ? select('setting', '*', null, null, 'select') : [];
            $__errReport2 = function_exists('select') ? (select('topicid', 'idreport', 'report', 'errorreport', 'select')['idreport'] ?? null) : null;
            $__txt2 = "⚠️ <b>پنل برای ساخت سرویس پیدا نشد</b>\n"
                    . "🛒 کد سفارش: <code>{$order_id}</code>\n"
                    . "👤 کاربر: <code>{$Balance_id['id']}</code>\n"
                    . "📍 لوکیشن ذخیره‌شده در فاکتور: <code>" . htmlspecialchars((string)($get_invoice['Service_location'] ?? '')) . "</code>\n"
                    . "🧾 شناسه فاکتور: <code>{$get_invoice['id_invoice']}</code>\n"
                    . "ℹ️ DirectPayment بدون refund برگشت — لطفاً پنل را در دیتابیس بررسی کنید.";
            if (!empty($__setting2['Channel_Report']) && function_exists('telegram')) {
                @telegram('sendmessage', [
                    'chat_id' => $__setting2['Channel_Report'],
                    'message_thread_id' => $__errReport2,
                    'text' => $__txt2,
                    'parse_mode' => 'HTML',
                ]);
            }
            return false;
        }

        // [idempotent-refund guard] اگر این فاکتور قبلاً refund خورده (نشانه auto-refund تو dec_not_confirmed)،
        // دیگه نباید دوباره کیف پول رو شارژ کنیم — همینجا برمی‌گردیم و فقط لاگ می‌زنیم.
        // این جلوی double/triple-credit موقع retry گیر کردن سرویس رو می‌گیره.
        $__alreadyRefunded = false;
        try {
            $__pr = select("Payment_report", "dec_not_confirmed", "id_order", $order_id, "select");
            $__decNote = is_array($__pr) ? (string)($__pr['dec_not_confirmed'] ?? '') : '';
            if ($__decNote !== '' && stripos($__decNote, 'auto-refund') !== false) {
                $__alreadyRefunded = true;
            }
        } catch (Throwable $__e) { $__alreadyRefunded = false; }
        if ($__alreadyRefunded) {
            return false;
        }

        // [username normalize] اگر یوزرنیم فاکتور خالی/کوتاه‌تر از 3 کاراکتر بود، یکی معتبر بساز.
        // این از خطای پنل "Username must be at least 3 characters long" جلوگیری می‌کنه.
        if (!is_string($username_ac) || trim($username_ac) === '' || strlen(trim($username_ac)) < 3) {
            $username_ac = preg_replace('/[^A-Za-z0-9_]/', '', (string)$Balance_id['id']) . '_' . bin2hex(random_bytes(4));
            if (strlen($username_ac) < 3) $username_ac = 'u' . bin2hex(random_bytes(4));
            // فقط وقتی id_invoice معتبره update کن (جلوی خطای "Column id_invoice cannot be null" گرفته میشه)
            if (!empty($get_invoice['id_invoice'])) {
                try { update("invoice", "username", $username_ac, "id_invoice", $get_invoice['id_invoice']); } catch (Throwable $__e) { /* fail-open */ }
            }
        }

        // [duplicate-username guard - REMOVED]
        // پچ قبلی یک حلقه pre-check با DataUser داشت که باعث می‌شد:
        //   - چندین API call به panel قبل از createUser → کند و گاهی timeout
        //   - اگر createUser قبلی نیمه‌کاره موفق بوده (yوزر ساخته شده ولی bot جواب نگرفته)، DataUser می‌گفت "exists" و یوزرنیم عوض می‌شد
        //   - هر retry یوزرنیم جدید → چندین یوزر زامبی توی پنل + سرویس نهایی هیچ‌وقت تحویل نشد
        // الان فقط روی duplicate-error واقعی از createUser (در پایین) retry می‌کنیم، که هم سریع‌تر و هم دقیق‌تره.
        // این رفتار همون چیزیه که در wallet-payment موفق عمل می‌کنه.
        $date = strtotime("+" . $get_invoice['Service_time'] . "days");
        if (intval($get_invoice['Service_time']) == 0) {
            $timestamp = 0;
        } else {
            $timestamp = strtotime(date("Y-m-d H:i:s", $date));
        }
        $datac = array(
            'expire' => $timestamp,
            'data_limit' => $get_invoice['Volume'] * pow(1024, 3),
            'from_id' => $Balance_id['id'],
            'username' => $Balance_id['username'],
            'type' => 'buy'
        );
        if (function_exists('nmPanelNationalEnabled') && nmPanelNationalEnabled($marzban_list_get)) {
            if (nmStockCompleteBuyFromInventory($Balance_id['id'], $Balance_id, $marzban_list_get, $info_product, $get_invoice['id_invoice'], $username_ac, false, 'paid_national_buy')) {
                sendmessage($Balance_id['id'], $textbotlang['users']['selectoption'], $keyboard, 'HTML');
                return;
            }
            // [FIX بازگشتِ وجهِ اتمیک] عددِ کهنه‌ی موجودی بازنویسی می‌شد و هر
            // شارژ/هدیه‌ای که هم‌زمان می‌رسید از بین می‌رفت.
            balance_atomic_credit($Balance_id['id'], (float) ($Payment_report['price'] ?? 0));
            $balance = (float) $Balance_id['Balance'] + (float) $Payment_report['price'];
            // [refund-marker] برای جلوگیری از double-refund توسط retry
            try {
                $__nationalNote = '[auto-refund: national stock empty at ' . date('Y-m-d H:i:s') . ']';
                $__mk = $pdo->prepare("UPDATE Payment_report SET dec_not_confirmed = CASE WHEN dec_not_confirmed IS NULL OR dec_not_confirmed = '' THEN :n1 ELSE CONCAT(dec_not_confirmed, ' | ', :n2) END WHERE id_order = :o");
                $__mk->execute([':n1' => $__nationalNote, ':n2' => $__nationalNote, ':o' => $order_id]);
            } catch (Throwable $__e) { /* fail-open */ }
            sendmessage($Balance_id['id'], "❌ وضعیت نت ملی فعال است اما موجودی انبار برای این محصول تمام شده است. مبلغ پرداختی به کیف پول برگشت خورد.", $keyboard, 'HTML');
            return false;
        }
        // [zombie-rescue] قبل از تلاش جدید، اگه قبلاً تو panel یوزری برای این کاربر ساخته شده (zombie)،
        // اول بررسی کن: شاید createUser تو call قبلی موفق بوده فقط response نرسیده. اگه پیدا کردیم،
        // از همون استفاده کن (بدون ساختن یوزر جدید) — این جلوی تولید بیشتر zombie رو می‌گیره.
        $__isRetryCall = false;
        try {
            $__pr2 = select("Payment_report", "crypto_check_count", "id_order", $order_id, "select");
            $__cnt = is_array($__pr2) ? (int)($__pr2['crypto_check_count'] ?? 0) : 0;
            if ($__cnt >= 1) $__isRetryCall = true;
        } catch (Throwable $__e) { /* ignore */ }

        $dataoutput = null;
        if ($__isRetryCall) {
            // در حالت retry، اول چک کن یوزر در پنل وجود داره یا نه (با همون username فاکتور)
            try {
                $__zombieCheck = $ManagePanel->DataUser($marzban_list_get['name_panel'], $username_ac);
                if (is_array($__zombieCheck) && !empty($__zombieCheck['username']) && (string)$__zombieCheck['username'] === (string)$username_ac) {
                    // یوزر تو پنل هست! یعنی createUser قبلی واقعاً موفق بوده، فقط bot جواب نگرفته.
                    // از همون استفاده کن.
                    $dataoutput = $__zombieCheck;
                    $dataoutput['status'] = 'successful';
                    if (empty($dataoutput['configs']) && !empty($dataoutput['links'])) {
                        $dataoutput['configs'] = is_array($dataoutput['links']) ? $dataoutput['links'] : explode("\n", (string)$dataoutput['links']);
                    }
                }
            } catch (Throwable $__e) { /* fail-open */ }
        }

        if (function_exists('cubepay_log')) cubepay_log('DP: calling createUser', ['panel' => $marzban_list_get['name_panel'] ?? '?', 'user' => $username_ac]);
        if (empty($dataoutput) || empty($dataoutput['username'])) {
            $dataoutput = $ManagePanel->createUser($marzban_list_get['name_panel'], $info_product['code_product'], $username_ac, $datac);
        }
        if (function_exists('cubepay_log')) cubepay_log('DP: createUser returned', ['username' => $dataoutput['username'] ?? null]);

        // [duplicate retry — حداکثر 1 بار] اگه createUser duplicate برگردوند، فقط یک بار با random جدید retry می‌کنیم.
        try {
            if (empty($dataoutput['username'])) {
                $__msgRaw = is_array($dataoutput) ? ($dataoutput['msg'] ?? '') : '';
                $__msgStr = is_string($__msgRaw) ? $__msgRaw : json_encode($__msgRaw);
                if (stripos($__msgStr, 'duplicate') !== false || stripos($__msgStr, 'already exist') !== false || stripos($__msgStr, 'exists') !== false) {
                    $username_ac = preg_replace('/[^A-Za-z0-9_]/', '', (string)$Balance_id['id']) . '_' . bin2hex(random_bytes(4));
                    if (strlen($username_ac) < 3) $username_ac = 'u' . bin2hex(random_bytes(4));
                    if (!empty($get_invoice['id_invoice'])) {
                        try { update("invoice", "username", $username_ac, "id_invoice", $get_invoice['id_invoice']); } catch (Throwable $__e2) { /* fail-open */ }
                    }
                    $dataoutput = $ManagePanel->createUser($marzban_list_get['name_panel'], $info_product['code_product'], $username_ac, $datac);
                }
            }
        } catch (Throwable $__e) { /* fail-open */ }

        // [CRITICAL: mark invoice active EARLY]
        // اگه createUser موفق بوده، همین الان قبل از هر sendmessage/QR code generation/الخ که ممکنه hang کنه،
        // invoice رو active علامت بزن. این جلوی retry بعدی توسط cron رو می‌گیره حتی اگه پیام تلگرام hang کنه.
        if (!empty($dataoutput['username']) && !empty($get_invoice['id_invoice'])) {
            try {
                $__early = $pdo->prepare("UPDATE invoice SET Status = 'active' WHERE id_invoice = :i");
                $__early->execute([':i' => $get_invoice['id_invoice']]);
            } catch (Throwable $__e) { /* fail-open */ }
            // و dec_not_confirmed را با علامت موفقیت بگذار تا retry cron این رو پیدا نکنه
            try {
                $__doneNote = '[service-created at ' . date('Y-m-d H:i:s') . ' username=' . $dataoutput['username'] . ']';
                $__mk = $pdo->prepare("UPDATE Payment_report SET dec_not_confirmed = CASE WHEN dec_not_confirmed IS NULL OR dec_not_confirmed = '' THEN :n1 ELSE CONCAT(dec_not_confirmed, ' | ', :n2) END WHERE id_order = :o");
                $__mk->execute([':n1' => $__doneNote, ':n2' => $__doneNote, ':o' => $order_id]);
            } catch (Throwable $__e) { /* fail-open */ }
        }
        if ($dataoutput['username'] == null && function_exists('nmPanelEmergencyPanel')) {
            $emergencyPanel = nmPanelEmergencyPanel($marzban_list_get);
            if ($emergencyPanel) {
                $emergencyProduct = function_exists('nmEmergencyProductFor') ? nmEmergencyProductFor($info_product, $emergencyPanel) : $info_product;
                $datac['data_limit'] = ($emergencyProduct['Volume_constraint'] ?? $info_product['Volume_constraint']) * pow(1024, 3);
                $datac['expire'] = strtotime('+' . (int)($emergencyProduct['Service_time'] ?? $info_product['Service_time']) . ' day');
                $emergencyOut = $ManagePanel->createUser($emergencyPanel['name_panel'], $emergencyProduct['code_product'], $username_ac, $datac);
                if (($emergencyOut['username'] ?? null) != null) {
                    $dataoutput = $emergencyOut;
                    $marzban_list_get = $emergencyPanel;
                }
            }
        }
        if ($dataoutput['username'] == null && function_exists('nmPanelEmergencyEnabled') && nmPanelEmergencyEnabled($marzban_list_get)) {
            if (nmStockCompleteBuyFromInventory($Balance_id['id'], $Balance_id, $marzban_list_get, $info_product, $get_invoice['id_invoice'], $username_ac, false, 'paid_emergency_stock')) {
                sendmessage($Balance_id['id'], $textbotlang['users']['selectoption'], $keyboard, 'HTML');
                return true;
            }
        }
        if ($dataoutput['username'] == null) {
            if (function_exists('cubepay_log')) cubepay_log('DP: creation FAILED - refund path');
            $dataoutput['msg'] = json_encode($dataoutput['msg']);
            // [FIX بازگشتِ وجهِ اتمیک] عددِ کهنه‌ی موجودی بازنویسی می‌شد و هر
            // شارژ/هدیه‌ای که هم‌زمان می‌رسید از بین می‌رفت.
            balance_atomic_credit($Balance_id['id'], (float) ($Payment_report['price'] ?? 0));
            $balance = (float) $Balance_id['Balance'] + (float) $Payment_report['price'];
            // [refund-marker] برای جلوگیری از double-refund توسط retry — حتماً قبل از sendmessageها مارک کن
            try {
                $__failNote = '[auto-refund: service creation failed at ' . date('Y-m-d H:i:s') . ']';
                $__mk = $pdo->prepare("UPDATE Payment_report SET dec_not_confirmed = CASE WHEN dec_not_confirmed IS NULL OR dec_not_confirmed = '' THEN :n1 ELSE CONCAT(dec_not_confirmed, ' | ', :n2) END WHERE id_order = :o");
                $__mk->execute([':n1' => $__failNote, ':n2' => $__failNote, ':o' => $order_id]);
            } catch (Throwable $__e) { /* fail-open */ }
            // پیام UI fallback اگه textbotlang در دسترس نباشه (مثلاً وقتی از cron صدا زده میشه)
            $__uiErr = isset($textbotlang['users']['sell']['ErrorConfig']) && is_string($textbotlang['users']['sell']['ErrorConfig']) && trim($textbotlang['users']['sell']['ErrorConfig']) !== ''
                ? $textbotlang['users']['sell']['ErrorConfig']
                : "❌ متاسفانه ساخت سرویس با خطا مواجه شد. مبلغ پرداختی به کیف پول شما برگشت داده شد.";
            sendmessage($Balance_id['id'], $__uiErr, $keyboard, 'HTML');
            sendmessage($Balance_id['id'], "💎  کاربر عزیز بدلیل ساخته نشدن سرویس مبلغ $balance تومان به کیف پول شما اضافه گردید.", $keyboard, 'HTML');
            $texterros = "
⭕️ خطا در ساخت کانفیگ
✍️ دلیل خطا :
{$dataoutput['msg']}
آیدی کابر : {$Balance_id['id']}
نام کاربری کاربر : @{$Balance_id['username']}
نام پنل : {$marzban_list_get['name_panel']}";
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $texterros,
                    'parse_mode' => "HTML"
                ]);
            }
            return false;
        }
        $Shoppinginfo = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => "📚 مشاهده آموزش استفاده ", 'callback_data' => "helpbtn"],
                ]
            ]
        ]);
        $output_config_link = "";
        $config = "";
        if ($marzban_list_get['config'] == "onconfig" && is_array($dataoutput['configs'])) {
            foreach ($dataoutput['configs'] as $link) {
                $config .= "\n" . $link;
            }
        }
        $output_config_link = $marzban_list_get['sublink'] == "onsublink" ? $dataoutput['subscription_url'] : "";
        $datatextbot['textafterpay'] = $marzban_list_get['type'] == "Manualsale" ? $datatextbot['textmanual'] : $datatextbot['textafterpay'];
        $datatextbot['textafterpay'] = $marzban_list_get['type'] == "WGDashboard" ? $datatextbot['text_wgdashboard'] : $datatextbot['textafterpay'];
        $datatextbot['textafterpay'] = $marzban_list_get['type'] == "ibsng" || $marzban_list_get['type'] == "mikrotik" ? $datatextbot['textafterpayibsng'] : $datatextbot['textafterpay'];
        if (intval($get_invoice['Service_time']) == 0)
            $get_invoice['Service_time'] = $textbotlang['users']['stateus']['Unlimited'];
        $textcreatuser = str_replace('{username}', $dataoutput['username'], $datatextbot['textafterpay']);
        $textcreatuser = str_replace('{name_service}', $get_invoice['name_product'], $textcreatuser);
        $textcreatuser = str_replace('{location}', $marzban_list_get['name_panel'], $textcreatuser);
        $textcreatuser = str_replace('{day}', $get_invoice['Service_time'], $textcreatuser);
        $textcreatuser = str_replace('{volume}', $get_invoice['Volume'], $textcreatuser);
        $textcreatuser = applyConnectionPlaceholders($textcreatuser, $output_config_link, $config);
        if ($marzban_list_get['type'] == "Manualsale" || $marzban_list_get['type'] == "ibsng" || $marzban_list_get['type'] == "mikrotik") {
            $textcreatuser = str_replace('{password}', $dataoutput['subscription_url'], $textcreatuser);
            update("invoice", "user_info", $dataoutput['subscription_url'], "id_invoice", $get_invoice['id_invoice']);
        }
        if (function_exists('cubepay_log')) cubepay_log('DP: sending delivery message');
        sendMessageService($marzban_list_get, $dataoutput['configs'], $output_config_link, $dataoutput['username'], $Shoppinginfo, $textcreatuser, $get_invoice['id_invoice'], $get_invoice['id_user'], $image);
        if (function_exists('cubepay_log')) cubepay_log('DP: delivery message sent');
        $partsdic = explode("_", $Balance_id['Processing_value_four'], $get_invoice['id_user']);
        if ($partsdic[0] == "dis") {
            $SellDiscountlimit = select("DiscountSell", "*", "codeDiscount", $partsdic[1], "select");
            $value = intval($SellDiscountlimit['usedDiscount']) + 1;
            update("DiscountSell", "usedDiscount", $value, "codeDiscount", $partsdic[1]);
            $stmt = $pdo->prepare("INSERT INTO Giftcodeconsumed (id_user,code) VALUES (:id_user,:code)");
            $stmt->bindParam(':id_user', $Balance_id['id']);
            $stmt->bindParam(':code', $partsdic[1]);
            $stmt->execute();
            $text_report = "⭕️ یک کاربر با نام کاربری @{$Balance_id['username']}  و آیدی عددی {$Balance_id['id']} از کد تخفیف {$partsdic[1]} استفاده کرد.";
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $otherreport,
                    'text' => $text_report,
                ]);
            }
        }
        $affiliatescommission = select("affiliates", "*", null, null, "select");
        $marzbanporsant_one_buy = select("affiliates", "*", null, null, "select");
        $stmt = $pdo->prepare("SELECT * FROM invoice WHERE name_product != 'سرویس تست'  AND id_user = :id_user AND Status != 'Unpaid'");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->execute();
        $countinvoice = $stmt->rowCount();
        if ($affiliatescommission['status_commission'] == "oncommission" && ($Balance_id['affiliates'] != null && intval($Balance_id['affiliates']) != 0)) {
            if ($marzbanporsant_one_buy['porsant_one_buy'] == "on_buy_porsant") {
                if ($countinvoice <= 1) {
                    $result = ($Payment_report['price'] * $setting['affiliatespercentage']) / 100;
                    $user_Balance = select("user", "*", "id", $Balance_id['affiliates'], "select");
                    if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['affiliates'], $admin_ids)) {
                        sendmessage($Balance_id['affiliates'], "📌شما 2 امتیاز جدید کسب کردید.", null, 'html');
                        $scorenew = $user_Balance['score'] + 2;
                        update("user", "score", $scorenew, "id", $Balance_id['affiliates']);
                    }
                    // [FIX نابودیِ درآمدِ معرف] پورسانت به کاربرِ *دیگری* واریز
                    // می‌شود؛ موجودی او خوانده می‌شد، بعد پیام تلگرام می‌رفت، و
                    // سپس عددِ کهنه بازنویسی می‌شد — هر خرید یا درآمدی که آن
                    // کاربر در همان لحظه داشت پاک می‌شد. افزایشِ اتمیک.
                    $dateacc = date('Y/m/d H:i:s');
                    balance_atomic_credit($Balance_id['affiliates'], (float) $result);
                    $result = number_format($result);
                    $textadd = "🎁  پرداخت پورسانت

        مبلغ $result تومان به حساب شما از طرف  زیر مجموعه تان به کیف پول شما واریز گردید";
                    $textreportport = "
مبلغ $result به کاربر {$Balance_id['affiliates']} برای پورسانت از کاربر {$Balance_id['id']} واریز گردید
تایم : $dateacc";
                    if (strlen($setting['Channel_Report']) > 0) {
                        telegram('sendmessage', [
                            'chat_id' => $setting['Channel_Report'],
                            'message_thread_id' => $porsantreport,
                            'text' => $textreportport,
                            'parse_mode' => "HTML"
                        ]);
                    }
                    sendmessage($Balance_id['affiliates'], $textadd, null, 'HTML');
                }
            } else {

                $result = ($Payment_report['price'] * $setting['affiliatespercentage']) / 100;
                $user_Balance = select("user", "*", "id", $Balance_id['affiliates'], "select");
                if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['affiliates'], $admin_ids)) {
                    sendmessage($Balance_id['affiliates'], "📌شما 2 امتیاز جدید کسب کردید.", null, 'html');
                    $scorenew = $user_Balance['score'] + 2;
                    update("user", "score", $scorenew, "id", $Balance_id['affiliates']);
                }
                // [FIX نابودیِ درآمدِ معرف] — مثل شاخه‌ی بالا.
                $dateacc = date('Y/m/d H:i:s');
                balance_atomic_credit($Balance_id['affiliates'], (float) $result);
                $result = number_format($result);
                $textadd = "🎁  پرداخت پورسانت

        مبلغ $result تومان به حساب شما از طرف  زیر مجموعه تان به کیف پول شما واریز گردید";
                $textreportport = "
مبلغ $result به کاربر {$Balance_id['affiliates']} برای پورسانت از کاربر {$Balance_id['id']} واریز گردید
تایم : $dateacc";
                if (strlen($setting['Channel_Report']) > 0) {
                    telegram('sendmessage', [
                        'chat_id' => $setting['Channel_Report'],
                        'message_thread_id' => $porsantreport,
                        'text' => $textreportport,
                        'parse_mode' => "HTML"
                    ]);
                }
                sendmessage($Balance_id['affiliates'], $textadd, null, 'HTML');
            }
        }
        if ($marzban_list_get['MethodUsername'] == "متن دلخواه + عدد ترتیبی" || $marzban_list_get['MethodUsername'] == "نام کاربری + عدد به ترتیب" || $marzban_list_get['MethodUsername'] == "آیدی عددی+عدد ترتیبی" || $marzban_list_get['MethodUsername'] == "متن دلخواه نماینده + عدد ترتیبی") {
            $value = intval($Balance_id['number_username']) + 1;
            update("user", "number_username", $value, "id", $Balance_id['id']);
            if ($marzban_list_get['MethodUsername'] == "متن دلخواه + عدد ترتیبی" || $marzban_list_get['MethodUsername'] == "متن دلخواه نماینده + عدد ترتیبی") {
                $value = intval($setting['numbercount']) + 1;
                update("setting", "numbercount", $value);
            }
        }
        $__walletPortion = (float)$get_invoice['price_product'] - (float)($Payment_report['price'] ?? 0);
        if ($__walletPortion < 0) {
            $__walletPortion = 0;
        }
        $Balance_prims = (float)$Balance_id['Balance'] - $__walletPortion;
        if ($Balance_prims <= 0) {
            $Balance_prims = 0;
        }
        if (function_exists('cubepay_log')) cubepay_log('DP: deducting wallet', ['portion' => $__walletPortion, 'balance_before' => $Balance_id['Balance'], 'balance_after' => $Balance_prims]);
        // کسرِ اتمیک و «حداکثر یک‌بار برای همیشه»: اول ادعای مارکر [wallet-done]
        // روی Payment_report (فقط اگر قبلاً نخورده)، بعد کسرِ موجودی — هر دو در
        // یک تراکنش. اگر وسطش هر بلایی سرِ پروسه بیاید، یا هر دو ثبت شده‌اند یا
        // هیچ‌کدام؛ و بازیابیِ successful.php بعداً همین ادعا را تکرار می‌کند.
        try {
            $pdo->beginTransaction();
            $__claim = $pdo->prepare(
                "UPDATE Payment_report SET dec_not_confirmed = CONCAT(COALESCE(dec_not_confirmed,''), ' [wallet-done]')
                 WHERE id_order = ? AND (dec_not_confirmed IS NULL OR dec_not_confirmed NOT LIKE '%[wallet-done]%')"
            );
            $__claim->execute([$order_id]);
            if ($__claim->rowCount() === 1) {
                if ($__walletPortion > 0) {
                    $pdo->prepare("UPDATE user SET Balance = GREATEST(0, CAST(Balance AS DECIMAL(20,2)) - ?) WHERE id = ?")
                        ->execute([$__walletPortion, $Balance_id['id']]);
                }
                $pdo->commit();
                if (function_exists('cubepay_log')) cubepay_log('DP: wallet deducted', ['claimed' => 'yes', 'portion' => $__walletPortion]);
            } else {
                $pdo->rollBack();
                if (function_exists('cubepay_log')) cubepay_log('DP: wallet already deducted — skipped');
            }
        } catch (Throwable $__we) {
            try { $pdo->rollBack(); } catch (Throwable $__ignored) {}
            if (function_exists('cubepay_log')) cubepay_log('DP: wallet deduction FAILED — rescue will retry', ['error' => $__we->getMessage()]);
        }
        $balanceformatsell = select("user", "Balance", "id", $get_invoice['id_user'], "select")['Balance'];
        $balanceformatsell = number_format($balanceformatsell, 0);
        $balancebefore = number_format($Balance_id['Balance'], 0);
        $timejalali = jdate('Y/m/d H:i:s');
        $textonebuy = "";
        if ($countinvoice == 1) {
            $textonebuy = "📌 خرید اول کاربر";
        }
        // [fallback] اگه textbotlang در cron context کامل لود نشده، text رو با مقدار default پر کن تا تلگرام reject نکنه
        $__mngBtnText = '👤 مدیریت کاربر';
        if (isset($textbotlang['Admin']['ManageUser']['mangebtnuser']) && is_string($textbotlang['Admin']['ManageUser']['mangebtnuser']) && trim($textbotlang['Admin']['ManageUser']['mangebtnuser']) !== '') {
            $__mngBtnText = $textbotlang['Admin']['ManageUser']['mangebtnuser'];
        }
        $Response = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $__mngBtnText, 'callback_data' => 'manageuser_' . $Balance_id['id']],
                ],
            ]
        ]);
        $text_report = "📣 جزئیات ساخت اکانت در ربات بعد پرداخت ثبت شد .

$textonebuy
▫️آیدی عددی کاربر : <code>{$Balance_id['id']}</code>
▫️نام کاربری کاربر :@{$Balance_id['username']}
▫️نام کاربری کانفیگ :$username_ac
▫️لوکیشن سرویس : {$get_invoice['Service_location']}
▫️زمان خریداری شده :{$get_invoice['Service_time']} روز
▫️نام محصول خریداری شده :{$get_invoice['name_product']}
▫️حجم خریداری شده : {$get_invoice['Volume']} GB
▫️موجودی قبل خرید : $balancebefore تومان
▫️موجودی بعد خرید : $balanceformatsell تومان
▫️کد پیگیری: {$get_invoice['id_invoice']}
▫️نوع کاربر : {$Balance_id['agent']}
▫️شماره تلفن کاربر : {$Balance_id['number']}
▫️قیمت محصول : {$get_invoice['price_product']} تومان
▫️قیمت نهایی : {$Payment_report['price']} تومان
▫️زمان خرید : $timejalali";
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $buyreport,
                'text' => $text_report,
                'parse_mode' => "HTML",
                'reply_markup' => $Response
            ]);
            if (function_exists('cubepay_log')) cubepay_log('DP: channel report sent');
            try {
                $pdo->prepare(
                    "UPDATE Payment_report SET dec_not_confirmed = CONCAT(COALESCE(dec_not_confirmed,''), ' [report-done]')
                     WHERE id_order = ? AND (dec_not_confirmed IS NULL OR dec_not_confirmed NOT LIKE '%[report-done]%')"
                )->execute([$order_id]);
            } catch (Throwable $__re) { /* بازیابی جبران می‌کند */ }
        }
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], "📌شما 1 امتیاز جدید کسب کردید.", null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        update("invoice", "Status", "active", "username", $get_invoice['username']);
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            update("invoice", "Status", "active", "id_invoice", $get_invoice['id_invoice']);
            $textconfrom = "✅ پرداخت تایید شده
🛍خرید سرویس
▫️نام کاربری کانفیگ :$username_ac
▫️لوکیشن سرویس : {$get_invoice['Service_location']}
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💎 موجودی قبل خرید  : {$Balance_id['Balance']}
💸 مبلغ پرداختی: $format_price_cart تومان
✍️ توضیحات : {$paymentNote}

";
            // [FIX پیام دوتایی] وقتی ادمین رسید را تایید می‌کند، Editmessagetext
            // اینجا یک پیامِ تایید می‌سازد و هندلرِ تاییدِ ادمین (Confirm_pay_) هم
            // بعد از برگشت از این تابع دوباره یک پیامِ تایید می‌سازد — نتیجه دو
            // پیامِ جدا با اطلاعاتِ متفاوت (چه از ربات چه از دکمه‌ی رسیدِ مینی‌اپ).
            // اگر فراخوان اعلام کرده باشد که خودش پیامِ نهایی را می‌سازد، اینجا فقط
            // متن را تحویلش می‌دهیم تا در «یک» پیامِ واحد ادغام شود.
            if (!empty($GLOBALS['rx_admin_confirm_merge'])) {
                $GLOBALS['rx_dp_admin_confirm_text'] = $textconfrom;
            } else {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
    } elseif ($steppay[0] == "getextenduser") {
        $balanceformatsell = number_format(select("user", "Balance", "id", $Balance_id['id'], "select")['Balance'], 0);
        $partsdic = explode("%", $steppay[1]);
        $usernamepanel = $partsdic[0];
        $sql = "SELECT * FROM service_other WHERE username = :username  AND value  LIKE CONCAT('%', :value, '%') AND id_user = :id_user ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':username', $usernamepanel, PDO::PARAM_STR);
        $stmt->bindParam(':value', $partsdic[1], PDO::PARAM_STR);
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->execute();
        $data_order = $stmt->fetch(PDO::FETCH_ASSOC);
        $service_other = $data_order;
        if ($service_other == false) {
            sendmessage($Balance_id['id'], '❌ خطایی در هنگام تمدید رخ داده با پشتیبانی در ارتباط باشید', $keyboard, 'HTML');
            return false;
        }
        $service_other = json_decode($service_other['value'], true);
        $codeproduct = $service_other['code_product'];
        // Scoped to the payer, for the same reason as the bot's own copy of
        // this flow: the row decides which panel prices the extension.
        $nameloc = rx_invoice_for_user((string) $usernamepanel, $Balance_id['id']) ?: false;
        if (!is_array($nameloc)) {
            sendmessage($Balance_id['id'], '❌ سرویس این تمدید پیدا نشد؛ با پشتیبانی در ارتباط باشید.', null, 'HTML');
            return false;
        }
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        if ($codeproduct == "custom_volume") {
            $prodcut['code_product'] = "custom_volume";
            $prodcut['name_product'] = $nameloc['name_product'];
            $prodcut['price_product'] = $data_order['price'];
            $prodcut['Service_time'] = $service_other['Service_time'];
            $prodcut['Volume_constraint'] = $service_other['volumebuy'];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE (Location = '{$nameloc['Service_location']}' OR Location = '/all') AND (agent = '{$Balance_id['agent']}' OR agent = 'all') AND code_product = '$codeproduct'");
            $stmt->execute();
            $prodcut = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($nameloc['name_product'] == "سرویس تست") {
            update("invoice", "name_product", $prodcut['name_product'], "id_invoice", $nameloc['id_invoice']);
            update("invoice", "price_product", $prodcut['price_product'], "id_invoice", $nameloc['id_invoice']);
        }
        if (function_exists('nmStopIfServicePanelBlocked') && nmStopIfServicePanelBlocked($nameloc, $Balance_id['id'], $keyboard)) {
            // [FIX بازگشتِ وجهِ اتمیک] عددِ کهنه‌ی موجودی بازنویسی می‌شد و هر
            // شارژ/هدیه‌ای که هم‌زمان می‌رسید از بین می‌رفت.
            balance_atomic_credit($Balance_id['id'], (float) ($Payment_report['price'] ?? 0));
            $balance = (float)($Balance_id['Balance'] ?? 0) + (float)($Payment_report['price'] ?? 0);
            sendmessage($Balance_id['id'], "💎 مبلغ پرداختی به دلیل فعال بودن وضعیت اینترنت ملی/پنل اضطراری به کیف پول شما برگشت خورد.", $keyboard, 'HTML');
            return false;
        }
        $dateacc = date('Y/m/d H:i:s');
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $nameloc['username']);
        // [FIX نابودیِ کیف پول هنگام تمدید]
        // قبلاً اینجا موجودی با عددِ ثابتِ صفر بازنویسی می‌شد. منطقِ پشتش این بود
        // که «این شاخه فقط وقتی اجرا می‌شود که کیف پول کفافِ قیمت را نداده، پس
        // همه‌اش خرج شده». اما این صفر در لحظه‌ی «تاییدِ پرداخت» نوشته می‌شد، نه
        // لحظه‌ی صدور فاکتور — و بینشان ساعت‌ها فاصله است. هر شارژی که مشتری در
        // این فاصله انجام می‌داد، لحظه‌ی تاییدِ تو کاملاً از بین می‌رفت.
        // سهمِ واقعیِ کیف پول = قیمت محصول منهای چیزی که از درگاه پرداخت شده.
        $__walletShareExtend = (float) ($prodcut['price_product'] ?? 0) - (float) ($Payment_report['price'] ?? 0);
        if ($__walletShareExtend > 0) {
            balance_atomic_deduct($Balance_id['id'], $__walletShareExtend);
        }
        $extend = $ManagePanel->extend($marzban_list_get['Methodextend'], $prodcut['Volume_constraint'], $prodcut['Service_time'], $nameloc['username'], $prodcut['code_product'], $marzban_list_get['code_panel']);
        if ($extend['status'] == false && function_exists('nmPanelEmergencyPanel')) {
            $emergencyPanel = nmPanelEmergencyPanel($marzban_list_get);
            if ($emergencyPanel) {
                $emergencyProduct = function_exists('nmEmergencyProductFor') ? nmEmergencyProductFor($prodcut, $emergencyPanel) : $prodcut;
                $extendEmergency = $ManagePanel->extend($emergencyPanel['Methodextend'], $emergencyProduct['Volume_constraint'], $emergencyProduct['Service_time'], $nameloc['username'], $emergencyProduct['code_product'], $emergencyPanel['code_panel']);
                if (($extendEmergency['status'] ?? false) != false) {
                    $extend = $extendEmergency;
                    $marzban_list_get = $emergencyPanel;
                }
            }
        }
        if ($extend['status'] == false) {
            $fallbackStock = nmStockFallbackForInvoice($nameloc, $prodcut, 'extend_panel_fallback');
            if ($fallbackStock) {
                update("service_other", "output", json_encode(['status' => true, 'source' => 'nm_stock', 'stock_id' => $fallbackStock['id'], 'tier' => $fallbackStock['tier']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "id", $data_order['id']);
                update("service_other", "status", "paid", "id", $data_order['id']);
                sendmessage($Balance_id['id'], "✅ پنل اصلی در دسترس نبود؛ تمدید شما با کانفیگ جایگزین از انبار شبکه‌ملی تکمیل شد.", $keyboard, 'HTML');
                return true;
            }
            // [FIX بازگشتِ وجهِ اتمیک] عددِ کهنه‌ی موجودی بازنویسی می‌شد و هر
            // شارژ/هدیه‌ای که هم‌زمان می‌رسید از بین می‌رفت.
            balance_atomic_credit($Balance_id['id'], (float) ($Payment_report['price'] ?? 0));
            $balance = (float) $Balance_id['Balance'] + (float) $Payment_report['price'];
            sendmessage($Balance_id['id'], $textbotlang['users']['sell']['ErrorConfig'], $keyboard, 'HTML');
            sendmessage($Balance_id['id'], "💎  کاربر عزیز بدلیل تمدید نشدن سرویس مبلغ $balance تومان به کیف پول شما اضافه گردید.", $keyboard, 'HTML');
            $extend['msg'] = json_encode($extend['msg']);
            $textreports = "
        خطای تمدید سرویس
نام پنل : {$marzban_list_get['name_panel']}
نام کاربری سرویس : {$nameloc['username']}
دلیل خطا : {$extend['msg']}";
            sendmessage($nameloc['id_user'], "❌خطایی در تمدید سرویس رخ داده با پشتیبانی در ارتباط باشید", null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return false;
        }

        update("service_other", "output", json_encode($extend), "id", $data_order['id']);
        update("service_other", "status", "paid", "id", $data_order['id']);
        $partsdic = explode("_", $Balance_id['Processing_value_four']);
        if ($partsdic[0] == "dis") {
            $SellDiscountlimit = select("DiscountSell", "*", "codeDiscount", $partsdic[1], "select");
            $value = intval($SellDiscountlimit['usedDiscount']) + 1;
            update("DiscountSell", "usedDiscount", $value, "codeDiscount", $partsdic[1]);
            $stmt = $pdo->prepare("INSERT INTO Giftcodeconsumed (id_user,code) VALUES (:id_user,:code)");
            $stmt->bindParam(':id_user', $Balance_id['id']);
            $stmt->bindParam(':code', $partsdic[1]);
            $stmt->execute();
            $text_report = "⭕️ یک کاربر با نام کاربری @{$Balance_id['username']}  و آیدی عددی {$Balance_id['id']} از کد تخفیف {$partsdic[1]} استفاده کرد.";
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $otherreport,
                    'text' => $text_report,
                ]);
            }
        }
        $keyboardextendfnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['stateus']['backlist'], 'callback_data' => "backorder"],
                ],
                [
                    ['text' => $textbotlang['users']['stateus']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        if ($Balance_id['agent'] == "f") {
            $valurcashbackextend = select("shopSetting", "*", "Namevalue", "chashbackextend", "select")['value'];
        } else {
            $valurcashbackextend = json_decode(select("shopSetting", "*", "Namevalue", "chashbackextend_agent", "select")['value'], true)[$Balance_id['agenr']];
        }
        if (intval($valurcashbackextend) != 0) {
            $result = ($prodcut['price_product'] * $valurcashbackextend) / 100;
            // [FIX نابودیِ موجودی هنگام کش‌بکِ تمدید]
            // این یک «انتساب» بود نه «افزایش»: موجودی کاربر برابرِ خودِ مبلغِ
            // کش‌بک نوشته می‌شد. مشتری با ۲۰۰٬۰۰۰ تومان موجودی، پس از گرفتنِ
            // ۵٬۰۰۰ تومان هدیه‌ی تمدید، فقط ۵٬۰۰۰ تومان برایش می‌ماند — در حالی
            // که پیامِ خطِ بعد می‌گوید «حساب شما شارژ گردید».
            // balance_atomic_credit افزایشِ اتمیک انجام می‌دهد، پس شارژ/هدیه‌ای
            // که هم‌زمان برسد هم از بین نمی‌رود.
            balance_atomic_credit($Balance_id['id'], (float) $result);
            sendmessage($Balance_id['id'], "تبریک 🎉
📌 به عنوان هدیه تمدید مبلغ $result تومان حساب شما شارژ گردید", null, 'HTML');
        }
        $priceproductformat = number_format($prodcut['price_product']);
        $textextend = "✅ تمدید برای سرویس شما با موفقیت صورت گرفت
 
▫️نام سرویس : $usernamepanel
▫️نام محصول : {$prodcut['name_product']}
▫️مبلغ تمدید $priceproductformat تومان
";
        sendmessage($Balance_id['id'], $textextend, $keyboardextendfnished, 'HTML');
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], "📌شما 2 امتیاز جدید کسب کردید.", null, 'html');
            $scorenew = $Balance_id['score'] + 2;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $timejalali = jdate('Y/m/d H:i:s');
        $text_report = "📣 جزئیات تمدید اکانت در ربات شما ثبت شد .
    
▫️آیدی عددی کاربر : <code>{$Balance_id['id']}</code>
▫️نام کاربری کاربر : @{$Balance_id['username']}
▫️نام کاربری کانفیگ :$usernamepanel
▫️موقعیت سرویس سرویس : {$nameloc['Service_location']}
▫️نام محصول : {$prodcut['name_product']}
▫️حجم محصول : {$prodcut['Volume_constraint']}
▫️زمان محصول : {$prodcut['Service_time']}
▫️مبلغ تمدید : $priceproductformat تومان
▫️موجودی قبل از خرید : $balanceformatsell تومان
▫️زمان خرید : $timejalali";
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
                'parse_mode' => "HTML"
            ]);
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {

            $textconfrom = "✅ پرداخت تایید شده
🔋 تمدید سرویس
🪪 نام کاربری کانفیگ : $usernamepanel
🛍 نام محصول : {$prodcut['name_product']}
🌏 نام لوکیشن : {$nameloc['Service_location']}
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💎 موجودی قبل تمدید  : {$Balance_id['Balance']}
💸 مبلغ پرداختی: $format_price_cart تومان
✍️ توضیحات : {$paymentNote}

";
            // [FIX پیام دوتایی] وقتی ادمین رسید را تایید می‌کند، Editmessagetext
            // اینجا یک پیامِ تایید می‌سازد و هندلرِ تاییدِ ادمین (Confirm_pay_) هم
            // بعد از برگشت از این تابع دوباره یک پیامِ تایید می‌سازد — نتیجه دو
            // پیامِ جدا با اطلاعاتِ متفاوت (چه از ربات چه از دکمه‌ی رسیدِ مینی‌اپ).
            // اگر فراخوان اعلام کرده باشد که خودش پیامِ نهایی را می‌سازد، اینجا فقط
            // متن را تحویلش می‌دهیم تا در «یک» پیامِ واحد ادغام شود.
            if (!empty($GLOBALS['rx_admin_confirm_merge'])) {
                $GLOBALS['rx_dp_admin_confirm_text'] = $textconfrom;
            } else {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
    } elseif ($steppay[0] == "getextravolumeuser") {
        $steppay = explode("%", $steppay[1]);
        $volume = $steppay[1];
        // Scoped to the payer, for the same reason as the bot's own copy of
        // this flow: the row decides which panel prices the extension.
        $nameloc = rx_invoice_for_user((string) $steppay[0], $Balance_id['id']) ?: false;
        if (!is_array($nameloc)) {
            sendmessage($Balance_id['id'], '❌ سرویس این تمدید پیدا نشد؛ با پشتیبانی در ارتباط باشید.', null, 'HTML');
            return false;
        }
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        // [FIX نابودیِ کیف پول هنگام خرید حجم اضافه]
        // قیمتِ کلِ این سفارش مستقیماً ذخیره نشده، پس از جدولِ قیمتِ حجمِ اضافه‌ی
        // همین پنل و تخفیفِ کاربر بازسازی‌اش می‌کنیم تا فقط «سهمِ واقعیِ کیف پول»
        // کم شود، نه کلِ موجودی. اگر بازسازی ممکن نبود، تابع کمکی دقیقاً مثل قبل
        // کلِ موجودی را برمی‌گرداند تا رفتار قدیمی حفظ شود.
        $__xvRebuilt = null;
        $__xvPrices  = json_decode((string) ($marzban_list_get['priceextravolume'] ?? ''), true);
        $__xvPerGig  = is_array($__xvPrices) ? (float) ($__xvPrices[$Balance_id['agent']] ?? 0) : 0.0;
        if ($__xvPerGig > 0) {
            $__xvTotal = (float) $volume * $__xvPerGig;
            $__xvDisc  = (int) ($Balance_id['pricediscount'] ?? 0);
            if ($__xvDisc != 0) {
                $__xvTotal -= ($__xvTotal * $__xvDisc) / 100;
            }
            $__xvRebuilt = $__xvTotal - (float) ($Payment_report['price'] ?? 0);
        }
        $__xvWalletTake = rx_wallet_share_paid($steppay[2] ?? null, $__xvRebuilt, $Balance_id['Balance'] ?? 0);
        $inboundid = $marzban_list_get['inboundid'];
        if ($nameloc['inboundid'] != null) {
            $inboundid = $nameloc['inboundid'];
        }
        if (function_exists('nmStopIfServicePanelBlocked') && nmStopIfServicePanelBlocked($nameloc, $Balance_id['id'], $keyboard)) {
            balance_atomic_credit($Balance_id['id'], (float) ($Payment_report['price'] ?? 0));
            sendmessage($Balance_id['id'], "💎 مبلغ پرداختی به دلیل فعال بودن وضعیت اینترنت ملی/پنل اضطراری به کیف پول شما برگشت خورد.", $keyboard, 'HTML');
            return false;
        }
        balance_atomic_deduct($Balance_id['id'], $__xvWalletTake);
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $steppay[0]);
        $data_for_database = json_encode(array(
            'volume_value' => $volume,
            'old_volume' => $DataUserOut['data_limit'],
            'expire_old' => $DataUserOut['expire']
        ));
        $dateacc = date('Y/m/d H:i:s');
        $type = "extra_user";
        $extra_volume = $ManagePanel->extra_volume($nameloc['username'], $marzban_list_get['code_panel'], $volume);
        if ($extra_volume['status'] == false) {
            // [FIX نبودِ بازگشتِ وجه] شاخه‌ی تمدیدِ عادی بازگشتِ وجه دارد، ولی این
            // شاخه فقط خطا را گزارش می‌کرد و برمی‌گشت — یعنی مشتری هم پولِ درگاه را
            // داده بود، هم سهمِ کیف پولش کسر شده بود، و هیچ چیزی نگرفته بود.
            balance_atomic_credit($Balance_id['id'], (float) ($Payment_report['price'] ?? 0));
            if (!empty($__xvWalletTake)) {
                balance_atomic_credit($Balance_id['id'], (float) $__xvWalletTake);
            }
            $extra_volume['msg'] = json_encode($extra_volume['msg']);
            $textreports = "خطای خرید حجم اضافه
نام پنل : {$marzban_list_get['name_panel']}
نام کاربری سرویس : {$nameloc['username']}
دلیل خطا : {$extra_volume['msg']}";
            sendmessage($nameloc['id_user'], "❌ خطایی در خرید حجم اضافه رخ داد. کلِ مبلغ پرداختی به کیف پول شما برگشت داده شد. در صورت نیاز با پشتیبانی در ارتباط باشید.", null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return false;
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO service_other (id_user, username,value,type,time,price,output) VALUES (:id_user,:username,:value,:type,:time,:price,:output)");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':username', $steppay[0]);
        $stmt->bindParam(':value', $data_for_database);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':time', $dateacc);
        $stmt->bindParam(':price', $Payment_report['price']);
        $stmt->bindParam(':output', json_encode($extra_volume));
        $stmt->execute();
        $keyboardextrafnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['stateus']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        $volumesformat = number_format($Payment_report['price'], 0);
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], "📌شما 1 امتیاز جدید کسب کردید.", null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $textvolume = "✅ افزایش حجم برای سرویس شما با موفقیت صورت گرفت
 
▫️نام سرویس  : {$steppay[0]}
▫️حجم اضافه : $volume گیگ

▫️مبلغ افزایش حجم : $volumesformat تومان";
        sendmessage($Balance_id['id'], $textvolume, $keyboardextrafnished, 'HTML');
        $volumes = $volume;
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            $textconfrom = "✅ پرداخت تایید شده
🔋 خرید حجم اضافه
🛍 حجم خریداری شده  : $volumes گیگ
👤 نام کاربری کانفیگ {$steppay[0]}
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💎 موجودی قبل ازافزایش موجودی : {$Balance_id['Balance']}
💸 مبلغ پرداختی: $format_price_cart تومان
";
            // [FIX پیام دوتایی] وقتی ادمین رسید را تایید می‌کند، Editmessagetext
            // اینجا یک پیامِ تایید می‌سازد و هندلرِ تاییدِ ادمین (Confirm_pay_) هم
            // بعد از برگشت از این تابع دوباره یک پیامِ تایید می‌سازد — نتیجه دو
            // پیامِ جدا با اطلاعاتِ متفاوت (چه از ربات چه از دکمه‌ی رسیدِ مینی‌اپ).
            // اگر فراخوان اعلام کرده باشد که خودش پیامِ نهایی را می‌سازد، اینجا فقط
            // متن را تحویلش می‌دهیم تا در «یک» پیامِ واحد ادغام شود.
            if (!empty($GLOBALS['rx_admin_confirm_merge'])) {
                $GLOBALS['rx_dp_admin_confirm_text'] = $textconfrom;
            } else {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        $text_report = "⭕️ یک کاربر حجم اضافه خریده است
        
اطلاعات کاربر : 
🪪 آیدی عددی : {$Balance_id['id']}
🛍 حجم خریداری شده  : $volumes گیگ
💰 مبلغ پرداختی : {$Payment_report['price']} تومان
👤 نام کاربری کانفیگ {$steppay[0]}
موجودی کاربر قبل خرید : {$Balance_id['Balance']}
";
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
                'parse_mode' => "HTML"
            ]);
        }
    } elseif ($steppay[0] == "getextratimeuser") {
        $steppay = explode("%", $steppay[1]);
        $tmieextra = $steppay[1];
        // Scoped to the payer, for the same reason as the bot's own copy of
        // this flow: the row decides which panel prices the extension.
        $nameloc = rx_invoice_for_user((string) $steppay[0], $Balance_id['id']) ?: false;
        if (!is_array($nameloc)) {
            sendmessage($Balance_id['id'], '❌ سرویس این تمدید پیدا نشد؛ با پشتیبانی در ارتباط باشید.', null, 'HTML');
            return false;
        }
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        // [FIX نابودیِ کیف پول هنگام خرید زمان اضافه] — دقیقاً مثل شاخه‌ی حجمِ
        // اضافه. ضمناً کسر قبلاً روی `$nameloc['id_user']` انجام می‌شد (صاحبِ
        // فاکتور) نه پرداخت‌کننده؛ حالا که فاکتور به خودِ پرداخت‌کننده محدود شده
        // این دو یکی‌اند، ولی صریحاً از شناسه‌ی پرداخت‌کننده استفاده می‌کنیم.
        $__xtRebuilt = null;
        $__xtPrices  = json_decode((string) ($marzban_list_get['priceextratime'] ?? ''), true);
        $__xtPerDay  = is_array($__xtPrices) ? (float) ($__xtPrices[$Balance_id['agent']] ?? 0) : 0.0;
        if ($__xtPerDay > 0) {
            $__xtTotal = (float) $tmieextra * $__xtPerDay;
            $__xtDisc  = (int) ($Balance_id['pricediscount'] ?? 0);
            if ($__xtDisc != 0) {
                $__xtTotal -= ($__xtTotal * $__xtDisc) / 100;
            }
            $__xtRebuilt = $__xtTotal - (float) ($Payment_report['price'] ?? 0);
        }
        $__xtWalletTake = rx_wallet_share_paid($steppay[2] ?? null, $__xtRebuilt, $Balance_id['Balance'] ?? 0);
        $inboundid = $marzban_list_get['inboundid'];
        if ($nameloc['inboundid'] != false) {
            $inboundid = $nameloc['inboundid'];
        }
        if (function_exists('nmStopIfServicePanelBlocked') && nmStopIfServicePanelBlocked($nameloc, $Balance_id['id'], $keyboard)) {
            balance_atomic_credit($Balance_id['id'], (float) ($Payment_report['price'] ?? 0));
            sendmessage($Balance_id['id'], "💎 مبلغ پرداختی به دلیل فعال بودن وضعیت اینترنت ملی/پنل اضطراری به کیف پول شما برگشت خورد.", $keyboard, 'HTML');
            return false;
        }
        balance_atomic_deduct($Balance_id['id'], $__xtWalletTake);
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $steppay[0]);
        $data_for_database = json_encode(array(
            'day' => $tmieextra,
            'old_volume' => $DataUserOut['data_limit'],
            'expire_old' => $DataUserOut['expire']
        ));
        $dateacc = date('Y/m/d H:i:s');
        $type = "extra_time_user";
        $timeservice = $DataUserOut['expire'] - time();
        $day = floor($timeservice / 86400);
        $extra_time = $ManagePanel->extra_time($nameloc['username'], $marzban_list_get['code_panel'], $tmieextra);
        if ($extra_time['status'] == false) {
            // [FIX نبودِ بازگشتِ وجه] — مثل شاخه‌ی حجمِ اضافه؛ قبلاً مشتری هم پول
            // درگاه را داده بود، هم سهمِ کیف پولش کسر شده بود، و هیچ نمی‌گرفت.
            balance_atomic_credit($Balance_id['id'], (float) ($Payment_report['price'] ?? 0));
            if (!empty($__xtWalletTake)) {
                balance_atomic_credit($Balance_id['id'], (float) $__xtWalletTake);
            }
            $extra_time['msg'] = json_encode($extra_time['msg']);
            $textreports = "خطای خرید زمان اضافه
نام پنل : {$marzban_list_get['name_panel']}
نام کاربری سرویس : {$nameloc['username']}
دلیل خطا : {$extra_time['msg']}";
            sendmessage($Balance_id['id'], "❌ خطایی در خرید زمان اضافه رخ داد. کلِ مبلغ پرداختی به کیف پول شما برگشت داده شد. در صورت نیاز با پشتیبانی در ارتباط باشید.", null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return false;
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO service_other (id_user, username,value,type,time,price,output) VALUES (:id_user,:username,:value,:type,:time,:price,:output)");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':username', $steppay[0]);
        $stmt->bindParam(':value', $data_for_database);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':time', $dateacc);
        $stmt->bindParam(':price', $Payment_report['price']);
        $stmt->bindParam(':output', json_encode($extra_time));
        $stmt->execute();
        $keyboardextrafnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['stateus']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        $volumesformat = number_format($Payment_report['price']);
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], "📌شما 1 امتیاز جدید کسب کردید.", null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $textextratime = "✅ افزایش زمان برای سرویس شما با موفقیت صورت گرفت
 
▫️نام سرویس : {$steppay[0]}
▫️زمان اضافه : $tmieextra روز

▫️مبلغ افزایش زمان : $volumesformat تومان";
        sendmessage($Balance_id['id'], $textextratime, $keyboardextrafnished, 'HTML');
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            $volumes = $tmieextra;
            $textconfrom = "✅ پرداخت تایید شده
🔋 خرید زمان اضافه
🛍 زمان خریداری شده  : $volumes روز
👤 نام کاربری کانفیگ {$steppay[0]}
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💎 موجودی قبل ازافزایش موجودی : {$Balance_id['Balance']}
💸 مبلغ پرداختی: $format_price_cart تومان
";
            // [FIX پیام دوتایی] وقتی ادمین رسید را تایید می‌کند، Editmessagetext
            // اینجا یک پیامِ تایید می‌سازد و هندلرِ تاییدِ ادمین (Confirm_pay_) هم
            // بعد از برگشت از این تابع دوباره یک پیامِ تایید می‌سازد — نتیجه دو
            // پیامِ جدا با اطلاعاتِ متفاوت (چه از ربات چه از دکمه‌ی رسیدِ مینی‌اپ).
            // اگر فراخوان اعلام کرده باشد که خودش پیامِ نهایی را می‌سازد، اینجا فقط
            // متن را تحویلش می‌دهیم تا در «یک» پیامِ واحد ادغام شود.
            if (!empty($GLOBALS['rx_admin_confirm_merge'])) {
                $GLOBALS['rx_dp_admin_confirm_text'] = $textconfrom;
            } else {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        $text_report = "⭕️ یک کاربر زمان اضافه خریده است
        
اطلاعات کاربر : 
🪪 آیدی عددی : {$Balance_id['id']}
🛍 زمان خریداری شده  : $volumes روز
💰 مبلغ پرداختی : {$Payment_report['price']} تومان
👤 نام کاربری کانفیگ {$steppay[0]}";
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
            ]);
        }
    } else {
        $__chargeBonus = isset($Payment_report['charge_bonus']) ? intval($Payment_report['charge_bonus']) : 0;
        $__paidAmount = intval($Payment_report['price']);
        $__creditAmount = $__paidAmount + $__chargeBonus;
        // [FIX از‌دست‌رفتنِ شارژِ هم‌زمان] این پرکاربردترین مسیرِ پولیِ ربات است.
        // موجودی یک‌بار در ابتدای DirectPayment خوانده می‌شد و بعد از چندین تماسِ
        // شبکه‌ای و پیامِ تلگرام، مقدارِ کهنه + مبلغ دوباره نوشته می‌شد. اگر دو رسید
        // نزدیک به هم تایید می‌شد (تاییدِ پشت‌سرهمِ صف توسط ادمین، یا کرونِ رمزارز
        // هم‌زمان با وب‌هوک)، فقط یکی از آن‌ها اعمال می‌شد و شارژِ دیگری بی‌صدا
        // گم می‌شد. افزایشِ اتمیک این مشکل را از بین می‌برد.
        balance_atomic_credit($Payment_report['id_user'], (float) $__creditAmount);
        $Balance_confrim = intval($Balance_id['Balance']) + $__creditAmount;
        update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);
        update("user", "Processing_value_four", "", "id", $Payment_report['id_user']);
        $format_price_cart = number_format($__paidAmount, 0);
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            $textconfrom = "⭕️ یک پرداخت جدید انجام شده است
افزایش موجودی.
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💸 مبلغ پرداختی: $format_price_cart تومان
💎 موجودی قبل ازافزایش موجودی : {$Balance_id['Balance']}
✍️ توضیحات : {$paymentNote}";
            // [FIX پیام دوتایی] وقتی ادمین رسید را تایید می‌کند، Editmessagetext
            // اینجا یک پیامِ تایید می‌سازد و هندلرِ تاییدِ ادمین (Confirm_pay_) هم
            // بعد از برگشت از این تابع دوباره یک پیامِ تایید می‌سازد — نتیجه دو
            // پیامِ جدا با اطلاعاتِ متفاوت (چه از ربات چه از دکمه‌ی رسیدِ مینی‌اپ).
            // اگر فراخوان اعلام کرده باشد که خودش پیامِ نهایی را می‌سازد، اینجا فقط
            // متن را تحویلش می‌دهیم تا در «یک» پیامِ واحد ادغام شود.
            if (!empty($GLOBALS['rx_admin_confirm_merge'])) {
                $GLOBALS['rx_dp_admin_confirm_text'] = $textconfrom;
            } else {
                Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
            }
        }
        $__creditFmt = number_format($__creditAmount, 0);
        sendmessage($Payment_report['id_user'], "💎 کاربر گرامی مبلغ {$__creditFmt} تومان به کیف پول شما واریز گردید با تشکراز پرداخت شما.
                
🛒 کد پیگیری شما: {$Payment_report['id_order']}", null, 'HTML');
    }

    // [FIX] DirectPayment قبلاً هیچ‌وقت مقداری برنمی‌گرداند (همیشه null).
    // اما nm_safe_direct_payment() شرطِ سخت‌گیرانه‌ی `$result === false` را چک
    // می‌کند تا شکستِ تحویل را به ادمین گزارش بدهد — و چون null هرگز === false
    // نیست، آن گارد عملاً همیشه خاموش بود: وقتی ساختِ سرویس شکست می‌خورد و پول
    // برگشت داده می‌شد، پنلِ ادمین باز هم «موفق» نشان می‌داد و حتی کش‌بکِ کارت
    // هم پرداخت می‌شد. حالا مسیرهای شکست false و رسیدن به انتها true می‌دهد.
    return true;
}

/**
 * The invoice with this service username that belongs to this customer.
 *
 * Service usernames are not unique across a shop — the duplicate check runs
 * against one panel, and with «نام کاربری دلخواه» the customer picks the name
 * themselves — so `select("invoice", "*", "username", $u, "select")` returns
 * whichever row MySQL reaches first, which may be somebody else's.
 *
 * Two different bugs came out of that, depending on whether the caller
 * bothered to check afterwards:
 *
 *   No check — the receipt an admin is asked to confirm showed another
 *   customer's product, volume and duration.
 *
 *   Checked after the fact — the query found the stranger's row, the ownership
 *   test then failed, and the actual owner was told their service does not
 *   exist. Safe, and still broken for the person who paid.
 *
 * Scoping the query fixes both: the owner always finds theirs, and nobody
 * finds anybody else's.
 */
if (!function_exists('rx_invoice_for_user')) {
    function rx_invoice_for_user(string $username, $userId): ?array
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO) || trim($username) === '' || (string) $userId === '') {
            return null;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM invoice
                  WHERE username = :u AND id_user = :uid
               ORDER BY time_sell DESC LIMIT 1'
            );
            $stmt->execute([':u' => $username, ':uid' => (string) $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            error_log('[rx_invoice_for_user] ' . $e->getMessage());
            return null;
        }
    }
}

/**
 * The invoice for this service username **on this panel**.
 *
 * The panel-level twin of rx_invoice_for_user(). A panel function does not
 * know which customer is asking — the username is its only handle — but a
 * username is unique within one panel, because the panel enforces it. It is
 * not unique across a shop, and that is the whole difference.
 *
 * It matters here more than anywhere else: the row this returns supplies
 * `id_invoice`, which becomes the customer's /sub/<id> URL, and that id is the
 * only thing protecting their configs. Unscoped, one customer could be handed
 * another's subscription secret — full access to their configs, not merely the
 * wrong screen.
 *
 * @return array|false The row, or false, matching what the call sites test.
 */
if (!function_exists('rx_invoice_on_panel')) {
    function rx_invoice_on_panel(string $username, string $panelName)
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO) || trim($username) === '' || trim($panelName) === '') {
            return false;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM invoice
                  WHERE username = :u AND Service_location = :p
               ORDER BY time_sell DESC LIMIT 1'
            );
            $stmt->execute([':u' => $username, ':p' => $panelName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : false;
        } catch (Throwable $e) {
            error_log('[rx_invoice_on_panel] ' . $e->getMessage());
            return false;
        }
    }
}
