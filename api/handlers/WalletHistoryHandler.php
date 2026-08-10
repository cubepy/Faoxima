<?php


declare(strict_types=1);

require_once __DIR__ . '/BaseHandler.php';

// [FEATURE] بخش تاریخچه‌ی کیف پول: ترکیب رکوردهای شارژ (Payment_report، واریز) و خریدهای انجام‌شده
// (invoice، برداشت) به یک تایم‌لاین واحد برای نمایش در مینی‌اپ.
final class WalletHistoryHandler extends BaseHandler
{
    public function handle(): void
    {
        $this->requireMethod('GET');
        $userId = (string)($this->user['id'] ?? '');
        $limit = FaoximaInput::intRange($this->data, 'limit', 1, 50, 20);
        $page  = FaoximaInput::intMin($this->data, 'page', 1, 1);

        $credits = FaoximaDb::fetchAll(
            "SELECT id_order AS ref, time AS ts, price AS amount, Payment_Method AS method
               FROM Payment_report
              WHERE id_user = :uid AND payment_Status = 'paid'
              ORDER BY time DESC
              LIMIT 200",
            [':uid' => $userId]
        );
        $debits = FaoximaDb::fetchAll(
            "SELECT id_invoice AS ref, time_sell AS ts, price_product AS amount, name_product AS product, Status AS status
               FROM invoice
              WHERE id_user = :uid AND Status NOT IN ('unpaid', 'failed', 'Unpaid')
              ORDER BY time_sell DESC
              LIMIT 200",
            [':uid' => $userId]
        );

        $items = [];
        foreach ($credits as $c) {
            $tsRaw = $c['ts'] ?? null;
            $ts = is_numeric($tsRaw) ? (int) $tsRaw : (int) strtotime((string) $tsRaw);
            $items[] = [
                'type' => 'credit',
                'ref' => (string) $c['ref'],
                'ts' => $ts,
                'amount' => (int) $c['amount'],
                'title' => 'شارژ کیف پول',
                'subtitle' => (string) ($c['method'] ?? ''),
            ];
        }
        foreach ($debits as $d) {
            $items[] = [
                'type' => 'debit',
                'ref' => (string) $d['ref'],
                'ts' => (int) $d['ts'],
                'amount' => (int) $d['amount'],
                'title' => (string) ($d['product'] ?? 'خرید سرویس'),
                'subtitle' => (string) ($d['status'] ?? ''),
            ];
        }

        usort($items, function ($a, $b) {
            return $b['ts'] <=> $a['ts'];
        });

        $total = count($items);
        $offset = ($page - 1) * $limit;
        $pageItems = array_slice($items, $offset, $limit);

        FaoximaResponse::ok([
            'items' => $pageItems,
            'total' => $total,
            'page' => $page,
            'pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
        ]);
    }
}
