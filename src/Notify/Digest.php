<?php
declare(strict_types=1);

namespace MK\Notify;

use MK\Creator\Business;
use MK\Order\DispatchDeadline;
use MK\Order\Statuses;
use MK\Report\Service as ReportService;
use MK\Support\Money;

/**
 * One mail a day, to the operator, about what is waiting for them.
 *
 * Schedule\Jobs has scheduled mk_send_daily_digest since the beginning and
 * nothing answered it: Action Scheduler recorded "no callback registered"
 * every morning for seventeen days and then gave up rescheduling it
 * (found 2026-09-26, during the handover review).
 *
 * What it answers is the client's own question -- what does the operator
 * have to do each day. Four things can pile up unattended here: a business
 * application nobody reviewed, a cancellation request nobody read, a parcel
 * nobody posted, and a buyer waiting on a confirmation. Each has a screen,
 * and expecting someone to visit four screens daily forever is how they stop
 * being visited.
 *
 * It mails only when something needs doing. A daily "nothing to report"
 * teaches the reader to ignore the sender, and the first mail that does
 * matter is then ignored too.
 */
final class Digest
{
    /** Never scan more than this many orders in one pass. */
    private const SCAN_LIMIT = 300;

    public static function send(): void
    {
        /** A test needs to ask "what would a busy morning look like". */
        $counts = (array) apply_filters('mk_digest_counts', self::counts());

        if (array_sum([$counts['overdue'], $counts['reports'], $counts['applications']]) === 0) {
            return;   // nothing needs a person today
        }

        $lines = [];

        if ($counts['applications'] > 0) {
            $lines[] = sprintf(
                "・事業者申請の審査待ち：%d件\n  %s",
                $counts['applications'],
                admin_url('admin.php?page=mk-business')
            );
        }

        if ($counts['reports'] > 0) {
            $lines[] = sprintf(
                "・キャンセル申請・通報の未対応：%d件\n  %s",
                $counts['reports'],
                admin_url('admin.php?page=mk-reports')
            );
        }

        if ($counts['overdue'] > 0) {
            $lines[] = sprintf(
                "・発送期限を過ぎている取引：%d件\n  %s",
                $counts['overdue'],
                admin_url('admin.php?page=wc-orders')
            );
        }

        $body = "本日ご確認いただきたい項目です。\n\n"
            . implode("\n\n", $lines)
            . "\n\n────────────\n"
            . sprintf("昨日の成立：%d件（%s）\n", $counts['sales'], Money::format($counts['gross']))
            . sprintf("発送済み・受取確認待ち：%d件\n", $counts['awaiting'])
            . "\n※ ご対応が必要な項目があるときだけお送りしています。";

        $short = sprintf(
            '運営メモ：審査待ち%d件／未対応%d件／発送遅れ%d件',
            $counts['applications'],
            $counts['reports'],
            $counts['overdue']
        );

        // Straight to whichever screen has the most pressing item on it;
        // the mail's own subject already carries the site name, which the
        // channel prefixes.
        $url = admin_url('admin.php?page=wc-orders');

        if ($counts['applications'] > 0) {
            $url = admin_url('admin.php?page=mk-business');
        } elseif ($counts['reports'] > 0) {
            $url = admin_url('admin.php?page=mk-reports');
        }

        foreach (self::operators() as $operatorId) {
            Dispatcher::send(new Notification(
                type: 'admin.digest',
                userId: $operatorId,
                subject: '本日の運営メモ',
                body: $body,
                short: $short,
                url: $url,
                context: $counts,
            ));
        }
    }

    /**
     * @return array<string, int>
     */
    public static function counts(): array
    {
        return [
            'applications' => self::pendingApplications(),
            'reports'      => self::openReports(),
            'overdue'      => self::overdueOrders(),
            'awaiting'     => self::countByStatus(Statuses::SHIPPED),
            'sales'        => self::yesterday()['count'],
            'gross'        => self::yesterday()['gross'],
        ];
    }

    /** @return int[] */
    private static function operators(): array
    {
        $admins = get_users([
            'role'    => 'administrator',
            'fields'  => 'ID',
            'number'  => 10,
            'orderby' => 'ID',
        ]);

        return array_map('intval', $admins);
    }

    private static function pendingApplications(): int
    {
        $users = get_users([
            'meta_key'   => Business::META_STATUS,
            'meta_value' => Business::STATUS_PENDING,
            'fields'     => 'ID',
            'number'     => 200,
        ]);

        return count($users);
    }

    private static function openReports(): int
    {
        return count((new ReportService())->listByStatus(ReportService::STATUS_OPEN, 200));
    }

    private static function overdueOrders(): int
    {
        // By the deadline itself, not by the flag the overdue sweep writes:
        // the flag is only on orders the sweep has reached, and the operator
        // wants to know what is late now, which is what the creator's own
        // screen says. The dates are stored as GMT 'Y-m-d H:i:s', so a string
        // comparison is a chronological one.
        $orders = wc_get_orders([
            'status'     => Statuses::PAID,
            'limit'      => self::SCAN_LIMIT,
            'return'     => 'ids',
            'meta_query' => [
                [
                    'key'     => DispatchDeadline::META_DUE_AT,
                    'value'   => gmdate('Y-m-d H:i:s'),
                    'compare' => '<',
                ],
            ],
        ]);

        return is_array($orders) ? count($orders) : 0;
    }

    private static function countByStatus(string $status): int
    {
        $orders = wc_get_orders(['status' => $status, 'limit' => self::SCAN_LIMIT, 'return' => 'ids']);

        return is_array($orders) ? count($orders) : 0;
    }

    /**
     * @return array{count: int, gross: int}
     */
    private static function yesterday(): array
    {
        static $cached = null;

        if (is_array($cached)) {
            return $cached;
        }

        $from = new \DateTimeImmutable('yesterday 00:00', wp_timezone());
        $to   = new \DateTimeImmutable('today 00:00', wp_timezone());

        $orders = wc_get_orders([
            'status'       => [Statuses::PAID, Statuses::SHIPPED, Statuses::RECEIVED, 'completed'],
            'limit'        => self::SCAN_LIMIT,
            'date_created' => $from->getTimestamp() . '...' . $to->getTimestamp(),
        ]);

        $gross = 0;

        foreach (is_array($orders) ? $orders : [] as $order) {
            $gross += (int) round((float) $order->get_total());
        }

        $cached = ['count' => is_array($orders) ? count($orders) : 0, 'gross' => $gross];

        return $cached;
    }
}
