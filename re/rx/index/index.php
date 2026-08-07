<?php
require_once dirname(__DIR__, 2) . '/_error_log.php';
if (!defined('REFACTORED_LEGACY_ROOT')) {
    define('REFACTORED_LEGACY_ROOT', dirname(__DIR__, 3));
}
@chdir(REFACTORED_LEGACY_ROOT);

$__rx_parts = require __DIR__ . '/manifest.php';
$__rx_code = '';
foreach ($__rx_parts as $__rx_part) {
    $__rx_path = __DIR__ . DIRECTORY_SEPARATOR . $__rx_part;
    if (!is_file($__rx_path)) {
        rx_log_event('RX_MISSING_PART', $__rx_path, ['module' => basename(__DIR__)]);
        throw new RuntimeException('Missing refactored part: ' . $__rx_path);
    }
    $__rx_raw = file_get_contents($__rx_path);
    $__rx_raw = preg_replace('/^<\?php(\r\n|\r|\n)/', '', $__rx_raw, 1);
    $__rx_code .= $__rx_raw;
}
unset($__rx_parts, $__rx_part, $__rx_path, $__rx_raw);

try {
    eval($__rx_code);
} catch (Throwable $__rx_throwable) {
    rx_log_event('RX_EVAL_THROWABLE', $__rx_throwable->getMessage(), [
        'class' => get_class($__rx_throwable),
        'file'  => $__rx_throwable->getFile(),
        'line'  => $__rx_throwable->getLine(),
    ]);
    // Never leave the user in dead silence when a handler crashes mid-flow
    // (e.g. after they sent a payment receipt): eval() shares this scope, so
    // $from_id/$callback_query_id from botapi.php are visible here. Sending a
    // message only needs the bot token from config.php, not the DB.
    try {
        if (function_exists('telegram') && !empty($callback_query_id)) {
            telegram('answerCallbackQuery', [
                'callback_query_id' => $callback_query_id,
                'text' => "⚠️ خطای موقتی رخ داد، لطفاً چند لحظه دیگر دوباره تلاش کنید.",
                'show_alert' => true,
                'cache_time' => 3,
            ]);
        } elseif (function_exists('sendmessage') && isset($from_id) && intval($from_id) != 0 && empty($is_bot) && (($Chat_type ?? 'private') === 'private')) {
            sendmessage($from_id, "⚠️ خطای موقتی در پردازش درخواست شما رخ داد، لطفاً چند لحظه دیگر دوباره تلاش کنید.\n\n📌 در صورت تکرار با پشتیبانی در ارتباط باشید.", null, 'HTML');
        }
    } catch (Throwable $__rx_notify_error) {
    }
    throw $__rx_throwable;
}
unset($__rx_code);
