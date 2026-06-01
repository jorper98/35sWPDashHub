<?php

declare(strict_types=1);

namespace S35WpHub\Util;

/**
 * Normalizes JSON / REST values that may be string, number, or WP-style { raw, rendered } arrays.
 */
final class MixedText
{
    public static function toPlainString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            $s = (string) $value;

            return trim($s);
        }
        if (is_array($value)) {
            foreach (['rendered', 'raw', 'name', 'text'] as $key) {
                if (isset($value[$key])) {
                    $inner = self::toPlainString($value[$key]);
                    if ($inner !== '') {
                        return $inner;
                    }
                }
            }
            if (isset($value[0])) {
                $inner = self::toPlainString($value[0]);
                if ($inner !== '') {
                    return $inner;
                }
            }
            foreach ($value as $inner) {
                $t = self::toPlainString($inner);
                if ($t !== '') {
                    return $t;
                }
            }
        }

        return '';
    }
}
