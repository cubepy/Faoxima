<?php
require_once 'config.php';
require_once 'function.php';
require_once 'request.php';
ini_set('error_log', 'error_log');

if (!defined('GUARD_CORE_BASE_URL')) {
    define('GUARD_CORE_BASE_URL', 'https://core.erfjab.com');
}

function guardGetBaseUrl($panelUrl = null, $version = 1)
{
    $normalizedUrl = trim((string) $panelUrl);
    if ($normalizedUrl === '' || $normalizedUrl === '/') {
        // گاردv2 روی سرورِ خودِ فروشنده بالا می‌آید و آدرسِ ثابتِ عمومی ندارد،
        // پس هرگز نباید بی‌صدا به آدرسِ core نسخه‌ی یک برگردد.
        return ((int) $version === 2) ? '' : GUARD_CORE_BASE_URL;
    }
    return rtrim($normalizedUrl, '/');
}

/**
 * نسخه‌ی گارد را از ستون version_panel می‌خواند: '2' یعنی «گاردv2» (GoGuard).
 * از همان الگویی استفاده می‌کنیم که پاسارگاد دارد (type می‌ماند و نسخه در
 * ستونِ جدا می‌نشیند)، چون اضافه‌کردنِ یک رشته‌ی type دوم یعنی ده‌ها شاخه‌ی
 * جدید در panels.php که فراموش‌کردنِ یکی‌شان تمدید را بی‌صدا خراب می‌کند.
 */
function guardPanelVersion($panel): int
{
    if (is_array($panel)) {
        if (isset($panel['version']) && is_numeric($panel['version'])) {
            return ((int) $panel['version'] === 2) ? 2 : 1;
        }
        if (isset($panel['panel']) && is_array($panel['panel'])) {
            $panel = $panel['panel'];
        }
        return ((string) ($panel['version_panel'] ?? '1') === '2') ? 2 : 1;
    }
    return 1;
}

function guardIsV2($panelConfig): bool
{
    return guardPanelVersion($panelConfig) === 2;
}

/** نامِ نمایشیِ نوع پنل برای پیام‌های ادمین. */
function guardTypeLabel($panelOrVersion): string
{
    $v = is_int($panelOrVersion) ? $panelOrVersion : guardPanelVersion($panelOrVersion);
    return $v === 2 ? 'گاردv2' : 'Guard (GuardCore)';
}

function getGuardPanelConfig($namePanel)
{
    $panel = select("marzban_panel", "*", "name_panel", $namePanel, "select");
    if (!$panel || !is_array($panel) || ($panel['type'] ?? null) !== "guard") {
        return [
            'status' => false,
            'msg' => 'Guard panel not found'
        ];
    }
    if (empty($panel['api_key']) && empty($panel['password_panel'])) {
        return [
            'status' => false,
            'msg' => 'API key is not configured for this Guard panel'
        ];
    }

    $version = guardPanelVersion($panel);
    $normalizedUrl = guardGetBaseUrl($panel['url_panel'] ?? null, $version);
    if ($version === 2 && $normalizedUrl === '') {
        return [
            'status' => false,
            'msg' => 'آدرس پنل گاردv2 تنظیم نشده است'
        ];
    }
    if (($panel['url_panel'] ?? '') !== $normalizedUrl) {
        update("marzban_panel", "url_panel", $normalizedUrl, "id", $panel['id']);
    }

    return [
        'status' => true,
        'version' => $version,
        'panel' => array_merge($panel, ['url_panel' => $normalizedUrl]),
        'api_key' => !empty($panel['api_key']) ? $panel['api_key'] : $panel['password_panel']
    ];
}

function guardApiRequest(array $panelConfig, string $method, string $endpoint, $payload = null, bool $asJson = true)
{
    $panel = $panelConfig['panel'];
    $apiKey = $panelConfig['api_key'];
    $baseUrl = guardGetBaseUrl($panel['url_panel'] ?? null, guardPanelVersion($panelConfig));
    $url = rtrim($baseUrl, '/') . $endpoint;
    $request = new CurlRequest($url);
    $headers = [
        'accept: application/json',
        'X-API-Key: ' . $apiKey,
    ];
    if ($payload !== null && $asJson) {
        $headers[] = 'Content-Type: application/json';
    }
    $request->setHeaders($headers);

    if ($asJson && $payload !== null && is_array($payload)) {
        $payload = json_encode($payload);
    }

    switch (strtoupper($method)) {
        case 'POST':
            return $request->post($payload);
        case 'PUT':
            return $request->put($payload);
        case 'PATCH':
            return $request->PATCH($payload);
        case 'DELETE':
            return $request->delete($payload);
        default:
            return $request->get();
    }
}

function guardDecodeResponse(array $response)
{
    if (!empty($response['error'])) {
        return [
            'status' => false,
            'msg' => $response['error']
        ];
    }

    $statusCode = $response['status'] ?? null;
    $decodedBody = [];
    if (!empty($response['body'])) {
        $decoded = json_decode($response['body'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $decodedBody = $decoded;
        }
    }

    if ($statusCode !== null && $statusCode >= 400) {
        $message = '';
        if (is_array($decodedBody) && isset($decodedBody['message'])) {
            $message = $decodedBody['message'];
        } elseif (is_array($decodedBody) && isset($decodedBody['detail'])) {
            $detail = $decodedBody['detail'];
            $message = is_array($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : $detail;
        } else {
            $message = $statusCode;
        }
        return [
            'status' => false,
            'msg' => $message
        ];
    }

    return [
        'status' => true,
        'data' => $decodedBody,
        'raw' => $response
    ];
}

/**
 * پاسخِ گاردv2 را به همان شکلی درمی‌آورد که نسخه‌ی یک می‌داد، تا هیچ‌کدام از
 * ده‌ها شاخه‌ی type == "guard" در panels.php لازم نباشد تغییر کند:
 *   services         → service_ids
 *   subscription_link → subscription_url
 *   total_usage/reset_usage → current_usage
 *   enabled / limit_expire / limit_usage → expired و limited
 * وضعیت را از فیلدهایی می‌سازیم که معنایشان قطعی است (enabled و دو سقف)، نه از
 * expired_at/limited_at که ممکن است «زمانِ رویداد» باشند نه «آیا رخ داده».
 */
function guardV2NormalizeSubscription(array $subscription): array
{
    if (isset($subscription['services']) && is_array($subscription['services'])
        && !isset($subscription['service_ids'])) {
        $subscription['service_ids'] = array_values(array_map('intval', $subscription['services']));
    }
    if (!empty($subscription['subscription_link']) && empty($subscription['subscription_url'])) {
        $subscription['subscription_url'] = (string) $subscription['subscription_link'];
    }

    $total = isset($subscription['total_usage']) ? (int) $subscription['total_usage'] : null;
    $reset = isset($subscription['reset_usage']) ? (int) $subscription['reset_usage'] : 0;
    if ($total !== null && !isset($subscription['current_usage'])) {
        $current = $total - $reset;
        $subscription['current_usage'] = $current > 0 ? $current : 0;
    }

    $limitExpire = isset($subscription['limit_expire']) ? (int) $subscription['limit_expire'] : 0;
    $limitUsage  = isset($subscription['limit_usage'])  ? (int) $subscription['limit_usage']  : 0;
    $used        = isset($subscription['current_usage']) ? (int) $subscription['current_usage'] : 0;

    if (!isset($subscription['expired'])) {
        $subscription['expired'] = ($limitExpire > 0 && $limitExpire <= time());
    }
    if (!isset($subscription['limited'])) {
        $subscription['limited'] = ($limitUsage > 0 && $used >= $limitUsage);
    }
    if (!isset($subscription['online_at']) && isset($subscription['last_online_at'])) {
        $subscription['online_at'] = $subscription['last_online_at'];
    }
    return $subscription;
}

function guardNormalizeSubscriptionEntry(array $panelConfig, array $subscription)
{
    if (guardIsV2($panelConfig)) {
        $subscription = guardV2NormalizeSubscription($subscription);
    }
    $baseUrl = guardGetBaseUrl($panelConfig['panel']['url_panel'] ?? null, guardPanelVersion($panelConfig));

    $tag = isset($subscription['tag']) ? trim((string) $subscription['tag']) : '';
    $accessKey = isset($subscription['access_key']) ? trim((string) $subscription['access_key']) : '';
    if ($accessKey === '' && isset($subscription['access_key_secret'])) {
        $accessKey = trim((string) $subscription['access_key_secret']);
    }

    $existingUrl = '';
    foreach (['subscription_url', 'subscription', 'link'] as $key) {
        if (!empty($subscription[$key])) {
            $existingUrl = trim((string) $subscription[$key]);
            break;
        }
    }

    $normalizedExisting = '';
    if ($existingUrl !== '') {
        if (preg_match('/^https?:\/\//i', $existingUrl)) {
            $normalizedExisting = $existingUrl;
        } else {
            $normalizedExisting = rtrim($baseUrl, '/') . '/' . ltrim($existingUrl, '/');
        }
    }

    $computedUrl = '';
    if ($tag !== '' && $accessKey !== '') {
        $computedUrl = rtrim($baseUrl, '/') . '/' . rawurlencode($tag) . '/' . rawurlencode($accessKey);
    }

    $finalUrl = $normalizedExisting !== '' ? $normalizedExisting : $computedUrl;
    if ($finalUrl !== '') {
        $subscription['subscription_url'] = $finalUrl;
        $subscription['subscription'] = $finalUrl;
    }

    return $subscription;
}

function guardNormalizeSubscriptionItems(array $panelConfig, $data)
{
    if (!is_array($data)) {
        return $data;
    }

    $isSubscription = isset($data['subscription']) || isset($data['subscription_url']) || isset($data['tag']) || isset($data['access_key']) || isset($data['link']) || isset($data['subscription_link']);
    if ($isSubscription) {
        return guardNormalizeSubscriptionEntry($panelConfig, $data);
    }

    foreach (['data', 'subscription', 'subscriptions', 'items'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            $data[$key] = guardNormalizeSubscriptionItems($panelConfig, $data[$key]);
        }
    }

    $isList = array_keys($data) === range(0, count($data) - 1);
    if ($isList) {
        foreach ($data as $index => $item) {
            if (is_array($item)) {
                $data[$index] = guardNormalizeSubscriptionItems($panelConfig, $item);
            }
        }
    }

    return $data;
}

function guardExtractAdminName($adminData)
{
    if (!is_array($adminData)) {
        return '';
    }
    foreach (['name', 'username', 'email', 'id'] as $key) {
        if (!empty($adminData[$key])) {
            return (string) $adminData[$key];
        }
    }
    return '';
}

function guardTestConnection($baseUrl, $apiKey, $version = 1)
{
    $apiKey = trim((string) $apiKey);
    $version = ((int) $version === 2) ? 2 : 1;
    $normalizedBaseUrl = guardGetBaseUrl($baseUrl, $version);

    if ($apiKey === '') {
        return [
            'status' => false,
            'msg' => 'Guard API key is missing'
        ];
    }
    if ($version === 2 && $normalizedBaseUrl === '') {
        return [
            'status' => false,
            'msg' => 'آدرس پنل گاردv2 وارد نشده است'
        ];
    }

    $panelConfig = [
        'status' => true,
        'version' => $version,
        'panel' => [
            'type' => 'guard',
            'url_panel' => $normalizedBaseUrl,
            'api_key' => $apiKey,
            'password_panel' => null,
            'version_panel' => (string) $version,
        ],
        'api_key' => $apiKey,
    ];

    $response = guardApiRequest($panelConfig, 'GET', '/api/admins/current');
    $decoded = guardDecodeResponse($response);

    if ($decoded['status'] === false) {
        $message = $decoded['msg'] ?? 'Unable to connect to Guard';
        if (!empty($response['status'])) {
            $message .= " (HTTP {$response['status']})";
        }
        return [
            'status' => false,
            'msg' => $message,
            'response' => $response
        ];
    }

    return [
        'status' => true,
        'msg' => 'Guard connection succeeded',
        'data' => $decoded['data'],
        'response' => $response,
        'panel_config' => $panelConfig,
    ];
}

function guardGetServices($namePanelOrConfig)
{
    if (is_array($namePanelOrConfig)) {
        $config = $namePanelOrConfig;
    } else {
        $config = getGuardPanelConfig($namePanelOrConfig);
    }
    if ($config['status'] === false) {
        return $config;
    }
    $endpoint = guardIsV2($config) ? '/api/services?page=1&limit=200' : '/api/services';
    $response = guardApiRequest($config, 'GET', $endpoint);
    $decoded = guardDecodeResponse($response);
    if ($decoded['status'] === false) {
        return $decoded;
    }
    $services = $decoded['data'];
    // گاردv2 لیست را صفحه‌بندی‌شده می‌دهد: {items:[...], page, limit, total}
    if (isset($services['items']) && is_array($services['items'])) {
        $services = $services['items'];
    } elseif (isset($services['services']) && is_array($services['services'])) {
        $services = $services['services'];
    }
    if (!is_array($services)) {
        return [
            'status' => false,
            'msg' => 'Invalid services response from Guard'
        ];
    }
    return [
        'status' => true,
        'services' => $services
    ];
}

function guardServiceLabel(array $service)
{
    $idValue = isset($service['id']) ? $service['id'] : '?';
    $id = is_numeric($idValue) ? intval($idValue) : $idValue;

    foreach (['remark', 'name', 'title'] as $key) {
        if (!empty($service[$key]) && is_string($service[$key])) {
            $label = trim($service[$key]);
            if ($label !== '') {
                return $label;
            }
        }
    }

    return "service-{$id}";
}

function guardNormalizeExpire($timestamp)
{
    $timestamp = intval($timestamp);
    if ($timestamp <= 0) {
        return time() + (86400 * 365 * 10);
    }
    if ($timestamp <= time()) {
        return time() + 300;
    }
    return $timestamp;
}

function guardParseServiceIds($serviceValue)
{
    if (is_array($serviceValue)) {
        return $serviceValue;
    }
    if ($serviceValue === null || $serviceValue === '' || $serviceValue === false) {
        return [];
    }
    if (is_string($serviceValue)) {
        $serviceValue = trim($serviceValue);
        if (in_array(strtolower($serviceValue), ['all', '0'], true)) {
            return ['all'];
        }
        $decoded = json_decode($serviceValue, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        if (strpos($serviceValue, ',') !== false) {
            $parts = array_map('trim', explode(',', $serviceValue));
            $ids = [];
            foreach ($parts as $part) {
                if (ctype_digit($part)) {
                    $ids[] = intval($part);
                }
            }
            return $ids;
        }
        if (ctype_digit($serviceValue)) {
            return [intval($serviceValue)];
        }
    }
    return [];
}

function guardExtractServiceIdsFromList(array $services)
{
    $serviceIds = [];
    foreach ($services as $service) {
        if (isset($service['id'])) {
            $serviceIds[] = intval($service['id']);
        }
    }
    return $serviceIds;
}

function guardResolveServiceIds($namePanel, $serviceValue = null)
{
    $parsedServices = guardParseServiceIds($serviceValue);
    $needsAll = empty($parsedServices) || in_array('all', $parsedServices, true) || in_array(0, $parsedServices, true);
    if ($needsAll) {
        $servicesResponse = guardGetServices($namePanel);
        if ($servicesResponse['status'] === false) {
            return $servicesResponse;
        }
        $services = $servicesResponse['services'];
        $serviceIds = [];
        foreach ($services as $service) {
            if (isset($service['id'])) {
                $serviceIds[] = intval($service['id']);
            }
        }
        if (empty($serviceIds)) {
            return [
                'status' => false,
                'msg' => 'No services available on Guard panel'
            ];
        }
        return [
            'status' => true,
            'service_ids' => $serviceIds
        ];
    }

    $serviceIds = array_values(array_unique(array_map('intval', $parsedServices)));
    if (empty($serviceIds)) {
        return [
            'status' => false,
            'msg' => 'No valid service id provided'
        ];
    }

    return [
        'status' => true,
        'service_ids' => $serviceIds
    ];
}

function guardDecodeAutoRenewalsConfig($value)
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $value = $decoded;
        }
    }
    if (!is_array($value)) {
        return [];
    }
    $isList = array_keys($value) === range(0, count($value) - 1);
    if (!$isList) {
        $value = [$value];
    }
    $normalized = [];
    foreach ($value as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $normalized[] = [
            'expire_days' => isset($entry['expire_days']) ? intval($entry['expire_days']) : 0,
            'usage_gb' => isset($entry['usage_gb']) ? floatval($entry['usage_gb']) : 0,
            'reset_usage' => !empty($entry['reset_usage']) || (!empty($entry['reset']) && $entry['reset'] === true),
        ];
    }
    return $normalized;
}

function guardBuildAutoRenewalsPayload(array $entries)
{
    $payload = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $expireDays = max(0, intval($entry['expire_days'] ?? 0));
        $usageGb = isset($entry['usage_gb']) ? floatval($entry['usage_gb']) : 0;
        $resetUsage = !empty($entry['reset_usage']);

        $limitExpire = 0;
        if ($expireDays > 0) {
            $limitExpire = guardNormalizeExpire(time() + ($expireDays * 86400));
        }
        $limitUsage = $usageGb > 0 ? intval(round($usageGb * 1024 * 1024 * 1024)) : 0;

        $payload[] = [
            "limit_expire" => $limitExpire,
            "limit_usage" => $limitUsage,
            "reset_usage" => $resetUsage
        ];
    }
    return $payload;
}

function guardParseAutoRenewalsInput($input)
{
    $input = trim((string) $input);
    if ($input === '' || $input === '-' || $input === '0') {
        return [
            'status' => true,
            'entries' => []
        ];
    }

    $lines = preg_split('/[\n;]+/', $input);
    $entries = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode(',', $line));
        if (count($parts) !== 3) {
            return [
                'status' => false,
                'msg' => 'Invalid auto renewal format'
            ];
        }
        [$expireDays, $usageGb, $resetFlag] = $parts;
        if ($expireDays === '' || !is_numeric($expireDays) || $usageGb === '' || !is_numeric($usageGb)) {
            return [
                'status' => false,
                'msg' => 'Invalid numbers in auto renewal'
            ];
        }
        $resetUsage = in_array(strtolower($resetFlag), ['1', 'true', 'yes'], true);
        $entries[] = [
            'expire_days' => intval($expireDays),
            'usage_gb' => floatval($usageGb),
            'reset_usage' => $resetUsage,
        ];
    }

    return [
        'status' => true,
        'entries' => $entries,
    ];
}

/**
 * بدنه‌ی درخواست را به قالبِ گاردv2 ترجمه می‌کند.
 *
 * تفاوت‌های واقعی با نسخه‌ی یک:
 *   - نامِ فیلدِ سرویس‌ها service_ids نیست، services است.
 *   - فیلدهای auto_delete_days / auto_renewals / discord_webhook_url اصلاً
 *     وجود ندارند؛ فرستادنشان یعنی ۴۰۰ گرفتن از پنل.
 * هر کلیدی که در مشخصاتِ v2 نیست حذف می‌شود — لیستِ سفید، نه لیستِ سیاه، تا
 * فیلدِ ناشناخته‌ای که بعداً جایی اضافه شود بی‌صدا درخواست را خراب نکند.
 */
function guardV2SubscriptionPayload(array $payload): array
{
    static $allowed = [
        'username', 'usernames', 'access_key', 'email', 'enabled',
        'expiry_grace_days', 'limit_expire', 'limit_usage', 'limited_grace_days',
        'note', 'on_hold_timeout_days', 'owner_id', 'phone', 'services', 'telegram_id',
    ];

    if (!isset($payload['services'])) {
        $services = null;
        if (isset($payload['service_ids'])) {
            $services = $payload['service_ids'];
        }
        if (is_array($services)) {
            $payload['services'] = array_values(array_unique(array_map('intval', $services)));
        }
    }

    $out = array();
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $payload)) continue;
        $value = $payload[$key];
        // فیلدهای اختیاریِ خالی را اصلاً نمی‌فرستیم؛ v2 برای telegram_id و
        // phone و note رشته می‌خواهد و null را رد می‌کند.
        if ($value === null && !in_array($key, ['username', 'usernames', 'services'], true)) {
            continue;
        }
        $out[$key] = $value;
    }
    return $out;
}

function guardCreateSubscription($namePanel, array $payload)
{
    $config = getGuardPanelConfig($namePanel);
    if ($config['status'] === false) {
        return $config;
    }

    if (isset($payload['username'])) {
        $payload = [$payload];
    }

    foreach ($payload as &$subscription) {
        if (isset($subscription['limit_expire'])) {
            $limitExpire = $subscription['limit_expire'];
            if (is_numeric($limitExpire)) {
                $limitExpire = intval($limitExpire);
                if ($limitExpire > 0 && $limitExpire < 315576000) {
                    $limitExpire = time() + ($limitExpire * 86400);
                }
            }
            $subscription['limit_expire'] = guardNormalizeExpire($limitExpire);
        }

        $parsedServices = guardParseServiceIds($subscription['service_ids'] ?? null);
        if (empty($parsedServices) || in_array('all', $parsedServices, true) || in_array(0, $parsedServices, true)) {
            $serviceResult = guardResolveServiceIds($namePanel, $parsedServices);
            if ($serviceResult['status'] === false) {
                return $serviceResult;
            }
            $subscription['service_ids'] = $serviceResult['service_ids'];
        } else {
            $subscription['service_ids'] = array_values(array_unique(array_map('intval', $parsedServices)));
        }
    }
    unset($subscription);

    // Force-disable auto renewals regardless of caller payload.
    foreach ($payload as &$subscription) {
        if (is_array($subscription)) {
            unset($subscription['auto_renewals']);
            unset($subscription['auto_renew']);
            unset($subscription['renewals']);
            unset($subscription['renew']);
            unset($subscription['autoRenewals']);
        }
    }
    unset($subscription);

    if (guardIsV2($config)) {
        foreach ($payload as &$subscription) {
            if (is_array($subscription)) {
                $subscription = guardV2SubscriptionPayload($subscription);
            }
        }
        unset($subscription);
    }

    $response = guardApiRequest($config, 'POST', '/api/subscriptions', $payload);
    $decoded = guardDecodeResponse($response);
    if ($decoded['status'] === false) {
        return $decoded;
    }
    $normalized = guardNormalizeSubscriptionItems($config, $decoded['data']);
    return [
        'status' => true,
        'data' => $normalized,
        'raw' => $response
    ];
}

function guardUpdateSubscription($namePanel, string $username, array $payload)
{
    // Force-disable auto renewals on updates as well.
    unset($payload['auto_renewals']);
    unset($payload['auto_renew']);
    unset($payload['renewals']);
    unset($payload['renew']);
    unset($payload['autoRenewals']);

    $config = getGuardPanelConfig($namePanel);
    if ($config['status'] === false) {
        return $config;
    }
    if (guardIsV2($config)) {
        // گاردv2 مسیرِ PUT /api/subscriptions/{username} را ندارد؛ فقط
        // به‌روزرسانیِ دسته‌ای دارد که نام‌ها را در خودِ بدنه می‌گیرد.
        $body = guardV2SubscriptionPayload($payload);
        unset($body['username']);   // در این endpoint یعنی «تغییر نام»، نه هدف
        $body['usernames'] = array($username);
        $response = guardApiRequest($config, 'PUT', '/api/subscriptions', $body);
        $decoded = guardDecodeResponse($response);
        if ($decoded['status'] !== false && isset($decoded['data'])) {
            $decoded['data'] = guardNormalizeSubscriptionItems($config, $decoded['data']);
        }
        return $decoded;
    }
    $encodedUsername = urlencode($username);
    $response = guardApiRequest($config, 'PUT', "/api/subscriptions/{$encodedUsername}", $payload);
    return guardDecodeResponse($response);
}

function guardDeleteSubscriptions($namePanel, array $usernames)
{
    $config = getGuardPanelConfig($namePanel);
    if ($config['status'] === false) {
        return $config;
    }
    $response = guardApiRequest($config, 'DELETE', '/api/subscriptions', ['usernames' => $usernames]);
    return guardDecodeResponse($response);
}

function guardGetSubscription($namePanel, string $username)
{
    $config = getGuardPanelConfig($namePanel);
    if ($config['status'] === false) {
        return $config;
    }
    if (guardIsV2($config)) {
        // گاردv2 مسیرِ GET /api/subscriptions/{username} را ندارد؛ باید لیست را
        // با فیلترِ usernames گرفت. نتیجه صفحه‌بندی‌شده است، پس خودمان دنبالِ
        // ردیفی می‌گردیم که نامش *دقیقاً* برابر باشد — نه صرفاً اولین ردیف،
        // چون آن‌وقت یک تطبیقِ نسبی می‌توانست سرویسِ کاربرِ دیگری را برگرداند.
        $response = guardApiRequest($config, 'GET', '/api/subscriptions?limit=50&usernames=' . rawurlencode($username));
        $decoded = guardDecodeResponse($response);
        if ($decoded['status'] === false) {
            return $decoded;
        }
        $items = $decoded['data'];
        if (is_array($items) && isset($items['items']) && is_array($items['items'])) {
            $items = $items['items'];
        }
        $match = null;
        if (is_array($items)) {
            foreach ($items as $row) {
                if (is_array($row) && isset($row['username'])
                    && (string) $row['username'] === (string) $username) {
                    $match = $row;
                    break;
                }
            }
        }
        if ($match === null) {
            return [
                'status' => false,
                'msg' => 'User not found'
            ];
        }
        return [
            'status' => true,
            'data' => guardNormalizeSubscriptionItems($config, $match),
            'raw' => $response
        ];
    }
    $encodedUsername = urlencode($username);
    $response = guardApiRequest($config, 'GET', "/api/subscriptions/{$encodedUsername}");
    $decoded = guardDecodeResponse($response);
    if ($decoded['status'] === false) {
        return $decoded;
    }

    $decoded['data'] = guardNormalizeSubscriptionItems($config, $decoded['data']);
    return $decoded;
}

function guardGetSubscriptionUsages($namePanel, string $username)
{
    $config = getGuardPanelConfig($namePanel);
    if ($config['status'] === false) {
        return $config;
    }
    $encodedUsername = urlencode($username);
    $suffix = guardIsV2($config) ? 'hourly-usage' : 'usages';
    $response = guardApiRequest($config, 'GET', "/api/subscriptions/{$encodedUsername}/{$suffix}");
    return guardDecodeResponse($response);
}

function guardToggleSubscriptions($namePanel, array $usernames, string $action)
{
    $config = getGuardPanelConfig($namePanel);
    if ($config['status'] === false) {
        return $config;
    }
    $endpoint = $action === "disable" ? '/api/subscriptions/disable' : '/api/subscriptions/enable';
    $response = guardApiRequest($config, 'POST', $endpoint, ['usernames' => $usernames]);
    return guardDecodeResponse($response);
}

function guardResetSubscriptions($namePanel, array $usernames)
{
    $config = getGuardPanelConfig($namePanel);
    if ($config['status'] === false) {
        return $config;
    }
    $response = guardApiRequest($config, 'POST', '/api/subscriptions/reset', ['usernames' => $usernames]);
    return guardDecodeResponse($response);
}

function guardRevokeSubscriptions($namePanel, array $usernames)
{
    $config = getGuardPanelConfig($namePanel);
    if ($config['status'] === false) {
        return $config;
    }
    $response = guardApiRequest($config, 'POST', '/api/subscriptions/revoke', ['usernames' => $usernames]);
    return guardDecodeResponse($response);
}

