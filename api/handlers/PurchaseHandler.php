<?php


declare(strict_types=1);

require_once __DIR__ . '/BaseHandler.php';
require_once __DIR__ . '/DiscountSupport.php';

final class PurchaseHandler extends BaseHandler
{
    public function handle(): void
    {
        $this->requireMethod('POST');

        global $textbotlang, $connect;

        $managePanel = new ManagePanel();
        $errorReport   = (string)(select('topicid', 'idreport', 'report', 'errorreport',  'select')['idreport'] ?? '');
        $porsantReport = (string)(select('topicid', 'idreport', 'report', 'porsantreport', 'select')['idreport'] ?? '');
        $buyReport     = (string)(select('topicid', 'idreport', 'report', 'buyreport',     'select')['idreport'] ?? '');


        $codePanel = $this->resolveCountryId();
        if ($codePanel === '') {
            FaoximaResponse::badRequest('country_id is required');
        }
        $panel = select('marzban_panel', '*', 'code_panel', $codePanel, 'select');
        if (empty($panel)) {
            FaoximaResponse::fail(404, 'پنل انتخابی موجود نیست.');
        }
        if (($panel['status'] ?? '') === 'disable') {
            FaoximaResponse::fail(409, 'پنل انتخابی درحال حاضر فعال نیست');
        }


        $customService = FaoximaInput::array($this->data, 'custom_service');
        if (empty($customService)) {
            $serviceId = FaoximaInput::string($this->data, 'service_id');
            if ($serviceId === '') {
                FaoximaResponse::badRequest('service_id is required');
            }
            // [FIX 4] محصول فقط با code_product خوانده می‌شد و هیچ‌وقت با پنل انتخابی
            // تطبیق داده نمی‌شد؛ یعنی مشتری می‌توانست کدِ ارزان‌ترین پلنِ فروشگاه را
            // روی گران‌ترین سرور بخرد. حالا محصول باید به همان پنل (یا /all) تعلق داشته باشد.
            $product = FaoximaDb::fetchOne(
                "SELECT * FROM product
                  WHERE code_product = :code
                    AND (Location = :loc OR Location = '/all')
                  LIMIT 1",
                [':code' => $serviceId, ':loc' => $panel['name_panel']]
            );
        } else {
            $product = $this->buildCustomProduct($panel, $customService);
        }

        if (empty($product)) {
            FaoximaResponse::fail(404, 'محصول انتخابی پیدا نشد');
        }

        if (!$this->productIsAllowedForAgent((array)$product, $this->user['agent'])) {
            FaoximaResponse::fail(403, 'این محصول برای نوع کاربری شما فعال نیست');
        }


        $discount = (int)($this->user['pricediscount'] ?? 0);
        if ($discount !== 0) {
            $delta = ($product['price_product'] * $discount) / 100;
            $product['price_product'] = $product['price_product'] - $delta;
        }

        $discountCode = FaoximaInput::string($this->data, 'discount_code');
        if ($discountCode !== '') {
            $dv = MiniDiscount::validateSell(
                $discountCode,
                'buy',
                (string)($product['code_product'] ?? ''),
                (string)($panel['code_panel'] ?? ''),
                $this->user
            );
            if (empty($dv['ok'])) {
                FaoximaResponse::fail(422, (string)($dv['reason'] ?? '❌ کد تخفیف نامعتبر است.'));
            }
            $product['price_product'] = MiniDiscount::applyToPrice($dv['row'], (float)$product['price_product']);
            // [FIX] علامت‌زدنِ کد به‌عنوان «مصرف‌شده» تا بعد از ساختِ موفقِ سرویس عقب
            // انداخته شد. قبلاً همین‌جا انجام می‌شد، در حالی که بعد از این نقطه شش مسیرِ
            // خطا وجود دارد (نام کاربری تکراری، موجودی ناکافی، خطای درج فاکتور، خطای
            // کسر موجودی، شکستِ createUser و…) و هیچ‌کدام کد را آزاد نمی‌کردند — یعنی
            // کاربر کدِ یک‌بارمصرفش را بی‌آنکه سرویسی بگیرد از دست می‌داد و ظرفیت
            // کمپین هم بی‌دلیل می‌سوخت.
            $rxPendingDiscountCode = $discountCode;
        }


        $rxPendingDiscountCode = $rxPendingDiscountCode ?? null;

        $orderId = bin2hex(random_bytes(4));
        $customUsername = FaoximaInput::nullableString($this->data, 'custom_username');
        $methodUsername = (string)($panel['MethodUsername'] ?? '');
        if ($methodUsername === 'نام کاربری دلخواه') {
            $trimmedCustom = trim((string)$customUsername);
            if ($trimmedCustom === '') {
                FaoximaResponse::fail(422, 'برای این پنل، انتخاب نام کاربری دلخواه ضروری است.');
            }
            if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $trimmedCustom)) {
                FaoximaResponse::fail(422, 'نام کاربری معتبر نیست. فقط حروف انگلیسی، عدد، _ . - (۳ تا ۴۰ کاراکتر).');
            }
            $customUsername = $trimmedCustom;
        }
        $usernameAc = generateUsername(
            $this->user['id'],
            $methodUsername,
            $this->user['username'] ?? '',
            $orderId,
            $customUsername,
            $panel['namecustom'] ?? '',
            $this->user['namecustom'] ?? ''
        );
        $usernameAc = strtolower((string)$usernameAc);


        // [FIX خرید قفل‌شده با «نام کاربری وجود دارد»]
        // روش‌های «عدد ترتیبی» نام را از یک شمارنده می‌سازند و آن شمارنده فقط پس از
        // یک خریدِ کاملاً موفق جلو می‌رود. پس اگر یک خرید نیمه‌کاره بماند (خطای پنل،
        // تایم‌اوت، یا همان «Panel Not Found» که روی پنل پاسارگاد داشتیم) و ردیفِ
        // فاکتور باقی بماند، شمارنده سرِ جای قبلی می‌ماند و هر خریدِ بعدی دقیقاً همان
        // نام را می‌سازد → «نام کاربری وجود دارد مراحل را از اول طی کنید».
        // «از اول طی کردن» هم چاره نبود، چون همان نام دوباره ساخته می‌شد؛ یعنی
        // فروشگاه برای همیشه روی آن پنل قفل می‌شد.
        // ربات از قبل rxResolveUsernameCollision را داشت و به‌جای خطا، شماره‌ی آزاد
        // بعدی را پیدا و شمارنده را جلو می‌برد. مینی‌اپ این کار را نمی‌کرد.
        $rxTakenOnPanel = function ($cand) use ($managePanel, $panel) {
            try {
                $d = $managePanel->DataUser($panel['name_panel'], $cand);
            } catch (Throwable $e) {
                return true;   // مطمئن نیستیم آزاد است — روی نام دیگری می‌رویم
            }
            return is_array($d) && isset($d['username']);
        };
        $rxTakenLocally = function ($cand) {
            return (bool) FaoximaDb::fetchScalar(
                'SELECT 1 FROM invoice WHERE username = :u LIMIT 1',
                [':u' => $cand]
            );
        };

        if ($rxTakenLocally($usernameAc) || $rxTakenOnPanel($usernameAc)) {
            $rxResolved  = null;
            $rxGlobalSeq = in_array($methodUsername, ['متن دلخواه + عدد ترتیبی', 'متن دلخواه نماینده + عدد ترتیبی'], true);
            $rxUserSeq   = in_array($methodUsername, ['نام کاربری + عدد به ترتیب', 'آیدی عددی+عدد ترتیبی'], true);

            if (($rxGlobalSeq || $rxUserSeq) && preg_match('/^(.*)_(\d+)$/', $usernameAc, $rxParts)) {
                $rxBase = $rxParts[1];
                $rxNum  = (int) $rxParts[2];

                // نام‌های گرفته‌شده‌ی این پایه را یکجا می‌خوانیم تا به‌جای ۳۰ تماس
                // با پنل، فقط یک کوئری بزنیم و بعد تنها کاندیدای نهایی را از پنل بپرسیم.
                $rxTakenSet = [];
                try {
                    $rows = FaoximaDb::fetchAll(
                        "SELECT username FROM invoice WHERE username LIKE :like",
                        [':like' => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $rxBase) . '\_%']
                    );
                    foreach ($rows as $r) {
                        $rxTakenSet[strtolower((string) $r['username'])] = true;
                    }
                } catch (Throwable $e) {
                    $rxTakenSet = [];
                }

                for ($i = 1; $i <= 200; $i++) {
                    $cand = strtolower($rxBase . '_' . ($rxNum + $i));
                    if (isset($rxTakenSet[$cand])) continue;
                    if ($rxTakenOnPanel($cand)) continue;
                    if ($rxGlobalSeq) {
                        update('setting', 'numbercount', $rxNum + $i);
                        // شمارنده‌ی درون‌حافظه را هم جلو می‌بریم، وگرنه افزایشِ پایین‌ترِ
                        // همین فایل که از مقدارِ کهنه حساب می‌کند، این اصلاح را خنثی می‌کرد.
                        $this->setting['numbercount'] = $rxNum + $i;
                    } else {
                        update('user', 'number_username', $rxNum + $i, 'id', $this->user['id']);
                        $this->user['number_username'] = $rxNum + $i;
                    }
                    $rxResolved = $cand;
                    break;
                }
            }

            // روش‌های غیرترتیبی (یا وقتی ۲۰۰ شماره‌ی بعدی هم پر بود): پسوند تصادفی
            if ($rxResolved === null) {
                for ($i = 0; $i < 12; $i++) {
                    $cand = strtolower($usernameAc . '_' . random_int(100, 999));
                    if ($rxTakenLocally($cand) || $rxTakenOnPanel($cand)) continue;
                    $rxResolved = $cand;
                    break;
                }
            }

            if ($rxResolved === null) {
                FaoximaResponse::fail(409, 'ساخت نام کاربری برای این سرور ممکن نشد. لطفاً با پشتیبانی در ارتباط باشید.');
            }
            $usernameAc = $rxResolved;
        }


        $customNote = FaoximaInput::nullableString($this->data, 'custom_note');
        if ($customNote !== null && strlen($customNote) <= 1) {
            $customNote = null;
        }

        $notifications = json_encode(['volume' => false, 'time' => false]);
        $serviceTime = (int)$product['Service_time'];


        $shortfall = (float)$product['price_product'] - (float)$this->user['Balance'];
        if ($shortfall > 0.0) {
            $directBuyRow = select('shopSetting', '*', 'Namevalue', 'statusdirectpabuy', 'select');
            $directBuy = is_array($directBuyRow) ? (string)($directBuyRow['value'] ?? '') : '';
            $directBuyEnabled = ($directBuy === 'ondirectbuy');

            if (!$directBuyEnabled) {
                FaoximaResponse::fail(402, 'موجودی کمتر از قیمت محصول است');
            }


            try {
                FaoximaDb::execute(
                    "INSERT INTO invoice
                        (id_user, id_invoice, username, time_sell, Service_location, name_product,
                         price_product, Volume, Service_time, Status, note, refral, notifctions)
                     VALUES (:id_user, :id_invoice, :username, :time_sell, :location, :name_product,
                             :price, :volume, :service_time, :status, :note, :refral, :notifs)",
                    [
                        ':id_user'      => $this->user['id'],
                        ':id_invoice'   => $orderId,
                        ':username'     => $usernameAc,
                        ':time_sell'    => time(),
                        ':location'     => $panel['name_panel'],
                        ':name_product' => $product['name_product'],
                        ':price'        => $product['price_product'],
                        ':volume'       => $product['Volume_constraint'],
                        ':service_time' => $serviceTime,
                        ':status'       => 'unpaid',
                        ':note'         => $customNote,
                        ':refral'       => $this->user['affiliates'],
                        ':notifs'       => $notifications,
                    ]
                );
            } catch (Throwable $e) {
                FaoximaLogger::exception($e, 'Unpaid invoice insert failed', ['user_id' => $this->user['id']]);
                FaoximaResponse::serverError('خطا در ذخیره فاکتور');
            }

            $amountDue = (int) ceil($shortfall);


            if ($amountDue <= 1) $amountDue = 0;

            FaoximaLogger::debug('Direct-buy initiated', [
                'user_id'    => $this->user['id'],
                'order_id'   => $orderId,
                'username'   => $usernameAc,
                'amount_due' => $amountDue,
                'price'      => $product['price_product'],
                'balance'    => $this->user['Balance'],
            ]);

            $payload = [
                'success'          => true,
                'status'           => true,
                'message'          => 'موجودی کافی نیست — لطفاً مبلغ کسری را پرداخت کنید',
                'requires_payment' => true,
                'amount_due'       => $amountDue,
                'balance'          => (float)$this->user['Balance'],
                'price'            => (float)$product['price_product'],
                'username'         => $usernameAc,
                'order_id'         => $orderId,
                'product'          => [
                    'name'       => (string)$product['name_product'],
                    'traffic_gb' => (int)$product['Volume_constraint'],
                    'time_days'  => $serviceTime,
                ],
                'panel'            => [
                    'id'   => (string)$panel['code_panel'],
                    'name' => (string)$panel['name_panel'],
                ],
            ];
            if (function_exists('__miniapp_emit')) {
                __miniapp_emit(200, $payload);
            } else {
                while (ob_get_level() > 0) { @ob_end_clean(); }
                if (!headers_sent()) {
                    http_response_code(200);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode($payload, JSON_UNESCAPED_UNICODE);
                $GLOBALS['__miniapp_response_sent'] = true;
            }
            exit;
        }


        try {
            FaoximaDb::execute(
                "INSERT INTO invoice
                    (id_user, id_invoice, username, time_sell, Service_location, name_product,
                     price_product, Volume, Service_time, Status, note, refral, notifctions)
                 VALUES (:id_user, :id_invoice, :username, :time_sell, :location, :name_product,
                         :price, :volume, :service_time, :status, :note, :refral, :notifs)",
                [
                    ':id_user'      => $this->user['id'],
                    ':id_invoice'   => $orderId,
                    ':username'     => $usernameAc,
                    ':time_sell'    => time(),
                    ':location'     => $panel['name_panel'],
                    ':name_product' => $product['name_product'],
                    ':price'        => $product['price_product'],
                    ':volume'       => $product['Volume_constraint'],
                    ':service_time' => $serviceTime,
                    ':status'       => 'active',
                    ':note'         => $customNote,
                    ':refral'       => $this->user['affiliates'],
                    ':notifs'       => $notifications,
                ]
            );
        } catch (Throwable $e) {
            FaoximaLogger::exception($e, 'Invoice insert failed', ['user_id' => $this->user['id']]);
            FaoximaResponse::serverError('خطا در ذخیره فاکتور');
        }


        $expireTs = $serviceTime > 0 ? strtotime('+' . $serviceTime . ' days') : 0;


        $priceToCharge = (float) $product['price_product'];
        $balanceChargedAtomically = false;
        if ($priceToCharge > 0.0) {
            $charge = balance_atomic_charge($this->user['id'], $priceToCharge, 0);
            if (empty($charge['ok'])) {
                FaoximaLogger::warn('Atomic balance charge failed at purchase', [
                    'user_id'  => $this->user['id'],
                    'order_id' => $orderId,
                    'price'    => $priceToCharge,
                    'reason'   => $charge['reason'] ?? 'unknown',
                ]);
                try { FaoximaDb::execute('DELETE FROM invoice WHERE id_invoice = :o AND id_user = :u', [':o' => $orderId, ':u' => $this->user['id']]); } catch (Throwable $_) {}
                FaoximaResponse::fail(402, 'موجودی کافی نیست (تلاش هم‌زمان شناسایی شد). یک بار دیگر تلاش کنید.');
            }
            $balanceChargedAtomically = true;

            $this->user['Balance'] = $charge['new_balance'];
        }


        $createPayload = [
            'expire'     => $expireTs,
            'data_limit' => (int)$product['Volume_constraint'] * pow(1024, 3),
            'from_id'    => $this->user['id'],
            'username'   => $this->user['username'] ?? '',
            'type'       => 'buy',
        ];

        // [FIX 6] در این نقطه موجودی کاربر کسر شده و ردیف فاکتور هم ساخته شده است.
        // createUser یک درخواست HTTP به پنلی است که در اختیار ما نیست و اگر جایی
        // داخلش Exception پرتاب شود، اجرا تا catch سراسری بالا می‌رود و ۵۰۰ برمی‌گردد:
        // پول کسر شده، فاکتور نیمه‌کاره باقی مانده و هیچ اکانتی هم ساخته نشده.
        // شاخه‌ی پایین همین سه‌تا را برای پنلی که «نه» می‌گوید برمی‌گرداند؛ پنلی که
        // کرش می‌کند دقیقاً همان‌قدر برای مشتری هزینه دارد.
        try {
            $remote = $managePanel->createUser(
                $panel['name_panel'],
                $product['code_product'],
                $usernameAc,
                $createPayload
            );
        } catch (Throwable $e) {
            FaoximaLogger::exception($e, 'createUser threw', [
                'user_id' => $this->user['id'],
                'panel'   => $panel['name_panel'] ?? null,
                'product' => $product['code_product'] ?? null,
            ]);

            if ($balanceChargedAtomically) {
                balance_atomic_credit($this->user['id'], $priceToCharge);
            }
            try { FaoximaDb::execute('DELETE FROM invoice WHERE id_invoice = :o AND id_user = :u', [':o' => $orderId, ':u' => $this->user['id']]); } catch (Throwable $_) {}

            $errorText = "⭕️ خطای ساخت اشتراک (Exception) \n✍️ دلیل خطا : \n{$e->getMessage()}\nآیدی کابر : {$this->user['id']}\nنام کاربری کاربر : @{$this->user['username']}\nنام پنل : {$panel['name_panel']}";
            $this->reportToChannel($errorText, $errorReport);

            FaoximaResponse::serverError('خطایی در ساخت اشتراک رخ داده است با پشتیبانی در ارتباط باشید');
        }

        if (empty($remote['username'])) {
            $reason = is_array($remote) ? json_encode($remote['msg'] ?? $remote) : (string)$remote;
            FaoximaLogger::error('createUser failed', [
                'user_id' => $this->user['id'],
                'panel' => $panel['name_panel'],
                'product' => $product['code_product'] ?? null,
                'reason' => $reason,
            ]);


            if ($balanceChargedAtomically) {
                balance_atomic_credit($this->user['id'], $priceToCharge);
            }
            try { FaoximaDb::execute('DELETE FROM invoice WHERE id_invoice = :o AND id_user = :u', [':o' => $orderId, ':u' => $this->user['id']]); } catch (Throwable $_) {}

            $errorText = "⭕️ خطای ساخت اشتراک \n✍️ دلیل خطا : \n{$reason}\nآیدی کابر : {$this->user['id']}\nنام کاربری کاربر : @{$this->user['username']}\nنام پنل : {$panel['name_panel']}";
            $this->reportToChannel($errorText, $errorReport);

            FaoximaResponse::serverError('خطایی در ساخت اشتراک رخ داده است با پشتیبانی در ارتباط باشید');
        }


        // سرویس واقعاً ساخته شد — حالا کد تخفیف مصرف‌شده حساب می‌شود.
        // [FIX 5] خروجیِ markSellUsed حالا بولین است و ظرفیت کد را به‌صورت اتمیک
        // برمی‌دارد. در این نقطه سرویس ساخته و مبلغ کسر شده است، پس اگر ظرفیت همین
        // لحظه توسط کاربر دیگری پر شده باشد نمی‌توان خرید را رد کرد (کاربر سرویسِ
        // تحویل‌شده‌اش را از دست می‌داد)؛ فقط ثبت می‌شود تا فروشنده متوجه شود.
        if (!empty($rxPendingDiscountCode)) {
            if (!MiniDiscount::markSellUsed($rxPendingDiscountCode, $this->user)) {
                FaoximaLogger::warn('Discount capacity ran out after service was delivered', [
                    'user_id'  => $this->user['id'] ?? null,
                    'order_id' => $orderId,
                    'code'     => $rxPendingDiscountCode,
                ]);
            }
            $rxPendingDiscountCode = null;
        }

        $configList = is_array($remote['configs'] ?? null) ? $remote['configs'] : [];
        $configsText = '';
        if (($panel['config'] ?? '') === 'onconfig') {
            foreach ($configList as $link) {
                $configsText .= "\n" . $link;
            }
        }
        $subLink = ($panel['sublink'] ?? '') === 'onsublink' ? ($remote['subscription_url'] ?? '') : '';

        $template = $this->resolveTemplate($panel['type'] ?? '');
        $template = str_replace('{username}', "<code>{$remote['username']}</code>", $template);
        $template = str_replace('{name_service}', $product['name_product'] ?? '', $template);
        $template = str_replace('{location}', $panel['name_panel'] ?? '', $template);

        $displayDays   = $serviceTime === 0 ? ($textbotlang['users']['stateus']['Unlimited'] ?? '∞') : $serviceTime;
        $displayVolume = (int)$product['Volume_constraint'] === 0 ? ($textbotlang['users']['stateus']['Unlimited'] ?? '∞') : $product['Volume_constraint'];
        $template = str_replace('{day}', (string)$displayDays, $template);
        $template = str_replace('{volume}', (string)$displayVolume, $template);
        $template = applyConnectionPlaceholders($template, $subLink, $configsText);


        $rootDir = defined('REFACTORED_LEGACY_ROOT') ? REFACTORED_LEGACY_ROOT : dirname(__DIR__, 2);
        $backgroundCandidates = [
            $rootDir . DIRECTORY_SEPARATOR . 'images.jpeg',
            $rootDir . DIRECTORY_SEPARATOR . 'images.jpg',
        ];
        $backgroundImage = 'images.jpg';
        foreach ($backgroundCandidates as $candidate) {
            if (is_file($candidate)) { $backgroundImage = $candidate; break; }
        }


        $cardSent = $this->sendInfoCardNotification(
            $panel,
            $remote,
            $usernameAc,
            $orderId,
            $product,
            $serviceTime,
            $template
        );


        if (!$cardSent) {
            $serviceKeyboard = json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '📚 مشاهده آموزش استفاده ', 'callback_data' => 'helpbtn'],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE);

            sendMessageService(
                $panel,
                $configList,
                $subLink,
                $this->user['username'] ?? '',
                $serviceKeyboard,
                $template,
                $orderId,
                $this->user['id'],
                $backgroundImage
            );
        }




        $methodUsername = $panel['MethodUsername'] ?? '';
        $sequentialMethods = [
            'متن دلخواه + عدد ترتیبی',
            'نام کاربری + عدد به ترتیب',
            'آیدی عددی+عدد ترتیبی',
            'متن دلخواه نماینده + عدد ترتیبی',
        ];
        if (in_array($methodUsername, $sequentialMethods, true)) {
            update('user', 'number_username', (int)$this->user['number_username'] + 1, 'id', $this->user['id']);
            if ($methodUsername === 'متن دلخواه + عدد ترتیبی' || $methodUsername === 'متن دلخواه نماینده + عدد ترتیبی') {
                update('setting', 'numbercount', (int)$this->setting['numbercount'] + 1);
            }
        }


        // [FIX 12] فاکتورهای Unpaid (تلاش‌های ناتمامِ خرید) هم شمرده می‌شدند، پس یک
        // تلاشِ رهاشده باعث می‌شد خریدِ اولِ واقعی «خرید دوم» به‌نظر برسد و پورسانتِ
        // معرف هیچ‌وقت پرداخت نشود.
        $invoiceCount = (int) FaoximaDb::fetchScalar(
            "SELECT COUNT(*) FROM invoice
              WHERE name_product != 'سرویس تست'
                AND Status != 'Unpaid'
                AND id_user = :id",
            [':id' => $this->user['id']]
        );
        $this->payAffiliate($product, $invoiceCount, $porsantReport);


        if ((int)($this->setting['scorestatus'] ?? 0) === 1) {
            sendmessage($this->user['id'], '📌شما 1 امتیاز جدید کسب کردید.', null, 'html');
            update('user', 'score', (int)$this->user['score'] + 1, 'id', $this->user['id']);
        }


        $this->reportPurchase($buyReport, $product, $panel, $usernameAc, $orderId, $invoiceCount);

        FaoximaLogger::debug('Purchase completed', [
            'user_id'  => $this->user['id'],
            'order_id' => $orderId,
            'panel'    => $panel['name_panel'],
            'product'  => $product['code_product'] ?? null,
            'amount'   => $product['price_product'],
        ]);

        $totalBytes = (int)$product['Volume_constraint'] * (int) pow(1024, 3);
        $serviceTimeInt = (int)$serviceTime;
        $configsArr = array_values(array_filter(array_map(static function ($c) {
            return is_string($c) ? trim($c) : '';
        }, $configList), static function ($c) { return $c !== ''; }));

        $payload = [
            'success'  => true,
            'status'   => true,
            'message'  => 'ok',
            'order_id' => $orderId,
            'service'  => [
                'id'                => $orderId,
                'username'          => (string)($remote['username'] ?? $usernameAc),
                'status'            => 'active',
                'active'            => true,
                'expire'            => $expireTs,
                'product_name'      => (string)($product['name_product'] ?? ''),
                'panel_name'        => (string)($panel['name_panel'] ?? ''),
                'panel_type'        => (string)($panel['type'] ?? ''),
                'service_time_days' => $serviceTimeInt,
                'days_left'         => $serviceTimeInt,
                'volume_gb'         => (int)$product['Volume_constraint'],
                'used_bytes'        => 0,
                'total_bytes'       => $totalBytes,
                'unlimited_volume'  => $totalBytes === 0,
                'unlimited_time'    => $serviceTimeInt === 0,
                'subscription_url'  => (string)$subLink,
                'configs'           => $configsArr,
            ],
        ];
        if (function_exists('__miniapp_emit')) {
            __miniapp_emit(200, $payload);
        } else {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            if (!headers_sent()) {
                http_response_code(200);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            $GLOBALS['__miniapp_response_sent'] = true;
        }
        exit;
    }

    private function sendInfoCardNotification(
        array $panel,
        $remote,
        string $usernameAc,
        string $orderId,
        array $product,
        int $serviceTime,
        string $caption
    ): bool {

        $rootDir = defined('REFACTORED_LEGACY_ROOT') ? REFACTORED_LEGACY_ROOT : dirname(__DIR__, 2);
        $infocardPath = $rootDir . DIRECTORY_SEPARATOR . 'infocard.php';
        if (!is_file($infocardPath)) {
            return false;
        }
        require_once $infocardPath;
        if (!function_exists('createServiceInfoCard') || !function_exists('telegram')) {
            return false;
        }


        global $usernamebot;
        $botUsername = '';
        if (isset($usernamebot) && is_string($usernamebot)) {
            $botUsername = ltrim(trim($usernamebot), '@');
        }
        if ($botUsername === '' && function_exists('telegram')) {
            try {
                $me = @telegram('getMe', []);
                if (is_array($me) && isset($me['result']['username'])) {
                    $botUsername = (string)$me['result']['username'];
                }
            } catch (Throwable $_) {  }
        }


        $totalBytes = (int)$product['Volume_constraint'] * (int) pow(1024, 3);
        $params = [
            'config_name'    => $usernameAc,
            'bot_username'   => $botUsername,
            'user_id'        => (string)$this->user['id'],
            'active'         => true,
            'used_bytes'     => 0,
            'total_bytes'    => $totalBytes,
            'days_left'      => $serviceTime,
            'unlimited_time' => $serviceTime === 0,
        ];

        $color = function_exists('getInfoCardColor') ? getInfoCardColor() : 'yellow';
        $outPath = $rootDir . DIRECTORY_SEPARATOR . 'infocard_' . $this->user['id'] . '_' . bin2hex(random_bytes(3)) . '.png';

        try {
            $written = createServiceInfoCard($params, $color, $outPath);
        } catch (Throwable $e) {
            FaoximaLogger::warn('createServiceInfoCard threw', ['err' => $e->getMessage()]);
            return false;
        }
        if ($written === false || !is_file($outPath)) {
            return false;
        }


        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '📷 دریافت QR Code',         'callback_data' => 'infocard_qr_' . $orderId],
                ],
                [
                    ['text' => '📚 مشاهده آموزش استفاده ', 'callback_data' => 'helpbtn'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        try {
            telegram('sendphoto', [
                'chat_id'      => $this->user['id'],
                'photo'        => new CURLFile($outPath),
                'caption'      => $caption,
                'parse_mode'   => 'HTML',
                'reply_markup' => $keyboard,
            ]);
            @unlink($outPath);
            return true;
        } catch (Throwable $e) {
            @unlink($outPath);
            FaoximaLogger::warn('sendphoto (info card) failed', ['err' => $e->getMessage()]);
            return false;
        }
    }

    private function buildCustomProduct(array $panel, array $customService): array
    {
        global $textbotlang;
        $agent = (string) ($this->user['agent'] ?? 'f');

        // [FIX 2] روی پنلی که فروشنده حجم دلخواه را فعال نکرده، min و max هر دو صفر
        // می‌شوند و حجم ۰ / زمان ۰ از اعتبارسنجی رد می‌شود و قیمت هم صفر درمی‌آید؛
        // یعنی اکانت رایگانِ نامحدودِ بدون انقضا، آن هم به‌دفعات. پس اول فعال بودن
        // فروش سرویس دلخواه را چک می‌کنیم.
        if (!$this->customServiceIsOnSale($panel, $agent)) {
            FaoximaResponse::fail(403, '⛔️ سرویس دلخواه روی این سرور برای شما فعال نیست.');
        }

        $main  = $this->decodeJsonField($panel['mainvolume'] ?? null);
        $max   = $this->decodeJsonField($panel['maxvolume'] ?? null);
        $minT  = $this->decodeJsonField($panel['maintime'] ?? null);
        $maxT  = $this->decodeJsonField($panel['maxtime'] ?? null);
        $tp    = $this->decodeJsonField($panel['pricecustomvolume'] ?? null);
        $timeP = $this->decodeJsonField($panel['pricecustomtime'] ?? null);

        $minVolume = (int)($main[$agent] ?? 0);
        $maxVolume = (int)($max[$agent] ?? 0);
        $minTime   = (int)($minT[$agent] ?? 0);
        $maxTime   = (int)($maxT[$agent] ?? 0);

        $volume = (int)($customService['traffic_gb'] ?? 0);
        $time   = (int)($customService['time_days'] ?? 0);

        if ($volume > $maxVolume || $volume < $minVolume) {
            FaoximaResponse::badRequest('حجم نامعتبر است خرید را از اول انجام دهید');
        }
        if ($time > $maxTime || $time < $minTime) {
            FaoximaResponse::badRequest('زمان نامعتبر است خرید را از اول انجام دهید');
        }

        $price = ($volume * (float)($tp[$agent] ?? 0))
               + ($time   * (float)($timeP[$agent] ?? 0));

        // [FIX 2] سرویس با قیمت صفر یا حجم/زمان صفر نباید فروخته شود؛ حجم ۰ روی پنل
        // یعنی نامحدود و زمان ۰ یعنی بدون انقضا، و کیف‌پول هم اصلاً کسر نمی‌شود.
        $this->assertCustomServiceIsSellable($volume, $time, $price);

        return [
            'code_product'      => 'customvolume',
            'name_product'      => $textbotlang['users']['customsellvolume']['title'] ?? 'سرویس سفارشی',
            'Volume_constraint' => $volume,
            'Service_time'      => $time,
            'Location'          => $panel['name_panel'],
            'price_product'     => $price,
        ];
    }

    private function resolveTemplate(string $panelType): string
    {
        $rows = select('textbot', '*', null, null, 'fetchAll') ?: [];
        $bag = [
            'textafterpay' => '',
            'textmanual' => '',
            'text_wgdashboard' => '',
            'textafterpayibsng' => '',
        ];
        foreach ($rows as $row) {
            if (isset($bag[$row['id_text']])) {
                $bag[$row['id_text']] = $row['text'];
            }
        }

        if ($panelType === 'Manualsale')           return $bag['textmanual'] ?: $bag['textafterpay'];
        if ($panelType === 'WGDashboard')          return $bag['text_wgdashboard'] ?: $bag['textafterpay'];
        if ($panelType === 'ibsng' || $panelType === 'mikrotik') {
            return $bag['textafterpayibsng'] ?: $bag['textafterpay'];
        }
        return $bag['textafterpay'];
    }

    private function payAffiliate(array $product, int $invoiceCount, string $topicId): void
    {
        $affiliates = select('affiliates', '*', null, null, 'select');
        if (!is_array($affiliates)) return;

        $statusOk = ($affiliates['status_commission'] ?? '') === 'oncommission';
        $hasAffiliate = !empty($this->user['affiliates']) && (int)$this->user['affiliates'] !== 0;
        if (!$statusOk || !$hasAffiliate) return;


        // [FIX] این شرط وارونه بود و سقفِ «خرید اول» را هم اعمال نمی‌کرد.
        // معنای porsant_one_buy در بقیه‌ی ربات (re/rx/index/user_flow.php:1450)
        // این است: روشن = پورسانت فقط برای «خرید اولِ» هر زیرمجموعه، خاموش =
        // پورسانت روی همه‌ی خریدها. مینی‌اپ دقیقاً برعکس عمل می‌کرد: با روشن
        // بودن روی هر خرید پورسانت می‌داد (پرداختِ بی‌پایان به معرف) و با خاموش
        // بودن اصلاً پورسانت نمی‌داد. $invoiceCount هم گرفته می‌شد ولی خوانده نمی‌شد.
        $oneBuyOnly = ($affiliates['porsant_one_buy'] ?? '') === 'on_buy_porsant';
        if ($oneBuyOnly && $invoiceCount !== 1) return;

        $rate = (float)($this->setting['affiliatespercentage'] ?? 0);
        $commission = ((float)$product['price_product'] * $rate) / 100;

        $referrer = select('user', '*', 'id', $this->user['affiliates'], 'select');
        if (empty($referrer)) return;

        if ((int)($this->setting['scorestatus'] ?? 0) === 1) {
            sendmessage($this->user['affiliates'], '📌شما 2 امتیاز جدید کسب کردید.', null, 'html');
            update('user', 'score', (int)$referrer['score'] + 2, 'id', $this->user['affiliates']);
        }
        balance_atomic_credit($this->user['affiliates'], $commission);

        $formatted = number_format($commission);
        $when = date('Y/m/d H:i:s');

        $textUser = "🎁  پرداخت پورسانت\n\nمبلغ {$formatted} تومان به حساب شما از طرف زیر مجموعه تان به کیف پول شما واریز گردید";
        $textReport = "\nمبلغ {$formatted} به کاربر {$this->user['affiliates']} برای پورسانت از کاربر {$this->user['id']} واریز گردید\nتایم : {$when}";

        $this->reportToChannel($textReport, $topicId);
        sendmessage($this->user['affiliates'], $textUser, null, 'HTML');
    }

    private function reportPurchase(string $topicId, array $product, array $panel, string $usernameAc, string $orderId, int $invoiceCount): void
    {
        $balanceAfter = (float) FaoximaDb::fetchScalar(
            'SELECT Balance FROM user WHERE id = :id',
            [':id' => $this->user['id']]
        );
        $balanceBefore = number_format((float)$this->user['Balance']);
        $balanceAfter  = number_format($balanceAfter);

        $firstBuy = $invoiceCount === 1 ? '📌 خرید اول کاربر' : '';
        $when = jdate('Y/m/d H:i:s');

        // [FIX 10] نام پنل و نام محصول از دیتابیس می‌آیند و اگر کاراکتر < داشته باشند،
        // تلگرام کل پیام HTML را رد می‌کند و گزارش خرید اصلاً به کانال نمی‌رسد؛ پس
        // فقط همین مقادیرِ درج‌شده escape می‌شوند (نه تگ‌های <code> خودِ متن).
        $text = "📣 جزئیات ساخت اکانت در مینی اپ ثبت شد .

{$firstBuy}
▫️آیدی عددی کاربر : <code>{$this->user['id']}</code>
▫️نام کاربری کاربر :@{$this->user['username']}
▫️نام کاربری کانفیگ :{$usernameAc}
▫️موقعیت سرویس : " . htmlspecialchars((string) $panel['name_panel'], ENT_QUOTES, 'UTF-8') . "
▫️نام محصول :" . htmlspecialchars((string) $product['name_product'], ENT_QUOTES, 'UTF-8') . "
▫️زمان خریداری شده :{$product['Service_time']} روز
▫️حجم خریداری شده : {$product['Volume_constraint']} GB
▫️موجودی قبل خرید : {$balanceBefore} تومان
▫️موجودی بعد خرید : {$balanceAfter} تومان
▫️کد پیگیری: {$orderId}
▫️نوع کاربر : {$this->user['agent']}
▫️شماره تلفن کاربر : {$this->user['number']}
▫️قیمت محصول : {$product['price_product']} تومان
▫️زمان خرید : {$when}";

        global $textbotlang;
        $manageBtn = $textbotlang['Admin']['ManageUser']['mangebtnuser'] ?? '👤 مدیریت کاربر';

        $reply = json_encode([
            'inline_keyboard' => [[
                ['text' => $manageBtn, 'callback_data' => 'manageuser_' . $this->user['id']],
            ]],
        ]);

        $channel = $this->setting['Channel_Report'] ?? '';
        if ((string)$channel === '') return;

        telegram('sendmessage', [
            'chat_id'           => $channel,
            'message_thread_id' => $topicId,
            'text'              => $text,
            'parse_mode'        => 'HTML',
            'reply_markup'      => $reply,
        ]);
    }

    private function reportToChannel(string $text, string $topicId): void
    {
        // [FIX 10] متن خطای پنل ممکن است شامل < باشد (مثلاً وقتی پنل یک صفحه‌ی HTML
        // خطا برمی‌گرداند). تلگرام در حالت parse_mode=HTML چنین پیامی را دور می‌اندازد،
        // یعنی دقیقاً همان‌جایی که هشدار بیشترین اهمیت را دارد، ادمین هیچ‌چیز نمی‌بیند.
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $channel = $this->setting['Channel_Report'] ?? '';
        if ((string)$channel === '') return;

        telegram('sendmessage', [
            'chat_id'           => $channel,
            'message_thread_id' => $topicId,
            'text'              => $text,
            'parse_mode'        => 'HTML',
        ]);
    }
}

