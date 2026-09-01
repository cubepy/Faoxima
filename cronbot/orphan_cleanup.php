<?php
/**
 * پاکسازیِ «کانفیگ‌های یتیم» روی پنل — سرویس‌هایی که ربات «حذف‌شده» علامتشان
 * زده (disabled / removeTime / removevolume) ولی هنوز روی پنل باقی مانده‌اند.
 * این‌ها با هیچ کرونی برنمی‌گردند، چون هر دو کرونِ حذف فقط فاکتورهای باز را
 * می‌خوانند.
 *
 *   گزارش     : ...?token=cube-orphan-sweep
 *   حذف واقعی : ...?token=cube-orphan-sweep&apply=1
 *   بیشتر     : ...?token=cube-orphan-sweep&limit=2000
 *   یک کاربر  : ...?token=cube-orphan-sweep&user=cubevip_9648
 *   خط فرمان  : php cronbot/orphan_cleanup.php [apply]
 *
 * هر ردیف یک تماسِ زنده با پنل دارد، پس اجرای کامل چند دقیقه طول می‌کشد؛
 * پیشرفت هر ۲۵ ردیف چاپ می‌شود.
 *
 * حفاظت‌ها:
 *   - سرویسی که روی پنل هنوز active یا on_hold است هرگز پاک نمی‌شود.
 *   - فقط نام‌کاربری‌هایی بررسی می‌شوند که در جدول invoice ردیفِ بسته دارند؛
 *     هیچ کاربری که ربات نساخته لمس نمی‌شود.
 *   - پیش‌فرض «فقط گزارش» است.
 */

const RX_ORPHAN_TOKEN = 'cube-orphan-sweep';

$rxCli = (PHP_SAPI === 'cli');

// حالتِ کرون: بی‌صدا، دسته‌ی کوچک، حذف خودکار. برای اجرای زمان‌بندی‌شده.
$rxCron = $rxCli
    ? in_array('cron', array_slice($argv, 1), true)
    : (($_GET['cron'] ?? '') === '1');

if (!$rxCli) {
    // توکن برای هر دسترسیِ وب لازم است، حتی حالتِ کرون.
    if (($_GET['token'] ?? '') !== RX_ORPHAN_TOKEN) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("forbidden — توکن اشتباه است\n");
    }
    if (!$rxCron) {
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><meta charset=utf-8><title>Orphan cleanup</title>";
        echo "<pre style=\"font:13px/1.7 ui-monospace,Menlo,Consolas,monospace;padding:16px\">";
    }
}

// در حالتِ تعاملی بلوکِ راه‌اندازیِ کرونِ _init.php را رد می‌کنیم: آن بلوک با
// Content-Length: 0 و fastcgi_finish_request() ارتباطِ مرورگر را همان اول
// می‌بندد و display_errors را خاموش می‌کند — برای کرون درست است، برای صفحه‌ای
// که کسی منتظرش نشسته نه. در حالتِ کرون دقیقاً همان رفتار را می‌خواهیم.
if (!$rxCron && !defined('RX_CRON_INIT_LOADED')) define('RX_CRON_INIT_LOADED', true);
require_once __DIR__ . '/_init.php';
if ($rxCron && function_exists('rx_cron_boot')) rx_cron_boot('orphan_cleanup', 1800);

@ini_set('display_errors', 1);
@error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
@date_default_timezone_set('Asia/Tehran');
@set_time_limit(0);
@ini_set('memory_limit', '256M');

function rx_out($s = '') {
    if (!empty($GLOBALS['rxQuiet'])) return;   // حالتِ کرون چیزی چاپ نمی‌کند
    echo $s . "\n"; @ob_flush(); @flush();
}
$GLOBALS['rxQuiet'] = $rxCron;

$rxStart = microtime(true);
rx_out("Faoxima — پاکسازی کانفیگ‌های یتیم        " . date('Y-m-d H:i:s'));
rx_out(str_repeat('=', 62));

$root = dirname(__DIR__);
foreach (['config.php', 'botapi.php', 'panels.php', 'function.php'] as $f) {
    if (!is_file($root . '/' . $f)) {
        rx_out("❌ فایل لازم پیدا نشد: {$root}/{$f}");
        exit;
    }
}
ob_start();
foreach (['config.php', 'botapi.php', 'panels.php', 'function.php'] as $f) {
    require_once $root . '/' . $f;
}
$rxNoise = trim((string) ob_get_clean());
if ($rxNoise !== '' && (($_GET['debug'] ?? '') === '1')) {
    rx_out("--- خروجی هنگام لود ---\n" . substr($rxNoise, 0, 2000) . "\n--- پایان ---\n");
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    rx_out("❌ اتصال به دیتابیس برقرار نشد (احتمالاً max_user_connections پر است). چند دقیقه بعد دوباره.");
    exit;
}
if (!class_exists('ManagePanel')) {
    rx_out("❌ کلاس ManagePanel لود نشد — panels.php را دوباره آپلود کنید.");
    exit;
}

$ManagePanel = new ManagePanel();

/* ---------- حالت بررسیِ یک نام کاربری ---------- */
$rxOneUser = trim((string) ($_GET['user'] ?? ($rxCli && isset($argv[2]) && $argv[1] === 'user' ? $argv[2] : '')));
if ($rxOneUser !== '') {
    rx_out("بررسی تکیِ : {$rxOneUser}\n" . str_repeat('-', 62));
    $q = $pdo->prepare("SELECT id_invoice, username, Service_location, Status, name_product
                          FROM invoice WHERE username = :u ORDER BY id_invoice DESC LIMIT 10");
    $q->execute([':u' => $rxOneUser]);
    $found = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$found) {
        rx_out("در جدول invoice هیچ ردیفی با این نام نیست.");
        rx_out("یعنی ربات هیچ سابقه‌ای از این سرویس ندارد و این ابزار به آن دست نمی‌زند.");
    } else {
        foreach ($found as $r) {
            rx_out("  فاکتور {$r['id_invoice']} | وضعیت: {$r['Status']} | پنل: {$r['Service_location']} | محصول: {$r['name_product']}");
            $d = $ManagePanel->DataUser($r['Service_location'], $r['username']);
            $st = (is_array($d) ? ($d['status'] ?? '?') : '?');
            rx_out("  وضعیت روی پنل: {$st}");
        }
    }
    rx_out("\nزمان: " . round(microtime(true) - $rxStart, 1) . " ثانیه");
    exit;
}

/* ---------- سوییپ ---------- */
$rxApply = $rxCron
    || ($rxCli ? in_array('apply', array_slice($argv, 1), true) : (($_GET['apply'] ?? '') === '1'));
$rxLimit = (int) ($_GET['limit'] ?? 300);
if ($rxLimit < 1) $rxLimit = 300;
if ($rxLimit > 5000) $rxLimit = 5000;
// کرون دسته‌ی کوچک و تصادفی می‌گیرد: هم پنل را زیر فشار نمی‌گذارد، هم برخلافِ
// «۳۰۰ تای آخر» با گذشت زمان به همه‌ی ردیف‌های قدیمی هم می‌رسد.
if ($rxCron) $rxLimit = 60;
$rxOrder = $rxCron ? 'RAND()' : 'id_invoice DESC';

// نمای کلیِ وضعیت فاکتورها — کمک می‌کند بفهمیم چه چیزی بیرون از دامنه‌ی سوییپ است.
if (!$rxCron) {
    rx_out("وضعیت فاکتورها در دیتابیس:");
    foreach ($pdo->query("SELECT Status, COUNT(*) c FROM invoice GROUP BY Status ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        rx_out(sprintf("   %-18s %d", $r['Status'], $r['c']));
    }
    rx_out("");
}

$stmt = $pdo->prepare(
    "SELECT id_invoice, username, Service_location, Status
       FROM invoice
      WHERE Status IN ('disabled', 'removeTime', 'removevolume')
        AND username IS NOT NULL AND username != ''
   ORDER BY {$rxOrder}
      LIMIT {$rxLimit}"
);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

rx_out($rxApply ? "== حالت حذف واقعی ==" : "== فقط گزارش — هیچ چیزی پاک نمی‌شود ==");
rx_out("فاکتورهای بسته‌ی بررسی‌شده: " . count($rows) . "  (limit={$rxLimit})");
rx_out(str_repeat('-', 62));

$orphan = 0; $deleted = 0; $failed = 0; $skippedActive = 0; $alreadyGone = 0; $i = 0;

foreach ($rows as $row) {
    $i++;
    if ($i % 25 === 0) rx_out("   … {$i}/" . count($rows) . " بررسی شد");
    $panelName = (string) $row['Service_location'];
    $username  = trim((string) $row['username']);
    if ($panelName === '' || $username === '') continue;

    $data = $ManagePanel->DataUser($panelName, $username);
    if (!is_array($data) || ($data['status'] ?? '') === 'Unsuccessful') {
        $alreadyGone++;                 // روی پنل نیست — همان چیزی که می‌خواهیم
        continue;
    }

    $panelStatus = (string) ($data['status'] ?? '');
    if (in_array($panelStatus, ['active', 'on_hold'], true)) {
        $skippedActive++;
        rx_out("SKIP    {$username} @ {$panelName} — روی پنل فعال است (فاکتور: {$row['Status']})");
        continue;
    }

    $orphan++;
    if (!$rxApply) {
        rx_out("یتیم    {$username} @ {$panelName} — پنل: {$panelStatus} / فاکتور: {$row['Status']}");
        continue;
    }

    $res = $ManagePanel->RemoveUser($panelName, $username);
    if (is_array($res) && ($res['status'] ?? '') === 'successful') {
        $deleted++;
        rx_out("حذف شد  {$username} @ {$panelName}");
    } else {
        $failed++;
        rx_out("ناموفق  {$username} @ {$panelName} — " . json_encode($res, JSON_UNESCAPED_UNICODE));
    }
}

rx_out(str_repeat('-', 62));
rx_out("یتیم پیدا شده        : {$orphan}");
rx_out("حذف شد               : {$deleted}");
rx_out("ناموفق               : {$failed}");
rx_out("روی پنل فعال (رد شد) : {$skippedActive}");
rx_out("از قبل روی پنل نبود  : {$alreadyGone}");
rx_out("زمان                 : " . round(microtime(true) - $rxStart, 1) . " ثانیه");
if (!$rxApply && $orphan > 0) rx_out("\nبرای حذف واقعی همین آدرس را با &apply=1 باز کنید.");

if ($rxCron && ($deleted > 0 || $failed > 0)) {
    $line = "[orphan_cleanup] بررسی {$i} | حذف {$deleted} | ناموفق {$failed} | فعال رد شد {$skippedActive}";
    error_log($line);
    $ch = function_exists('select') ? select("setting", "*", null, null, "select") : null;
    $chat = is_array($ch) ? (string) ($ch['Channel_Report'] ?? '') : '';
    if ($chat !== '' && function_exists('telegram')) {
        $topic = select("topicid", "idreport", "report", "otherreport", "select")['idreport'] ?? '';
        telegram('sendmessage', [
            'chat_id' => $chat,
            'message_thread_id' => $topic,
            'text' => "🧹 پاکسازی خودکار کانفیگ‌های یتیم\n\nبررسی‌شده : {$i}\nحذف‌شده از پنل : {$deleted}\nناموفق : {$failed}",
            'parse_mode' => 'HTML',
        ]);
    }
}
