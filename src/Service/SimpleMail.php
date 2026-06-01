<?php

declare(strict_types=1);

namespace S35WpHub\Service;

/**
 * Plain UTF-8 email via PHP mail(). Requires a working sendmail/MTA or host mail configuration.
 */
final class SimpleMail
{
    public static function sendText(string $to, string $subject, string $body, string $fromEmail, string $fromName): bool
    {
        $to = trim($to);
        $fromEmail = trim($fromEmail);
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)
            || ! filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $fn = trim($fromName);
        $fromHeader = $fn !== ''
            ? self::encodeFromName($fn) . ' <' . $fromEmail . '>'
            : $fromEmail;

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $fromHeader,
        ];

        $encSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8', 'Q', "\r\n")
            : $subject;

        return @mail($to, $encSubject, $body, implode("\r\n", $headers));
    }

    private static function encodeFromName(string $name): string
    {
        if (preg_match('/^[\p{L}\p{N}\s._\'-]+$/u', $name) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
        }

        return function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($name, 'UTF-8', 'Q', "\r\n")
            : $name;
    }
}
