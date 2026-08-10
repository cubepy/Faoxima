<?php


declare(strict_types=1);

require_once __DIR__ . '/BaseHandler.php';

// [FEATURE] بخش مدیریت تیکت‌ها: کاربر از مینی‌اپ تیکت می‌فرسته، ادمین از پنل وب (panel/tickets.php)
// می‌بینه/می‌بنده/حذف می‌کنه، و یه پیام هم به ادمین‌ها تو تلگرام می‌ره تا زود متوجه بشن.
final class TicketHandler extends BaseHandler
{
    public $mode = 'create';

    public function handle(): void
    {
        $this->ensureTable();
        switch ($this->mode) {
            case 'create':
                $this->handleCreate();
                return;
            case 'list':
                $this->handleList();
                return;
        }
        FaoximaResponse::badRequest('Ticket mode invalid');
    }

    private function ensureTable(): void
    {
        FaoximaDb::execute(
            "CREATE TABLE IF NOT EXISTS support_tickets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                id_user VARCHAR(64) NOT NULL,
                department VARCHAR(191) NOT NULL DEFAULT '',
                subject VARCHAR(500) NOT NULL DEFAULT '',
                message TEXT NOT NULL,
                status ENUM('open','closed') NOT NULL DEFAULT 'open',
                admin_reply TEXT NULL,
                created_at INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at INT UNSIGNED NULL,
                KEY idx_tickets_user (id_user),
                KEY idx_tickets_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    private function handleCreate(): void
    {
        $this->requireMethod('POST');
        $department = FaoximaInput::string($this->data, 'department', 'پشتیبانی فنی');
        $subject = trim(FaoximaInput::string($this->data, 'subject'));
        $message = trim(FaoximaInput::string($this->data, 'message'));

        if ($subject === '' || $message === '') {
            FaoximaResponse::badRequest('subject and message are required');
        }
        if (mb_strlen($subject) > 200) $subject = mb_substr($subject, 0, 200);
        if (mb_strlen($message) > 4000) $message = mb_substr($message, 0, 4000);

        $userId = (string)($this->user['id'] ?? '');
        $now = time();

        FaoximaDb::execute(
            "INSERT INTO support_tickets (id_user, department, subject, message, status, created_at)
             VALUES (:id_user, :department, :subject, :message, 'open', :created_at)",
            [
                ':id_user' => $userId,
                ':department' => $department,
                ':subject' => $subject,
                ':message' => $message,
                ':created_at' => $now,
            ]
        );
        $ticketId = (int) FaoximaDb::pdo()->lastInsertId();

        $this->notifyAdmins($ticketId, $userId, $department, $subject, $message);

        FaoximaResponse::ok(['id' => $ticketId], 'تیکت با موفقیت ارسال شد');
    }

    private function handleList(): void
    {
        $this->requireMethod('GET');
        $userId = (string)($this->user['id'] ?? '');
        $rows = FaoximaDb::fetchAll(
            "SELECT id, department, subject, status, created_at, admin_reply
               FROM support_tickets
              WHERE id_user = :id_user
              ORDER BY created_at DESC
              LIMIT 50",
            [':id_user' => $userId]
        );
        FaoximaResponse::ok(['tickets' => $rows]);
    }

    private function notifyAdmins(int $ticketId, string $userId, string $department, string $subject, string $message): void
    {
        if (!function_exists('select') || !function_exists('telegram')) {
            return;
        }
        $balanceRow = select('user', '*', 'id', $userId, 'select');
        $username = is_array($balanceRow) ? (string)($balanceRow['username'] ?? '') : '';
        $text = "🎫 <b>تیکت پشتیبانی جدید</b>\n\n"
              . "🆔 شماره تیکت: <code>{$ticketId}</code>\n"
              . "👤 کاربر: <code>{$userId}</code>" . ($username !== '' ? " (@{$username})" : '') . "\n"
              . "🗂 دپارتمان: " . htmlspecialchars($department, ENT_QUOTES, 'UTF-8') . "\n"
              . "📌 موضوع: " . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . "\n\n"
              . "✍️ متن:\n" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
              . "\n\n📌 برای پاسخ/بستن این تیکت به پنل وب مراجعه کنید.";
        $adminIds = select('admin', 'id_admin', null, null, 'FETCH_COLUMN') ?: [];
        foreach ((array) $adminIds as $adminId) {
            $adminRow = select('admin', '*', 'id_admin', $adminId, 'select');
            if (is_array($adminRow) && ($adminRow['rule'] ?? '') === 'support') {
                continue;
            }
            try {
                telegram('sendmessage', [
                    'chat_id' => $adminId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ]);
            } catch (Throwable $e) {
                // best-effort
            }
        }
    }
}
