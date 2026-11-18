<?php
/**
 * CStore — TOTP (Time-based One-Time Password) Implementation
 * RFC 6238 compliant, self-contained (no Composer dependencies)
 */

class TOTP
{
    private static string $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a random Base32-encoded secret (160-bit)
     */
    public static function generateSecret(int $length = 20): string
    {
        $bytes = random_bytes($length);
        return self::base32Encode($bytes);
    }

    /**
     * Generate the current TOTP code
     */
    public static function getCode(string $secret, ?int $time = null, int $period = 30, int $digits = 6): string
    {
        $time = $time ?? time();
        $timeSlice = intdiv($time, $period);

        $key = self::base32Decode($secret);
        $timeBytes = pack('N*', 0) . pack('N*', $timeSlice); // 8-byte big-endian

        $hash = hash_hmac('sha1', $timeBytes, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % pow(10, $digits);

        return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a TOTP code with ±1 time window (90 seconds tolerance)
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        return self::verifyStep($secret, $code, $window) !== null;
    }

    /**
     * Verify a TOTP code and return the absolute 30-second time step that
     * matched (for replay protection), or null if the code is invalid.
     */
    public static function verifyStep(string $secret, string $code, int $window = 1): ?int
    {
        if ($secret === '' || !preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $current = intdiv(time(), 30);
        $matched = null;
        // Check every step in the window (no early exit) to keep timing uniform
        for ($i = -$window; $i <= $window; $i++) {
            $step = $current + $i;
            if (hash_equals(self::getCode($secret, $step * 30), $code) && $matched === null) {
                $matched = $step;
            }
        }
        return $matched;
    }

    /**
     * Generate otpauth:// URI for QR code
     */
    public static function getOtpAuthUrl(string $label, string $secret, string $issuer = 'CStore'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($label)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    /**
     * Base32 encode
     */
    private static function base32Encode(string $data): string
    {
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        $chunks = str_split($binary, 5);
        foreach ($chunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $result .= self::$base32Chars[bindec($chunk)];
        }

        return $result;
    }

    /**
     * Base32 decode
     */
    private static function base32Decode(string $data): string
    {
        $data = strtoupper($data);
        $binary = '';
        foreach (str_split($data) as $char) {
            $pos = strpos(self::$base32Chars, $char);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        $bytes = str_split($binary, 8);
        foreach ($bytes as $byte) {
            if (strlen($byte) < 8) break;
            $result .= chr(bindec($byte));
        }

        return $result;
    }
}
