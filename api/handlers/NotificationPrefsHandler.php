<?php


declare(strict_types=1);

require_once __DIR__ . '/BaseHandler.php';

// [FEATURE] مدیریت اعلانات کاربر: کاربر می‌تونه از مینی‌اپ انتخاب کنه که یادآوری‌های
// انقضا/اتمام حجم سرویس و پیام‌های تبلیغاتی/همگانی رو دریافت کنه یا نه.
final class NotificationPrefsHandler extends BaseHandler
{
    public $mode = 'get';

    private const DEFAULTS = [
        'service_reminders' => 'on',
        'promotions' => 'on',
    ];

    public function handle(): void
    {
        switch ($this->mode) {
            case 'get':
                $this->handleGet();
                return;
            case 'set':
                $this->handleSet();
                return;
        }
        FaoximaResponse::badRequest('Notification prefs mode invalid');
    }

    private function readPrefs(): array
    {
        $raw = $this->user['notif_prefs'] ?? null;
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = [];
        }
        return array_merge(self::DEFAULTS, $decoded);
    }

    private function handleGet(): void
    {
        $this->requireMethod('GET');
        FaoximaResponse::ok(['prefs' => $this->readPrefs()]);
    }

    private function handleSet(): void
    {
        $this->requireMethod('POST');
        $prefs = $this->readPrefs();
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $this->data)) {
                $val = $this->data[$key];
                $prefs[$key] = ($val === false || $val === 'off' || $val === 0 || $val === '0') ? 'off' : 'on';
            }
        }
        $userId = (string)($this->user['id'] ?? '');
        update('user', 'notif_prefs', json_encode($prefs, JSON_UNESCAPED_UNICODE), 'id', $userId);
        FaoximaResponse::ok(['prefs' => $prefs], 'تنظیمات اعلانات ذخیره شد');
    }
}
