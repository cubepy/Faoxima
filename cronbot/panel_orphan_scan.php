<?php
/**
 * سوییپِ سمتِ پنل — برای کانفیگ‌هایی که ربات هیچ ردی از آن‌ها ندارد.
 *
 * orphan_cleanup.php از سمتِ دیتابیس شروع می‌کند، پس اکانتی که ردیفِ فاکتورش
 * پاک شده برایش نامرئی است. این ابزار برعکس عمل می‌کند: لیستِ کاربرانِ
 * منقضی/محدودشده را از خودِ پنل می‌گیرد و آن‌هایی را که در جدول invoice هیچ
 * ردیفی ندارند نشان می‌دهد.
 *
 *   گزارش : ...?token=cube-orphan-sweep&panel=<نام پنل>
 *   حذف   : ...&apply=1&prefix=cubevip_,cubeco_,cubecip_
 *   همه‌ی پنل‌ها: panel را ندهید
 *
 * پارامترها:
 *   panel    نام دقیقِ پنل. ندادنش یعنی همه‌ی پنل‌های marzban/pasargard.
 *   prefix   فهرستِ پیشوندهای مجاز با کاما. برای حذف *اجباری* است.
 *   mindays  فقط اکانت‌هایی که حداقل این تعداد روز از انقضاشان گذشته (پیش‌فرض ۷).
 *   apply=1  حذفِ واقعی.
 *
 * چرا prefix اجباری است: روی پنلِ شما ممکن است کاربرانی باشند که دستی ساخته‌اید
 * یا ربات/فروشنده‌ی دیگری ساخته. «نبودن در جدول invoice» به‌تنهایی دلیل کافی
 * برای حذف نیست. با prefix فقط الگوی نام‌گذاریِ خودِ ربات لمس می‌شود.
 */

const RX_ORPHAN_TOKEN = 'cube-orphan-sweep';

$rxCli = (PHP_SAPI === 'cli');
if (!$rxCli) {
    if (($_GET['token'] ?? '') !== RX_ORPHAN_TOKEN) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("forbidden — توکن اشتباه است\n");
    }
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><meta charset=utf-8><title>Panel orphan scan</title>";
    echo "<pre style=\"font:13px/1.7 ui-monospace,Menlo,Consolas,monospace;padding:16px\">";
}

if (!defined('RX_CRON_INIT_LOADED')) define('RX_CRON_INIT_LOADED', true);
require_once __DIR__ . '/_init.php';

@ini_set('display_errors', 1);
@error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
@date_default_timezone_set('Asia/Tehran');
@set_time_limit(0);
@ini_set('memory_limit', '256M');

function rx_out($s = '') { echo $s . "\n"; @ob_flush(); @flush(); }

/** چند روز از انقضا گذشته؛ expire بسته به نسخه‌ی پنل عدد unix یا رشته‌ی ISO است. */
function rx_expired_days_ago($expire): ?int
{
    if (is_numeric($expire)) {
        $ts = (int) $expire;
    } elseif (is_string($expire) && trim($expire) !== '') {
        $ts = (int) strtotime($expire);
    } else {
        return null;                       // بی‌انقضا یا نامشخص
    }
    if ($ts <= 0) return null;
    return intdiv(time() - $ts, 86400);
}

/**
 * تصمیمِ واحد برای یک کاربرِ پنل. جدا نگه داشته شده تا بشود مستقیم تستش کرد —
 * این تنها جایی است که مشخص می‌کند چه چیزی کاندیدِ حذف می‌شود.
 */
function rx_orphan_verdict(array $u, array $prefixes, int $minDays, bool $knownToBot, string $fallbackStatus = ''): string
{
    $uname = trim((string) ($u['username'] ?? ''));
    if ($uname === '') return 'skip_noname';

    $status = (string) ($u['status'] ?? $fallbackStatus);
    // هرگز سرویسِ زنده — حتی اگر پنل اشتباهی در لیستِ منقضی‌ها برش گردانده باشد.
    if (in_array($status, ['active', 'on_hold'], true)) return 'skip_active';

    // ربات سابقه دارد؟ پس مسیرِ عادیِ حذف مسئولش است، نه این ابزار.
    if ($knownToBot) return 'skip_known';

    if (!empty($prefixes)) {
        $match = false;
        foreach ($prefixes as $pfx) {
            if ($pfx !== '' && strpos($uname, $pfx) === 0) { $match = true; break; }
        }
        if (!$match) return 'skip_prefix';
    }

    $ageDays = rx_expired_days_ago($u['expire'] ?? null);
    if ($minDays > 0 && $ageDays !== null && $ageDays < $minDays) return 'skip_fresh';

    return 'candidate';
}

$rxStart = microtime(true);
rx_out("Faoxima — اسکنِ کانفیگ‌های بی‌سابقه روی پنل     " . date('Y-m-d H:i:s'));
rx_out(str_repeat('=', 64));

$root = dirname(__DIR__);
foreach (['config.php', 'botapi.php', 'panels.php', 'function.php'] as $f) {
    if (!is_file($root . '/' . $f)) { rx_out("❌ فایل لازم نیست: {$root}/{$f}"); exit; }
}
ob_start();
foreach (['config.php', 'botapi.php', 'panels.php', 'function.php'] as $f) { require_once $root . '/' . $f; }
ob_end_clean();

if (!isset($pdo) || !($pdo instanceof PDO)) { rx_out("❌ اتصال دیتابیس برقرار نشد. کمی بعد دوباره."); exit; }
if (!function_exists('getusers'))          { rx_out("❌ تابع getusers لود نشد — Marzban.php را بررسی کنید."); exit; }

$rxApply   = $rxCli ? in_array('apply', array_slice($argv, 1), true) : (($_GET['apply'] ?? '') === '1');
$rxPanelIn = trim((string) ($_GET['panel'] ?? ''));
$rxMinDays = isset($_GET['mindays']) ? max(0, (int) $_GET['mindays']) : 7;
$rxPrefix  = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['prefix'] ?? '')))));

if ($rxApply && empty($rxPrefix)) {
    rx_out("❌ برای حذف باید prefix بدهید، مثلاً :");
    rx_out("   &apply=1&prefix=cubevip_,cubeco_,cubecip_");
    rx_out("");
    rx_out("   بدون آن، هر کاربرِ دستی‌ساخته یا متعلق به ربات دیگری هم کاندید حذف می‌شود.");
    exit;
}

// پنل‌های هدف
$sql = "SELECT name_panel, type, version_panel FROM marzban_panel WHERE type IN ('marzban','pasargard')";
$args = [];
if ($rxPanelIn !== '') { $sql .= " AND name_panel = :p"; $args[':p'] = $rxPanelIn; }
$q = $pdo->prepare($sql); $q->execute($args);
$panels = $q->fetchAll(PDO::FETCH_ASSOC);
if (!$panels) { rx_out("هیچ پنل marzban/pasargard پیدا نشد" . ($rxPanelIn !== '' ? " با نام «{$rxPanelIn}»" : "") . "."); exit; }

rx_out($rxApply ? "== حالت حذف واقعی ==" : "== فقط گزارش — هیچ چیزی پاک نمی‌شود ==");
rx_out("پنل‌ها  : " . count($panels));
rx_out("پیشوند : " . (empty($rxPrefix) ? '(همه — فقط گزارش)' : implode(', ', $rxPrefix)));
rx_out("حداقل روزِ گذشته از انقضا : {$rxMinDays}");
rx_out(str_repeat('-', 64));

$invStmt = $pdo->prepare("SELECT COUNT(*) FROM invoice WHERE username = :u");
$ManagePanel = new ManagePanel();
$totOrphan = 0; $totDeleted = 0; $totFailed = 0; $totKnown = 0; $totSkipped = 0;

foreach ($panels as $panel) {
    $pname = (string) $panel['name_panel'];
    rx_out("\n▌ پنل: {$pname}  ({$panel['type']}, version_panel=" . (string) $panel['version_panel'] . ")");

    foreach (['expired', 'limited'] as $status) {
        $res = getusers($pname, $status);
        if (!is_array($res) || !empty($res['error'])) {
            rx_out("   ⚠️ {$status}: خطا — " . (is_array($res) ? ($res['error'] ?? '?') : 'پاسخ نامعتبر'));
            continue;
        }
        $users = is_array($res['users'] ?? null) ? $res['users'] : [];
        $total = isset($res['total']) ? (int) $res['total'] : count($users);
        rx_out("   {$status}: " . count($users) . " کاربر دریافت شد" . ($total > count($users) ? " (از مجموع {$total} — پنل صفحه‌بندی کرده، بعد از این اجرا دوباره بزنید)" : ""));

        foreach ($users as $u) {
            $uname = trim((string) ($u['username'] ?? ''));
            if ($uname === '') continue;

            $ustatus = (string) ($u['status'] ?? $status);
            $invStmt->execute([':u' => $uname]);
            $knownToBot = ((int) $invStmt->fetchColumn() > 0);

            $verdict = rx_orphan_verdict($u, $rxPrefix, $rxMinDays, $knownToBot, $status);
            if ($verdict === 'skip_known') { $totKnown++; continue; }
            if ($verdict !== 'candidate')  { $totSkipped++; continue; }

            $ageDays = rx_expired_days_ago($u['expire'] ?? null);
            $totOrphan++;
            $ageTxt = ($ageDays === null) ? 'تاریخ انقضا نامشخص' : "{$ageDays} روز از انقضا گذشته";
            if (!$rxApply) {
                rx_out("      بی‌سابقه  {$uname} — {$ustatus} / {$ageTxt}");
                continue;
            }
            $r = $ManagePanel->RemoveUser($pname, $uname);
            if (is_array($r) && ($r['status'] ?? '') === 'successful') {
                $totDeleted++; rx_out("      حذف شد   {$uname} — {$ageTxt}");
            } else {
                $totFailed++;  rx_out("      ناموفق   {$uname} — " . json_encode($r, JSON_UNESCAPED_UNICODE));
            }
        }
    }
}

rx_out("\n" . str_repeat('-', 64));
rx_out("بی‌سابقه پیدا شده     : {$totOrphan}");
rx_out("حذف شد                : {$totDeleted}");
rx_out("ناموفق                : {$totFailed}");
rx_out("ربات سابقه داشت (رد)  : {$totKnown}");
rx_out("رد شد (پیشوند/تازه)   : {$totSkipped}");
rx_out("زمان                  : " . round(microtime(true) - $rxStart, 1) . " ثانیه");
if (!$rxApply && $totOrphan > 0) {
    rx_out("\nبرای حذف، prefix بدهید و apply=1 :");
    rx_out("   &apply=1&prefix=cubevip_,cubeco_,cubecip_");
}
