<?php

namespace App\Support;

use RuntimeException;

/**
 * pokerth_ranking.player.password = MariaDB AES_ENCRYPT(password, APP_SALT)
 * (block_encryption_mode aes-128-ecb). This class computes exactly the same
 * bytes in PHP, so APP_SALT and plaintext passwords never appear in SQL text
 * (processlist, query logs, error messages). Bind the result as a parameter.
 *
 * Twin of inquies\pokerth\event\listener::ranking_password_sql() in the forum.
 */
class RankingPassword
{
    public static function encrypt(string $password): string
    {
        $cipher = openssl_encrypt($password, 'aes-128-ecb', self::key(), OPENSSL_RAW_DATA);
        if ($cipher === false) {
            throw new RuntimeException('Password encryption failed.');
        }
        return $cipher;
    }

    /** Plaintext, or null if the blob is empty or does not decrypt (like AES_DECRYPT). */
    public static function decrypt(?string $blob): ?string
    {
        if ($blob === null || $blob === '') {
            return null;
        }
        $plain = openssl_decrypt($blob, 'aes-128-ecb', self::key(), OPENSSL_RAW_DATA);
        return $plain === false ? null : $plain;
    }

    public static function check(string $password, ?string $blob): bool
    {
        $plain = self::decrypt($blob);
        return $plain !== null && hash_equals($plain, $password);
    }

    /** MariaDB folds the key string into 16 bytes by XOR. */
    private static function key(): string
    {
        $salt = (string) env('APP_SALT');
        if ($salt === '') {
            throw new RuntimeException('APP_SALT is not set.');
        }
        $key = str_repeat("\0", 16);
        for ($i = 0, $n = strlen($salt); $i < $n; $i++) {
            $key[$i % 16] = chr(ord($key[$i % 16]) ^ ord($salt[$i]));
        }
        return $key;
    }
}
