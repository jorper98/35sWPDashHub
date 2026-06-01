<?php

declare(strict_types=1);

namespace S35WpHub\Service;

use S35WpHub\Model\Site;
use S35WpHub\Util\SiteUrl;

final class PluginDeployService
{
    public function __construct(
        private readonly WordPressHttp $http
    ) {
    }

    /**
     * @return array{ok: bool, message: string, decoded: mixed|null, status: int}
     */
    public function installPackageFromUrl(Site $site, string $plainAppPassword, string $packageUrl): array
    {
        $base = SiteUrl::restBase($site->siteUrl);
        $url = $base . '/s35-wp-hub/v1/plugins/install-package';
        $user = $site->adminUser;
        $pass = SyncService::normalizeAppPassword($plainAppPassword);

        $body = ['package_url' => $packageUrl];
        $res = $this->http->request('POST', $url, $user, $pass, $body, true);

        if ($res['ok']) {
            $msg = 'Package install completed.';
            if (is_array($res['decoded']) && isset($res['decoded']['message'])) {
                $msg = (string) $res['decoded']['message'];
            }

            return ['ok' => true, 'message' => $msg, 'decoded' => $res['decoded'], 'status' => $res['status']];
        }

        $detail = '';
        if (is_array($res['decoded']) && isset($res['decoded']['message'])) {
            $detail = (string) $res['decoded']['message'];
        } elseif (is_array($res['decoded']) && isset($res['decoded']['code'])) {
            $detail = (string) $res['decoded']['code'];
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
