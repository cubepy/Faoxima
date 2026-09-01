<?php
/**
 * پاکسازیِ یک‌باره‌ی «کانفیگ‌های یتیم» روی پنل.
 *
 * سرویس‌هایی که ربات قبلاً «حذف‌شده» علامتشان زده (disabled / removeTime /
 * removevolume) ولی هنوز روی پنل باقی مانده‌اند. این‌ها با هیچ کرونی برنمی‌گردند،
 * چون هر دو کرونِ حذف فقط ردیف‌های بازِ فاکتور را می‌خوانند.
 *
 * پیش‌فرض «فقط گزارش» است و هیچ چیزی پاک نمی‌کند. برای حذف واقعی باید صریحاً
 * apply=1 بدهید:
 *
 *     مرورگر :  https://<domain>/cronbot/orphan_cleanup.php?token=<TOKEN>
 *               https://<domain>/cronbot/orphan_cleanup.php?token=<TOKEN>&apply=1
 *     خط فرمان: php cronbot/orphan_cleanup.php
 *               php cronbot/orphan_cleanup.php apply
 *
 * TOKEN را از مقدارِ ثابتِ زیر بردارید (برای اجرای وب اجباری است).
 *
 * حفاظت‌ها:
 *   - سرویسی که روی پنل هنوز active یا on_hold است هرگز پاک نمی‌شود.
 *   - فقط نام‌کاربری‌هایی که در جدول invoice ردیفِ «حذف‌شده» دارند بررسی می‌شوند؛
 *     هیچ کاربری که ربات نساخته لمس نمی‌شود.
 *   - در هر اجرا حداکثر RX_ORPHAN_LIMIT ردیف.
 */

const RX_ORPHAN_TOKEN = 'cube-orphan-sweep';
const RX_ORPHAN_LIMIT = 300;

require_once __DIR__ . '/_init.php';
rx_cron_boot('orphan_cleanup', 900);

$rxCli = (PHP_SAPI === 'cli');
if (!$rxCli) {
    if (($_GET['token'] ?? '') !== RX_ORPHAN_TOKEN) {
        http_response_code(403);
        exit("forbidden\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

if (!rx_cron_require_or_skip('orphan_cleanup', [
    __DIR__ . '/../config.php',
    __DIR__ . '/../botapi.php',
    __DIR__ . '/../panels.php',
    __DIR__ . '/../function.php',
])) {
    return;
}
if (!rx_cron_db_ready('orphan_cleanup')) {
    return;
}

$rxApply = $rxCli
    ? in_array('apply', array_slice($argv, 1), true)
    : (($_GET['apply'] ?? '') === '1');

@set_time_limit(0);
$ManagePanel = new ManagePanel();

$stmt = $pdo->prepare(
    "SELECT id_invoice, username, Service_location, Status
       FROM invoice
      WHERE Status IN ('disabled', 'removeTime', 'removevolume')
        AND username IS NOT NULL AND username != ''
   ORDER BY id_invoice DESC
      LIMIT " . RX_ORPHAN_LIMIT
);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo ($rxApply ? "== حالت حذف واقعی ==\n" : "== فقط گزارش (برای حذف apply بدهید) ==\n");
echo "ردیف‌های بررسی‌شده: " . count($rows) . "\n\n";

$orphan = 0; $deleted = 0; $failed = 0; $skippedActive = 0; $alreadyGone = 0;

foreach ($rows as $row) {
    $panelName = (string) $row['Service_location'];
    $username  = trim((string) $row['username']);
    if ($panelName === '' || $username === '') continue;

    $data = $ManagePanel->DataUser($panelName, $username);
    if (!is_array($data) || ($data['status'] ?? '') === 'Unsuccessful') {
        // یا روی پنل نیست (همان چیزی که می‌خواهیم) یا پنل جواب نداد.
        $alreadyGone++;
        continue;
    }

    $panelStatus = (string) ($data['status'] ?? '');
    if (in_array($panelStatus, ['active', 'on_hold'], true)) {
        // سرویسِ زنده روی پنل با فاکتورِ بسته — این را دستی بررسی کنید، پاکش نمی‌کنیم.
        $skippedActive++;
        echo "SKIP (روی پنل فعال است) {$username} @ {$panelName} — فاکتور {$row['Status']}\n";
        continue;
    }

    $orphan++;
    if (!$rxApply) {
        echo "یتیم  {$username} @ {$panelName} — پنل: {$panelStatus} / فاکتور: {$row['Status']}\n";
        continue;
    }

    $res = $ManagePanel->RemoveUser($panelName, $username);
    if (is_array($res) && ($res['status'] ?? '') === 'successful') {
        $deleted++;
        echo "حذف شد {$username} @ {$panelName}\n";
    } else {
        $failed++;
        echo "ناموفق {$username} @ {$panelName} — " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "\n---------------------------\n";
echo "یتیم پیدا شده        : {$orphan}\n";
echo "حذف شد               : {$deleted}\n";
echo "ناموفق               : {$failed}\n";
echo "روی پنل فعال (رد شد) : {$skippedActive}\n";
echo "از قبل روی پنل نبود  : {$alreadyGone}\n";
if (!$rxApply && $orphan > 0) {
    echo "\nبرای حذف واقعی، همین آدرس را با &apply=1 باز کنید.\n";
}
