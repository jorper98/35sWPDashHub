<?php

declare(strict_types=1);

/**
 * Copy this file to config.php and set your values.
 * Generate encryption key: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
 *
 * Windows + HTTPS: `curl_ca_bundle` must be a real path to cacert.pem (not null / not empty),
 * unless php.ini already sets curl.cainfo. See README → “SSL certificate error when syncing”.
 */
return [
    'db_path' => dirname(__DIR__) . '/storage/database.sqlite',
    /** Base64-encoded 32-byte key for AES-256-GCM */
    'encryption_key' => 'CHANGE_ME_BASE64_32_BYTES',
    /** Public URL of this dashboard (no trailing slash); used for redirects only */
    'base_url' => 'https://dashboard.example.com/public',

    /** Max size in bytes for uploaded plugin zips on the Plugin packages page (default 20 MB). */
    'plugin_package_max_bytes' => 20971520,

    /**
     * Path to Mozilla CA bundle (cacert.pem). Empty string = do not pass a bundle (often fails on Windows).
     * Download: https://curl.se/ca/cacert.pem — then set e.g. 'D:/PHP/extras/ssl/cacert.pem'
     */
    'curl_ca_bundle' => '',

    /**
     * Set false only for local dev / self-signed sites. Never use false against the public internet.
     */
    'http_verify_ssl' => true,

    /**
     * Dashboard login (required). Add more entries for additional operators.
     * Generate a hash: php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
     * Example below uses password "changeme" — replace before production.
     */
    'dashboard_users' => [
        [
            'username' => 'admin',
            'password_hash' => '$2y$10$9voXpBX3pvRVh7SCa7N0WeD0/v0l27eQyANqxY.whrRKq9..gR5FG',
        ],
    ],

    /**
     * From address for owner email reports (PHP mail()). Required to send reports.
     * Example: noreply@yourdomain.com
     */
    'report_mail_from' => '',
    /** Display name on report emails */
    'report_mail_from_name' => 's35-wp-hub',

    /**
     * Opening paragraph(s) after "Hello …" on owner report emails (plain text, multiple lines OK).
     * Leave empty string to omit. Include "Below is a brief summary:" (or similar) if you want that line.
     */
    'report_mail_intro' => <<<'INTRO'
Thank you for trusting us with your website. Many details were taken care of behind the scenes for you, so you do not have to worry about the technical aspects of running your site.

Below is a brief summary:
INTRO,

    /**
     * Closing text appended to owner report emails (plain text, multiple lines OK).
     */
    'report_mail_signature' => <<<'SIG'
The 35Sites Support Team
Visit our Help Center

Know someone looking for better hosting? We're always looking to help more people simplify their web presence. Referrals mean the world to us!
SIG,
];
