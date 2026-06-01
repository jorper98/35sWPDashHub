<?php

declare(strict_types=1);

namespace S35WpHub\Service;

use S35WpHub\Config;
use S35WpHub\Model\Owner;
use S35WpHub\Model\Site;
use S35WpHub\Repository\LogRepository;
use S35WpHub\Util\MixedText;

final class OwnerReportService
{
    private const REPORT_DAYS = 30;

    public function __construct(
        private readonly LogRepository $logs
    ) {
    }

    /**
     * @param list<Site> $sites
     */
    public function buildPlainText(Owner $owner, string $agencyName, array $sites): string
    {
        $lines = [];
        $lines[] = 'Hello ' . $owner->firstName . ',';
        $lines[] = '';
        $introRaw = Config::get('report_mail_intro');
        $intro = is_string($introRaw) ? trim($introRaw) : '';
        if ($intro !== '') {
            foreach (preg_split("/\r\n|\n|\r/", $intro) as $introLine) {
                $lines[] = $introLine;
            }
            $lines[] = '';
        }
        $lines[] = 'Site report from ' . $agencyName . ' (35sDashHub). Generated ' . gmdate('Y-m-d H:i') . ' UTC.';
        $lines[] = 'Your renewal date on file: ' . ($owner->renewalDate !== '' ? $owner->renewalDate : '—');
        $lines[] = '';

        if ($sites === []) {
            $lines[] = 'No sites are currently assigned to you in this dashboard.';
            $lines[] = '';
            $lines = array_merge($lines, $this->signatureLines());

            return implode("\n", $lines);
        }

        foreach ($sites as $site) {
            $lines = array_merge($lines, $this->siteSectionLines($site));
        }

        $lines = array_merge($lines, $this->signatureLines());

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function siteSectionLines(Site $site): array
    {
        $sid = (int) $site->id;
        $title = $site->label !== null && $site->label !== '' ? $site->label : $site->siteUrl;
        $lines = [];
        $lines[] = str_repeat('=', 50);
        $lines[] = $title;
        $lines[] = $site->siteUrl;
        $lines[] = '';
        $snap = $site->siteSnapshot();
        $wp = is_array($snap) ? MixedText::toPlainString($snap['wp_version'] ?? null) : '';
        $php = is_array($snap) ? MixedText::toPlainString($snap['php_version'] ?? null) : '';
        $theme = is_array($snap) ? MixedText::toPlainString($snap['active_theme_name'] ?? null) : '';
        if (strcasecmp($theme, 'Array') === 0) {
            $theme = '';
        }
        $wpDisplay = $wp !== '' ? $wp : '—';
        $themeDisplay = $theme !== '' ? $theme : '—';
        $phpDisplay = $php !== '' ? $php : '—';
        $counts = $this->logs->eventCountsForSiteSinceDays($sid, self::REPORT_DAYS);
        $days = (string) self::REPORT_DAYS;
        $lines[] = 'Website' . "\t" . $site->siteUrl;
        $lines[] = 'Current WordPress Version' . "\t" . $wpDisplay;
        $lines[] = 'Active theme' . "\t" . $themeDisplay;
        $lines[] = 'PHP Version' . "\t" . $phpDisplay;
        $lines[] = 'Total events in the last ' . $days . ' days: ' . (string) $counts['total']
            . '    Update events: ' . (string) $counts['updates']
            . '    Backups created: ' . (string) $counts['backups'];
        $lines[] = '';

        $pendingTotal = $site->pendingPlugins + $site->pendingThemes + $site->pendingCore;
        $status = $site->lastStatus;
        $hasError = $site->lastError !== null && $site->lastError !== '';

        if ($status === 'online' && ! $hasError) {
            $lines[] = 'Site Health: Secure and Active';
        } elseif ($status === 'online' && $hasError) {
            $lines[] = 'Site Health: Reachable — review recommended';
        } elseif ($status === 'offline') {
            $lines[] = 'Site Health: Unreachable or offline';
        } else {
            $lines[] = 'Site Health: Not yet verified';
        }

        if ($pendingTotal === 0 && $status === 'online') {
            $lines[] = 'Software: Up to date ✅';
        } elseif ($status === 'online') {
            $lines[] = 'Software: Updates available — ' . $site->pendingPlugins . ' plugin(s), '
                . $site->pendingThemes . ' theme(s), ' . $site->pendingCore . ' core pending';
        } else {
            $lines[] = 'Software: Pending counts unavailable until the site is online.';
        }

        if ($status === 'online' && $pendingTotal === 0 && ! $hasError) {
            $lines[] = 'Your site is looking great!';
        } elseif ($status === 'online' && $pendingTotal > 0) {
            $lines[] = 'There are updates waiting to be applied when you are ready.';
        } elseif ($status === 'offline') {
            $lines[] = 'We could not reach your site on the last check.';
        } elseif ($hasError) {
            $lines[] = 'The last check reported an issue; see details below.';
        }

        $lines[] = '';
        $lines[] = $this->lastCheckedLine($site->lastSyncAt);
        if ($hasError) {
            $lines[] = 'Note: ' . trim((string) $site->lastError);
        }
        $lines[] = '';

        $runs = $this->logs->updateRunsForSiteSinceDays($sid, self::REPORT_DAYS);
        $ok = 0;
        $fail = 0;
        foreach ($runs as $r) {
            if (($r['action'] ?? '') === 'update_run') {
                ++$ok;
            } else {
                ++$fail;
            }
        }
        $lines[] = '30-Day History: ' . $ok . ' successful update' . ($ok === 1 ? '' : 's')
            . ', ' . $fail . ' issue' . ($fail === 1 ? '' : 's') . '. Details follow:';
        $lines[] = '';
        if ($runs === []) {
            $lines[] = '  (No remote update runs logged in this period.)';
        } else {
            foreach ($runs as $r) {
                $when = (string) ($r['created_at'] ?? '');
                $msg = trim((string) ($r['message'] ?? ''));
                if (($r['action'] ?? '') === 'update_run_failed') {
                    $msg = '[issue] ' . $msg;
                }
                $lines[] = '  - ' . $when . '  ' . $msg;
            }
        }
        $lines[] = '';

        return $lines;
    }

    private function lastCheckedLine(?string $lastSyncAt): string
    {
        if ($lastSyncAt === null || trim($lastSyncAt) === '') {
            return 'We have not synced this site from the dashboard yet.';
        }
        $ts = strtotime($lastSyncAt);
        if ($ts === false) {
            return 'We last checked in on your site at (unknown time). Last sync record: ' . $lastSyncAt;
        }
        $time = date('g:i A', $ts);
        $d = date('Y-m-d', $ts);

        return 'We last checked in on your site at ' . $time . ' on ' . $d;
    }

    /**
     * @return list<string>
     */
    private function signatureLines(): array
    {
        $raw = Config::get('report_mail_signature');
        $sig = is_string($raw) ? trim($raw) : '';
        if ($sig === '') {
            return ['—', '35sDashHub'];
        }
        $lines = ['—', ''];
        foreach (preg_split("/\r\n|\n|\r/", $sig) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param list<Site> $sites
     */
    public function sendReport(
        Owner $owner,
        string $agencyName,
        array $sites,
        string $fromEmail,
        string $fromName
    ): bool {
        $to = trim($owner->ownerEmail);
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (! filter_var(trim($fromEmail), FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $body = $this->buildPlainText($owner, $agencyName, $sites);
        $subject = $agencyName . ' — your site report (' . gmdate('Y-m-d') . ')';

        return SimpleMail::sendText($to, $subject, $body, trim($fromEmail), $fromName);
    }
}
