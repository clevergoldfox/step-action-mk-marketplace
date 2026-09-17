<?php
declare(strict_types=1);

namespace MK\Report;

use WC_Order;

/**
 * Photos attached to a buyer's claim: stored privately, shown to the operator.
 *
 * A photo of a parcel can show a name and address label, a face, the inside
 * of someone's home. It is evidence for the operator, not content for the
 * site, so it goes where the 古物商許可証 images go: outside the web root,
 * under random names, served only through an admin handler that checks the
 * capability and a nonce bound to the one report. See Creator\Business for
 * why a deny rule inside public_html is not relied on here.
 *
 * Which files belong to which report is kept on the order, keyed by report
 * id. Every claim is against an order, and the order is already where the
 * operator looks.
 */
final class ClaimFiles
{
    public const MAX_FILES = 3;
    public const MAX_BYTES = 10 * 1024 * 1024;

    private const SUBDIR = 'mk-private/claims';
    private const META   = '_mk_report_files_';
    private const NONCE  = 'mk_claim_file';

    public static function register(): void
    {
        add_action('admin_post_mk_claim_file', [self::class, 'stream']);
    }

    public static function dir(): string
    {
        return dirname(untrailingslashit(ABSPATH)) . '/' . self::SUBDIR;
    }

    /**
     * What is wrong with the files sent, as buyer-readable messages.
     *
     * @param array<string, mixed> $files one $_FILES entry for a multiple input
     * @return string[]
     */
    public static function problems(array $files): array
    {
        $entries = self::entries($files);

        if (count($entries) > self::MAX_FILES) {
            return [sprintf('添付できる画像は%d枚までです。', self::MAX_FILES)];
        }

        $errors = [];

        foreach ($entries as $i => $file) {
            $label = sprintf('%d枚目の画像', $i + 1);

            if ((int) $file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = $label . 'を受け取れませんでした。もう一度お試しください。';
            } elseif ((int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_BYTES) {
                $errors[] = $label . 'は10MB以内でお送りください。';
            } elseif (self::extensionOf((string) $file['tmp_name'], (string) $file['name']) === '') {
                $errors[] = $label . 'は、JPEGまたはPNGの画像でお送りください。';
            }
        }

        return $errors;
    }

    /**
     * Store the files for one report.
     *
     * @param array<string, mixed> $files
     * @param bool $uploaded false lets tests store files they made themselves
     * @return int how many were stored
     */
    public static function store(WC_Order $order, int $reportId, array $files, bool $uploaded): int
    {
        $entries = self::entries($files);

        if ($entries === [] || self::problems($files) !== [] || !wp_mkdir_p(self::dir())) {
            return 0;
        }

        foreach (['.htaccess' => "Require all denied\nDeny from all\n", 'index.php' => "<?php // Silence.\n"] as $name => $body) {
            if (!file_exists(self::dir() . '/' . $name)) {
                @file_put_contents(self::dir() . '/' . $name, $body);
            }
        }

        $stored = [];

        foreach ($entries as $file) {
            $ext    = self::extensionOf((string) $file['tmp_name'], (string) $file['name']);
            $name   = bin2hex(random_bytes(16)) . '.' . $ext;
            $target = self::dir() . '/' . $name;
            $moved  = $uploaded
                ? @move_uploaded_file((string) $file['tmp_name'], $target)
                : @copy((string) $file['tmp_name'], $target);

            if ($moved) {
                @chmod($target, 0600);
                $stored[] = $name;
            }
        }

        if ($stored !== []) {
            $order->update_meta_data(self::META . $reportId, $stored);
            $order->save();
        }

        return count($stored);
    }

    /** @return string[] stored file names for one report */
    public static function namesFor(WC_Order $order, int $reportId): array
    {
        $names = $order->get_meta(self::META . $reportId);

        return is_array($names) ? array_values(array_filter($names, [self::class, 'isOurName'])) : [];
    }

    public static function pathFor(string $name): string
    {
        if (!self::isOurName($name)) {
            return '';
        }

        $path = self::dir() . '/' . $name;

        return is_file($path) ? $path : '';
    }

    public static function adminUrl(int $reportId, int $index): string
    {
        return wp_nonce_url(
            admin_url(sprintf('admin-post.php?action=mk_claim_file&report=%d&file=%d', $reportId, $index)),
            self::NONCE . '_' . $reportId
        );
    }

    /** The image, to the operator only. */
    public static function stream(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('権限がありません。', '', ['response' => 403]);
        }

        $reportId = isset($_GET['report']) ? (int) $_GET['report'] : 0;
        $index    = isset($_GET['file']) ? (int) $_GET['file'] : -1;

        check_admin_referer(self::NONCE . '_' . $reportId);

        $report = (new Service())->find($reportId);
        $order  = $report && $report->target_type === Service::TARGET_ORDER ? wc_get_order((int) $report->target_id) : null;
        $names  = $order instanceof WC_Order ? self::namesFor($order, $reportId) : [];
        $path   = isset($names[$index]) ? self::pathFor($names[$index]) : '';

        if ($path === '') {
            wp_die('画像が見つかりません。', '', ['response' => 404]);
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        nocache_headers();
        header('Content-Type: ' . ($ext === 'png' ? 'image/png' : 'image/jpeg'));
        header('Content-Disposition: inline; filename="claim-' . $reportId . '-' . ($index + 1) . '.' . $ext . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        readfile($path);
        exit;
    }

    /**
     * The files actually chosen, as a flat list. A multiple file input
     * arrives as parallel arrays, with an empty entry when nothing was picked.
     *
     * @param array<string, mixed> $files
     * @return array<int, array{name:string, tmp_name:string, size:int, error:int}>
     */
    private static function entries(array $files): array
    {
        if (!isset($files['name'])) {
            return [];
        }

        $entries = [];

        foreach ((array) $files['name'] as $i => $name) {
            $error = (int) (((array) ($files['error'] ?? []))[$i] ?? UPLOAD_ERR_NO_FILE);

            if ($error === UPLOAD_ERR_NO_FILE || (string) $name === '') {
                continue;
            }

            $entries[] = [
                'name'     => (string) $name,
                'tmp_name' => (string) (((array) ($files['tmp_name'] ?? []))[$i] ?? ''),
                'size'     => (int) (((array) ($files['size'] ?? []))[$i] ?? 0),
                'error'    => $error,
            ];
        }

        return $entries;
    }

    /** jpg or png from the file's real contents, or '' if it is neither. */
    private static function extensionOf(string $path, string $name): string
    {
        if ($path === '' || !is_file($path)) {
            return '';
        }

        $check = wp_check_filetype_and_ext($path, $name, ['jpg|jpeg' => 'image/jpeg', 'png' => 'image/png']);

        return match ((string) ($check['type'] ?? '')) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            default      => '',
        };
    }

    private static function isOurName($name): bool
    {
        return is_string($name) && (bool) preg_match('/^[a-f0-9]{32}\.(jpg|png)$/', $name);
    }
}
