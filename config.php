<?php

$dbname     = '';
$usernamedb = '';
$passworddb = '';


$connect = null;
$pdo     = null;
$dsn     = '';
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
];

if ($dbname !== '' && $usernamedb !== '') {
    if (function_exists('mysqli_report')) {
        @mysqli_report(MYSQLI_REPORT_OFF);
    }
    // Retry several times, with exponentially increasing waits, on a
    // transient "too many connections" (1040) error before giving up — the
    // cron dispatcher fires several worker scripts in parallel every minute,
    // and now CubePay's own callbacks add extra momentary connection load
    // too, so brief spikes against a low shared-hosting connection cap are
    // common. Worst case here is ~6.2s total, safely under CubePay's own
    // 10s callback timeout.
    $rxMysqliMaxAttempts = 6;
    $rxMysqliWaitMs = 200;
    for ($rxMysqliAttempt = 1; $rxMysqliAttempt <= $rxMysqliMaxAttempts; $rxMysqliAttempt++) {
        try {
            $connect = @mysqli_connect('localhost', $usernamedb, $passworddb, $dbname);
        } catch (\Throwable $rxMysqliConnectError) {
            $connect = null;
        }
        if ($connect instanceof mysqli) {
            break;
        }
        $rxMysqliErrno = function_exists('mysqli_connect_errno') ? mysqli_connect_errno() : 0;
        if ($rxMysqliErrno === 1040 && $rxMysqliAttempt < $rxMysqliMaxAttempts) {
            usleep($rxMysqliWaitMs * 1000);
            $rxMysqliWaitMs *= 2;
            continue;
        }
        break;
    }
    if (!($connect instanceof mysqli)) {
        error_log('config.php mysqli_connect failed: ' . (function_exists('mysqli_connect_error') ? mysqli_connect_error() : 'unknown error'));
    }
    if ($connect instanceof mysqli) {
        @mysqli_set_charset($connect, 'utf8mb4');
    } else {
        $connect = null;
    }

    $dsn = 'mysql:host=localhost;dbname=' . $dbname . ';charset=utf8mb4';
    $rxPdoMaxAttempts = 6;
    $rxPdoWaitMs = 200;
    for ($rxPdoAttempt = 1; $rxPdoAttempt <= $rxPdoMaxAttempts; $rxPdoAttempt++) {
        try {
            $pdo = new PDO($dsn, $usernamedb, $passworddb, $options);
            break;
        } catch (\PDOException $rxPdoError) {
            $pdo = null;
            $rxIsTooMany = (strpos($rxPdoError->getMessage(), '1040') !== false)
                || (stripos($rxPdoError->getMessage(), 'too many connections') !== false);
            if ($rxIsTooMany && $rxPdoAttempt < $rxPdoMaxAttempts) {
                usleep($rxPdoWaitMs * 1000);
                $rxPdoWaitMs *= 2;
                continue;
            }
            error_log('config.php PDO connection failed: ' . $rxPdoError->getMessage());
            break;
        }
    }
} else {
    $rxInstallerPending = is_file(__DIR__ . DIRECTORY_SEPARATOR . 'installer' . DIRECTORY_SEPARATOR . 'index.php');
    if (!$rxInstallerPending) {
        $rxConfigEmptyMarker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rx_config_empty.flag';
        if (!is_file($rxConfigEmptyMarker) || (time() - (int) @filemtime($rxConfigEmptyMarker)) > 3600) {
            error_log('config.php: database credentials are empty — fill $dbname/$usernamedb/$passworddb to enable DB-backed features.');
            @touch($rxConfigEmptyMarker);
        }
        unset($rxConfigEmptyMarker);
    }
    unset($rxInstallerPending);
}

$APIKEY                     = '8647796749:AAG0F0Br01vhyvMc6bY1cdquLHj-u3zTir0';
$adminnumber                = '7580961460';
$domainhosts                = 'alirezapc.ir/Foxima/Faoxima-0.0.2';
$usernamebot                = 'Cubevvpn_bot';
$telegramCurlTimeout        = 10;
$telegramStrictIpValidation = true;
$domainhosts                = rtrim(preg_replace('#^https?://#', '', $domainhosts), '/');


if (!defined('APP_ORIGIN') && $domainhosts !== '') {
    define('APP_ORIGIN', 'https://' . $domainhosts);
}


$GLOBALS['dbname']                     = $dbname;
$GLOBALS['usernamedb']                 = $usernamedb;
$GLOBALS['passworddb']                 = $passworddb;
$GLOBALS['dsn']                        = $dsn;
$GLOBALS['options']                    = $options;
$GLOBALS['pdo']                        = $pdo;
$GLOBALS['connect']                    = $connect;
$GLOBALS['APIKEY']                     = $APIKEY;
$GLOBALS['adminnumber']                = $adminnumber;
$GLOBALS['domainhosts']                = $domainhosts;
$GLOBALS['usernamebot']                = $usernamebot;
$GLOBALS['telegramCurlTimeout']        = $telegramCurlTimeout;
$GLOBALS['telegramStrictIpValidation'] = $telegramStrictIpValidation;

require_once __DIR__ . '/proxy.php';
