<?php


declare(strict_types=1);

require_once __DIR__ . '/../lib/Db.php';

if (class_exists('MiniDiscount')) {
    return;
}

final class MiniDiscount
{
    const SECTIONS = ['buy', 'extend', 'volume', 'time', 'charge', 'all'];

    public static function valueType(array $row): string
    {
        $vt = strtolower(trim((string)($row['value_type'] ?? '')));
        if (in_array($vt, ['percent', 'amount', 'free'], true)) {
            return $vt;
        }
        return 'percent';
    }

    public static function describe(array $row): string
    {
        $vt = self::valueType($row);
        $val = (float)($row['price'] ?? 0);
        if ($vt === 'free')   return 'رایگان';
        if ($vt === 'amount') return number_format($val) . ' تومان';
        return rtrim(rtrim((string)$val, '0'), '.') . '٪';
    }

    public static function applyToPrice(array $row, float $price): float
    {
        $vt = self::valueType($row);
        $val = (float)($row['price'] ?? 0);
        if ($vt === 'free') {
            return 0.0;
        }
        if ($vt === 'amount') {
            $p = $price - $val;
            return $p < 0 ? 0.0 : $p;
        }
        $p = $price - (($price * $val) / 100);
        return $p < 0 ? 0.0 : $p;
    }

    public static function redeemGift(string $code, array $user): array
    {
        $code = trim($code);
        if ($code === '') {
            return ['ok' => false, 'reason' => '❌ کد هدیه را وارد کنید.'];
        }

        $row = FaoximaDb::fetchOne('SELECT * FROM Discount WHERE code = :c LIMIT 1', [':c' => $code]);
        if ($row === null) {
            return ['ok' => false, 'reason' => '❌ کد هدیه نامعتبر است.'];
        }

        $status = strtolower(trim((string)($row['status'] ?? '')));
        if ($status !== '' && $status !== 'active') {
            return ['ok' => false, 'reason' => '❌ این کد هدیه غیرفعال است.'];
        }

        $target = trim((string)($row['target_user'] ?? ''));
        if ($target !== '' && $target !== (string)$user['id']) {
            return ['ok' => false, 'reason' => '❌ کد هدیه نامعتبر است.'];
        }

        $expire = (int)($row['expire_at'] ?? 0);
        if ($expire !== 0 && time() >= $expire) {
            return ['ok' => false, 'reason' => '❌ زمان این کد هدیه به پایان رسیده است.'];
        }

        $limitUse  = (int)($row['limituse'] ?? 0);
        $limitUsed = (int)($row['limitused'] ?? 0);
        if ($limitUse > 0 && $limitUsed >= $limitUse) {
            return ['ok' => false, 'reason' => '❌ ظرفیت استفاده از این کد هدیه به پایان رسیده است.'];
        }

        $amount = (int) round((float)($row['price'] ?? 0));
        if ($amount <= 0) {
            return ['ok' => false, 'reason' => '❌ این کد هدیه مبلغی ندارد.'];
        }

        // [FIX] قبلاً اول موجودی واریز می‌شد و بعد limitused با مقدارِ مطلقِ خوانده‌شده
        // بازنویسی می‌شد (بدون قفل و بدون شرط). دو درخواستِ هم‌زمان هر دو از بررسی
        // ظرفیتِ بالا رد می‌شدند، هر دو کیف پول را شارژ می‌کردند و هر دو همان عدد را
        // می‌نوشتند — یعنی یک کدِ یک‌بارمصرف چند بار نقد می‌شد.
        // حالا اول ظرفیت به‌صورت اتمیک «رزرو» می‌شود و فقط برنده‌ی همان UPDATE
        // شارژ را انجام می‌دهد. اگر واریز شکست خورد، رزرو پس داده می‌شود.
        try {
            $pdo = FaoximaDb::pdo();
            if ($limitUse > 0) {
                $claim = $pdo->prepare(
                    "UPDATE Discount SET limitused = limitused + 1
                      WHERE code = :c AND CAST(limitused AS UNSIGNED) < :lim"
                );
                $claim->execute([':c' => $code, ':lim' => $limitUse]);
            } else {
                $claim = $pdo->prepare("UPDATE Discount SET limitused = limitused + 1 WHERE code = :c");
                $claim->execute([':c' => $code]);
            }
            if ($claim->rowCount() !== 1) {
                return ['ok' => false, 'reason' => '❌ ظرفیت استفاده از این کد هدیه به پایان رسیده است.'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => '❌ خطا در بررسی کد هدیه. لطفاً دوباره تلاش کنید.'];
        }

        $credited = balance_atomic_credit($user['id'], $amount);
        if ($credited === false) {
            try {
                $pdo->prepare("UPDATE Discount SET limitused = GREATEST(0, CAST(limitused AS UNSIGNED) - 1) WHERE code = :c")
                    ->execute([':c' => $code]);
            } catch (Throwable $e) { /* رزرو پس داده نشد؛ فقط یک ظرفیت می‌سوزد */ }
            return ['ok' => false, 'reason' => '❌ خطا در واریز هدیه. لطفاً دوباره تلاش کنید.'];
        }

        self::recordConsumed($code, (string)$user['id'], 'gift');

        $newBalance = FaoximaDb::fetchScalar('SELECT Balance FROM user WHERE id = :u', [':u' => $user['id']]);
        $newBalance = $newBalance === null ? ((float)($user['Balance'] ?? 0) + $amount) : (float)$newBalance;

        return ['ok' => true, 'amount' => $amount, 'new_balance' => $newBalance];
    }

    public static function validateSell(string $code, string $section, string $codeProduct, string $codePanel, array $user, bool $blockIfUserDiscount = true): array
    {
        $code = trim($code);
        if ($code === '') {
            return ['ok' => false, 'reason' => '❌ کد تخفیف را وارد کنید.'];
        }

        $section = in_array($section, self::SECTIONS, true) ? $section : 'all';

        if ($blockIfUserDiscount && $section !== 'charge') {
            $userDiscount = (int)($user['pricediscount'] ?? 0);
            if ($userDiscount !== 0) {
                return ['ok' => false, 'reason' => '❌ شما تخفیف اختصاصی دارید و امکان استفاده از کد تخفیف وجود ندارد.'];
            }
        }

        $agent = (string)($user['agent'] ?? 'f');
        if ($codeProduct === '') $codeProduct = 'all';
        if ($codePanel === '')   $codePanel = '/all';

        $row = FaoximaDb::fetchOne(
            "SELECT * FROM DiscountSell
              WHERE codeDiscount = :code
                AND (code_product = :cp OR code_product = 'all')
                AND (code_panel = :cpan OR code_panel = '/all')
                AND (agent = :agent OR agent = 'allusers' OR agent = 'all')
                AND (COALESCE(NULLIF(section, ''), type, 'all') = :section
                     OR COALESCE(NULLIF(section, ''), type, 'all') = 'all')
                AND (status IS NULL OR status = '' OR status = 'active')
                AND (target_user IS NULL OR target_user = '' OR target_user = :uid)
              LIMIT 1",
            [
                ':code' => $code,
                ':cp' => $codeProduct,
                ':cpan' => $codePanel,
                ':agent' => $agent,
                ':section' => $section,
                ':uid' => (string)$user['id'],
            ]
        );
        if ($row === null) {
            return ['ok' => false, 'reason' => '❌ کد تخفیف نامعتبر است یا برای این بخش فعال نیست.'];
        }

        $expiry = (int)($row['time'] ?? 0);
        if ($expiry !== 0 && time() >= $expiry) {
            return ['ok' => false, 'reason' => '❌ زمان کد تخفیف به پایان رسیده است.'];
        }

        $limitTotal = (int)($row['limitDiscount'] ?? 0);
        if ($limitTotal > 0 && (int)($row['usedDiscount'] ?? 0) >= $limitTotal) {
            return ['ok' => false, 'reason' => '❌ ظرفیت استفاده از این کد تخفیف به پایان رسیده است.'];
        }

        $usedByUser = (int) FaoximaDb::fetchScalar(
            'SELECT COUNT(*) FROM Giftcodeconsumed WHERE id_user = :u AND code = :c',
            [':u' => (string)$user['id'], ':c' => $code]
        );
        $useUser = (int)($row['useuser'] ?? 0);
        if ($useUser > 0 && $usedByUser >= $useUser) {
            return ['ok' => false, 'reason' => '⭕️ سقف استفاده شما از این کد تخفیف پر شده است.'];
        }

        if ((string)($row['usefirst'] ?? '') === '1') {
            $invoiceCount = (int) FaoximaDb::fetchScalar(
                'SELECT COUNT(*) FROM invoice WHERE id_user = :u',
                [':u' => (string)$user['id']]
            );
            if ($invoiceCount != 0) {
                return ['ok' => false, 'reason' => '❌ این کد تخفیف فقط برای اولین خرید قابل استفاده است.'];
            }
        }

        $vt = self::valueType($row);
        $val = (float)($row['price'] ?? 0);
        if ($vt === 'percent' && ($val <= 0 || $val > 100)) {
            return ['ok' => false, 'reason' => '❌ درصد کد تخفیف نامعتبر است.'];
        }
        if ($vt === 'amount' && $val <= 0) {
            return ['ok' => false, 'reason' => '❌ مبلغ کد تخفیف نامعتبر است.'];
        }

        return [
            'ok'         => true,
            'code'       => $code,
            'value_type' => $vt,
            'value'      => $val,
            'label'      => self::describe($row),
            'row'        => $row,
        ];
    }

    /**
     * [FIX 5] یک بار مصرفِ کد تخفیف را برمی‌دارد؛ اگر آخرین ظرفیت در همان لحظه
     * نصیب کاربر دیگری شده باشد false برمی‌گرداند.
     *
     * قبلاً این متد «بخوان، یکی اضافه کن، بنویس» بود و هیچ سقفی در WHERE نداشت و
     * چیزی هم برنمی‌گرداند؛ یعنی دو مشتری که هم‌زمان آخرین ظرفیتِ یک کد را می‌گرفتند
     * هر دو از بررسیِ validateSell رد می‌شدند و هر دو تخفیف را می‌گرفتند — کدِ
     * محدود به یک استفاده، بارها مصرف می‌شد.
     */
    public static function markSellUsed(string $code, array $user): bool
    {
        $code = trim($code);
        if ($code === '') return false;
        try {
            $pdo = FaoximaDb::pdo();
            $stmt = $pdo->prepare(
                "UPDATE DiscountSell
                    SET usedDiscount = CAST(COALESCE(NULLIF(usedDiscount, ''), '0') AS SIGNED) + 1
                  WHERE codeDiscount = :c
                    AND (limitDiscount IS NULL
                         OR limitDiscount = ''
                         OR CAST(limitDiscount AS SIGNED) <= 0
                         OR CAST(COALESCE(NULLIF(usedDiscount, ''), '0') AS SIGNED) < CAST(limitDiscount AS SIGNED))"
            );
            $stmt->execute([':c' => $code]);
            if ($stmt->rowCount() < 1) {
                return false;
            }
        } catch (Throwable $e) {
            return false;
        }

        // [FIX 5] سقفِ «هر کاربر چند بار» هم فقط خوانده می‌شد و هیچ‌وقت با نوشتن گره
        // نمی‌خورد؛ پس یک کدِ یک‌بار-برای-هر-کاربر با دو بار زدنِ دکمه‌ی تأیید دو بار
        // مصرف می‌شد. مثل کدهای هدیه: اول ردیف مصرف را ثبت می‌کنیم، بعد ردیف‌های
        // آزادنشده تا همان id را می‌شماریم؛ در تلاش‌های هم‌زمان دقیقاً useuser تای اول
        // خودشان را داخل سقف می‌بینند.
        $userId = (string)$user['id'];
        $mine = self::recordConsumed($code, $userId, 'sell');
        $useUser = 0;
        try {
            $useUser = (int) FaoximaDb::fetchScalar(
                'SELECT useuser FROM DiscountSell WHERE codeDiscount = :c LIMIT 1',
                [':c' => $code]
            );
        } catch (Throwable $e) {
            $useUser = 0;
        }
        if ($useUser > 0 && $mine > 0) {
            try {
                $pdo = FaoximaDb::pdo();
                $rank = $pdo->prepare(
                    'SELECT COUNT(*) FROM Giftcodeconsumed
                      WHERE id_user = :u AND code = :c AND id <= :id
                        AND (released IS NULL OR released = 0)'
                );
                $rank->execute([':u' => $userId, ':c' => $code, ':id' => $mine]);
                if ((int) $rank->fetchColumn() > $useUser) {
                    $pdo->prepare('DELETE FROM Giftcodeconsumed WHERE id = :id')->execute([':id' => $mine]);
                    $pdo->prepare(
                        "UPDATE DiscountSell
                            SET usedDiscount = GREATEST(CAST(COALESCE(NULLIF(usedDiscount, ''), '0') AS SIGNED) - 1, 0)
                          WHERE codeDiscount = :c"
                    )->execute([':c' => $code]);
                    return false;
                }
            } catch (Throwable $e) {
                // سقف کل از قبل رعایت شده؛ ردکردنِ یک مشتریِ واقعی به‌خاطر خطای یک
                // کوئریِ شمارش، بدترِ این دو حالت است.
            }
        }
        return true;
    }

    /** [FIX 5] ردیف مصرف را ثبت می‌کند و id آن را برمی‌گرداند (یا ۰). */
    private static function recordConsumed(string $code, string $userId, string $kind): int
    {
        try {
            $pdo = FaoximaDb::pdo();
            $pdo->prepare('INSERT INTO Giftcodeconsumed (id_user, code, kind, consumed_at) VALUES (:u, :c, :k, :t)')
                ->execute([':u' => $userId, ':c' => $code, ':k' => $kind, ':t' => (string)time()]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            try {
                $pdo = FaoximaDb::pdo();
                $pdo->prepare('INSERT INTO Giftcodeconsumed (id_user, code) VALUES (:u, :c)')
                    ->execute([':u' => $userId, ':c' => $code]);
                return (int) $pdo->lastInsertId();
            } catch (Throwable $e2) {
                return 0;
            }
        }
    }

    public static function releaseLastUnpaidDiscount(string $userId, ?string $discountCode = null, ?int $referenceTime = null): bool
    {
        $userId = trim($userId);
        if ($userId === '') return false;
        try {
            $pdo = FaoximaDb::pdo();
            if ($referenceTime !== null && $referenceTime > 0) {
                $low = (string)($referenceTime - 900);
                $high = (string)($referenceTime + 900);
            } else {
                $low = (string)(time() - 1800);
                $high = (string)(time() + 60);
            }
            $params = [':u' => $userId, ':lo' => $low, ':hi' => $high];
            $codeClause = '';
            if ($discountCode !== null && trim($discountCode) !== '') {
                $codeClause = ' AND code = :c';
                $params[':c'] = trim($discountCode);
            }
            $row = FaoximaDb::fetchOne(
                "SELECT id, code FROM Giftcodeconsumed
                  WHERE id_user = :u
                    AND kind = 'sell'
                    AND (released IS NULL OR released = 0)
                    AND consumed_at <> ''
                    AND CAST(consumed_at AS UNSIGNED) BETWEEN :lo AND :hi" . $codeClause . "
                  ORDER BY id DESC LIMIT 1",
                $params
            );
            if (!is_array($row) || empty($row['code'])) return false;

            $code = (string)$row['code'];
            $rowId = (int)$row['id'];

            $marked = $pdo->prepare('UPDATE Giftcodeconsumed SET released = 1 WHERE id = :id AND (released IS NULL OR released = 0)');
            $marked->execute([':id' => $rowId]);
            if ($marked->rowCount() < 1) return false;

            // [FIX 5] کم‌کردن باید داخل خودِ دیتابیس انجام شود، به همان دلیلی که
            // زیادکردن؛ «بخوان و مقدارِ منهای یک را بنویس» هر مصرفی را که وسطِ کار
            // ثبت شود از بین می‌برد و ظرفیتِ کد دوباره آزاد می‌شود.
            $pdo->prepare(
                "UPDATE DiscountSell
                    SET usedDiscount = GREATEST(CAST(COALESCE(NULLIF(usedDiscount, ''), '0') AS SIGNED) - 1, 0)
                  WHERE codeDiscount = :c"
            )->execute([':c' => $code]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
