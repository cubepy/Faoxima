<?php


declare(strict_types=1);

require_once __DIR__ . '/BaseHandler.php';

final class PaymentReceiptHandler extends BaseHandler
{
    public function handle(): void
    {
        $this->requireMethod('POST');

        $orderId = FaoximaInput::string($_POST, 'order_id');
        if ($orderId === '') {
            FaoximaResponse::badRequest('order_id is required');
        }

        if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
            FaoximaResponse::badRequest('photo file is required');
        }
        $f = $_FILES['photo'];
        if ((int)($f['error'] ?? 99) !== UPLOAD_ERR_OK) {
            FaoximaResponse::badRequest('photo upload error: ' . ($f['error'] ?? 'unknown'));
        }
        if ((int)($f['size'] ?? 0) > 8 * 1024 * 1024) {
            FaoximaResponse::badRequest('photo too large (max 8 MB)');
        }
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) {
            FaoximaResponse::badRequest('photo not accessible on server');
        }

        // [FIX 9] فقط حجم فایل بررسی می‌شد، پس هر فایلی تا ۸ مگابایت به‌عنوان «رسید»
        // برای همه‌ی ادمین‌ها ارسال می‌شد. رسید یعنی تصویر.
        //
        // [FIX 9b — مهم] نسخه‌ی اولِ این گارد فقط JPG/PNG/WEBP را می‌پذیرفت و
        // رسیدِ کاربرانِ آیفون را رد می‌کرد: iOS عکس‌ها را با فرمت HEIC/HEIF ذخیره
        // می‌کند و همیشه هنگام آپلود به JPEG تبدیل نمی‌شود. ضمناً getimagesize()
        // اصلاً HEIC را نمی‌شناسد و برایش false برمی‌گرداند.
        // هدفِ این گارد جلوگیری از ارسالِ فایل‌های غیرتصویری (zip/pdf/exe) به کانال
        // ادمین است، نه محدودکردنِ فرمت‌های عکس. پس: هر چیزی که getimagesize
        // به‌عنوان تصویر بشناسد پذیرفته می‌شود، و HEIC/HEIF/AVIF هم از روی امضای
        // بایتیِ خودشان (باکسِ ftyp) شناسایی و پذیرفته می‌شوند.
        $receiptIsImage = (@getimagesize($tmp) !== false);
        if (!$receiptIsImage) {
            $head = (string) @file_get_contents($tmp, false, null, 0, 32);
            if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
                $brand = strtolower(substr($head, 8, 4));
                // heic/heix/hevc/mif1/msf1 = HEIF ، avif/avis = AVIF
                if (in_array($brand, ['heic','heix','hevc','hevx','mif1','msf1','avif','avis'], true)) {
                    $receiptIsImage = true;
                }
            }
        }
        if (!$receiptIsImage) {
            FaoximaResponse::badRequest('❌ فایل ارسالی تصویر نیست. لطفاً تصویر رسید را بفرستید.');
        }

        // [FIX 9] هر رسید یک عکس برای تک‌تک ادمین‌ها می‌فرستد و تنها فاکتورِ تأییدشده
        // رد می‌شد؛ یعنی یک نفر می‌توانست بی‌نهایت بار همان فاکتور را دوباره بفرستد و
        // کانال ادمین‌ها را پر کند. اصلاحِ واقعیِ رسید باید ممکن بماند، پس محدودیت
        // می‌گذاریم نه ممنوعیت: حداکثر ۳ رسید در هر ۵ دقیقه.
        try {
            $recentReceipts = (int) FaoximaDb::fetchScalar(
                "SELECT COUNT(*) FROM Payment_report
                  WHERE id_user = :u
                    AND payment_Status = 'waiting'
                    AND time >= :since",
                [':u' => (string)$this->user['id'], ':since' => date('Y/m/d H:i:s', time() - 300)]
            );
        } catch (Throwable $e) {
            $recentReceipts = 0;
        }
        // [FIX 9b] سقف از ۳ به ۱۵ افزایش یافت. این کوئری «رسیدهای فرستاده‌شده» را
        // نمی‌شمارد، بلکه فاکتورهای در انتظارِ تاییدِ کاربر را می‌شمارد (ارسالِ دوباره‌ی
        // رسید برای همان سفارش یک UPDATE است و ستون time عوض نمی‌شود). با سقفِ ۳،
        // مشتری‌ای که چند سفارشِ در انتظارِ تایید داشت از فرستادنِ رسیدِ سفارشِ بعدی
        // قفل می‌شد — یعنی گارد به‌جای مهاجم، مشتریِ واقعی را می‌گرفت.
        // سقفِ بالاتر همچنان جلوی سیلِ واقعی را می‌گیرد ولی مشتری عادی به آن نمی‌خورد.
        if ($recentReceipts >= 15) {
            FaoximaResponse::fail(429, '⏳ به‌تازگی چند رسید فرستاده‌اید. چند دقیقه صبر کنید و اگر مشکلی هست با پشتیبانی در ارتباط باشید.');
        }


        // [FIX] قید source = 'miniapp' برداشته شد. صاحبِ فاکتور همچنان با id_user
        // بررسی می‌شود، پس محدودیتی از دست نمی‌رود؛ ولی این قید باعث می‌شد رسیدِ
        // فاکتورهایی که از خودِ ربات ساخته شده‌اند از داخل مینی‌اپ قابل ارسال نباشد.
        $payment = FaoximaDb::fetchOne(
            'SELECT * FROM Payment_report
              WHERE id_order = :o AND id_user = :u
              LIMIT 1',
            [':o' => $orderId, ':u' => $this->user['id']]
        );
        if ($payment === null) {
            // پیام فارسیِ روشن به‌جای متن فنی: مینی‌اپ همین متن را به کاربر نشان
            // می‌دهد، و «Payment record not found» برای مشتری هیچ معنایی ندارد.
            FaoximaResponse::fail(404, '❌ این فاکتور دیگر در سیستم موجود نیست (احتمالاً منقضی شده). لطفاً یک فاکتور جدید بسازید و رسید را روی آن ارسال کنید. اگر مبلغ را واریز کرده‌اید، رسید را برای پشتیبانی بفرستید.');
        }
        $currentStatus = strtolower((string)($payment['payment_Status'] ?? ''));
        if ($currentStatus === 'paid') {
            FaoximaResponse::fail(409, '✅ این پرداخت قبلاً تأیید شده است.');
        }


        $admins = [];
        try {
            $admins = FaoximaDb::fetchAll(
                "SELECT id_admin FROM admin
                  WHERE rule = 'administrator'
                     OR rule = 'Seller'"
            );
        } catch (Throwable $e) {
            FaoximaLogger::userFacing('admin table fetch failed', ['err' => $e->getMessage()]);
        }
        $adminIds = [];
        foreach ($admins as $row) {
            $id = trim((string)($row['id_admin'] ?? ''));
            if ($id !== '' && ctype_digit($id)) {
                $adminIds[] = $id;
            }
        }
        if (empty($adminIds)) {
            FaoximaResponse::fail(503, '❌ هیچ ادمینی روی سرور تنظیم نشده است.');
        }


        global $APIKEY;
        $apiKey = is_string($APIKEY ?? null) ? $APIKEY : '';
        // [FIX 11] فالبکِ خواندن token_bot از جدول setting حذف شد: چنین ستونی در این
        // اسکیما وجود ندارد، پس select خطا پرتاب می‌کرد و به‌جای پیام تمیزِ ۵۰۳ که
        // سه خط پایین‌تر آماده است، کاربر با خطای ۵۰۰ روبه‌رو می‌شد.
        if ($apiKey === '') {
            FaoximaResponse::fail(503, '❌ توکن ربات روی سرور تنظیم نشده است.');
        }

        $userId   = (string)$this->user['id'];
        $userName = (string)($this->user['username'] ?? '');
        $name     = trim((string)($this->user['first_name'] ?? '') . ' ' . (string)($this->user['last_name'] ?? ''));
        $balance  = (int)($this->user['Balance'] ?? 0);
        $amount   = (int)($payment['price'] ?? 0);
        $method   = (string)($payment['Payment_Method'] ?? 'cart to cart');


        $caption =
            "💳 رسید پرداخت کارت‌به‌کارت (از مینی‌اپ)\n\n" .
            "🆔 کد پیگیری: <code>" . htmlspecialchars($orderId, ENT_QUOTES) . "</code>\n" .
            "💰 مبلغ: " . number_format($amount) . " تومان\n" .
            "👤 کاربر: <a href=\"tg://user?id={$userId}\">" .
                htmlspecialchars($name !== '' ? $name : $userId, ENT_QUOTES) .
                "</a>" . ($userName !== '' ? ' (@' . htmlspecialchars($userName, ENT_QUOTES) . ')' : '') . "\n" .
            "🪪 شناسه عددی: <code>{$userId}</code>\n" .
            "💎 موجودی فعلی: " . number_format($balance) . " تومان\n" .
            "📌 روش: " . htmlspecialchars($method, ENT_QUOTES);


        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '✅ تأیید', 'callback_data' => 'Confirm_pay_' . $orderId],
                    ['text' => '❌ رد',    'callback_data' => 'reject_pay_'  . $orderId],
                ],
                [
                    ['text' => '➕ افزایش موجودی',     'callback_data' => 'addbalamceuser_' . $orderId],
                    ['text' => '🚫 مسدود (رسید جعلی)', 'callback_data' => 'blockuserfake_' . $userId],
                ],
                [
                    ['text' => '👁 مشاهده کاربر', 'url' => 'tg://user?id=' . $userId],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);


        // [FIX] این حلقه قبلاً هیچ سقف زمانی کلی نداشت: برای هر ادمین تا 30 ثانیه (sendPhoto)
        // یا 15 ثانیه (متن/فوروارد) صبر می‌کرد، به‌صورت پشت‌سرهم. اگه اتصال سرور به
        // api.telegram.org کند/ناپایدار بود (رایج روی هاست‌های ایرانی) یا چند ادمین تنظیم شده
        // بود، مجموع این تاخیرها می‌تونست از max_execution_time پی‌اچ‌پی هاست رد بشه — یعنی
        // اسکریپت وسط کار متوقف می‌شد بدون اینکه هیچ پاسخی به مینی‌اپ برگرده، و کاربر همون
        // «در حال ارسال...» رو تا ابد می‌دید. الان یه سقف زمانی کلی (۲۰ ثانیه) روی کل تلاش
        // برای اطلاع‌رسانی به ادمین‌ها گذاشته شده تا این متد در هر حالتی به‌سرعت جواب بده.
        $notifyDeadline = microtime(true) + 20.0;

        $fileId = null;
        $docDelivered = false;
        $failedAdmins = [];
        $remainingAdmins = $adminIds;
        while (!empty($remainingAdmins) && microtime(true) < $notifyDeadline) {
            $candidate = array_shift($remainingAdmins);
            $maybeFileId = $this->sendReceiptPhoto($apiKey, $candidate, $tmp, (string)($f['type'] ?? 'image/jpeg'), $caption, $keyboard);
            if (is_string($maybeFileId) && $maybeFileId !== '') {
                $fileId = $maybeFileId;
                break;
            }
            // [FIX 9b] عکس‌های آیفون با فرمت HEIC را تلگرام به‌عنوان «photo» قبول
            // نمی‌کند، ولی به‌عنوان «document» می‌پذیرد. قبلاً در این حالت مستقیم به
            // پیامِ متنی برمی‌گشتیم و ادمین اصلاً تصویرِ رسید را نمی‌دید — یعنی باید
            // بدونِ دیدنِ رسید تصمیم می‌گرفت. حالا اول فایل را به شکل سند می‌فرستیم.
            if ($this->sendReceiptDocument($apiKey, $candidate, $tmp, (string)($f['type'] ?? ''), (string)($f['name'] ?? ''), $caption, $keyboard)) {
                $docDelivered = true;
                continue;
            }
            $failedAdmins[] = $candidate;
        }

        if ($fileId === null) {
            $textOk = false;
            foreach ($failedAdmins as $adminId) {
                if (microtime(true) >= $notifyDeadline) break;
                if ($this->sendReceiptText($apiKey, $adminId, $caption, $keyboard)) {
                    $textOk = true;
                }
            }
            if (!$textOk && !$docDelivered) {
                FaoximaLogger::warn('Receipt: all admins unreachable', ['admins' => $failedAdmins]);
                FaoximaResponse::fail(502, '❌ ارسال رسید به ادمین ناموفق بود. لطفاً دوباره تلاش کنید.');
            }
        } else {
            foreach ($failedAdmins as $adminId) {
                if (microtime(true) >= $notifyDeadline) break;
                if (!$this->forwardReceiptByFileId($apiKey, $adminId, $fileId, $caption, $keyboard)) {
                    $this->sendReceiptText($apiKey, $adminId, $caption, $keyboard);
                }
            }
            foreach ($remainingAdmins as $adminId) {
                if (microtime(true) >= $notifyDeadline) break;
                if (!$this->forwardReceiptByFileId($apiKey, $adminId, $fileId, $caption, $keyboard)) {
                    $this->sendReceiptText($apiKey, $adminId, $caption, $keyboard);
                }
            }
        }


        try {
            $pdo = FaoximaDb::pdo();
            $stmt = $pdo->prepare(
                'UPDATE Payment_report
                    SET payment_Status = :s
                  WHERE id_order = :o AND id_user = :u'
            );
            $stmt->execute([
                ':s' => 'waiting',
                ':o' => $orderId,
                ':u' => $this->user['id'],
            ]);
        } catch (Throwable $e) {
            FaoximaLogger::warn('Payment_report status update failed', ['err' => $e->getMessage()]);
        }

        // [FIX] این لاگ قبلاً با سطح debug ثبت می‌شد که به‌طور پیش‌فرض فیلتر می‌شه و اصلاً
        // نوشته نمی‌شه (چون حداقل سطح لاگ روی 'info' تنظیمه). یعنی حتی وقتی همه‌چیز درست کار
        // می‌کرد، هیچ ردی از "رسید فرستاده شد" توی لاگ نمی‌موند و امکان نداشت بشه فهمید یک
        // سفارش خاص واقعاً به ادمین رسیده یا نه. الان با warn ثبت می‌شه تا همیشه (چه موفق چه
        // ناموفق) قابل جستجو با کد پیگیری سفارش باشه.
        FaoximaLogger::warn('Receipt uploaded', [
            'order'         => $orderId,
            'user_id'       => $this->user['id'],
            'amount'        => $amount,
            'admins'        => count($adminIds),
            'failed_first'  => $failedAdmins,
            'photo_ok'      => $fileId !== null,
        ]);

        FaoximaResponse::ok([
            'order_id' => $orderId,
            'message'  => '✅ رسید شما برای ادمین ارسال شد. پس از تأیید، حساب شما شارژ می‌شود.',
        ]);
    }


    /**
     * [FIX 9b] ارسالِ رسید به‌شکلِ «سند».
     * تلگرام برای sendPhoto فقط چند فرمتِ محدود را می‌پذیرد و عکس‌های HEIC/HEIF
     * آیفون را رد می‌کند؛ ولی همان فایل را به‌عنوان document قبول می‌کند. بدون این
     * مسیر، ادمین فقط یک پیامِ متنی می‌گرفت و باید بدونِ دیدنِ رسید تصمیم می‌گرفت.
     */
    private function sendReceiptDocument(string $apiKey, string $chatId, string $localPath, string $mime, string $originalName, string $caption, string $keyboardJson): bool
    {
        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || strlen($ext) > 5 || !preg_match('/^[a-z0-9]+$/', $ext)) {
            $ext = 'jpg';
        }
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }
        $ch = curl_init('https://api.telegram.org/bot' . $apiKey . '/sendDocument');
        if (function_exists('faoxima_apply_curl_proxy')) faoxima_apply_curl_proxy($ch, 'telegram');
        $post = [
            'chat_id'      => $chatId,
            'caption'      => $caption,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboardJson,
            'document'     => new CURLFile($localPath, $mime, 'receipt.' . $ext),
        ];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            FaoximaLogger::warn('sendDocument HTTP failed', ['http' => $httpCode, 'curl_err' => $curlErr, 'admin' => $chatId]);
            return false;
        }
        $tg = json_decode((string) $response, true);
        if (!is_array($tg) || empty($tg['ok'])) {
            FaoximaLogger::warn('sendDocument rejected', ['desc' => is_array($tg) ? (string)($tg['description'] ?? '') : '', 'admin' => $chatId]);
            return false;
        }
        return true;
    }

    private function sendReceiptPhoto(string $apiKey, string $chatId, string $localPath, string $mime, string $caption, string $keyboardJson): ?string
    {
        $ch = curl_init('https://api.telegram.org/bot' . $apiKey . '/sendPhoto');
        if (function_exists('faoxima_apply_curl_proxy')) faoxima_apply_curl_proxy($ch, 'telegram');
        $post = [
            'chat_id'      => $chatId,
            'caption'      => $caption,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboardJson,
            'photo'        => new CURLFile($localPath, $mime, 'receipt.jpg'),
        ];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            FaoximaLogger::warn('sendPhoto HTTP failed', ['http' => $httpCode, 'curl_err' => $curlErr, 'admin' => $chatId]);
            return null;
        }
        $tg = json_decode((string)$response, true);
        if (!is_array($tg) || empty($tg['ok'])) {
            $desc = is_array($tg) ? (string)($tg['description'] ?? '') : '';
            FaoximaLogger::warn('sendPhoto rejected', ['desc' => $desc, 'admin' => $chatId]);
            return null;
        }


        $sizes = $tg['result']['photo'] ?? [];
        if (!is_array($sizes) || empty($sizes)) return null;
        $best = end($sizes);
        return is_array($best) ? (string)($best['file_id'] ?? '') : null;
    }


    private function forwardReceiptByFileId(string $apiKey, string $chatId, string $fileId, string $caption, string $keyboardJson): bool
    {
        if ($fileId === '') return false;
        $url = 'https://api.telegram.org/bot' . $apiKey . '/sendPhoto';
        $payload = [
            'chat_id'      => $chatId,
            'photo'        => $fileId,
            'caption'      => $caption,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboardJson,
        ];
        $ch = curl_init($url);
        if (function_exists('faoxima_apply_curl_proxy')) faoxima_apply_curl_proxy($ch, 'telegram');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || !$response) return false;
        $tg = json_decode((string)$response, true);
        return is_array($tg) && !empty($tg['ok']);
    }


    private function sendReceiptText(string $apiKey, string $chatId, string $caption, string $keyboardJson): bool
    {
        $url = 'https://api.telegram.org/bot' . $apiKey . '/sendMessage';
        $payload = [
            'chat_id'      => $chatId,
            'text'         => $caption,
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboardJson,
        ];
        $ch = curl_init($url);
        if (function_exists('faoxima_apply_curl_proxy')) faoxima_apply_curl_proxy($ch, 'telegram');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = @curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || !$response) return false;
        $tg = json_decode((string)$response, true);
        return is_array($tg) && !empty($tg['ok']);
    }
}

