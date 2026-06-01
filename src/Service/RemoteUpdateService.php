<?php

declare(strict_types=1);

namespace S35WpHub\Service;

use S35WpHub\Model\Site;
use S35WpHub\Util\SiteUrl;

final class RemoteUpdateService
{
    public function __construct(
        private readonly WordPressHttp $http
    ) {
    }

    /**
     * @return array{ok: bool, message: string, decoded: mixed|null, status: int}
     */
    public function runUpdates(Site $site, string $plainAppPassword, string $scope = 'all'): array
    {
        $base = SiteUrl::restBase($site->siteUrl);
        $user = $site->adminUser;
        $pass = SyncService::normalizeAppPassword($plainAppPassword);

        return $this->dispatch(
            $base . '/s35-wp-hub/v1/updates/run',
            $user,
            $pass,
            ['scope' => $scope],
            'Update run completed.'
        );
    }

    /**
     * @return array{ok: bool, message: string, decoded: mixed|null, status: int}
     */
    public function deletePlugin(Site $site, string $plainAppPassword, string $pluginFile): array
    {
        $base = SiteUrl::restBase($site->siteUrl);
        $user = $site->adminUser;
        $pass = SyncService::normalizeAppPassword($plainAppPassword);

        return $this->dispatch(
            $base . '/s35-wp-hub/v1/plugins/delete',
            $user,
            $pass,
            ['plugin_file' => $pluginFile],
            'Plugin deleted.'
        );
    }

    /**
     * @return array{ok: bool, message: string, decoded: mixed|null, status: int}
     */
    public function deactivatePlugin(Site $site, string $plainAppPassword, string $pluginFile): array
    {
        $base = SiteUrl::restBase($site->siteUrl);
        $user = $site->adminUser;
        $pass = SyncService::normalizeAppPassword($plainAppPassword);

        return $this->dispatch(
            $base . '/s35-wp-hub/v1/plugins/deactivate',
            $user,
            $pass,
            ['plugin_file' => $pluginFile],
            'Plugin deactivated.'
        );
    }

    /**
     * @return array{ok: bool, message: string, decoded: mixed|null, status: int}
     */
    public function activatePlugin(Site $site, string $plainAppPassword, string $pluginFile): array
    {
        $base = SiteUrl::restBase($site->siteUrl);
        $user = $site->adminUser;
        $pass = SyncService::normalizeAppPassword($plainAppPassword);

        return $this->dispatch(
            $base . '/s35-wp-hub/v1/plugins/activate',
            $user,
            $pass,
            ['plugin_file' => $pluginFile],
            'Plugin activated.'
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array{ok: bool, message: string, decoded: mixed|null, status: int}
     */
    private function dispatch(
        string $url,
        string $user,
        string $pass,
        array $body,
        string $successDefault
    ): array {
        $res = $this->http->request('POST', $url, $user, $pass, $body, true);

        if ($res['ok']) {
            $msg = is_array($res['decoded']) && isset($res['decoded']['message'])
                ? (string) $res['decoded']['message']
                : $successDefault;

            return ['ok' => true, 'message' => $msg, 'decoded' => $res['decoded'], 'status' => $res['status']];
        }

        $detail = '';
        if (is_array($res['decoded']) && isset($res['decoded']['message'])) {
            $detail = (string) $res['decoded']['message'];
        } elseif ($res['body'] !== '') {
            $detail = mb_substr($res['body'], 0, 500);
        }
        $err = $res['error'] ?? ('HTTP ' . $res['status']);
        if ($detail !== '') {
            $err .= ': ' . $detail;
        }

        return ['ok' => false, 'message' => $err, 'decoded' => $res['decoded'], 'status' => $res['status']];
    }
}
