<?php
/**
 * دانلودِ آخرین نسخه‌ی CubeVPN — پراکسیِ سمتِ سرور.
 *
 * چرا لازم شد: صفحه‌ی دانلود مستقیماً از مرورگرِ بازدیدکننده به
 * api.github.com می‌زد. تا وقتی ریپو عمومی بود کار می‌کرد؛ حالا که خصوصی
 * شده هم آن درخواست 404 می‌گیرد و هم فایلِ ریلیز بدون توکن دانلود نمی‌شود.
 * توکن را هم نمی‌شود داخل جاوااسکریپت گذاشت (هر کسی سورس صفحه را می‌بیند).
 *
 * پس درخواست از اینجا می‌رود: توکن فقط روی سرور می‌ماند، و مهم‌تر اینکه
 * بازدیدکننده فایل را از دامنه‌ی خودتان می‌گیرد نه از گیت‌هاب — که برای
 * مخاطبِ این صفحه (کسی که هنوز VPN ندارد) اصلِ ماجراست.
 *
 * نصب:
 *   ۱) این فایل را کنار صفحه‌ی دانلود بگذارید.
 *   ۲) فایل cubevpn_config.php را بسازید (نمونه‌اش کنار همین فایل است) و
 *      توکن گیت‌هاب را داخلش بگذارید.
 *   ۳) دکمه‌ی صفحه را به همین فایل لینک کنید:  <a href="cubevpn.php">
 *
 * آدرس‌ها:
 *   cubevpn.php            دانلود آخرین نسخه
 *   cubevpn.php?info=1     JSON: نسخه، حجم، تاریخ  (برای نمایش روی دکمه)
 */

declare(strict_types=1);

const RX_OWNER          = 'cubepy';
const RX_REPO           = 'CubeVPN';
const RX_META_TTL       = 600;          // ثانیه — تا این مدت دوباره از گیت‌هاب نمی‌پرسیم
const RX_CACHE_DIR      = __DIR__ . '/.cubevpn-cache';
const RX_CACHE_MAX_DAYS = 30;           // فایل‌های قدیمی‌تر از این پاک می‌شوند
const RX_HTTP_TIMEOUT   = 25;

// ---------------------------------------------------------------- توکن
$RX_TOKEN = '';
$cfg = __DIR__ . '/cubevpn_config.php';
if (is_file($cfg)) {
    $c = require $cfg;
    if (is_array($c) && !empty($c['token'])) $RX_TOKEN = trim((string) $c['token']);
}
if ($RX_TOKEN === '' && getenv('CUBEVPN_GITHUB_TOKEN')) {
    $RX_TOKEN = trim((string) getenv('CUBEVPN_GITHUB_TOKEN'));
}

// ---------------------------------------------------------------- ابزار
function rx_fail(int $code, string $userMsg, string $logMsg = ''): void
{
    if ($logMsg !== '') error_log('[cubevpn] ' . $logMsg);
    http_response_code($code);
    if ((($_GET['info'] ?? '') === '1')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $userMsg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset=utf-8><title>CubeVPN</title>'
       . '<div style="font:16px/1.9 system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 20px;'
       . 'direction:rtl;text-align:center;color:#e8e9f3;background:#12131a">'
       . '<h2 style="color:#A9B4FF">دانلود در دسترس نیست</h2><p>' . htmlspecialchars($userMsg, ENT_QUOTES, 'UTF-8')
       . '</p><p style="opacity:.7;font-size:14px">لطفاً چند دقیقه بعد دوباره تلاش کنید.</p></div>';
    exit;
}

function rx_cache_dir(): string
{
    if (!is_dir(RX_CACHE_DIR)) {
        @mkdir(RX_CACHE_DIR, 0775, true);
        // پوشه‌ی کش نباید از وب خوانده شود.
        @file_put_contents(RX_CACHE_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents(RX_CACHE_DIR . '/index.html', '');
    }
    return RX_CACHE_DIR;
}

/** درخواست به API گیت‌هاب. ریدایرکت را عمداً دنبال نمی‌کنیم. */
function rx_gh(string $url, string $token, string $accept, bool $follow = false): array
{
    $ch = curl_init($url);
    $headers = [
        'Accept: ' . $accept,
        'User-Agent: CubeVPN-Downloader',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => RX_HTTP_TIMEOUT,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_errno($ch) ? curl_error($ch) : '';
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) return ['code' => 0, 'headers' => '', 'body' => '', 'error' => $err];
    return [
        'code'    => $code,
        'headers' => substr($raw, 0, $hlen),
        'body'    => substr($raw, $hlen),
        'error'   => $err,
    ];
}

function rx_header_value(string $rawHeaders, string $name): string
{
    foreach (preg_split('/\r?\n/', $rawHeaders) as $line) {
        if (stripos($line, $name . ':') === 0) return trim(substr($line, strlen($name) + 1));
    }
    return '';
}

/**
 * بهترین فایلِ نصبِ اندروید را از میان فایل‌های ریلیز انتخاب می‌کند.
 * جدا نگه داشته شده تا بشود مستقیم تستش کرد.
 */
function rx_pick_asset(array $assets): ?array
{
    // از PHP_INT_MIN شروع می‌کنیم نه از صفر: اگر تنها فایلِ موجود امتیازِ
    // منفی بگیرد (مثلاً فقط x86 منتشر شده باشد) باز هم باید همان را بدهیم —
    // «یک فایل نه‌چندان مناسب» بهتر از «اصلاً دانلودی نیست» است.
    $best = null; $bestScore = PHP_INT_MIN;
    foreach ($assets as $a) {
        $name = strtolower((string) ($a['name'] ?? ''));
        if ($name === '') continue;
        if (substr($name, -4) !== '.apk') continue;          // فقط اندروید
        // universal باید قاطعانه برنده باشد: صفحه نمی‌داند بازدیدکننده چه
        // گوشی‌ای دارد و فقط universal روی همه نصب می‌شود. در نسخه‌ی اول
        // arm64-v8a امتیازِ «arm64» و «v8a» را جدا می‌گرفت و از universal
        // جلو می‌زد — تست همین را گرفت.
        $score = 10;
        if (strpos($name, 'universal') !== false) {
            $score += 20;
        } elseif (strpos($name, 'arm64') !== false || strpos($name, 'v8a') !== false) {
            $score += 8;
        } elseif (strpos($name, 'armeabi') !== false || strpos($name, 'v7a') !== false) {
            $score += 4;
        }
        if (strpos($name, 'x86') !== false)   $score -= 12;  // روی گوشی به‌درد نمی‌خورد
        if (strpos($name, 'debug') !== false) $score -= 15;  // هرگز نسخه‌ی دیباگ
        if ($score > $bestScore) { $bestScore = $score; $best = $a; }
    }
    return $best;
}

/** آخرین ریلیزِ منتشرشده؛ اگر «latest» نبود، جدیدترین غیرِ پیش‌نویس. */
function rx_latest_release(string $token): ?array
{
    $r = rx_gh('https://api.github.com/repos/' . RX_OWNER . '/' . RX_REPO . '/releases/latest', $token, 'application/vnd.github+json');
    if ($r['code'] === 200) {
        $j = json_decode($r['body'], true);
        if (is_array($j) && !empty($j['assets'])) return $j;
    }
    if ($r['code'] === 401 || $r['code'] === 403) {
        rx_fail(500, 'دسترسی به مخزن برقرار نشد.', 'auth failed on /releases/latest — HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 200));
    }
    // مثلاً وقتی فقط pre-release منتشر شده
    $r = rx_gh('https://api.github.com/repos/' . RX_OWNER . '/' . RX_REPO . '/releases?per_page=20', $token, 'application/vnd.github+json');
    if ($r['code'] !== 200) {
        rx_fail(502, 'ارتباط با گیت‌هاب برقرار نشد.', 'list releases HTTP ' . $r['code'] . ' ' . $r['error'] . ' ' . substr($r['body'], 0, 200));
    }
    $list = json_decode($r['body'], true);
    if (!is_array($list)) return null;
    foreach ($list as $rel) {
        if (!empty($rel['draft'])) continue;
        if (!empty($rel['assets'])) return $rel;
    }
    return null;
}

function rx_meta(string $token): array
{
    $file = rx_cache_dir() . '/meta.json';
    if (is_file($file) && (time() - (int) filemtime($file)) < RX_META_TTL) {
        $j = json_decode((string) file_get_contents($file), true);
        if (is_array($j) && !empty($j['asset_id'])) return $j;
    }
    $rel = rx_latest_release($token);
    if (!is_array($rel)) rx_fail(404, 'هنوز نسخه‌ای برای دانلود منتشر نشده است.', 'no release with assets');
    $asset = rx_pick_asset(is_array($rel['assets'] ?? null) ? $rel['assets'] : []);
    if ($asset === null) rx_fail(404, 'فایل نصب اندروید در آخرین نسخه پیدا نشد.', 'no .apk asset in ' . ($rel['tag_name'] ?? '?'));
    $meta = [
        'version'      => (string) ($rel['tag_name'] ?? ''),
        'name'         => (string) ($asset['name'] ?? 'CubeVPN.apk'),
        'asset_id'     => (int) ($asset['id'] ?? 0),
        'size'         => (int) ($asset['size'] ?? 0),
        'published_at' => (string) ($rel['published_at'] ?? ''),
    ];
    @file_put_contents($file, json_encode($meta, JSON_UNESCAPED_UNICODE));
    return $meta;
}

/** فایل را از گیت‌هاب می‌گیرد و روی دیسک کش می‌کند. */
function rx_ensure_file(array $meta, string $token): string
{
    $path = rx_cache_dir() . '/asset-' . $meta['asset_id'] . '.apk';
    if (is_file($path) && ($meta['size'] <= 0 || filesize($path) === $meta['size'])) return $path;

    $url = 'https://api.github.com/repos/' . RX_OWNER . '/' . RX_REPO . '/releases/assets/' . $meta['asset_id'];
    // گیت‌هاب برای فایلِ ریلیز به یک آدرسِ امضاشده ریدایرکت می‌کند. آن آدرس
    // خودش امضا دارد و اگر هدرِ Authorization را هم برایش بفرستیم رد می‌کند،
    // پس ریدایرکت را دستی و بدون توکن دنبال می‌کنیم.
    $r = rx_gh($url, $token, 'application/octet-stream', false);
    if ($r['code'] >= 300 && $r['code'] < 400) {
        $loc = rx_header_value($r['headers'], 'location');
        if ($loc === '') rx_fail(502, 'دریافت فایل از گیت‌هاب ناموفق بود.', 'redirect without Location');
        $r = rx_gh($loc, '', 'application/octet-stream', true);   // بدون توکن
    }
    if ($r['code'] !== 200 || $r['body'] === '') {
        rx_fail(502, 'دریافت فایل از گیت‌هاب ناموفق بود.', 'asset download HTTP ' . $r['code'] . ' ' . $r['error']);
    }
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.part';
    if (@file_put_contents($tmp, $r['body']) === false) {
        rx_fail(500, 'ذخیره‌ی فایل روی سرور ممکن نشد.', 'cannot write ' . $tmp . ' — پوشه باید قابل نوشتن باشد');
    }
    @rename($tmp, $path);           // اتمیک: دانلودِ نیمه‌کاره هیچ‌وقت سرو نمی‌شود
    rx_prune_cache();
    return $path;
}

function rx_prune_cache(): void
{
    $cut = time() - (RX_CACHE_MAX_DAYS * 86400);
    foreach ((array) glob(rx_cache_dir() . '/asset-*.apk') as $f) {
        if (is_file($f) && filemtime($f) < $cut) @unlink($f);
    }
    foreach ((array) glob(rx_cache_dir() . '/*.part') as $f) {
        if (is_file($f) && filemtime($f) < time() - 3600) @unlink($f);
    }
}

/**
 * هدرِ Range را می‌خواند. برمی‌گرداند [start, end] یا null.
 * جدا نگه داشته شده تا بشود مستقیم تستش کرد.
 */
function rx_parse_range(?string $header, int $size): ?array
{
    if ($header === null || $size <= 0) return null;
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) return null;
    $from = $m[1]; $to = $m[2];
    if ($from === '' && $to === '') return null;
    if ($from === '') {                       // مثلاً bytes=-500 یعنی ۵۰۰ بایتِ آخر
        $len = (int) $to;
        if ($len <= 0) return null;
        $start = max(0, $size - $len);
        $end   = $size - 1;
    } else {
        $start = (int) $from;
        $end   = ($to === '') ? $size - 1 : (int) $to;
    }
    if ($start > $end || $start >= $size) return null;
    if ($end >= $size) $end = $size - 1;
    return [$start, $end];
}

// ---------------------------------------------------------------- اجرا
if ($RX_TOKEN === '') {
    rx_fail(500, 'دانلود هنوز پیکربندی نشده است.', 'no token — cubevpn_config.php را بسازید');
}

$meta = rx_meta($RX_TOKEN);

if (($_GET['info'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo json_encode([
        'ok'           => true,
        'version'      => $meta['version'],
        'file'         => $meta['name'],
        'size'         => $meta['size'],
        'size_mb'      => $meta['size'] > 0 ? round($meta['size'] / 1048576, 1) : null,
        'published_at' => $meta['published_at'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$path = rx_ensure_file($meta, $RX_TOKEN);
$size = (int) filesize($path);
$dl   = 'CubeVPN-' . preg_replace('/[^A-Za-z0-9._-]/', '', $meta['version'] ?: 'latest') . '.apk';

while (ob_get_level() > 0) ob_end_clean();
@set_time_limit(0);
ignore_user_abort(true);

header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . $dl . '"');
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

$range = rx_parse_range($_SERVER['HTTP_RANGE'] ?? null, $size);
$fh = @fopen($path, 'rb');
if ($fh === false) rx_fail(500, 'فایل روی سرور خوانده نشد.', 'fopen failed: ' . $path);

if ($range !== null) {
    [$start, $end] = $range;
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
    header('Content-Length: ' . (string) ($end - $start + 1));
    fseek($fh, $start);
    $remaining = $end - $start + 1;
} else {
    header('Content-Length: ' . (string) $size);
    $remaining = $size;
}

while ($remaining > 0 && !feof($fh)) {
    $chunk = fread($fh, (int) min(262144, $remaining));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}
fclose($fh);
