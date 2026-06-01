<?php

declare(strict_types=1);

namespace S35WpHub;

final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    public function __construct(
        private readonly string $rawKey
    ) {
        if (strlen($this->rawKey) !== 32) {
            throw new \InvalidArgumentException('Encryption key must be exactly 32 bytes.');
        }
    }

    public static function fromBase64Key(string $base64): self
    {
        $binary = base64_decode($base64, true);
        if ($binary === false || strlen($binary) !== 32) {
            throw new \InvalidArgumentException('encryption_key must be base64-encoded 32 random bytes.');
        }

        return new self($binary);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->rawKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $stored): string
    {
        $binary = base64_decode($stored, true);
        if ($binary === false || strlen($binary) < 12 + self::TAG_LENGTH + 1) {
            throw new \RuntimeException('Invalid ciphertext.');
        }
        $iv = substr($binary, 0, 12);
        $tag = substr($binary, 12, self::TAG_LENGTH);
        $ciphertext = substr($binary, 12 + self::TAG_LENGTH);
        $plain = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->rawKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plain;
    }
}
