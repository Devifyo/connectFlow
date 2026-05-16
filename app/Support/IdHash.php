<?php

namespace App\Support;

class IdHash
{
    private static string $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private static function key(): int
    {
        return crc32(config('app.key')) & 0x3FFFFFFF;
    }

    public static function encode(int $id): string
    {
        $num = $id ^ self::key();
        $base = 62;
        $result = '';
        do {
            $result = self::$chars[$num % $base] . $result;
            $num = intdiv($num, $base);
        } while ($num > 0);
        return str_pad($result, 6, '0', STR_PAD_LEFT);
    }

    public static function decode(string $hash): ?int
    {
        if (!preg_match('/^[0-9a-zA-Z]{6}$/', $hash)) return null;
        $hash = ltrim($hash, '0') ?: '0';
        $base = 62;
        $num = 0;
        for ($i = 0; $i < strlen($hash); $i++) {
            $pos = strpos(self::$chars, $hash[$i]);
            if ($pos === false) return null;
            $num = $num * $base + $pos;
        }
        return $num ^ self::key();
    }

    public static function signedUrl(int $id): string
    {
        $token = self::encode($id);
        $expires = time() + 300;
        $sig = substr(hash_hmac('sha256', $token . $expires, config('app.key')), 0, 10);
        return '/api/profile/picture/' . $token . '?e=' . $expires . '&s=' . $sig;
    }

    public static function verifySignature(string $token, int $expires, string $sig): bool
    {
        if ($expires < time()) return false;
        $expected = substr(hash_hmac('sha256', $token . $expires, config('app.key')), 0, 10);
        return hash_equals($expected, $sig);
    }

    public static function emailAvatarUrl(int $id): string
    {
        $token = self::encode($id);
        $expires = time() + 86400;
        $sig = substr(hash_hmac('sha256', 'avatar' . $token . $expires, config('app.key')), 0, 12);
        return '/api/email-avatar/' . $token . '?e=' . $expires . '&s=' . $sig;
    }

    public static function verifyEmailAvatarSignature(string $token, int $expires, string $sig): bool
    {
        if ($expires < time()) return false;
        $expected = substr(hash_hmac('sha256', 'avatar' . $token . $expires, config('app.key')), 0, 12);
        return hash_equals($expected, $sig);
    }
}
