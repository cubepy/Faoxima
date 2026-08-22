<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Marzban.php';
require_once __DIR__ . '/function.php';
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/x-ui_single.php';
require_once __DIR__ . '/hiddify.php';
require_once __DIR__ . '/alireza.php';
require_once __DIR__ . '/marzneshin.php';
require_once __DIR__ . '/alireza_single.php';
require_once __DIR__ . '/WGDashboard.php';
require_once __DIR__ . '/s_ui.php';
require_once __DIR__ . '/ibsng.php';
require_once __DIR__ . '/mikrotik.php';

class ManagePanel
{
    public $pdo, $domainhosts, $name_panel;


    private static $panelRowCache = [];


    // [FIX تمدیدِ موفقِ دروغین] چک‌های پنل فقط status == 500 را «خطا» می‌دیدند.
    // پنل برای payloadِ نادرست 422، برای توکنِ منقضی 401 و برای مسیرِ اشتباه 404
    // برمی‌گرداند — همه‌ی این‌ها از کنار آن شرط رد می‌شدند و «موفق» گزارش می‌شدند:
    // پول کم می‌شد، فاکتور تمدید می‌شد، و روی پنل هیچ اتفاقی نمی‌افتاد.
    private static function rx_http_failed($response): ?string
    {
        if (!is_array($response)) return null;
        $code = $response['status'] ?? null;
        if (!is_numeric($code)) return null;   // بعضی درایورها اینجا bool می‌گذارند
        $code = (int) $code;
        if ($code < 100 || ($code >= 200 && $code < 300)) return null;
        return 'HTTP ' . $code;
    }

    private function loadPanel(string $key, string $column = 'name_panel'): ?array
    {
        $cacheKey = $column . '|' . $key;
        if (array_key_exists($cacheKey, self::$panelRowCache)) {
            return self::$panelRowCache[$cacheKey];
        }
        $row = select('marzban_panel', '*', $column, $key, 'select');
        $value = is_array($row) ? $row : null;
        self::$panelRowCache[$cacheKey] = $value;
        return $value;
    }
    function createUser($name_panel, $code_product, $usernameC, array $Data_Config)
    {
        $Output = [];
        global $pdo, $domainhosts;
        if (strlen($usernameC) < 3) {
            return array(
                "status" => "Unsuccessful",
                "msg" => "Username must be at least 3 characters long."
            );
        }


        $Get_Data_Panel = $this->loadPanel($name_panel, "name_panel");
        if ($Get_Data_Panel == false) {
            $Output['status'] = 'Unsuccessful';
            $Output['msg'] = 'Panel Not Found';
            return $Output;
        }
        // Scoped to this panel, not to the username alone.
        //
        // $inoice['id_invoice'] becomes the customer's /sub/<id> URL below, and
        // that id is the only thing protecting their configs. Service
        // usernames are unique *within* a panel — the panel itself enforces
        // that — but not across a shop, and with «نام کاربری دلخواه» the
        // customer picks the name. So an unscoped lookup could hand one
        // customer another customer's subscription secret, which is full
        // access to their configs, not merely the wrong screen.
        if ($Get_Data_Panel['subvip'] == "onsubvip") {
            $inoice = function_exists('rx_invoice_on_panel')
                ? rx_invoice_on_panel((string) $usernameC, (string) $Get_Data_Panel['name_panel'])
                : false;   // no link beats the wrong customer's link
        } else {
            $inoice = false;
        }
        if (!in_array($code_product, ["usertest", "🛍 حجم دلخواه", "customvolume"])) {

            $stmt = $pdo->prepare("SELECT * FROM product WHERE (Location = :name_panel OR Location = '/all')  AND code_product = :code_product");
            $stmt->bindParam(':name_panel', $name_panel);
            $stmt->bindParam(':code_product', $code_product);
            $stmt->execute();
            $Get_Data_Product = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            if ($code_product == "usertest") {
                $Get_Data_Product['name_product'] = "usertest";
            } else {
                $Get_Data_Product['name_product'] = false;
            }
            $Get_Data_Product['data_limit_reset'] = "no_reset";
        }
        $expire = $Data_Config['expire'];
        $data_limit = $Data_Config['data_limit'];
        $note = "{$Data_Config['from_id']} | {$Data_Config['username']} | {$Data_Config['type']}";
        if (in_array($Get_Data_Panel['type'], ["marzban", "pasargard"], true)) {

            $ConnectToPanel = adduser($Get_Data_Panel['name_panel'], $data_limit, $usernameC, $expire, $note, $Get_Data_Product['data_limit_reset'], $Get_Data_Product['name_product']);
            // [FIX] فقط ۵۰۰ «خطا» حساب می‌شد. پنل برای توکنِ منقضی 401، برای
            // payloadِ نادرست 422 و برای نامِ تکراری 409 می‌دهد — همه‌ی این‌ها
            // «موفق» رد می‌شدند و سرویسِ ساخته‌نشده به مشتری فروخته می‌شد.
            if (self::rx_http_failed($ConnectToPanel) !== null) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => self::rx_http_failed($ConnectToPanel)
                );
            }
            if (!empty($ConnectToPanel['error'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $ConnectToPanel['error']
                );
            }
            $data_Output = json_decode($ConnectToPanel['body'], true);
            if (!empty($data_Output['detail']) && $data_Output['detail']) {
                $Output['status'] = 'Unsuccessful';
                if ($data_Output['detail']) {
                    $Output['msg'] = $data_Output['detail'];
                } else {
                    $Output['msg'] = '';
                }
            } else {
                if (!preg_match('/^(https?:\/\/)?([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(:\d+)?((\/[^\s\/]+)+)?$/', $data_Output['subscription_url'])) {
                    $data_Output['subscription_url'] = $Get_Data_Panel['url_panel'] . "/" . ltrim($data_Output['subscription_url'], "/");
                }
                if ((string)($Get_Data_Panel['version_panel'] ?? '0') === '1') {
                    $out_put_link = outputlunk($data_Output['subscription_url']);
                    if (isBase64($out_put_link)) {
                        $data_Output['links'] = base64_decode(outputlunk($data_Output['subscription_url']));
                    }
                    $data_Output['links'] = explode("\n", $data_Output['links']);
                }
                if ($inoice != false) {
                    $data_Output['subscription_url'] = "https://$domainhosts/sub/" . $inoice['id_invoice'];
                }
                $Output['status'] = 'successful';
                $Output['username'] = $data_Output['username'];
                $Output['subscription_url'] = $data_Output['subscription_url'];
                $Output['configs'] = $data_Output['links'];
            }
        } elseif ($Get_Data_Panel['type'] == "marzneshin") {

            $ConnectToPanel = adduserm($Get_Data_Panel['name_panel'], $data_limit, $usernameC, $expire, $Get_Data_Product['name_product'], $note, $Get_Data_Product['data_limit_reset']);
            // [FIX] فقط ۵۰۰ «خطا» حساب می‌شد. پنل برای توکنِ منقضی 401، برای
            // payloadِ نادرست 422 و برای نامِ تکراری 409 می‌دهد — همه‌ی این‌ها
            // «موفق» رد می‌شدند و سرویسِ ساخته‌نشده به مشتری فروخته می‌شد.
            if (self::rx_http_failed($ConnectToPanel) !== null) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => self::rx_http_failed($ConnectToPanel)
                );
            }
            if (!empty($ConnectToPanel['error'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $ConnectToPanel['error']
                );
            }
            $data_Output = json_decode($ConnectToPanel['body'], true);
            if (isset($data_Output['detail']) && $data_Output['detail']) {
                $Output['status'] = 'Unsuccessful';
                if ($data_Output['detail']) {
                    $Output['msg'] = $data_Output['detail'];
                } else {
                    $Output['msg'] = '';
                }
            } else {
                if (!preg_match('/^(https?:\/\/)?([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(:\d+)?((\/[^\s\/]+)+)?$/', $data_Output['subscription_url'])) {
                    $data_Output['subscription_url'] = $Get_Data_Panel['url_panel'] . "/" . ltrim($data_Output['subscription_url'], "/");
                }
                $data_Output['links'] = outputlunk($data_Output['subscription_url']);
                if (isBase64($data_Output['links'])) {
                    $data_Output['links'] = base64_decode($data_Output['links']);
                }
                $links_user = explode("\n", trim($data_Output['links']));
                $date = new DateTime($data_Output['expire']);
                if ($inoice != false) {
                    $data_Output['subscription_url'] = "https://$domainhosts/sub/" . $inoice['id_invoice'];
                }
                $data_Output['expire'] = $date->getTimestamp();
                $Output['status'] = 'successful';
                $Output['username'] = $data_Output['username'];
                $Output['subscription_url'] = $data_Output['subscription_url'];
                $Output['configs'] = $links_user;
            }
        } elseif ($Get_Data_Panel['type'] == "x-ui_single") {
            $subId = bin2hex(random_bytes(8));
            if (isset($Get_Data_Product['inbounds']) and $Get_Data_Product['inbounds'] != null) {
                $inbounds = $Get_Data_Product['inbounds'];
            } else {
                $inbounds = $Get_Data_Panel['inboundid'];
            }
            $limitIp = (int) ($Get_Data_Product['limit_ip'] ?? 0);
            $data_Output = addClient($Get_Data_Panel['name_panel'], $usernameC, $expire, $data_limit, generateUUID(), "", $subId, $inbounds, $Get_Data_Product['name_product'], $note, $limitIp);
            if (!empty($data_Output['error'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $data_Output['error']
                );
            } elseif (!empty($data_Output['status']) && $data_Output['status'] != 200) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $data_Output['status']
                );
            } else {
                $data_Output = json_decode($data_Output['body'], true);
                if (!$data_Output['success']) {
                    $Output['status'] = 'Unsuccessful';
                    $Output['msg'] = $data_Output['msg'];
                } else {
                    $subscriptionUrl = rtrim($Get_Data_Panel['linksubx'], '/') . "/{$subId}";
                    $singleLink = get_single_link_after_create(
                        $Get_Data_Panel['url_panel'],
                        $inbounds,
                        $subscriptionUrl,
                        $usernameC,
                        $Get_Data_Panel['name_panel'],
                        $Get_Data_Panel['code_panel'] ?? null
                    );
                    $links_user = [];
                    if ($singleLink) {
                        $links_user[] = $singleLink;
                    }
                    $subscriptionLinks = get_subscription_links_with_retry($subscriptionUrl);
                    if (is_array($subscriptionLinks)) {
                        foreach ($subscriptionLinks as $linkItem) {
                            if (!in_array($linkItem, $links_user, true)) {
                                $links_user[] = $linkItem;
                            }
                        }
                    }
                    if (empty($links_user)) {
                        $links_user[] = 'در دسترس نیست';
                    }
                    $Output['status'] = 'successful';
                    $Output['username'] = $usernameC;
                    $Output['subscription_url'] = $subscriptionUrl;
                    $Output['configs'] = $links_user;
                    if ($inoice != false) {
                        $Output['subscription_url'] = "https://$domainhosts/sub/" . $inoice['id_invoice'];
                    }
                }
            }
        } elseif ($Get_Data_Panel['type'] == "alireza_single") {
            $subId = bin2hex(random_bytes(8));
            $Expireac = $expire * 1000;
            if (isset($Get_Data_Product['inbounds']) and $Get_Data_Product['inbounds'] != null) {
                $inbounds = $Get_Data_Product['inbounds'];
            } else {
                $inbounds = $Get_Data_Panel['inboundid'];
            }
            $data_Output = addClientalireza_singel($Get_Data_Panel['name_panel'], $usernameC, $Expireac, $data_limit, generateUUID(), "", $subId, $inbounds);
            if (!empty($data_Output['error'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $data_Output['error']
                );
            } elseif (!empty($data_Output['status']) && $data_Output['status'] != 200) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $data_Output['status']
                );
            } else {
                $data_Output = json_decode($data_Output['body'], true);
                if (!$data_Output['success']) {
                    $Output['status'] = 'Unsuccessful';
                    $Output['msg'] = $data_Output['msg'];
                } else {
                    $Output['status'] = 'successful';
                    $Output['username'] = $usernameC;
                    $Output['subscription_url'] = $Get_Data_Panel['linksubx'] . "/{$subId}";
                    $Output['configs'] = [outputlunk($Output['subscription_url'])];
                    if ($inoice != false) {
                        $Output['subscription_url'] = "https://$domainhosts/sub/" . $inoice['id_invoice'];
                    }
                }
            }
        } elseif ($Get_Data_Panel['type'] == "hiddify") {
            if ($expire != 0) {
                $current_timestamp = time();
                $diff_seconds = $expire - $current_timestamp;
                $diff_days = ceil($diff_seconds / (60 * 60 * 24));
            } else {
                $diff_days = 111111;
            }
            $uuid = generateUUID();
            $data = array(
                "uuid" => $uuid,
                "name" => $usernameC,
                "added_by_uuid" => $Get_Data_Panel['secret_code'],
                "current_usage_GB" => "0",
                "usage_limit_GB" => $data_limit / pow(1024, 3),
                "package_days" => $diff_days,
                "comment" => $note,
            );
            $data_Output = adduserhi($Get_Data_Panel['name_panel'], $data);
            if (!empty($data_Output['error'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $data_Output['error']
                );
            } elseif (!empty($data_Output['status']) && $data_Output['status'] != 200) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $data_Output['status']
                );
            }
            $data_Output = json_decode($data_Output['body'], true);
            if (isset($data_Output['message']) && $data_Output['message']) {
                $Output['status'] = 'Unsuccessful';
                $Output['msg'] = $data_Output['message'];
            } else {
                $Output['status'] = 'successful';
                $Output['username'] = $usernameC;
                $Output['subscription_url'] = "{$Get_Data_Panel['linksubx']}/{$data_Output['uuid']}/";
                $Output['configs'] = [];
                if ($inoice != false) {
                    $Output['subscription_url'] = "https://$domainhosts/sub/" . $inoice['id_invoice'];
                }
            }
        } elseif ($Get_Data_Panel['type'] == "guard") {
            $serviceIdsSource = $Get_Data_Panel['guard_service_ids'] ?? null;
            if (!empty($Get_Data_Product['inbounds'])) {
                $decodedServices = json_decode($Get_Data_Product['inbounds'], true);
                if (is_array($decodedServices)) {
                    $serviceIdsSource = $decodedServices;
                }
            }
            $guardNote = isset($Get_Data_Panel['guard_note']) ? trim((string) $Get_Data_Panel['guard_note']) : '';
            $guardAutoDeleteDays = isset($Get_Data_Panel['guard_auto_delete_days']) ? max(0, intval($Get_Data_Panel['guard_auto_delete_days'])) : 0;
            $guardAutoRenewalsConfig = guardDecodeAutoRenewalsConfig($Get_Data_Panel['guard_auto_renewals'] ?? []);
            $guardAutoRenewalsPayload = guardBuildAutoRenewalsPayload($guardAutoRenewalsConfig);
            $payloadNote = $guardNote !== '' ? $guardNote : $note;
            $serviceResult = guardResolveServiceIds($Get_Data_Panel['name_panel'], $serviceIdsSource);
            if ($serviceResult['status'] === false) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $serviceResult['msg']
                );
            }
            $limitExpire = guardNormalizeExpire($expire);
            $payload = array(
                array(
                    "username" => $usernameC,
                    "limit_usage" => $data_limit,
                    "limit_expire" => $limitExpire,
                    "service_ids" => $serviceResult['service_ids'],
                    "note" => $payloadNote,
                    "telegram_id" => null,
                    "discord_webhook_url" => null,
                    "auto_delete_days" => $guardAutoDeleteDays,
                    "auto_renewals" => $guardAutoRenewalsPayload
                )
            );
            $createResponse = guardCreateSubscription($Get_Data_Panel['name_panel'], $payload);
            if ($createResponse['status'] === false) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $createResponse['msg']
                );
            }
            $subscriptionUrl = '';
            $configs = array();
            $createdData = $createResponse['data'];
            if (is_array($createdData) && isset($createdData[0])) {
                $first = $createdData[0];
                if (isset($first['subscription_url'])) {
                    $subscriptionUrl = $first['subscription_url'];
                } elseif (isset($first['subscription'])) {
                    $subscriptionUrl = $first['subscription'];
                }
            } elseif (is_array($createdData)) {
                if (isset($createdData['subscription_url'])) {
                    $subscriptionUrl = $createdData['subscription_url'];
                } elseif (isset($createdData['subscription'])) {
                    $subscriptionUrl = $createdData['subscription'];
                }
            }
            if (!empty($subscriptionUrl)) {
                $configs[] = $subscriptionUrl;
            }
            // Only make the extra remote call if the create response didn't already
            // give us what we need — this call is a common source of slowness/timeouts
            // for Guard-panel purchases, so we skip it whenever possible.
            if (empty($subscriptionUrl) || empty($configs)) {
                $userData = $this->DataUser($Get_Data_Panel['name_panel'], $usernameC);
                if (!empty($userData) && (!isset($userData['status']) || $userData['status'] != "Unsuccessful")) {
                    if (!empty($userData['subscription_url'])) {
                        $subscriptionUrl = $userData['subscription_url'];
                    }
                    if (!empty($userData['links'])) {
                        $configs = $userData['links'];
                    }
                }
            }
            if ($inoice != false) {
                $subscriptionUrl = "https://$domainhosts/sub/" . $inoice['id_invoice'];
            }
            $Output['status'] = 'successful';
            $Output['username'] = $usernameC;
            $Output['subscription_url'] = $subscriptionUrl;
            $Output['configs'] = $configs;
        } elseif ($Get_Data_Panel['type'] == "Manualsale") {
            $statement = $pdo->prepare("SELECT * FROM manualsell WHERE codepanel = :code_panel AND status = 'active' AND codeproduct = '$code_product' ORDER BY RAND() LIMIT 1");
            $statement->execute(array(':code_panel' => $Get_Data_Panel['code_panel']));
            $configman = $statement->fetch(PDO::FETCH_ASSOC);
            $Output['status'] = 'successful';
            $Output['username'] = $usernameC;
            $Output['subscription_url'] = $configman['contentrecord'];
            $Output['configs'] = "";
            update("manualsell", "status", "selled", "id", $configman['id']);
            update("manualsell", "username", $usernameC, "id", $configman['id']);
        } elseif ($Get_Data_Panel['type'] == "WGDashboard") {
            $data_limit = round($data_limit / (1024 * 1024 * 1024), 2);
            $data_Output = addpear($Get_Data_Panel['name_panel'], $usernameC);
            if (isset($data_Output['status']) && $data_Output['status'] === false) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => isset($data_Output['msg']) ? $data_Output['msg'] : ''
                );
            }
            if (!empty($data_Output['status']) && $data_Output['status'] != 200) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $data_Output['status']
                );
            }
            if (!empty($data_Output['error'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $data_Output['error']
                );
            }
            $data_Output = $data_Output['body'];
            $response = json_decode($data_Output['response'], true);
            if ($data_limit != 0) {
                $jobResponse = setjob($Get_Data_Panel['name_panel'], "total_data", $data_limit, $data_Output['public_key']);
                if (isset($jobResponse['status']) && $jobResponse['status'] === false) {
                    return array(
                        'status' => 'Unsuccessful',
                        'msg' => isset($jobResponse['msg']) ? $jobResponse['msg'] : ''
                    );
                }
            }
            if ($expire != 0) {
                $jobResponse = setjob($Get_Data_Panel['name_panel'], "date", date('Y-m-d H:i:s', $expire), $data_Output['public_key']);
                if (isset($jobResponse['status']) && $jobResponse['status'] === false) {
                    return array(
                        'status' => 'Unsuccessful',
                        'msg' => isset($jobResponse['msg']) ? $jobResponse['msg'] : ''
                    );
                }
            }
            update("invoice", "user_info", json_encode($data_Output), "username", $usernameC);
            if (!$response['status']) {
                $Output['status'] = 'Unsuccessful';
                $Output['msg'] = $data_Output['msg'];
            } else {
                $download_config = downloadconfig($Get_Data_Panel['name_panel'], $data_Output['public_key']);
                if (isset($download_config['status']) && $download_config['status'] === false) {
                    return array(
                        'status' => 'Unsuccessful',
                        'msg' => isset($download_config['msg']) ? $download_config['msg'] : ''
                    );
                }
                if (!empty($download_config['status']) && $download_config['status'] != 200) {
                    return array(
                        'status' => 'Unsuccessful',
                        'msg' => $download_config['status']
                    );
                }
                if (!empty($download_config['error'])) {
                    return array(
                        'status' => 'Unsuccessful',
                        'msg' => $download_config['error']
                    );
                }
                $download_config = json_decode($download_config['body'], true)['data'];
                $Output['status'] = 'successful';
                $Output['username'] = $usernameC;
                $Output['subscription_url'] = strval($download_config['file']);
                $Output['configs'] = [];
            }
        } elseif ($Get_Data_Panel['type'] == "s_ui") {
            if ($Get_Data_Product['inbounds'] != null) {
                $Get_Data_Panel['inbounds'] = $Get_Data_Product['inbounds'];
            }
            $data_Output = addClientS_ui($Get_Data_Panel['name_panel'], $usernameC, $expire, $data_limit, json_decode($Get_Data_Panel['inbounds']), $note);
            if (!$data_Output['success']) {
                $Output['status'] = 'Unsuccessful';
                $Output['msg'] = $data_Output['msg'];
            } else {
                $setting_app = get_settig($Get_Data_Panel['name_panel']);
                $url = explode(":", $Get_Data_Panel['url_panel']);
                $url_sub = $url[0] . ":" . $url[1] . ":" . $setting_app['subPort'] . $setting_app['subPath'] . $usernameC;
                $Output['status'] = 'successful';
                $Output['username'] = $usernameC;
                $Output['subscription_url'] = $url_sub;
                $Output['configs'] = [outputlunk($url_sub)];
            }
        } elseif ($Get_Data_Panel['type'] == "ibsng") {
            $password = bin2hex(random_bytes(6));
            $name_group = $Get_Data_Panel['proxies'];
            if ($Get_Data_Product['inbounds'] != null) {
                $name_group = $Get_Data_Panel['inbounds'];
            } elseif ($code_product == "usertest") {
                $name_group = "usertest";
            }
            $data_Output = addUserIBsng($Get_Data_Panel['name_panel'], $usernameC, $password, $name_group);
            if (!$data_Output) {
                $Output['status'] = 'Unsuccessful';
                $Output['msg'] = $data_Output['msg'];
            } else {
                $Output['status'] = 'successful';
                $Output['username'] = $usernameC;
                $Output['subscription_url'] = $password;
                $Output['configs'] = [];
            }
        } elseif ($Get_Data_Panel['type'] == "mikrotik") {
            $password = bin2hex(random_bytes(6));
            $name_group = $Get_Data_Panel['proxies'];
            if ($Get_Data_Product['inbounds'] != null) {
                $name_group = $Get_Data_Product['inbounds'];
            } elseif ($code_product == "usertest") {
                $name_group = "usertest";
            }
            $data_Output = addUser_mikrotik($Get_Data_Panel['name_panel'], $usernameC, $password, $name_group);
            if (isset($data_Output['error'])) {
                $Output['status'] = 'Unsuccessful';
                $Output['msg'] = $data_Output['msg'];
            } else {
                $Output['status'] = 'successful';
                $Output['username'] = $usernameC;
                $Output['subscription_url'] = $password;
                $Output['configs'] = [];
            }
        } else {
            $Output['status'] = 'Unsuccessful';
            $Output['msg'] = 'Panel Not Found';
        }
        if (function_exists('normalizeServiceConfigs')) {
            if (isset($Output['status']) && $Output['status'] === 'successful') {
                $Output['configs'] = normalizeServiceConfigs($Output['configs'] ?? null, $Output['subscription_url'] ?? null);
            } else {
                $Output['configs'] = normalizeServiceConfigs($Output['configs'] ?? null);
            }
        } else {
            if (!isset($Output['configs'])) {
                $Output['configs'] = [];
            } elseif (!is_array($Output['configs'])) {
                $value = trim((string) $Output['configs']);
                $Output['configs'] = $value === '' ? [] : [$value];
            }
        }
        return $Output;
    }
    function DataUser($name_panel, $username)
    {
        $Output = array();
        global $pdo, $domainhosts;
        $Get_Data_Panel = $this->loadPanel($name_panel, "name_panel");
        if (!$Get_Data_Panel || !is_array($Get_Data_Panel)) {
            return array(
                'status' => 'Unsuccessful',
                'msg' => 'Panel Not Found'
            );
        }
        // Scoped to this panel, not to the username alone.
        //
        // $inoice['id_invoice'] becomes the customer's /sub/<id> URL below, and
        // that id is the only thing protecting their configs. Service
        // usernames are unique *within* a panel — the panel itself enforces
        // that — but not across a shop, and with «نام کاربری دلخواه» the
        // customer picks the name. So an unscoped lookup could hand one
        // customer another customer's subscription secret, which is full
        // access to their configs, not merely the wrong screen.
        if (isset($Get_Data_Panel['subvip']) && $Get_Data_Panel['subvip'] == "onsubvip") {
            $inoice = function_exists('rx_invoice_on_panel')
                ? rx_invoice_on_panel((string) $username, (string) $Get_Data_Panel['name_panel'])
                : false;   // no link beats the wrong customer's link
        } else {
            $inoice = false;
        }
        if (in_array($Get_Data_Panel['type'], ["marzban", "pasargard"], true)) {
            $UsernameData = getuser($username, $Get_Data_Panel['name_panel']);
            if (!empty($UsernameData['error'])) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['error']
                );
            } elseif (!empty($UsernameData['status']) && $UsernameData['status'] == 500) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['status']
                );
            } else {
                $UsernameData = json_decode($UsernameData['body'], true);
                if (!empty($UsernameData['detail'])) {
                    return array(
                        'status' => 'Unsuccessful',
                        'msg' => $UsernameData['detail']
                    );
                }
                if (!preg_match('/^(https?:\/\/)?([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(:\d+)?((\/[^\s\/]+)+)?$/', $UsernameData['subscription_url'])) {
                    $UsernameData['subscription_url'] = $Get_Data_Panel['url_panel'] . "/" . ltrim($UsernameData['subscription_url'], "/");
                }
                if ((string)($Get_Data_Panel['version_panel'] ?? '0') === '1') {
                    $UsernameData['expire'] = strtotime($UsernameData['expire']);
                    $UsernameData['links'] = base64_decode(outputlunk($UsernameData['subscription_url']));
                    $UsernameData['links'] = explode("\n", $UsernameData['links']);
                    $sublist_update = get_list_update($name_panel, $username);
                    if (!empty($sublist_update['error'])) {
                        return array(
                            'status' => 'Unsuccessful',
                            'msg' => $sublist_update['error']
                        );
                    } elseif (!empty($sublist_update['status']) && $sublist_update['status'] == 500) {
                        return array(
                            'status' => 'Unsuccessful',
                            'msg' => $sublist_update['status']
                        );
                    }
                    $sublist_update_body = json_decode($sublist_update['body'], true);
                    if (!empty($sublist_update_body['updates']) && is_array($sublist_update_body['updates'])) {
                        $first_update = $sublist_update_body['updates'][0];
                        $UsernameData['sub_updated_at'] = isset($first_update['created_at']) ? $first_update['created_at'] : null;
                        $UsernameData['sub_last_user_agent'] = isset($first_update['user_agent']) ? $first_update['user_agent'] : null;
                    } else {
                        $UsernameData['sub_updated_at'] = isset($UsernameData['sub_updated_at']) ? $UsernameData['sub_updated_at'] : null;
                        $UsernameData['sub_last_user_agent'] = isset($UsernameData['sub_last_user_agent']) ? $UsernameData['sub_last_user_agent'] : null;
                    }
                } else {
                    $UsernameData['expire'] = $UsernameData['expire'];
                }
                if ($inoice != false) {
                    $UsernameData['subscription_url'] = "https://$domainhosts/sub/" . $inoice['id_invoice'];
                }
                if ((string)($Get_Data_Panel['version_panel'] ?? '0') === '1') {
                    $UsernameData['proxies'] = isset($UsernameData['proxy_settings']) ? $UsernameData['proxy_settings'] : null;
                }
                $Output = array(
                    'status' => $UsernameData['status'],
                    'username' => $UsernameData['username'],
                    'data_limit' => $UsernameData['data_limit'],
                    'expire' => $UsernameData['expire'],
                    'online_at' => $UsernameData['online_at'],
                    'used_traffic' => $UsernameData['used_traffic'],
                    'links' => $UsernameData['links'],
                    'subscription_url' => $UsernameData['subscription_url'],
                    'sub_updated_at' => $UsernameData['sub_updated_at'],
                    'sub_last_user_agent' => $UsernameData['sub_last_user_agent'],
                    'uuid' => $UsernameData['proxies'],
                    'data_limit_reset' => $UsernameData['data_limit_reset_strategy']
                );
            }
        } elseif ($Get_Data_Panel['type'] == "marzneshin") {
            $UsernameData = getuserm($username, $Get_Data_Panel['name_panel']);
            if (!empty($UsernameData['error'])) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['error']
                );
            } elseif (!empty($UsernameData['status']) && $UsernameData['status'] == 500) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['status']
                );
            } else {
                $UsernameData = json_decode($UsernameData['body'], true);
                if (isset($UsernameData['detail']) && $UsernameData['detail']) {
                    $Output = array(
                        'status' => 'Unsuccessful',
                        'msg' => $UsernameData['detail']
                    );
                } elseif (!isset($UsernameData['username'])) {
                    $Output = array(
                        'status' => 'Unsuccessful',
                        'msg' => "Unsuccessful"
                    );
                } else {
                    if (!preg_match('/^(https?:\/\/)?([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(:\d+)?((\/[^\s\/]+)+)?$/', $UsernameData['subscription_url'])) {
                        $UsernameData['subscription_url'] = $Get_Data_Panel['url_panel'] . "/" . ltrim($UsernameData['subscription_url'], "/");
                    }
                    $UsernameData['status'] = "active";
                    if (!$UsernameData['enabled']) {
                        $UsernameData['status'] = "disabled";
                    }
                    if ($UsernameData['expire_strategy'] == "start_on_first_use") {
                        $UsernameData['status'] = "on_hold";
                    }
                    if ($UsernameData['expired']) {
                        $UsernameData['status'] = "expired";
                    }
                    if (($UsernameData['data_limit'] - $UsernameData['used_traffic'] <= 0) and $UsernameData['data_limit'] != null) {
                        $UsernameData['status'] = "limtied";
                    }
                    $UsernameData['links'] = outputlunk($UsernameData['subscription_url']);
                    if (isBase64($UsernameData['links'])) {
                        $UsernameData['links'] = base64_decode($UsernameData['links']);
                    }
                    $links_user = explode("\n", trim($UsernameData['links']));
                    if ($UsernameData['data_limit'] == null) {
                        $UsernameData['data_limit'] = 0;
                    }
                    if (isset($UsernameData['expire_date'])) {
                        $expiretime = strtotime(($UsernameData['expire_date']));
                    } else {
                        $expiretime = 0;
                    }
                    if ($inoice != false) {
                        $UsernameData['subscription_url'] = "https://$domainhosts/sub/" . $inoice['id_invoice'];
                    }
                    $Output = array(
                        'status' => $UsernameData['status'],
                        'username' => $UsernameData['username'],
                        'data_limit' => $UsernameData['data_limit'],
                        'expire' => $expiretime,
                        'online_at' => $UsernameData['online_at'],
                        'used_traffic' => $UsernameData['used_traffic'],
                        'links' => $links_user,
                        'subscription_url' => $UsernameData['subscription_url'],
                        'sub_updated_at' => $UsernameData['sub_updated_at'],
                        'sub_last_user_agent' => $UsernameData['sub_last_user_agent'],
                        'uuid' => null
                    );
                }
            }
        } elseif ($Get_Data_Panel['type'] == "guard") {
            $subscription = guardGetSubscription($Get_Data_Panel['name_panel'], $username);
            if ($subscription['status'] === false) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $subscription['msg']
                );
            }
            $subscriptionData = $subscription['data'];
            if (isset($subscriptionData['data']) && is_array($subscriptionData['data'])) {
                $subscriptionData = $subscriptionData['data'];
            }
            if (isset($subscriptionData['subscription']) && is_array($subscriptionData['subscription'])) {
                $subscriptionData = $subscriptionData['subscription'];
            }
            if (is_array($subscriptionData) && isset($subscriptionData[0])) {
                $subscriptionData = $subscriptionData[0];
            }
            if (!is_array($subscriptionData)) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => 'User not found'
                );
            }
            $guardEnabled = array_key_exists('enabled', $subscriptionData)
                ? filter_var($subscriptionData['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null;
            if (!empty($subscriptionData['expired'])) {
                $statusGuard = "expired";
            } elseif (!empty($subscriptionData['limited'])) {
                $statusGuard = "limited";
            } elseif ($guardEnabled === false) {
                $statusGuard = "disabled";
            } elseif (isset($subscriptionData['status']) && is_string($subscriptionData['status']) && $subscriptionData['status'] !== '') {
                $statusGuard = $subscriptionData['status'];
            } else {
                $statusGuard = "active";
            }
            $limitExpire = isset($subscriptionData['limit_expire']) ? intval($subscriptionData['limit_expire']) : 0;
            $dataLimit = isset($subscriptionData['limit_usage']) ? intval($subscriptionData['limit_usage']) : 0;
            $subscriptionUrl = $subscriptionData['subscription_url'] ?? ($subscriptionData['subscription'] ?? '');
            $serviceIds = isset($subscriptionData['service_ids']) && is_array($subscriptionData['service_ids']) ? $subscriptionData['service_ids'] : array();
            $usage = 0;
            // The guard panel returns several usage-related fields:
            //   - total_usage  : lifetime cumulative usage, never resets
            //   - reset_usage  : cumulative usage snapshot at the last reset
            //   - current_usage: usage since the last reset (= total_usage - reset_usage)
            // "حجم مصرفی" must reflect the CURRENT period, so current_usage
            // is the correct field — total_usage was being used before and
            // kept showing stale lifetime totals after every reset.
            if (isset($subscriptionData['current_usage'])) {
                $usage = intval($subscriptionData['current_usage']);
            } elseif (isset($subscriptionData['total_usage']) && isset($subscriptionData['reset_usage'])) {
                $usage = intval($subscriptionData['total_usage']) - intval($subscriptionData['reset_usage']);
            } elseif (isset($subscriptionData['total_usage'])) {
                $usage = intval($subscriptionData['total_usage']);
            } elseif (isset($subscriptionData['usage'])) {
                $usage = intval($subscriptionData['usage']);
            } elseif (isset($subscriptionData['used_traffic'])) {
                $usage = intval($subscriptionData['used_traffic']);
            }
            $onlineAt = $subscriptionData['online_at'] ?? ($subscriptionData['last_online_at'] ?? null);
            $isOnline = null;
            if (array_key_exists('is_online', $subscriptionData)) {
                $isOnline = $subscriptionData['is_online'];
            } elseif (array_key_exists('online', $subscriptionData)) {
                $isOnline = $subscriptionData['online'];
            }
            if ($isOnline !== null) {
                $isOnline = filter_var($isOnline, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
            $links = array();
            if (!empty($subscriptionUrl)) {
                $links[] = $subscriptionUrl;
            }
            if ($inoice != false) {
                $subscriptionUrl = "https://$domainhosts/sub/" . $inoice['id_invoice'];
            }
            $Output = array(
                'status' => $statusGuard,
                'username' => $subscriptionData['username'] ?? $username,
                'data_limit' => $dataLimit,
                'expire' => $limitExpire,
                'online_at' => $onlineAt,
                'is_online' => $isOnline,
                'used_traffic' => $usage,
                'links' => $links,
                'subscription_url' => $subscriptionUrl,
                'sub_updated_at' => null,
                'sub_last_user_agent' => null,
                'uuid' => null,
                'data_limit_reset' => null,
                'service_ids' => $serviceIds
            );
        } elseif ($Get_Data_Panel['type'] == "x-ui_single") {
            $user_data = get_clinets($username, $Get_Data_Panel['name_panel']);
            if (!empty($user_data['error'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $user_data['error']
                );
            } elseif (!empty($user_data['status']) && $user_data['status'] != 200) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => json_encode($user_data)
                );
            }
            $user_data = json_decode($user_data['body'], true);

            if (!is_array($user_data)) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => 'object invalid'
                );
            }
            if (empty($user_data['obj'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => "User not found"
                );
            }
            $user_data = $user_data['obj'];
            $expire = $user_data['expiryTime'] / 1000;
            if ($user_data['enable']) {
                $user_data['enable'] = "active";
            } else {
                $user_data['enable'] = "disabled";
            }
            if ((intval($user_data['total'])) != 0) {
                if ((intval($user_data['total']) - ($user_data['up'] + $user_data['down'])) <= 0)
                    $user_data['enable'] = "limited";
            }
            if (intval($user_data['expiryTime']) != 0) {
                if ($expire - time() <= 0)
                    $user_data['enable'] = "expired";
            }
            if ($user_data['expiryTime'] < -10000) {
                $user_data['enable'] = "on_hold";
                $expire = 0;
            }
            $subscriptionUrl = rtrim($Get_Data_Panel['linksubx'], '/') . "/{$user_data['subId']}";
            $linksub = $subscriptionUrl;
            $links_user_raw = outputlunk($subscriptionUrl);
            if (!is_string($links_user_raw)) {
                $links_user_raw = '';
            }
            if (isBase64($links_user_raw)) {
                $links_user_raw = base64_decode($links_user_raw);
            }
            $links_user = preg_split('/\R/', trim($links_user_raw));
            if (!is_array($links_user)) {
                $links_user = [];
            }
            $links_user = array_values(array_filter(array_map('trim', $links_user), function ($ln) {
                return $ln !== '';
            }));
            $singleLink = $links_user[0] ?? null;
            if (!$singleLink || !preg_match('/^(vless|vmess|trojan):\/\//i', $singleLink)) {
                if (is_file(xuisingle_cookie_path())) {
                    @unlink(xuisingle_cookie_path());
                }
                login($Get_Data_Panel['code_panel']);
                $singleLink = get_single_link_smart(
                    $Get_Data_Panel['url_panel'],
                    $Get_Data_Panel['inboundid'],
                    $subscriptionUrl,
                    $username,
                    $Get_Data_Panel['name_panel'],
                    $Get_Data_Panel['code_panel'] ?? null
                );
                if (is_file(xuisingle_cookie_path())) {
                    @unlink(xuisingle_cookie_path());
                }
                if (!$singleLink) {
                    return array(
                        'status' => 'Unsuccessful',
                        'msg' => 'Unable to build single link'
                    );
                }
                array_unshift($links_user, $singleLink);
            }
            if ($inoice != false)
                $linksub = "https://$domainhosts/sub/" . $inoice['id_invoice'];
            $user_data['lastOnline'] = $user_data['lastOnline'] == 0 ? "offline" : date('Y-m-d H:i:s', $user_data['lastOnline'] / 1000);
            $Output = array(
                'status' => $user_data['enable'],
                'username' => $user_data['email'],
                'data_limit' => $user_data['total'],
                'expire' => $expire,
                'online_at' => $user_data['lastOnline'],
                'used_traffic' => $user_data['up'] + $user_data['down'],
                'links' => $links_user,
                'subscription_url' => $linksub,
                'sub_updated_at' => null,
                'sub_last_user_agent' => null,
            );

        } elseif ($Get_Data_Panel['type'] == "hiddify") {
            $UsernameData = getdatauser($username, $Get_Data_Panel['name_panel']);
            if (!isset($UsernameData)) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => "Not Connected TO paonel"
                );
            } elseif (isset($UsernameData['message'])) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['message']
                );
            } else {
                $startDate = $UsernameData['start_date'] ?? null;
                if ($startDate === null) {
                    $date = 0;
                } else {
                    $start_date = strtotime($startDate);
                    $package_days = isset($UsernameData['package_days']) ? intval($UsernameData['package_days']) : 0;
                    $end_date = $start_date + ($package_days * 86400);
                    $date = strtotime(date("Y-m-d H:i:s", $end_date));
                }
                $usageLimit = isset($UsernameData['usage_limit_GB']) ? $UsernameData['usage_limit_GB'] * pow(1024, 3) : 0;
                $currentUsage = isset($UsernameData['current_usage_GB']) ? $UsernameData['current_usage_GB'] * pow(1024, 3) : 0;
                $uuid = $UsernameData['uuid'] ?? null;
                $linksuburl = $uuid ? "{$Get_Data_Panel['linksubx']}/{$uuid}/" : $Get_Data_Panel['linksubx'];
                $lastOnline = $UsernameData['last_online'] ?? null;
                if ($lastOnline == "1-01-01 00:00:00") {
                    $lastOnline = null;
                }
                $remainingTraffic = $usageLimit - $currentUsage;
                if ($usageLimit > 0 && $remainingTraffic <= 0) {
                    $status = "limited";
                } elseif ($date != 0 && ($date - time()) <= 0) {
                    $status = "expired";
                } elseif ($startDate === null) {
                    $status = "on_hold";
                } else {
                    $status = "active";
                }
                if ($inoice != false) {
                    $linksuburl = "https://$domainhosts/sub/" . $inoice['id_invoice'];
                }
                $Output = array(
                    'status' => $status,
                    'username' => $UsernameData['name'] ?? ($UsernameData['email'] ?? $username),
                    'data_limit' => $usageLimit,
                    'expire' => $date,
                    'online_at' => $lastOnline,
                    'used_traffic' => $currentUsage,
                    'links' => [],
                    'subscription_url' => $linksuburl,
                    'sub_updated_at' => null,
                    'sub_last_user_agent' => null,
                );
            }
        } elseif ($Get_Data_Panel['type'] == "Manualsale") {
            $stmt = $pdo->prepare("SELECT * FROM manualsell WHERE username = :username");
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            $configman = $stmt->fetch(PDO::FETCH_ASSOC);
            $service = (function_exists('rx_invoice_on_panel')
                ? rx_invoice_on_panel((string) $username, (string) $Get_Data_Panel['name_panel'])
                : false);
            $Output = array(
                'status' => $service['Status'],
                'username' => $service['username'],
                'data_limit' => null,
                'expire' => $service['time_sell'],
                'online_at' => null,
                'used_traffic' => null,
                'links' => [],
                'subscription_url' => $configman['contentrecord'],
                'sub_updated_at' => null,
                'sub_last_user_agent' => null,
                'uuid' => null
            );
        } elseif ($Get_Data_Panel['type'] == "alireza_single") {
            $UsernameData2 = get_clinetsalireza($username, $Get_Data_Panel['name_panel']);
            if (!is_array($UsernameData2)) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => "user not found"
                );
            }
            $UsernameData = $UsernameData2[1];
            $UsernameData2 = $UsernameData2[0];
            $expire = $UsernameData['expiryTime'] / 1000;
            if (!$UsernameData['id']) {
                if (!isset($UsernameData['msg']))
                    $UsernameData['msg'] = null;
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['msg']
                );
            } else {
                if ($UsernameData['enable']) {
                    $UsernameData['enable'] = "active";
                } else {
                    $UsernameData['enable'] = "deactivev";
                }
                $subId = $UsernameData2['subId'];
                $status_user = get_onlineclialireza($Get_Data_Panel['name_panel'], $username);
                if ((intval($UsernameData['total'])) != 0) {
                    if ((intval($UsernameData['total']) - ($UsernameData['up'] + $UsernameData['down'])) <= 0)
                        $UsernameData['enable'] = "limited";
                }
                if (intval($UsernameData['expiryTime']) != 0) {
                    if ($expire - time() <= 0)
                        $UsernameData['enable'] = "expired";
                }
                $Output = array(
                    'status' => $UsernameData['enable'],
                    'username' => $UsernameData['email'],
                    'data_limit' => $UsernameData['total'],
                    'expire' => $expire,
                    'online_at' => $status_user,
                    'used_traffic' => $UsernameData['up'] + $UsernameData['down'],
                    'links' => [outputlunk($Get_Data_Panel['linksubx'] . "/{$UsernameData2['subId']}")],
                    'subscription_url' => $Get_Data_Panel['linksubx'] . "/{$UsernameData2['subId']}",
                    'sub_updated_at' => null,
                    'sub_last_user_agent' => null,
                );
            }
        } elseif ($Get_Data_Panel['type'] == "WGDashboard") {
            $UsernameData = get_userwg($username, $Get_Data_Panel['name_panel']);
            if (isset($UsernameData['status']) && $UsernameData['status'] === false && !isset($UsernameData['id'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => isset($UsernameData['msg']) ? $UsernameData['msg'] : ''
                );
            }
            $invoiceinfo = (function_exists('rx_invoice_on_panel')
                ? rx_invoice_on_panel((string) $username, (string) $Get_Data_Panel['name_panel'])
                : false);
            $infoconfig = isset($invoiceinfo['user_info']) ? json_decode($invoiceinfo['user_info'], true) : json_encode(array());
            if (!isset($UsernameData['id'])) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => isset($UsernameData['msg']) ? $UsernameData['msg'] : ''
                );
            } else {
                $jobtime = [];
                $jobvolume = [];
                foreach ($UsernameData['jobs'] as $job) {
                    if ($job['Field'] == "total_data") {
                        $jobvolume = $job;
                    } elseif ($job['Field'] == "date") {
                        $jobtime = $job;
                    }
                }
                if (intval($invoiceinfo['Service_time']) == 0) {
                    $expire = 0;
                } else {
                    if (isset($jobtime['Value'])) {
                        $expire = strtotime($jobtime['Value']);
                    } else {
                        $expire = 0;
                    }
                }
                $status = "active";
                if (!$UsernameData['configuration']['Status'])
                    $status = "disabled";
                if ($expire != 0 and $expire - time() < 0) {
                    $status = "expired";
                }
                $data_useage = ($UsernameData['total_data'] * pow(1024, 3)) + ($UsernameData['cumu_data'] * pow(1024, 3));
                if (($jobvolume['Value'] * pow(1024, 3)) < $data_useage) {
                    $status = "limited";
                }
                $download_config = downloadconfig($Get_Data_Panel['name_panel'], $UsernameData['id']);
                if (isset($download_config['status']) && $download_config['status'] === false) {
                    return array(
                        'status' => 'Unsuccessful',
                        'msg' => isset($download_config['msg']) ? $download_config['msg'] : ''
                    );
                }
                if (!empty($download_config['status']) && $download_config['status'] != 200) {
                    return array(
                        'status' => 'Unsuccessful',
                        'msg' => $download_config['status']
                    );
                }
                if (!empty($download_config['error'])) {
                    return array(
                        'status' => 'Unsuccessful',
                        'msg' => $download_config['error']
                    );
                }
                $download_config = json_decode($download_config['body'], true)['data'];
                $Output = array(
                    'status' => $status,
                    'username' => $UsernameData['name'],
                    'data_limit' => $jobvolume['Value'] * pow(1024, 3),
                    'expire' => $expire,
                    'online_at' => null,
                    'used_traffic' => $data_useage,
                    'links' => [],
                    'subscription_url' => strval($download_config['file']),
                    'sub_updated_at' => null,
                    'sub_last_user_agent' => null,
                );
            }
        } elseif ($Get_Data_Panel['type'] == "s_ui") {
            $UsernameData = GetClientsS_UI($username, $Get_Data_Panel['name_panel']);
            $onlinestatus = get_onlineclients_ui($Get_Data_Panel['name_panel'], $username);
            if (!isset($UsernameData['id'])) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['msg']
                );
            } else {
                $links = [];
                if (is_array($UsernameData['links'])) {
                    foreach ($UsernameData['links'] as $config) {
                        $links[] = $config['uri'];
                    }
                }
                $data_limit = $UsernameData['volume'];
                $useage = $UsernameData['up'] + $UsernameData['down'];
                $RemainingVolume = $data_limit - $useage;
                $expire = $UsernameData['expiry'];
                if ($UsernameData['enable']) {
                    $UsernameData['enable'] = "active";
                } elseif ($data_limit != 0 and $RemainingVolume < 0) {
                    $UsernameData['enable'] = "limited";
                } elseif ($expire - time() < 0 and $expire != 0) {
                    $UsernameData['enable'] = "expired";
                } else {
                    $UsernameData['enable'] = "disabled";
                }
                $setting_app = get_settig($Get_Data_Panel['name_panel']);
                $url = explode(":", $Get_Data_Panel['url_panel']);
                $url_sub = $url[0] . ":" . $url[1] . ":" . $setting_app['subPort'] . $setting_app['subPath'] . $username;
                $Output = array(
                    'status' => $UsernameData['enable'],
                    'username' => $UsernameData['name'],
                    'data_limit' => $data_limit,
                    'expire' => $expire,
                    'online_at' => $onlinestatus,
                    'used_traffic' => $useage,
                    'links' => $links,
                    'subscription_url' => $url_sub,
                    'sub_updated_at' => null,
                    'sub_last_user_agent' => null,
                );
            }
        } elseif ($Get_Data_Panel['type'] == "ibsng") {
            $UsernameData = GetUserIBsng($Get_Data_Panel['name_panel'], $username);
            if (!$UsernameData['status']) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['msg']
                );
            } else {
                $UsernameData = $UsernameData['data'];
                $data_limit = $UsernameData['data_limit'];
                $expire = strtotime($UsernameData['absolute_expire_date']);
                $UsernameData['enable'] = "active";
                $Output = array(
                    'status' => $UsernameData['enable'],
                    'username' => $UsernameData['username'],
                    'data_limit' => $data_limit,
                    'expire' => $expire,
                    'online_at' => strtolower($UsernameData['status']),
                    'used_traffic' => $UsernameData['used_traffic'],
                    'links' => [],
                    'subscription_url' => $UsernameData['password'],
                    'sub_updated_at' => null,
                    'sub_last_user_agent' => null,
                );
            }
        } elseif ($Get_Data_Panel['type'] == "mikrotik") {
            $UsernameData = GetUsermikrotik($Get_Data_Panel['name_panel'], $username)[0];
            if (isset($UsernameData['error'])) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['msg']
                );
            } else {
                $invocie = (function_exists('rx_invoice_on_panel')
                ? rx_invoice_on_panel((string) $username, (string) $Get_Data_Panel['name_panel'])
                : false);
                $traffic_get = GetUsermikrotik_volume($Get_Data_Panel['name_panel'], $UsernameData['.id']);
                $used_traffic = $traffic_get['total-upload'] + $traffic_get['total-download'];
                $data_limit = $invocie['Volume'] * pow(1024, 3);
                $expire = $invocie['time_sell'] + ($invocie['Service_time'] * 86400);
                $UsernameData['enable'] = "active";
                $Output = array(
                    'status' => $UsernameData['enable'],
                    'username' => $invocie['username'],
                    'data_limit' => $data_limit,
                    'expire' => $expire,
                    'online_at' => null,
                    'used_traffic' => $used_traffic,
                    'links' => [],
                    'subscription_url' => $UsernameData['password'],
                    'sub_updated_at' => null,
                    'sub_last_user_agent' => null,
                );
            }
        } else {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => 'Panel Not Found'
            );
        }
        return $Output;
    }
    function Revoke_sub($name_panel, $username)
    {
        $Output = array();
        $ManagePanel = new ManagePanel();
        $Get_Data_Panel = $this->loadPanel($name_panel, "name_panel");
        if (in_array($Get_Data_Panel['type'], ["marzban", "pasargard"], true)) {
            $revoke_sub = revoke_sub($username, $name_panel);
            if (isset($revoke_sub['detail']) && $revoke_sub['detail']) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $revoke_sub['detail']
                );
            } else {
                $config = new ManagePanel();
                $Data_User = $config->DataUser($name_panel, $username);
                if (!preg_match('/^(https?:\/\/)?([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(:\d+)?((\/[^\s\/]+)+)?$/', $Data_User['subscription_url'])) {
                    $Data_User['subscription_url'] = $Get_Data_Panel['url_panel'] . "/" . ltrim($Data_User['subscription_url'], "/");
                }
                $Output = array(
                    'status' => 'successful',
                    'configs' => $Data_User['links'],
                    'subscription_url' => $Data_User['subscription_url']
                );
            }
        } else if ($Get_Data_Panel['type'] == "marzneshin") {
            $revoke_sub = revoke_subm($username, $name_panel);
            if (isset($revoke_sub['detail']) && $revoke_sub['detail']) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $revoke_sub['detail']
                );
            } else {
                $config = new ManagePanel();
                $Data_User = $config->DataUser($name_panel, $username);
                $Data_User['links'] = [base64_decode(outputlunk($Data_User['subscription_url']))];
                $Output = array(
                    'status' => 'successful',
                    'configs' => $Data_User['links'],
                    'subscription_url' => $Data_User['subscription_url']
                );
            }
        } elseif ($Get_Data_Panel['type'] == "guard") {
            $revoke_sub = guardRevokeSubscriptions($name_panel, array($username));
            if ($revoke_sub['status'] === false) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $revoke_sub['msg']
                );
            } else {
                $Data_User = $ManagePanel->DataUser($name_panel, $username);
                $Output = array(
                    'status' => 'successful',
                    'configs' => $Data_User['links'] ?? array(),
                    'subscription_url' => $Data_User['subscription_url'] ?? null
                );
            }
        } elseif ($Get_Data_Panel['type'] == "x-ui_single") {
            $subId = bin2hex(random_bytes(8));
            $config = array(
                'settings' => json_encode(
                    array(
                        'clients' => array(
                            array(
                                "id" => generateUUID(),
                                "enable" => true,
                                "subId" => $subId,
                            )
                        ),
                    )
                )
            );
            $updateinbound = $ManagePanel->Modifyuser($username, $Get_Data_Panel['name_panel'], $config);
            if (!$updateinbound['status']) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => 'Unsuccessful'
                );
            } else {
                $Output = array(
                    'status' => 'successful',
                    'configs' => [outputlunk($Get_Data_Panel['linksubx'] . "/{$subId}")],
                    'subscription_url' => $Get_Data_Panel['linksubx'] . "/{$subId}",
                );
            }
        } elseif ($Get_Data_Panel['type'] == "alireza_single") {
            $subId = bin2hex(random_bytes(8));
            $config = array(
                'settings' => json_encode(
                    array(
                        'clients' => array(
                            array(
                                "id" => generateUUID(),
                                "enable" => true,
                                "subId" => $subId,
                            )
                        ),
                    )
                )
            );
            $updateinbound = $ManagePanel->Modifyuser($username, $Get_Data_Panel['name_panel'], $config);
            if (!$updateinbound['status']) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => 'Unsuccessful'
                );
            } else {
                $Output = array(
                    'status' => 'successful',
                    'configs' => [outputlunk($Get_Data_Panel['linksubx'] . "/{$subId}")],
                    'subscription_url' => $Get_Data_Panel['linksubx'] . "/{$subId}",
                );
            }
        } elseif ($Get_Data_Panel['type'] == "hiddify") {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => 'panel not supported'
            );
        } elseif ($Get_Data_Panel['type'] == "s_ui") {
            $clients = GetClientsS_UI($username, $name_panel);
            $password = bin2hex(random_bytes(16));
            $usernameac = $username;
            $configpanel = array(
                "object" => 'clients',
                'action' => "edit",
                "data" => json_encode(array(
                    "id" => $clients['id'],
                    "enable" => $clients['enable'],
                    "name" => $usernameac,
                    "config" => array(
                        "mixed" => array(
                            "username" => $usernameac,
                            "password" => generateAuthStr()
                        ),
                        "socks" => array(
                            "username" => $usernameac,
                            "password" => generateAuthStr()
                        ),
                        "http" => array(
                            "username" => $usernameac,
                            "password" => generateAuthStr()
                        ),
                        "shadowsocks" => array(
                            "name" => $usernameac,
                            "password" => $password
                        ),
                        "shadowsocks16" => array(
                            "name" => $usernameac,
                            "password" => $password
                        ),
                        "shadowtls" => array(
                            "name" => $usernameac,
                            "password" => $password
                        ),
                        "vmess" => array(
                            "name" => $usernameac,
                            "uuid" => generateUUID(),
                            "alterId" => 0
                        ),
                        "vless" => array(
                            "name" => $usernameac,
                            "uuid" => generateUUID(),
                            "flow" => ""
                        ),
                        "trojan" => array(
                            "name" => $usernameac,
                            "password" => generateAuthStr()
                        ),
                        "naive" => array(
                            "username" => $usernameac,
                            "password" => generateAuthStr()
                        ),
                        "hysteria" => array(
                            "name" => $usernameac,
                            "auth_str" => generateAuthStr()
                        ),
                        "tuic" => array(
                            "name" => $usernameac,
                            "uuid" => generateUUID(),
                            "password" => generateAuthStr()
                        ),
                        "hysteria2" => array(
                            "name" => $usernameac,
                            "password" => generateAuthStr()
                        )
                    ),
                    "inbounds" => $clients['inbounds'],
                    "links" => [],
                    "volume" => $clients['volume'],
                    "expiry" => $clients['expiry'],
                    "desc" => $clients['desc']
                )),
            );
            $result = updateClientS_ui($Get_Data_Panel['name_panel'], $configpanel);
            if (!$result['success']) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => 'Unsuccessful'
                );
            } else {
                $setting_app = get_settig($Get_Data_Panel['name_panel']);
                $url = explode(":", $Get_Data_Panel['url_panel']);
                $url_sub = $url[0] . ":" . $url[1] . ":" . $setting_app['subPort'] . $setting_app['subPath'] . $username;
                $Output = array(
                    'status' => 'successful',
                    'configs' => [outputlunk($url_sub)],
                    'subscription_url' => $url_sub,
                );
            }
        } else {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => 'Panel Not Found'
            );
        }
        return $Output;
    }
    function RemoveUser($name_panel, $username)
    {
        $Output = array();
        $Get_Data_Panel = $this->loadPanel($name_panel, "name_panel");
        if (in_array($Get_Data_Panel['type'], ["marzban", "pasargard"], true)) {
            $UsernameData = removeuser($Get_Data_Panel['name_panel'], $username);
            if (!empty($UsernameData['status']) && $UsernameData['status'] != 200) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['status']
                );
            } elseif (!empty($UsernameData['error'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['error']
                );
            }
            $UsernameData = json_decode($UsernameData['body'], true);
            if ($UsernameData['detail'] != "User successfully deleted") {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['detail']
                );
            } else {
                $Output = array(
                    'status' => 'successful',
                    'username' => $username,
                );
            }
        } elseif ($Get_Data_Panel['type'] == "marzneshin") {
            $UsernameData = removeuserm($Get_Data_Panel['name_panel'], $username);
            if (isset($UsernameData['detail']) && $UsernameData['detail']) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['detail']
                );
            } else {
                $Output = array(
                    'status' => 'successful',
                    'username' => $username,
                );
            }
        } elseif ($Get_Data_Panel['type'] == "x-ui_single") {
            $UsernameData = removeClient($Get_Data_Panel['name_panel'], $username);
            if (!empty($UsernameData['status']) && $UsernameData['status'] != 200) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['status']
                );
            } elseif (!empty($UsernameData['error'])) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['error']
                );
            }
            $UsernameData = json_decode($UsernameData['body'], true);
            if (!$UsernameData['success']) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['msg']
                );
            } else {
                $Output = array(
                    'status' => 'successful',
                    'username' => $username,
                );
            }
        } elseif ($Get_Data_Panel['type'] == "alireza_single") {
            $UsernameData = removeClientalireza_single($Get_Data_Panel['name_panel'], $username);
            if (!$UsernameData['success']) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['msg']
                );
            } else {
                $Output = array(
                    'status' => 'successful',
                    'username' => $username,
                );
            }
        } elseif ($Get_Data_Panel['type'] == "hiddify") {
            $data_user = getdatauser($username, $name_panel);
            removeuserhi($name_panel, $data_user['uuid']);
            $Output = array(
                'status' => 'successful',
                'msg' => ""
            );
        } elseif ($Get_Data_Panel['type'] == "Manualsale") {
            update("manualsell", "status", "delete", "username", $username);
            $Output = array(
                'status' => 'successful',
                'username' => $username,
            );
        } elseif ($Get_Data_Panel['type'] == "WGDashboard") {
            $UsernameData = remove_userwg($Get_Data_Panel['name_panel'], $username);
            if (!$UsernameData['status']) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['msg']
                );
            } else {
                $Output = array(
                    'status' => 'successful',
                    'username' => $username,
                );
            }
        } elseif ($Get_Data_Panel['type'] == "s_ui") {
            $UsernameData = removeClientS_ui($Get_Data_Panel['name_panel'], $username);
            if (!$UsernameData['success']) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['msg']
                );
            } else {
                $Output = array(
                    'status' => 'successful',
                    'username' => $username,
                );
            }
        } elseif ($Get_Data_Panel['type'] == "guard") {
            $UsernameData = guardDeleteSubscriptions($Get_Data_Panel['name_panel'], array($username));
            if ($UsernameData['status'] === false) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['msg']
                );
            } else {
                $Output = array(
                    'status' => 'successful',
                    'username' => $username,
                );
            }
        } elseif ($Get_Data_Panel['type'] == "ibsng") {
            $UsernameData = deleteUserIBSng($Get_Data_Panel['name_panel'], $username);
            if (!$UsernameData['status']) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['msg']
                );
            } else {
                $Output = array(
                    'status' => 'successful',
                    'username' => $username,
                );
            }
        } elseif ($Get_Data_Panel['type'] == "mikrotik") {
            $UsernameData = GetUsermikrotik($Get_Data_Panel['name_panel'], $username)[0];
            if (isset($UsernameData['error'])) {
                $Output = array(
                    'status' => 'Unsuccessful',
                    'msg' => $UsernameData['msg']
                );
            } else {
                deleteUser_mikrotik($Get_Data_Panel['name_panel'], $UsernameData['.id']);
                $Output = array(
                    'status' => 'successful',
                    'username' => $username,
                );
            }
        } else {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => 'Panel Not Found'
            );
        }
        return $Output;
    }
    function Modifyuser($username, $name_panel, $config = array())
    {

        $Output = array();
        $Get_Data_Panel = $this->loadPanel($name_panel, "name_panel");
        if (!$Get_Data_Panel || !is_array($Get_Data_Panel) || empty($Get_Data_Panel['type'])) {
            return array(
                'status' => false,
                'msg' => 'Panel Not Found'
            );
        }
        if (in_array($Get_Data_Panel['type'], ["marzban", "pasargard"], true)) {
            // پنل پاسارگاد همیشه از payloadِ نسخه‌ی جدید استفاده می‌کند، حتی اگر
            // ستون version_panel رویش '0' مانده باشد (پنل‌هایی که قبلاً از پنل وب
            // اضافه شده‌اند دقیقاً همین وضعیت را دارند). بدون این، proxy_settings
            // دوباره تزریق نمی‌شد و PUT ناقص می‌رفت.
            if ((string)($Get_Data_Panel['version_panel'] ?? '0') === '1'
                || $Get_Data_Panel['type'] === 'pasargard') {
                $result = getuser($username, $name_panel);
                if (!empty($result['body'])) {
                    $result = json_decode($result['body'], true);
                    if (is_array($result) && isset($result['proxy_settings'])) {
                        $config['proxy_settings'] = $result['proxy_settings'];
                    }
                }
            }
            $modify = Modifyuser($name_panel, $username, $config);
            if (!empty($modify['error'])) {
                return array(
                    'status' => false,
                    'msg' => $modify['error']
                );
            } elseif (self::rx_http_failed($modify) !== null) {
                return array(
                    'status' => false,
                    'msg' => 'error code : ' . self::rx_http_failed($modify)
                );
            }
            $modifycheck = json_decode($modify['body'], true);
            if (!empty($modifycheck['detail'])) {
                return array(
                    'status' => false,
                    'msg' => $modifycheck['detail']
                );
            }
            return array(
                'status' => true,
                'data' => $modify
            );
        } elseif ($Get_Data_Panel['type'] == "marzneshin") {
            $config['username'] = $username;
            $modify = Modifyuserm($name_panel, $username, $config);
            if (!empty($modify['error'])) {
                return array(
                    'status' => false,
                    'msg' => $modify['error']
                );
            } elseif (self::rx_http_failed($modify) !== null) {
                return array(
                    'status' => false,
                    'msg' => 'error code : ' . self::rx_http_failed($modify)
                );
            }
            $modifycheck = json_decode($modify['body'], true);
            if (!empty($modifycheck['detail'])) {
                return array(
                    'status' => false,
                    'msg' => $modifycheck['detail']
                );
            }
            return array(
                'status' => true,
                'data' => $modify
            );
        } elseif ($Get_Data_Panel['type'] == "x-ui_single") {
            $clients = get_clinets($username, $name_panel);
            if (!empty($clients['error'])) {
                return array(
                    'status' => false,
                    'msg' => $clients['error']
                );
            } elseif (!empty($clients['status']) && $clients['status'] != 200) {
                return array(
                    'status' => false,
                    'msg' => json_encode($clients)
                );
            }
            $clients = json_decode($clients['body'], true);
            if (!is_array($clients)) {
                return array(
                    'status' => false,
                    'msg' => 'object invalid'
                );
            }
            if (empty($clients['obj'])) {
                return array(
                    'status' => false,
                    'msg' => "User not found"
                );
            }
            $clients = $clients['obj'];
            $configs = array(
                'id' => intval($clients['inboundId']),
                'settings' => json_encode(
                    array(
                        'clients' => array(
                            array(
                                "id" => $clients['uuid'],
                                "flow" => "",
                                "email" => $clients['email'],
                                "totalGB" => $clients['total'],
                                "expiryTime" => $clients['expiryTime'],
                                "enable" => true,
                                "subId" => $clients['subId'],
                            )
                        ),
                        'decryption' => 'none',
                        'fallbacks' => array(),
                    )
                ),
            );
            $configs['settings'] = json_encode(array_replace_recursive(json_decode($configs['settings'], true), json_decode($config['settings'], true)));
            $modify = updateClient($Get_Data_Panel['name_panel'], $clients['uuid'], $configs);
            if (!empty($modify['error'])) {
                return array(
                    'status' => false,
                    'msg' => $modify['error']
                );
            } elseif (!empty($modify['status']) && $modify['status'] != 200) {
                return array(
                    'status' => false,
                    'msg' => 'error code : ' . $modify['status']
                );
            }
            $modify = json_decode($modify['body'], true);
            if (!$modify['success']) {
                return array(
                    'status' => false,
                    'msg' => 'error :' . $modify['msg']
                );
            }
            return array(
                'status' => true,
                'data' => $modify
            );
        } elseif ($Get_Data_Panel['type'] == "alireza_single") {
            $clients = get_clinetsalireza($username, $name_panel)[0];
            $configs = array(
                'id' => intval($Get_Data_Panel['inboundid']),
                'settings' => json_encode(
                    array(
                        'clients' => array(
                            array(
                                "id" => $clients['id'],
                                "flow" => $clients['flow'],
                                "email" => $clients['email'],
                                "totalGB" => $clients['totalGB'],
                                "expiryTime" => $clients['expiryTime'],
                                "enable" => true,
                                "subId" => $clients['subId'],
                            )
                        ),
                        'decryption' => 'none',
                        'fallbacks' => array(),
                    )
                ),
            );
            $configs['settings'] = json_encode(array_replace_recursive(json_decode($configs['settings'], true), json_decode($config['settings'], true)));
            $modify = updateClientalireza($Get_Data_Panel['name_panel'], $username, $configs);
            if (!empty($modify['error'])) {
                return array(
                    'status' => false,
                    'msg' => $modify['error']
                );
            } elseif (!empty($modify['status']) && $modify['status'] != 200) {
                return array(
                    'status' => false,
                    'msg' => 'error code : ' . $modify['status']
                );
            }
            $modify = json_decode($modify['body'], true);
            if (!$modify['success']) {
                return array(
                    'status' => false,
                    'msg' => 'error :' . $modify['msg']
                );
            }
            return array(
                'status' => true,
                'data' => $modify
            );
        } elseif ($Get_Data_Panel['type'] == "hiddify") {
            $modify = updateuserhi($username, $name_panel, $config);
            if (!empty($modify['error'])) {
                return array(
                    'status' => false,
                    'msg' => $modify['error']
                );
            } elseif (!empty($modify['status']) && $modify['status'] != 200) {
                return array(
                    'status' => false,
                    'msg' => 'error code : ' . $modify['status']
                );
            }
            $modify = json_decode($modify['body'], true);
            return array(
                'status' => true,
                'data' => $modify
            );
        } elseif ($Get_Data_Panel['type'] == "WGDashboard") {
            $data_user = get_userwg($username, $name_panel);
            if (isset($data_user['status']) && $data_user['status'] === false && !isset($data_user['id'])) {
                return array(
                    'status' => false,
                    'msg' => isset($data_user['msg']) ? $data_user['msg'] : ''
                );
            }
            $configs = array(
                "DNS" => $data_user['DNS'],
                "allowed_ip" => $data_user['allowed_ip'],
                "endpoint_allowed_ip" => "0.0.0.0/0",
                "jobs" => $data_user['jobs'],
                "id" => $data_user['id'],
                "keepalive" => $data_user['keepalive'],
                "mtu" => $data_user['mtu'],
                "name" => $data_user['name'],
                "preshared_key" => $data_user['preshared_key'],
                "private_key" => $data_user['private_key']
            );
            $configs = array_merge($configs, $config);
            $modify = updatepear($Get_Data_Panel['name_panel'], $configs);
            if (isset($modify['status']) && $modify['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => isset($modify['msg']) ? $modify['msg'] : ''
                );
            }
            if (!empty($modify['error'])) {
                return array(
                    'status' => false,
                    'msg' => $modify['error']
                );
            } elseif (!empty($modify['status']) && $modify['status'] != 200) {
                return array(
                    'status' => false,
                    'msg' => 'error code : ' . $modify['status']
                );
            }
            $modify = json_decode($modify['body'], true);
            return array(
                'status' => true,
                'data' => $modify
            );
        } elseif ($Get_Data_Panel['type'] == "s_ui") {
            $clients = GetClientsS_UI($username, $name_panel);
            if (!$clients)
                return [];
            $usernameac = $username;
            $configs = array(
                "object" => 'clients',
                'action' => "edit",
                "data" => array(
                    "id" => $clients['id'],
                    "enable" => $clients['enable'],
                    "name" => $usernameac,
                    "config" => $clients['config'],
                    "inbounds" => $clients['inbounds'],
                    "links" => $clients['links'],
                    "volume" => $clients['volume'],
                    "expiry" => $clients['expiry'],
                    "desc" => $clients['desc']
                ),
            );
            $configs['data'] = array_merge($configs['data'], $config);
            $configs['data'] = json_encode($configs['data'], true);
            $modify = updateClientS_ui($Get_Data_Panel['name_panel'], $configs);
            return array(
                'status' => true,
                'data' => $modify
            );
        } elseif ($Get_Data_Panel['type'] == "guard") {
            if (isset($config['limit_expire'])) {
                $config['limit_expire'] = guardNormalizeExpire($config['limit_expire']);
            }
            $modify = guardUpdateSubscription($Get_Data_Panel['name_panel'], $username, $config);
            if ($modify['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => $modify['msg']
                );
            }
            return array(
                'status' => true,
                'data' => $modify
            );
        }
    }
    function Change_status($username, $name_panel)
    {
        $ManagePanel = new ManagePanel();
        $DataUserOut = $ManagePanel->DataUser($name_panel, $username);
        $Get_Data_Panel = $this->loadPanel($name_panel, "name_panel");
        if ($DataUserOut['status'] == "Unsuccessful") {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => $DataUserOut['detail']
            );
            return;
        }
        if (!in_array($DataUserOut['status'], ["active", "disabled", "expired", "limited", "on_hold"])) {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => "status invalid"
            );
            return;
        }
        if (in_array($Get_Data_Panel['type'], ["marzban", "pasargard"], true)) {
            if ($DataUserOut['status'] == "active") {
                $status = "disabled";
            } else {
                $status = "active";
            }
            $configs = array("status" => $status);
            $ManagePanel->Modifyuser($username, $name_panel, $configs);
            $Output = array(
                'status' => 'successful',
                'msg' => null
            );
        } elseif ($Get_Data_Panel['type'] == "marzneshin") {
            if ($DataUserOut['status'] == "active") {
                disableduser($name_panel, $username);
            } else {
                enableuser($name_panel, $username);
            }
            $Output = array(
                'status' => 'successful',
                'msg' => null
            );
        } elseif ($Get_Data_Panel['type'] == "x-ui_single") {
            if ($DataUserOut['status'] == "active") {
                $status = false;
            } else {
                $status = true;
            }
            $configs = array(
                'settings' => json_encode(array(
                    'clients' => array(
                        array(
                            "enable" => $status,
                        )
                    ),
                )),
            );
            $ManagePanel->Modifyuser($username, $name_panel, $configs);
            $Output = array(
                'status' => 'successful',
                'msg' => null
            );
        } elseif ($Get_Data_Panel['type'] == "alireza_single") {
            if ($DataUserOut['status'] == "active") {
                $status = false;
            } else {
                $status = true;
            }
            $configs = array(
                'settings' => json_encode(array(
                    'clients' => array(
                        array(
                            "enable" => $status,
                        )
                    ),
                )),
            );
            $ManagePanel->Modifyuser($username, $name_panel, $configs);
            $Output = array(
                'status' => 'successful',
                'msg' => null
            );
        } elseif ($Get_Data_Panel['type'] == "hiddify") {
            $Output = array(
                'status' => 'Unsuccessful',
                'msg' => null
            );
        } elseif ($Get_Data_Panel['type'] == "s_ui") {
            if ($DataUserOut['status'] == "active") {
                $status = false;
            } else {
                $status = true;
            }
            $configs = array("enable" => $status);
            $ManagePanel->Modifyuser($username, $name_panel, $configs);
            $Output = array(
                'status' => 'successful',
                'msg' => null
            );
        } elseif ($Get_Data_Panel['type'] == "guard") {
            $action = $DataUserOut['status'] == "active" ? "disable" : "enable";
            $toggle = guardToggleSubscriptions($Get_Data_Panel['name_panel'], array($username), $action);
            if ($toggle['status'] === false) {
                return array(
                    'status' => 'Unsuccessful',
                    'msg' => $toggle['msg'] ?? 'Toggle failed'
                );
            }
            $Output = array(
                'status' => 'successful',
                'msg' => null
            );
        }

        return $Output;
    }
    function ResetUserDataUsage($username, $name_panel)
    {
        $panel = $this->loadPanel($name_panel, "name_panel");
        if ($panel == false) {
            return array(
                'status' => false,
                'msg' => 'data not found'
            );
        }
        if (in_array($panel['type'], ["marzban", "pasargard"], true)) {
            $reset = ResetUserDataUsage($username, $panel['name_panel']);
            if (!empty($reset['status']) && $reset['status'] != 200) {
                return array(
                    'status' => false,
                    'msg' => 'error code : ' . $reset['status']
                );
            } elseif (!empty($reset['error'])) {
                return array(
                    'status' => false,
                    'msg' => 'error  : ' . $reset['error']
                );
            }
            $reset = json_decode($reset['body'], true);
            if (!empty($reset['detail'])) {
                return array(
                    'status' => false,
                    'msg' => $reset['detail']
                );
            }
            return array(
                'status' => true,
                'msg' => 'successful'
            );
        } elseif ($panel['type'] == "marzneshin") {
            $reset = ResetUserDataUsagem($username, $panel['name_panel']);
            if (!empty($reset['status']) && $reset['status'] != 200) {
                return array(
                    'status' => false,
                    'msg' => 'error code : ' . $reset['status']
                );
            } elseif (!empty($reset['error'])) {
                return array(
                    'status' => false,
                    'msg' => 'error  : ' . $reset['error']
                );
            }
            $reset = json_decode($reset['body'], true);
            if (!empty($reset['detail'])) {
                return array(
                    'status' => false,
                    'msg' => $reset['detail']
                );
            }
            return array(
                'status' => true,
                'msg' => 'successful'
            );
        } elseif ($panel['type'] == 'x-ui_single') {
            $reset = ResetUserDataUsagex_uisin($username, $panel['name_panel']);
            if (!empty($reset['status']) && $reset['status'] != 200) {
                return array(
                    'status' => false,
                    'msg' => 'error code : ' . $reset['status']
                );
            } elseif (!empty($reset['error'])) {
                return array(
                    'status' => false,
                    'msg' => 'error  : ' . $reset['error']
                );
            }
            $reset = json_decode($reset['body'], true);
            if (!$reset['success']) {
                return array(
                    'status' => false,
                    'msg' => 'error :' . $reset['msg']
                );
            }
            return array(
                'status' => true,
                'data' => $reset
            );
        } elseif ($panel['type'] == 'alireza_single') {
            $reset = ResetUserDataUsagealirezasin($username, $panel['name_panel']);
            if (!empty($reset['status']) && $reset['status'] != 200) {
                return array(
                    'status' => false,
                    'msg' => 'error code : ' . $reset['status']
                );
            } elseif (!empty($reset['error'])) {
                return array(
                    'status' => false,
                    'msg' => 'error  : ' . $reset['error']
                );
            }
            $reset = json_decode($reset['body'], true);
            if (!$reset['success']) {
                return array(
                    'status' => false,
                    'msg' => 'error :' . $reset['msg']
                );
            }
            return array(
                'status' => true,
                'data' => $reset
            );
        } elseif ($panel['type'] == "WGDashboard") {
            $allowResponse = allowAccessPeers($panel['name_panel'], $username);
            if (isset($allowResponse['status']) && $allowResponse['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => isset($allowResponse['msg']) ? $allowResponse['msg'] : ''
                );
            }
            $datauser = get_userwg($username, $panel['name_panel']);
            if (isset($datauser['status']) && $datauser['status'] === false && !isset($datauser['id'])) {
                return array(
                    'status' => false,
                    'msg' => isset($datauser['msg']) ? $datauser['msg'] : ''
                );
            }
            $reset = ResetUserDataUsagewg($datauser['id'], $panel['name_panel']);
            if (isset($reset['status']) && $reset['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => isset($reset['msg']) ? $reset['msg'] : ''
                );
            }
            if (!empty($reset['status']) && $reset['status'] != 200) {
                return array(
                    'status' => false,
                    'msg' => 'error code : ' . $reset['status']
                );
            } elseif (!empty($reset['error'])) {
                return array(
                    'status' => false,
                    'msg' => 'error  : ' . $reset['error']
                );
            }
            $reset = json_decode($reset['body'], true);
            return array(
                'status' => true,
                'data' => $reset
            );
        } elseif ($panel['type'] == "hiddify") {
            return array(
                'status' => true
            );
        } elseif ($panel['type'] == "s_ui") {
            ResetUserDataUsages_ui($username, $name_panel);
            return array(
                'status' => true
            );
        } elseif ($panel['type'] == "guard") {
            $reset = guardResetSubscriptions($panel['name_panel'], array($username));
            if ($reset['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => $reset['msg']
                );
            }
            return array(
                'status' => true
            );
        }
    }
    function extend($Method_extend, $new_limit, $time_day, $username, $code_product, $name_panel)
    {
        $panel = $this->loadPanel($name_panel, "code_panel");
        $product = select("product", "*", "code_product", $code_product, "select");
        $invoice = (function_exists('rx_invoice_on_panel')
                ? rx_invoice_on_panel((string) $username, (string) (is_array($panel) ? ($panel['name_panel'] ?? '') : ''))
                : false);
        if ($code_product == "custom_volume")
            $product = true;
        if ($panel == false || $product == false) {
            return array(
                'status' => false,
                'msg' => 'data not found'
            );
        }
        $data_user = $this->DataUser($panel['name_panel'], $username);
        if ($data_user['status'] == "Unsuccessful") {
            return array(
                'status' => false,
                'msg' => $data_user['msg']
            );
        }
        $notifctions = json_encode(array(
            'volume' => false,
            'time' => false,
        ));
        update("invoice", "notifctions", $notifctions, 'id_invoice', $invoice['id_invoice']);
        $data_limit_old = $data_user['data_limit'];
        $time_old = $data_user['expire'];
        $time_old = time() - $time_old > 0 ? time() : $time_old;
        $data_limit_new = $new_limit == 0 ? 0 : $new_limit * pow(1024, 3);
        $data_limit_new_add = $new_limit == 0 ? 0 : $data_limit_old + ($new_limit * pow(1024, 3));
        $time_new = $time_day == 0 ? 0 : time() + $time_day * 86400;
        $time_old = $time_old == 0 ? time() : $time_old;
        $time_new_add = $time_day == 0 ? 0 : $time_old + ($time_day * 86400);

        $inbound_id = isset($panel['inboundid']) ? $panel['inboundid'] : 1;
        $inbounds = is_string($panel['inbounds']) ? json_decode($panel['inbounds']) : "{}";
        $inbounds = $product['inbounds'] != null ? json_decode($product['inbounds']) : $inbounds;
        if ($panel['type'] != "WGDashboard") {
            update("invoice", 'user_info', null, "username", $username);
        }
        update("invoice", 'uuid', null, "username", $username);
        update("invoice", 'Status', "active", "username", $username);
        // [FIX تمدیدِ بی‌اثر] این زنجیره هیچ شاخه‌ی else نداشت. اگر ستون
        // Methodextend خالی یا مقدارِ ناشناخته باشد، هیچ‌کدام از شاخه‌ها اجرا
        // نمی‌شد: نه ریستِ حجم انجام می‌شد نه حجمِ قبلی اضافه — فقط تاریخِ انقضا
        // جلو می‌رفت. یعنی تمدید «موفق» گزارش می‌شد ولی سرویس همچنان
        // «پایان حجم» می‌ماند، چون حجمِ مصرفی دست‌نخورده باقی می‌ماند.
        // پنل‌هایی که از پنلِ وب اضافه شده‌اند دقیقاً همین وضعیت را دارند:
        // دستور INSERT وب این ستون را پر نمی‌کند و NULL می‌ماند.
        // پیش‌فرض را همانی می‌گذاریم که خودِ ربات هنگام افزودن پنل ست می‌کند.
        $rxKnownExtend = [
            "ریست حجم و زمان",
            "اضافه شدن زمان و حجم به ماه بعد",
            "ریست زمان و اضافه کردن حجم قبلی",
            "ریست شدن حجم و اضافه شدن زمان",
            "اضافه شدن زمان و تبدیل حجم کل به حجم باقی مانده",
        ];
        $Method_extend = trim((string) $Method_extend);
        if (!in_array($Method_extend, $rxKnownExtend, true)) {
            error_log('[panels] extend: Methodextend خالی/ناشناخته («' . $Method_extend
                . '») برای پنل ' . (string) ($panel['name_panel'] ?? '?')
                . ' — پیش‌فرضِ «ریست حجم و زمان» اعمال شد');
            $Method_extend = "ریست حجم و زمان";
        }

        if ($Method_extend == "ریست حجم و زمان") {
            $reset = $this->ResetUserDataUsage($username, $panel['name_panel']);
            if ($reset['status'] == false) {
                return array(
                    'status' => false,
                    'msg' => 'error reset : ' . $reset['msg']
                );
            }
        } elseif ($Method_extend == "اضافه شدن زمان و حجم به ماه بعد") {
            $data_limit_new = $data_limit_new_add;
            $time_new = $time_new_add;
        } elseif ($Method_extend == "ریست زمان و اضافه کردن حجم قبلی") {
            $data_limit_new = $data_limit_new_add;
        } elseif ($Method_extend == "ریست شدن حجم و اضافه شدن زمان") {
            $reset = $this->ResetUserDataUsage($username, $panel['name_panel']);
            if ($reset['status'] == false) {
                return array(
                    'status' => false,
                    'msg' => 'error reset : ' . $reset['msg']
                );
            }
            $time_new = $time_new_add;
        } elseif ($Method_extend == "اضافه شدن زمان و تبدیل حجم کل به حجم باقی مانده") {
            $reset = $this->ResetUserDataUsage($username, $panel['name_panel']);
            if ($reset['status'] == false) {
                return array(
                    'status' => false,
                    'msg' => 'error reset : ' . $reset['msg']
                );
            }
            $time_new = $time_new_add;
            $data_limit_last = $data_user['data_limit'] - $data_user['used_traffic'];
            $data_limit_last = $data_limit_last < 0 ? 0 : $data_limit_last;
            $data_limit_new = $data_limit_new + $data_limit_last;
        }
        if ($panel['type'] == "marzban") {
            $data = array(
                'data_limit' => $data_limit_new,
                'expire' => $time_new,
                'inbounds' => $inbounds,
            );
            if ($invoice != false && $invoice['uuid'] != null) {
                $data['proxies'] = json_decode($invoice['uuid'], true);
            }
        } elseif ($panel['type'] == "pasargard") {
            // [FIX تمدیدِ بی‌اثر روی پاسارگاد]
            // پاسارگاد (PasarGuard) برای ویرایشِ کاربر نام‌فیلدهای دیگری دارد:
            // group_ids / proxy_settings — نه inbounds / proxies. قبلاً همان
            // payloadِ Marzban فرستاده می‌شد، پنل ردش می‌کرد، و چون چکِ خطا فقط
            // status == 500 را می‌دید، نتیجه «موفق» گزارش می‌شد: مبلغ کم می‌شد،
            // فاکتور در ربات تمدید می‌شد، ولی روی پنل هیچ چیز عوض نمی‌شد —
            // سرویس همچنان «پایان حجم» می‌ماند. (proxy_settings را خودِ
            // Modifyuser بالاتر زنده می‌خواند و تزریق می‌کند.)
            $data = array(
                'data_limit' => $data_limit_new,
                'expire' => $time_new,
            );
            // عضویتِ واقعیِ کاربر را زنده می‌خوانیم و همان را برمی‌گردانیم.
            // اگر group_ids را حذف کنیم، بسته به نسخه‌ی پنل ممکن است عضویتش
            // پاک شود و کانفیگ‌هایش از کار بیفتد؛ و اگر گروه‌های پیش‌فرضِ پنل را
            // بفرستیم، کاربری که دستی در گروهِ دیگری گذاشته شده جابه‌جا می‌شود.
            // پس اگر خواندن شکست خورد، عمداً حدس نمی‌زنیم و فیلد را نمی‌فرستیم.
            $rxLiveGroups = null;
            $rxLiveUser = getuser($username, $panel['name_panel']);
            if (empty($rxLiveUser['error']) && !empty($rxLiveUser['body'])) {
                $rxLiveDecoded = json_decode((string) $rxLiveUser['body'], true);
                if (is_array($rxLiveDecoded) && isset($rxLiveDecoded['group_ids']) && is_array($rxLiveDecoded['group_ids'])) {
                    $rxLiveGroups = array_values(array_map('intval', $rxLiveDecoded['group_ids']));
                }
            }
            if (is_array($rxLiveGroups) && $rxLiveGroups !== []) {
                $data['group_ids'] = $rxLiveGroups;
            } else {
                error_log('[panels] pasargard renew: could not read live group_ids for '
                    . $username . ' on ' . (string) $panel['name_panel']
                    . ' — omitting group_ids rather than guessing');
            }
        } elseif ($panel['type'] == "marzneshin") {
            $expire_strotegy = $time_new == 0 ? "never" : "fixed_date";
            $time_new = date('c', $time_new);
            $data = array(
                'username' => $username,
                'expire_date' => $time_new,
                'expire_strategy' => $expire_strotegy,
                'data_limit' => $data_limit_new
            );
        } elseif ($panel['type'] == "x-ui_single") {
            $clientData = array(
                "totalGB" => $data_limit_new,
                "expiryTime" => $time_new * 1000,
                "enable" => true,
            );
            // [FEATURE] محدودیت واقعی IP هم‌زمان: مقدار limit_ip محصول رو هم روی تمدید حفظ می‌کنیم.
            if (is_array($product) && isset($product['limit_ip']) && $product['limit_ip'] !== null && $product['limit_ip'] !== '') {
                $clientData['limitIp'] = (int) $product['limit_ip'];
            }
            $data = array(
                'settings' => json_encode(
                    array(
                        'clients' => array($clientData),
                        'decryption' => 'none',
                        'fallbacks' => array(),
                    )
                ),
            );
        } elseif ($panel['type'] == "alireza_single") {
            $data = array(
                'id' => intval($inbound_id),
                'settings' => json_encode(
                    array(
                        'clients' => array(
                            array(
                                "totalGB" => $data_limit_new,
                                "expiryTime" => $time_new * 1000,
                                "enable" => true,
                            )
                        ),
                        'decryption' => 'none',
                        'fallbacks' => array(),
                    )
                ),
            );
        } elseif ($panel['type'] == "WGDashboard") {
            if ($data_user['status'] == "limited" || $data_user['status'] == "expired") {
                $reset = $this->ResetUserDataUsage($username, $panel['name_panel']);
                if ($reset['status'] == false) {
                    return array(
                        'status' => false,
                        'msg' => 'error reset : ' . $reset['msg']
                    );
                }
            }
            $allowResponse = allowAccessPeers($panel['name_panel'], $username);
            if (isset($allowResponse['status']) && $allowResponse['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => isset($allowResponse['msg']) ? $allowResponse['msg'] : ''
                );
            }
            $datauser = get_userwg($username, $panel['name_panel']);
            if (isset($datauser['status']) && $datauser['status'] === false && !isset($datauser['id'])) {
                return array(
                    'status' => false,
                    'msg' => isset($datauser['msg']) ? $datauser['msg'] : ''
                );
            }
            $count = 0;
            foreach ($datauser['jobs'] as $jobsvolume) {
                if ($jobsvolume['Field'] == "date") {
                    break;
                }
                $count += 1;
            }
            $datam = array(
                "Job" => $datauser['jobs'][$count],
            );
            $deleteJob = deletejob($panel['name_panel'], $datam);
            if (isset($deleteJob['status']) && $deleteJob['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => isset($deleteJob['msg']) ? $deleteJob['msg'] : ''
                );
            }
            $count = 0;
            foreach ($datauser['jobs'] as $jobsvolume) {
                if ($jobsvolume['Field'] == "total_data") {
                    break;
                }
                $count += 1;
            }
            $datam = array(
                "Job" => $datauser['jobs'][$count],
            );
            $deleteJob = deletejob($panel['name_panel'], $datam);
            if (isset($deleteJob['status']) && $deleteJob['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => isset($deleteJob['msg']) ? $deleteJob['msg'] : ''
                );
            }
            $time_new = date("Y-m-d H:i:s", $time_new);
            if ($time_day != 0) {
                $setJob = setjob($panel['name_panel'], "date", $time_new, $datauser['id']);
                if (isset($setJob['status']) && $setJob['status'] === false) {
                    return array(
                        'status' => false,
                        'msg' => isset($setJob['msg']) ? $setJob['msg'] : ''
                    );
                }
            }
            if ($new_limit != 0) {
                $setJob = setjob($panel['name_panel'], "total_data", $data_limit_new / pow(1024, 3), $datauser['id']);
                if (isset($setJob['status']) && $setJob['status'] === false) {
                    return array(
                        'status' => false,
                        'msg' => isset($setJob['msg']) ? $setJob['msg'] : ''
                    );
                }
            }
            return array(
                'status' => true
            );
        } elseif ($panel['type'] == "hiddify") {
            $day = $time_new - time();
            $data = array(
                "package_days" => $day / 86400,
                "usage_limit_GB" => $data_limit_new / pow(1024, 3),
                "start_date" => null
            );
            if (in_array($Method_extend, ["ریست حجم و زمان", "ریست شدن حجم و اضافه شدن زمان", "اضافه شدن زمان و تبدیل حجم کل به حجم باقی مانده"])) {
                $data['current_usage_GB'] = "0";
            }
        } elseif ($panel['type'] == "s_ui") {
            $data = array(
                "volume" => $data_limit_new,
                "expiry" => $time_new
            );
        } elseif ($panel['type'] == "guard") {
            $limitExpire = guardNormalizeExpire($time_new);
            $serviceIdsSource = isset($data_user['service_ids']) ? $data_user['service_ids'] : ($panel['guard_service_ids'] ?? null);
            $serviceResult = guardResolveServiceIds($panel['name_panel'], $serviceIdsSource);
            if ($serviceResult['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => $serviceResult['msg']
                );
            }
            $data = array(
                "limit_usage" => $data_limit_new,
                "limit_expire" => $limitExpire,
                "service_ids" => $serviceResult['service_ids']
            );
        }
        $extend = $this->Modifyuser($username, $panel['name_panel'], $data);
        if ($extend['status'] == false) {
            return array(
                'status' => false,
                'msg' => $extend['msg']
            );
        }

        // [FIX تمدیدِ «موفقِ» بی‌اثر]
        // پنل می‌تواند به PUT پاسخ ۲۰۰ بدهد ولی عملاً چیزی را عوض نکند (مثلاً
        // فیلدی که نمی‌شناسد را بی‌صدا نادیده بگیرد). آن‌وقت ربات «تمدید موفق»
        // می‌گفت، پول کم می‌شد، و سرویس همچنان «پایان حجم» می‌ماند.
        // پس نتیجه را از خودِ پنل می‌خوانیم و تایید می‌کنیم.
        //
        // محافظه‌کارانه: اگر خواندنِ تایید به هر دلیلی نشد، تمدید را «موفق»
        // می‌گذاریم (نمی‌خواهیم یک خطای شبکه‌ی گذرا، تمدیدِ واقعاً انجام‌شده را
        // ناموفق اعلام کند). فقط وقتی خطا می‌دهیم که پنل صریحاً بگوید چیزی
        // عوض نشده.
        if (in_array($panel['type'], ["marzban", "pasargard"], true)) {
            $rxWasReset = in_array($Method_extend, [
                "ریست حجم و زمان",
                "ریست شدن حجم و اضافه شدن زمان",
            ], true);
            $rxAfter = $this->DataUser($panel['name_panel'], $username);
            if (is_array($rxAfter) && (($rxAfter['status'] ?? '') !== 'Unsuccessful')) {
                $rxUsed  = isset($rxAfter['used_traffic']) ? (float) $rxAfter['used_traffic'] : null;
                $rxLimit = isset($rxAfter['data_limit'])   ? (float) $rxAfter['data_limit']   : null;

                // اگر قرار بوده حجم ریست شود ولی مصرف هنوز از سقف بیشتر است،
                // یعنی روی پنل هیچ اتفاقی نیفتاده.
                if ($rxWasReset && $rxUsed !== null && $rxLimit !== null
                    && $rxLimit > 0 && $rxUsed >= $rxLimit) {
                    error_log('[panels] extend verify FAILED for ' . $username
                        . ' on ' . (string) $panel['name_panel']
                        . ' — used=' . $rxUsed . ' limit=' . $rxLimit
                        . ' method=' . $Method_extend
                        . ' payload=' . json_encode($data, JSON_UNESCAPED_UNICODE));
                    return array(
                        'status' => false,
                        'msg' => 'پنل تمدید را اعمال نکرد (حجم مصرفی ریست نشد). لطفاً روش تمدید و تنظیمات پنل را بررسی کنید.'
                    );
                }
            } else {
                error_log('[panels] extend verify skipped for ' . $username
                    . ' on ' . (string) $panel['name_panel'] . ' — could not read back');
            }
        }

        return $extend;
    }
    function extra_volume($username_account, $code_panel, $limit_volume_new)
    {
        $panel = $this->loadPanel($code_panel, "code_panel");
        $invoice = (function_exists('rx_invoice_on_panel')
                ? rx_invoice_on_panel((string) $username_account, (string) (is_array($panel) ? ($panel['name_panel'] ?? '') : ''))
                : false);
        if ($panel == false) {
            return array(
                'status' => false,
                'msg' => 'data not found'
            );
        }
        $notif_value = json_decode($invoice['notifctions'], true);
        $notifctions = json_encode(array(
            'volume' => false,
            'time' => $notif_value['time'],
        ));
        update("invoice", "notifctions", $notifctions, 'id_invoice', $invoice['id_invoice']);
        $user_info = $this->DataUser($panel['name_panel'], $username_account);
        if ($user_info['status'] == "Unsuccessful") {
            return array(
                'status' => false,
                'msg' => $user_info['msg']
            );
        }
        $old_limit_volume = $user_info['data_limit'];
        $new_limit = $limit_volume_new == 0 ? 0 : ($limit_volume_new * pow(1024, 3)) + $old_limit_volume;
        $inbound_id = isset($panel['inboundid']) ? $panel['inboundid'] : 1;
        $inbounds = is_string($panel['inbounds']) ? json_decode($panel['inbounds']) : "{}";
        if ($panel['type'] != "WGDashboard") {
            update("invoice", 'user_info', null, "username", $username_account);
        }
        update("invoice", 'uuid', null, "username", $username_account);
        update("invoice", 'Status', "active", "username", $username_account);
        if (in_array($panel['type'], ["marzban", "pasargard"], true)) {
            $data = array(
                'data_limit' => $new_limit,
                'inbounds' => $inbounds,
            );
            if ($invoice != false && $invoice['uuid'] != null) {
                $data['proxies'] = json_decode($invoice['uuid'], true);
            }
        } elseif ($panel['type'] == "marzneshin") {
            $data = array(
                'data_limit' => $new_limit,
            );
        } elseif ($panel['type'] == "x-ui_single") {
            $data = array(
                'settings' => json_encode(
                    array(
                        'clients' => array(
                            array(
                                "totalGB" => $new_limit,
                            )
                        ),
                    )
                ),
            );
        } elseif ($panel['type'] == "alireza_single") {
            $data = array(
                'id' => intval($inbound_id),
                'settings' => json_encode(
                    array(
                        'clients' => array(
                            array(
                                "totalGB" => $new_limit,
                            )
                        ),
                    )
                ),
            );
        } elseif ($panel['type'] == "hiddify") {
            $data_limit = ($user_info['data_limit'] / pow(1024, 3)) + $limit_volume_new;
            $datauser = getdatauser($username_account, $panel['name_panel']);
            $data = array(
                "current_usage_GB" => $datauser['current_usage_GB'],
                "usage_limit_GB" => $new_limit / pow(1024, 3),
            );
        } elseif ($panel['type'] == "WGDashboard") {
            $allowResponse = allowAccessPeers($panel['name_panel'], $username_account);
            if (isset($allowResponse['status']) && $allowResponse['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => isset($allowResponse['msg']) ? $allowResponse['msg'] : ''
                );
            }
            $datauser = get_userwg($username_account, $panel['name_panel']);
            if (isset($datauser['status']) && $datauser['status'] === false && !isset($datauser['id'])) {
                return array(
                    'status' => false,
                    'msg' => isset($datauser['msg']) ? $datauser['msg'] : ''
                );
            }
            $count = 0;
            foreach ($datauser['jobs'] as $jobsvolume) {
                if ($jobsvolume['Field'] == "total_data") {
                    break;
                }
                $count += 1;
            }
            if (isset($datauser['jobs'][$count])) {
                $datam = array(
                    "Job" => $datauser['jobs'][$count],
                );
                $deleteJob = deletejob($panel['name_panel'], $datam);
                if (isset($deleteJob['status']) && $deleteJob['status'] === false) {
                    return array(
                        'status' => false,
                        'msg' => isset($deleteJob['msg']) ? $deleteJob['msg'] : ''
                    );
                }
            } else {
                $resetResult = $this->ResetUserDataUsage($username_account, $panel['name_panel']);
                if (isset($resetResult['status']) && $resetResult['status'] === false) {
                    return array(
                        'status' => false,
                        'msg' => isset($resetResult['msg']) ? $resetResult['msg'] : ''
                    );
                }
            }
            $log = setjob($panel['name_panel'], "total_data", $new_limit / pow(1024, 3), $datauser['id']);
            if (isset($log['status']) && $log['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => isset($log['msg']) ? $log['msg'] : ''
                );
            }
            return array(
                'status' => true,
                'data' => $log
            );
        } elseif ($panel['type'] == "s_ui") {
            $data = array(
                "volume" => $new_limit,
            );
        } elseif ($panel['type'] == "guard") {
            $serviceIdsSource = isset($user_info['service_ids']) ? $user_info['service_ids'] : ($panel['guard_service_ids'] ?? null);
            $serviceResult = guardResolveServiceIds($panel['name_panel'], $serviceIdsSource);
            if ($serviceResult['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => $serviceResult['msg']
                );
            }
            $data = array(
                "limit_usage" => $new_limit,
                "service_ids" => $serviceResult['service_ids']
            );
        }
        $extra_volume = $this->Modifyuser($username_account, $panel['name_panel'], $data);
        if ($extra_volume['status'] == false) {
            return array(
                'status' => false,
                'msg' => $extra_volume['msg']
            );
        }
        return $extra_volume;
    }
    function extra_time($username_account, $code_panel, $limit_time_new)
    {
        $panel = $this->loadPanel($code_panel, "code_panel");
        $invoice = (function_exists('rx_invoice_on_panel')
                ? rx_invoice_on_panel((string) $username_account, (string) (is_array($panel) ? ($panel['name_panel'] ?? '') : ''))
                : false);
        if ($panel == false) {
            return array(
                'status' => false,
                'msg' => 'data not found'
            );
        }
        $notif_value = json_decode($invoice['notifctions'], true);
        $notifctions = json_encode(array(
            'volume' => $notif_value['volume'],
            'time' => false,
        ));
        update("invoice", "notifctions", $notifctions, 'id_invoice', $invoice['id_invoice']);
        $user_info = $this->DataUser($panel['name_panel'], $username_account);
        if ($user_info['status'] == "Unsuccessful") {
            return array(
                'status' => false,
                'msg' => $user_info['msg']
            );
        }
        $old_limit_time = $user_info['expire'];
        $old_limit_time = time() - $old_limit_time > 0 ? time() : $old_limit_time;
        $new_limit = $limit_time_new == 0 ? 0 : $limit_time_new * 86400 + $old_limit_time;
        $inbound_id = isset($panel['inboundid']) ? $panel['inboundid'] : 1;
        $inbounds = is_string($panel['inbounds']) ? json_decode($panel['inbounds']) : "{}";
        if ($panel['type'] != "WGDashboard") {
            update("invoice", 'user_info', null, "username", $username_account);
        }
        update("invoice", 'uuid', null, "username", $username_account);
        update("invoice", 'Status', "active", "username", $username_account);
        if (in_array($panel['type'], ["marzban", "pasargard"], true)) {
            $data = array(
                'expire' => $new_limit,
                'inbounds' => $inbounds,
            );
            if ($invoice != false && $invoice['uuid'] != null) {
                $data['proxies'] = json_decode($invoice['uuid'], true);
            }
        } elseif ($panel['type'] == "marzneshin") {
            $data = array(
                'expire_date' => $new_limit,
                'expire_strategy' => "fixed_date",

            );
        } elseif ($panel['type'] == "x-ui_single") {
            $new_limit = $new_limit * 1000;
            $data = array(
                'settings' => json_encode(
                    array(
                        'clients' => array(
                            array(
                                "expiryTime" => $new_limit,
                            )
                        ),
                    )
                ),
            );
        } elseif ($panel['type'] == "alireza_single") {
            $new_limit = $new_limit * 1000;
            $data = array(
                'id' => intval($inbound_id),
                'settings' => json_encode(
                    array(
                        'clients' => array(
                            array(
                                "expiryTime" => $new_limit,
                            )
                        ),
                    )
                ),
            );
        } elseif ($panel['type'] == "hiddify") {
            $new_limit = ($old_limit_time / pow(1024, 3)) + $limit_time_new;
            $datauser = getdatauser($username_account, $panel['name_panel']);
            $data = array(
                "current_usage_GB" => $datauser['current_usage_GB'],
                "usage_limit_GB" => $datauser['usage_limit_GB'],
                "package_days" => $new_limit,
                "start_date" => null
            );
        } elseif ($panel['type'] == "WGDashboard") {
            $allowResponse = allowAccessPeers($panel['name_panel'], $username_account);
            if (isset($allowResponse['status']) && $allowResponse['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => isset($allowResponse['msg']) ? $allowResponse['msg'] : ''
                );
            }
            $datauser = get_userwg($username_account, $panel['name_panel']);
            if (isset($datauser['status']) && $datauser['status'] === false && !isset($datauser['id'])) {
                return array(
                    'status' => false,
                    'msg' => isset($datauser['msg']) ? $datauser['msg'] : ''
                );
            }
            $count = 0;
            foreach ($datauser['jobs'] as $jobsvolume) {
                if ($jobsvolume['Field'] == "date") {
                    break;
                }
                $count += 1;
            }
            if (isset($datauser['jobs'][$count])) {
                $datam = array(
                    "Job" => $datauser['jobs'][$count],
                );
                $deleteJob = deletejob($panel['name_panel'], $datam);
                if (isset($deleteJob['status']) && $deleteJob['status'] === false) {
                    return array(
                        'status' => false,
                        'msg' => isset($deleteJob['msg']) ? $deleteJob['msg'] : ''
                    );
                }
            }
            $log = setjob($panel['name_panel'], "date", date('Y-m-d H:i:s', $new_limit), $datauser['id']);
            if (isset($log['status']) && $log['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => isset($log['msg']) ? $log['msg'] : ''
                );
            }
            return array(
                'status' => true,
                'data' => $log
            );
        } elseif ($panel['type'] == "guard") {
            $serviceIdsSource = isset($user_info['service_ids']) ? $user_info['service_ids'] : ($panel['guard_service_ids'] ?? null);
            $serviceResult = guardResolveServiceIds($panel['name_panel'], $serviceIdsSource);
            if ($serviceResult['status'] === false) {
                return array(
                    'status' => false,
                    'msg' => $serviceResult['msg']
                );
            }
            $data = array(
                "limit_expire" => guardNormalizeExpire($new_limit),
                "service_ids" => $serviceResult['service_ids']
            );
        } elseif ($panel['type'] == "s_ui") {
            $data = array(
                "expiry" => $new_limit,
            );
        }
        $extra_time = $this->Modifyuser($username_account, $panel['name_panel'], $data);
        if ($extra_time['status'] == false) {
            return array(
                'status' => false,
                'msg' => $extra_time['msg']
            );
        }
        return $extra_time;
    }
}


