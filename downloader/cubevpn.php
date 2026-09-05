<?php
/**
 * دانلودِ آخرین نسخه‌ی CubeVPN — پراکسیِ سمتِ سرور.
 *
 * چرا لازم شد: صفحه‌ی دانلود مستقیماً از مرورگرِ بازدیدکننده به
 * api.github.com می‌زد. تا وقتی مخزن عمومی بود کار می‌کرد؛ حالا که خصوصی شده
 * هم آن درخواست ۴۰۴ می‌گیرد و هم فایلِ ریلیز بدون توکن دانلود نمی‌شود. توکن را
 * هم نمی‌شود داخل جاوااسکریپت گذاشت (هر کسی سورس صفحه را می‌بیند).
 *
 * پس درخواست از اینجا می‌رود: توکن فقط روی سرور می‌ماند، و مهم‌تر اینکه
 * بازدیدکننده فایل را از دامنه‌ی خودتان می‌گیرد نه از گیت‌هاب — که برای
 * مخاطبِ این صفحه (کسی که هنوز VPN ندارد) اصلِ ماجراست.
 *
 * آدرس‌ها:
 *   cubevpn.php                 دانلود (نسخه‌ی universal)
 *   cubevpn.php?abi=arm64       فقط arm64-v8a
 *   cubevpn.php?abi=arm         فقط armeabi-v7a
 *   cubevpn.php?info=1          JSON: نسخه، حجم و فهرست معماری‌ها
 *   cubevpn.php?diag=1&token=…  عیب‌یابی (اگر صفحه خطای ۵۰۰ داد)
 *
 * سازگاری: عمداً از هیچ قابلیتِ نسخه‌ی جدیدِ PHP استفاده نشده (نه type hint،
 * نه declare(strict_types)، نه [] برای باز کردنِ آرایه) تا روی هاست‌هایی که
 * هنوز PHP قدیمی دارند هم اجرا شود؛ همان چیزی که «خطای ۵۰۰ بدون هیچ پیام»
 * می‌سازد.
 */

define('RX_OWNER',          'cubepy');
define('RX_REPO',           'CubeVPN');
define('RX_META_TTL',       600);          // ثانیه — تا این مدت دوباره از گیت‌هاب نمی‌پرسیم
define('RX_CACHE_DIR',      dirname(__FILE__) . '/.cubevpn-cache');
define('RX_CACHE_MAX_DAYS', 30);
define('RX_HTTP_TIMEOUT',   25);

// ---------------------------------------------------------------- بوت
@ini_set('memory_limit', '256M');
@set_time_limit(0);

// هیچ خطایی نباید به صفحه‌ی سفیدِ ۵۰۰ ختم شود: پیام قابل‌فهم می‌دهیم و
// جزئیات را در error_log می‌گذاریم.
function rx_shutdown_guard()
{
    $e = error_get_last();
    if (!$e) return;
    $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array($e['type'], $fatal)) return;
    if (headers_sent()) return;
    @error_log('[cubevpn] FATAL ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
    header('Content-Type: text/plain; charset=utf-8');
    echo "خطای داخلی سرور.\n\n";
    echo "برای دیدن علت، این آدرس را باز کنید:\n";
    echo "    cubevpn.php?diag=1\n";
}
register_shutdown_function('rx_shutdown_guard');

// ---------------------------------------------------------------- توکن
$RX_TOKEN = '';
$rx_cfg_file = dirname(__FILE__) . '/cubevpn_config.php';
if (is_file($rx_cfg_file)) {
    $rx_c = include $rx_cfg_file;
    if (is_array($rx_c) && isset($rx_c['token'])) $RX_TOKEN = trim($rx_c['token']);
}
if ($RX_TOKEN === '') {
    $rx_env = getenv('CUBEVPN_GITHUB_TOKEN');
    if ($rx_env) $RX_TOKEN = trim($rx_env);
}

// ---------------------------------------------------------------- ابزار
function rx_is_json_mode()
{
    return (isset($_GET['info']) && $_GET['info'] === '1');
}

function rx_fail($code, $userMsg, $logMsg)
{
    if ($logMsg !== '') @error_log('[cubevpn] ' . $logMsg);
    if (!headers_sent()) {
        if (function_exists('http_response_code')) http_response_code($code);
        else header('HTTP/1.1 ' . $code);
    }
    if (rx_is_json_mode()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'error' => $userMsg));
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset=utf-8><title>CubeVPN</title>'
       . '<body style="margin:0;background:#12131a">'
       . '<div style="font:16px/1.9 system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 20px;'
       . 'direction:rtl;text-align:center;color:#e8e9f3">'
       . '<h2 style="color:#A9B4FF">دانلود در دسترس نیست</h2><p>' . htmlspecialchars($userMsg, ENT_QUOTES, 'UTF-8')
       . '</p><p style="opacity:.7;font-size:14px">لطفاً چند دقیقه بعد دوباره تلاش کنید.</p></div>';
    exit;
}

function rx_cache_dir()
{
    if (!is_dir(RX_CACHE_DIR)) {
        @mkdir(RX_CACHE_DIR, 0775, true);
        // هر نحو داخل IfModule خودش: «Deny from all» روی آپاچی ۲.۴ بدون
        // mod_access_compat ناشناخته است و پوشه را با ۵۰۰ از کار می‌اندازد.
        @file_put_contents(RX_CACHE_DIR . '/.htaccess',
              "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        @file_put_contents(RX_CACHE_DIR . '/index.html', '');
    }
    return RX_CACHE_DIR;
}

/** درخواست به گیت‌هاب. ریدایرکت را عمداً دنبال نمی‌کنیم. */
function rx_gh($url, $token, $accept, $follow)
{
    if (!function_exists('curl_init')) {
        return array('code' => 0, 'headers' => '', 'body' => '', 'error' => 'افزونه‌ی cURL روی این هاست فعال نیست');
    }
    $ch = curl_init($url);
    $headers = array(
        'Accept: ' . $accept,
        'User-Agent: CubeVPN-Downloader',
        'X-GitHub-Api-Version: 2022-11-28',
    );
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $follow ? true : false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, RX_HTTP_TIMEOUT);
    $raw  = curl_exec($ch);
    $err  = curl_errno($ch) ? curl_error($ch) : '';
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) return array('code' => 0, 'headers' => '', 'body' => '', 'error' => $err);
    return array(
        'code'    => $code,
        'headers' => substr($raw, 0, $hlen),
        'body'    => substr($raw, $hlen),
        'error'   => $err,
    );
}

function rx_header_value($rawHeaders, $name)
{
    $lines = preg_split('/\r?\n/', $rawHeaders);
    foreach ($lines as $line) {
        if (stripos($line, $name . ':') === 0) return trim(substr($line, strlen($name) + 1));
    }
    return '';
}

/** معماریِ یک فایل: universal / arm64 / arm / x86 / ناشناخته. */
function rx_abi_of($name)
{
    $n = strtolower($name);
    if (strpos($n, 'universal') !== false) return 'universal';
    if (strpos($n, 'arm64') !== false || strpos($n, 'v8a') !== false) return 'arm64';
    if (strpos($n, 'armeabi') !== false || strpos($n, 'v7a') !== false) return 'arm';
    if (strpos($n, 'x86') !== false) return 'x86';
    return 'unknown';
}

/**
 * امتیازِ یک فایل برای «دکمه‌ی پیش‌فرض».
 * universal باید قاطعانه برنده باشد: صفحه نمی‌داند بازدیدکننده چه گوشی‌ای
 * دارد و فقط universal روی همه نصب می‌شود.
 */
function rx_asset_score($name)
{
    $n = strtolower($name);
    $abi = rx_abi_of($n);
    $score = 10;
    if ($abi === 'universal')   $score += 20;
    elseif ($abi === 'arm64')   $score += 8;
    elseif ($abi === 'arm')     $score += 4;
    if ($abi === 'x86')         $score -= 12;   // روی گوشی به‌درد نمی‌خورد
    if (strpos($n, 'debug') !== false) $score -= 15;
    return $score;
}

/**
 * بهترین فایلِ نصب را انتخاب می‌کند. اگر $wantAbi داده شود فقط همان معماری.
 * از PHP_INT_MIN شروع می‌کنیم: اگر تنها فایلِ موجود امتیازِ منفی بگیرد
 * (مثلاً فقط x86 منتشر شده) باز هم باید همان را بدهیم — «یک فایلِ نه‌چندان
 * مناسب» بهتر از «اصلاً دانلودی نیست» است.
 */
function rx_pick_asset($assets, $wantAbi = '')
{
    $best = null;
    $bestScore = -PHP_INT_MAX;
    foreach ($assets as $a) {
        if (!isset($a['name'])) continue;
        $name = strtolower($a['name']);
        if ($name === '' || substr($name, -4) !== '.apk') continue;
        if ($wantAbi !== '' && rx_abi_of($name) !== $wantAbi) continue;
        $score = rx_asset_score($name);
        if ($score > $bestScore) { $bestScore = $score; $best = $a; }
    }
    return $best;
}

/** آخرین ریلیزِ منتشرشده؛ اگر «latest» نبود، تازه‌ترین غیرِ پیش‌نویس. */
function rx_latest_release($token)
{
    $base = 'https://api.github.com/repos/' . RX_OWNER . '/' . RX_REPO;
    $r = rx_gh($base . '/releases/latest', $token, 'application/vnd.github+json', false);
    if ($r['code'] === 200) {
        $j = json_decode($r['body'], true);
        if (is_array($j) && !empty($j['assets'])) return $j;
    }
    if ($r['code'] === 401 || $r['code'] === 403) {
        rx_fail(500, 'دسترسی به مخزن برقرار نشد.',
            'auth failed — HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 200));
    }
    if ($r['code'] === 0) {
        rx_fail(502, 'ارتباط با گیت‌هاب برقرار نشد.', 'network: ' . $r['error']);
    }
    $r = rx_gh($base . '/releases?per_page=20', $token, 'application/vnd.github+json', false);
    if ($r['code'] !== 200) {
        rx_fail(502, 'ارتباط با گیت‌هاب برقرار نشد.',
            'list releases HTTP ' . $r['code'] . ' ' . $r['error'] . ' ' . substr($r['body'], 0, 200));
    }
    $list = json_decode($r['body'], true);
    if (!is_array($list)) return null;
    foreach ($list as $rel) {
        if (!empty($rel['draft'])) continue;
        if (!empty($rel['assets'])) return $rel;
    }
    return null;
}

function rx_meta($token)
{
    $file = rx_cache_dir() . '/meta.json';
    if (is_file($file) && (time() - (int) filemtime($file)) < RX_META_TTL) {
        $j = json_decode(file_get_contents($file), true);
        if (is_array($j) && !empty($j['variants'])) return $j;
    }
    $rel = rx_latest_release($token);
    if (!is_array($rel)) rx_fail(404, 'هنوز نسخه‌ای برای دانلود منتشر نشده است.', 'no release with assets');

    $assets = isset($rel['assets']) && is_array($rel['assets']) ? $rel['assets'] : array();

    $variants = array();
    foreach (array('universal', 'arm64', 'arm') as $abi) {
        $a = rx_pick_asset($assets, $abi);
        if ($a === null) continue;
        $variants[$abi] = array(
            'name'     => isset($a['name']) ? $a['name'] : 'CubeVPN.apk',
            'asset_id' => isset($a['id']) ? (int) $a['id'] : 0,
            'size'     => isset($a['size']) ? (int) $a['size'] : 0,
        );
    }
    $default = rx_pick_asset($assets, '');
    if ($default === null) {
        rx_fail(404, 'فایل نصب اندروید در آخرین نسخه پیدا نشد.',
            'no .apk asset in ' . (isset($rel['tag_name']) ? $rel['tag_name'] : '?'));
    }
    $meta = array(
        'version'      => isset($rel['tag_name']) ? $rel['tag_name'] : '',
        'published_at' => isset($rel['published_at']) ? $rel['published_at'] : '',
        'default'      => array(
            'name'     => isset($default['name']) ? $default['name'] : 'CubeVPN.apk',
            'asset_id' => isset($default['id']) ? (int) $default['id'] : 0,
            'size'     => isset($default['size']) ? (int) $default['size'] : 0,
        ),
        'variants'     => $variants,
    );
    @file_put_contents($file, json_encode($meta));
    return $meta;
}

/** فایل را از گیت‌هاب می‌گیرد و روی دیسک کش می‌کند. */
function rx_ensure_file($variant, $token)
{
    $path = rx_cache_dir() . '/asset-' . $variant['asset_id'] . '.apk';
    if (is_file($path) && ($variant['size'] <= 0 || filesize($path) === $variant['size'])) return $path;

    $url = 'https://api.github.com/repos/' . RX_OWNER . '/' . RX_REPO
         . '/releases/assets/' . $variant['asset_id'];
    // گیت‌هاب به یک آدرسِ امضاشده ریدایرکت می‌کند. آن آدرس خودش امضا دارد و
    // اگر هدرِ Authorization را هم برایش بفرستیم ردش می‌کند، پس ریدایرکت را
    // دستی و بدون توکن دنبال می‌کنیم.
    $r = rx_gh($url, $token, 'application/octet-stream', false);
    if ($r['code'] >= 300 && $r['code'] < 400) {
        $loc = rx_header_value($r['headers'], 'location');
        if ($loc === '') rx_fail(502, 'دریافت فایل از گیت‌هاب ناموفق بود.', 'redirect without Location');
        $r = rx_gh($loc, '', 'application/octet-stream', true);
    }
    if ($r['code'] !== 200 || $r['body'] === '') {
        rx_fail(502, 'دریافت فایل از گیت‌هاب ناموفق بود.',
            'asset download HTTP ' . $r['code'] . ' ' . $r['error']);
    }
    $tmp = $path . '.' . substr(md5(uniqid('', true)), 0, 8) . '.part';
    if (@file_put_contents($tmp, $r['body']) === false) {
        rx_fail(500, 'ذخیره‌ی فایل روی سرور ممکن نشد.',
            'cannot write ' . $tmp . ' — پوشه باید قابل نوشتن باشد');
    }
    @rename($tmp, $path);          // اتمیک: دانلودِ نیمه‌کاره هیچ‌وقت سرو نمی‌شود
    rx_prune_cache();
    return $path;
}

function rx_prune_cache()
{
    $cut = time() - (RX_CACHE_MAX_DAYS * 86400);
    $old = glob(rx_cache_dir() . '/asset-*.apk');
    if (is_array($old)) {
        foreach ($old as $f) { if (is_file($f) && filemtime($f) < $cut) @unlink($f); }
    }
    $parts = glob(rx_cache_dir() . '/*.part');
    if (is_array($parts)) {
        foreach ($parts as $f) { if (is_file($f) && filemtime($f) < time() - 3600) @unlink($f); }
    }
}

/** هدرِ Range را می‌خواند. برمی‌گرداند array(start, end) یا null. */
function rx_parse_range($header, $size)
{
    if ($header === null || $header === '' || $size <= 0) return null;
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) return null;
    $from = $m[1]; $to = $m[2];
    if ($from === '' && $to === '') return null;
    if ($from === '') {                        // bytes=-500 یعنی ۵۰۰ بایتِ آخر
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
    return array($start, $end);
}

// ---------------------------------------------------------------- عیب‌یابی
if (isset($_GET['diag']) && $_GET['diag'] === '1') {
    @ini_set('display_errors', 1);
    @error_reporting(E_ALL);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CubeVPN downloader — عیب‌یابی\n";
    echo str_repeat('=', 46) . "\n";
    echo "نسخه‌ی PHP        : " . PHP_VERSION . "\n";
    echo "cURL              : " . (function_exists('curl_init') ? 'فعال' : '❌ غیرفعال — بدون این کار نمی‌کند') . "\n";
    echo "allow_url_fopen   : " . (ini_get('allow_url_fopen') ? 'روشن' : 'خاموش (مهم نیست)') . "\n";
    echo "فایل تنظیمات      : " . (is_file($rx_cfg_file) ? 'هست' : '❌ نیست — cubevpn_config.php را بسازید') . "\n";
    echo "توکن              : " . ($RX_TOKEN !== '' ? 'تنظیم شده (' . strlen($RX_TOKEN) . ' کاراکتر)' : '❌ خالی') . "\n";
    $d = @rx_cache_dir();
    echo "پوشه‌ی کش         : " . (is_dir($d) ? $d : '❌ ساخته نشد') . "\n";
    echo "قابل نوشتن        : " . (is_dir($d) && is_writable($d) ? 'بله' : '❌ خیر — دسترسی ۷۵۵ یا ۷۷۵ بدهید') . "\n";
    if ($RX_TOKEN !== '' && function_exists('curl_init')) {
        $r = rx_gh('https://api.github.com/repos/' . RX_OWNER . '/' . RX_REPO . '/releases/latest',
                   $RX_TOKEN, 'application/vnd.github+json', false);
        echo "پاسخ گیت‌هاب      : HTTP " . $r['code'] . ($r['error'] !== '' ? ' — ' . $r['error'] : '') . "\n";
        if ($r['code'] === 200) {
            $j = json_decode($r['body'], true);
            echo "نسخه              : " . (isset($j['tag_name']) ? $j['tag_name'] : '?') . "\n";
            echo "فایل‌های ریلیز    :\n";
            if (!empty($j['assets'])) {
                foreach ($j['assets'] as $a) {
                    printf("   %-46s %6.1f MB   [%s]\n",
                        $a['name'], $a['size'] / 1048576, rx_abi_of($a['name']));
                }
                $p = rx_pick_asset($j['assets'], '');
                echo "انتخابِ دکمه       : " . ($p ? $p['name'] : '❌ هیچ فایل apk نیست') . "\n";
            } else {
                echo "   ❌ هیچ فایلی به ریلیز پیوست نشده\n";
            }
        } elseif ($r['code'] === 401 || $r['code'] === 403) {
            echo "→ توکن نامعتبر است یا به این مخزن دسترسی ندارد.\n";
        } elseif ($r['code'] === 404) {
            echo "→ مخزن یا ریلیز پیدا نشد (نام مخزن را چک کنید).\n";
        }
    }
    exit;
}

// ---------------------------------------------------------------- اجرا
if ($RX_TOKEN === '') {
    rx_fail(500, 'دانلود هنوز پیکربندی نشده است.',
        'no token — cubevpn_config.php را بسازید یا cubevpn.php?diag=1 را باز کنید');
}

$meta = rx_meta($RX_TOKEN);

if (rx_is_json_mode()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    $out = array(
        'ok'           => true,
        'version'      => $meta['version'],
        'file'         => $meta['default']['name'],
        'size'         => $meta['default']['size'],
        'size_mb'      => $meta['default']['size'] > 0 ? round($meta['default']['size'] / 1048576, 1) : null,
        'published_at' => $meta['published_at'],
        'variants'     => array(),
    );
    foreach ($meta['variants'] as $abi => $v) {
        $out['variants'][$abi] = array(
            'name'    => $v['name'],
            'size_mb' => $v['size'] > 0 ? round($v['size'] / 1048576, 1) : null,
        );
    }
    echo json_encode($out);
    exit;
}

// کدام معماری؟
$abi = isset($_GET['abi']) ? strtolower(trim($_GET['abi'])) : '';
if ($abi !== '' && isset($meta['variants'][$abi])) {
    $variant = $meta['variants'][$abi];
} else {
    $variant = $meta['default'];      // درخواستِ نامعتبر → همان پیش‌فرضِ امن
}

$path = rx_ensure_file($variant, $RX_TOKEN);
$size = (int) filesize($path);

$ver = preg_replace('/[^A-Za-z0-9._-]/', '', $meta['version'] !== '' ? $meta['version'] : 'latest');
$dl  = 'CubeVPN-' . $ver . ($abi !== '' && isset($meta['variants'][$abi]) ? '-' . $abi : '') . '.apk';

while (ob_get_level() > 0) ob_end_clean();
ignore_user_abort(true);

header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . $dl . '"');
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

$range = rx_parse_range(isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null, $size);
$fh = @fopen($path, 'rb');
if ($fh === false) rx_fail(500, 'فایل روی سرور خوانده نشد.', 'fopen failed: ' . $path);

if ($range !== null) {
    $start = $range[0];
    $end   = $range[1];
    if (function_exists('http_response_code')) http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    header('Content-Length: ' . ($end - $start + 1));
    fseek($fh, $start);
    $remaining = $end - $start + 1;
} else {
    header('Content-Length: ' . $size);
    $remaining = $size;
}

while ($remaining > 0 && !feof($fh)) {
    $chunk = fread($fh, min(262144, $remaining));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}
fclose($fh);
