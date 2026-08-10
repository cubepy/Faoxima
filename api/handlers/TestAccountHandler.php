<?php


declare(strict_types=1);

require_once __DIR__ . '/BaseHandler.php';

// [FEATURE] دریافت اکانت تست از داخل مینی‌اپ — همون منطق اصلیِ مسیر متنی «سرویس تست» تو ربات
// (re/rx/index/user_flow.php)، فقط برای حالتی که فقط یک پنل تست سراسری (ONTestAccount) تنظیم شده.
// اگه چند پنل تست یا پنل نوع Manualsale دارید، فعلاً از همون مسیر ربات استفاده کنید.
final class TestAccountHandler extends BaseHandler
{
    public function handle(): void
    {
        $this->requireMethod('POST');

        $userId = (string)($this->user['id'] ?? '');
        $username = $this->user['username'] ?? '';
        $customUsername = trim(FaoximaInput::string($this->data, 'username'));

        $setting = select('setting', '*');
        if (is_array($setting) && function_exists('check_active_btn') && !check_active_btn($setting['keyboardmain'] ?? '', 'text_usertest')) {
            FaoximaResponse::fail(403, 'سرویس تست در حال حاضر در دسترس نیست');
        }

        $limitUserTest = (int)($this->user['limit_usertest'] ?? 0);
        if ($limitUserTest <= 0) {
            FaoximaResponse::fail(403, 'سقف دریافت اکانت تست شما تمام شده است');
        }

        $panelCount = (int) select('marzban_panel', '*', 'TestAccount', 'ONTestAccount', 'count');
        if ($panelCount !== 1) {
            FaoximaResponse::fail(409, 'برای این حالت، لطفاً از منوی «سرویس تست» داخل خودِ ربات استفاده کنید (چند پنل تست تنظیم شده است)');
        }
        $panel = select('marzban_panel', '*', 'TestAccount', 'ONTestAccount', 'select');
        if (!is_array($panel)) {
            FaoximaResponse::notFound('پنل تست یافت نشد');
        }
        $hideList = $panel['hide_user'] !== null ? json_decode((string) $panel['hide_user'], true) : [];
        if (is_array($hideList) && in_array($userId, $hideList)) {
            FaoximaResponse::fail(403, 'دسترسی شما به این سرویس محدود شده است');
        }
        if (($panel['type'] ?? '') === 'Manualsale') {
            $stock = (int) FaoximaDb::fetchScalar(
                "SELECT COUNT(*) FROM manualsell WHERE codepanel = :codepanel AND codeproduct = 'usertest' AND status = 'active'",
                [':codepanel' => $panel['code_panel']]
            );
            if ($stock === 0) {
                FaoximaResponse::fail(409, 'موجودی این سرویس تست به پایان رسیده است');
            }
        }

        $isCustomUsernameMethod = in_array($panel['MethodUsername'] ?? '', ['نام کاربری دلخواه', 'نام کاربری دلخواه + عدد رندوم'], true);
        if ($isCustomUsernameMethod) {
            if ($customUsername === '' || !preg_match('~(?!_)^[a-z][a-z\d_]{2,32}(?<!_)$~i', $customUsername)) {
                FaoximaResponse::badRequest('نام کاربری نامعتبر است (فقط حروف انگلیسی، عدد و آندرلاین، حداقل ۳ کاراکتر)');
            }
        }

        $managePanel = new ManagePanel();
        $randomString = bin2hex(random_bytes(4));
        $seedText = strtolower($customUsername !== '' ? $customUsername : (string) $userId);
        $usernameAc = strtolower(generateUsername($userId, $panel['MethodUsername'], $username, $randomString, $seedText, $panel['namecustom'], $customUsername));
        $existing = $managePanel->DataUser($panel['name_panel'], $usernameAc);
        if (isset($existing['username'])) {
            $usernameAc = rand(1000000, 9999999) . '_' . $usernameAc;
        }

        // [FIX] این کسر قبلاً «بخوان از snapshot، منهای یک، بنویس» بود. دو درخواستِ
        // هم‌زمان هر دو عدد ۱ را می‌خواندند، هر دو اکانت می‌ساختند و هر دو ۰ می‌نوشتند
        // — دو سرویس تست با یک سهمیه. حالا کسر به‌صورت شرطی و اتمیک انجام می‌شود و
        // فقط درخواستی که واقعاً سهمیه را گرفت ادامه می‌دهد.
        try {
            $rxQuota = FaoximaDb::pdo()->prepare(
                "UPDATE user SET limit_usertest = CAST(limit_usertest AS SIGNED) - 1
                  WHERE id = :id AND CAST(limit_usertest AS SIGNED) > 0"
            );
            $rxQuota->execute([':id' => $userId]);
            $rxQuotaTaken = ($rxQuota->rowCount() === 1);
        } catch (Throwable $rxQuotaErr) {
            FaoximaLogger::warn('test account quota claim failed', ['err' => $rxQuotaErr->getMessage()]);
            $rxQuotaTaken = false;
        }
        if (!$rxQuotaTaken) {
            FaoximaResponse::fail(403, 'سقف دریافت اکانت تست شما تمام شده است');
        }
        $newLimit = max(0, $limitUserTest - 1);

        $expireAt = strtotime(date('Y-m-d H:i:s', strtotime('+' . $panel['time_usertest'] . 'hours')));
        $datac = [
            'expire' => $expireAt,
            'data_limit' => $panel['val_usertest'] * 1048576,
            'from_id' => $userId,
            'username' => $usernameAc,
            'type' => 'usertest',
        ];

        FaoximaDb::execute(
            "INSERT IGNORE INTO invoice (id_user, id_invoice, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status, notifctions)
             VALUES (:id_user, :id_invoice, :username, :time_sell, :location, :name_product, 0, :volume, :service_time, 'active', :notifctions)",
            [
                ':id_user' => $userId,
                ':id_invoice' => $randomString,
                ':username' => $usernameAc,
                ':time_sell' => time(),
                ':location' => $panel['name_panel'],
                ':name_product' => 'سرویس تست',
                ':volume' => $panel['val_usertest'],
                ':service_time' => $panel['time_usertest'],
                ':notifctions' => json_encode(['volume' => false, 'time' => false]),
            ]
        );

        $dataoutput = $managePanel->createUser($panel['name_panel'], 'usertest', $usernameAc, $datac);
        if (empty($dataoutput['username'])) {
            // بازگرداندن سهمیه به‌صورت نسبی (نه مقدار مطلقِ خوانده‌شده) تا سهمیه‌ای که
            // یک درخواستِ موفقِ هم‌زمان خرج کرده، اشتباهاً برنگردد.
            try {
                FaoximaDb::pdo()->prepare("UPDATE user SET limit_usertest = CAST(limit_usertest AS SIGNED) + 1 WHERE id = :id")
                    ->execute([':id' => $userId]);
            } catch (Throwable $rxQuotaBackErr) { /* لاگ کافی است */ }
            FaoximaDb::execute("DELETE FROM invoice WHERE id_invoice = :id", [':id' => $randomString]);
            FaoximaResponse::fail(502, 'ساخت اکانت تست با خطا مواجه شد؛ لطفاً بعداً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید');
        }

        FaoximaResponse::ok([
            'username' => $dataoutput['username'],
            'expire' => $expireAt,
            'volume_mb' => (int) $panel['val_usertest'],
            'hours' => (int) $panel['time_usertest'],
        ], 'اکانت تست با موفقیت ساخته شد');
    }
}
