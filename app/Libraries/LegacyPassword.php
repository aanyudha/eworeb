<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Verifies the legacy EWO password formats. The portable hash algorithm is
 * based on the public-domain phpass 0.3 by Solar Designer.
 */
final class LegacyPassword
{
    private const ALPHABET = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function verify(string $password, string $storedHash): bool
    {
        if ($storedHash === '' || $storedHash[0] === '*' || str_contains($password, "\0")) {
            return false;
        }

        $scheme = substr($storedHash, 0, 3);
        if ($scheme === '$P$' || $scheme === '$H$') {
            if (strlen($storedHash) !== 34) {
                return false;
            }

            $exponent = strpos(self::ALPHABET, $storedHash[3]);
            if ($exponent === false || $exponent < 7 || $exponent > 30) {
                return false;
            }

            $hash = md5(substr($storedHash, 4, 8) . $password, true);
            $rounds = 1 << $exponent;
            do {
                $hash = md5($hash . $password, true);
            } while (--$rounds > 0);

            return hash_equals($storedHash, substr($storedHash, 0, 12) . $this->encode($hash));
        }

        $hash = crypt($password, $storedHash);

        return $hash !== '' && $hash[0] !== '*' && hash_equals($storedHash, $hash);
    }

    private function encode(string $input): string
    {
        $output = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($input) as $byte) {
            $buffer |= ord($byte) << $bits;
            $bits += 8;

            while ($bits >= 6) {
                $output .= self::ALPHABET[$buffer & 63];
                $buffer >>= 6;
                $bits -= 6;
            }
        }

        return $bits > 0 ? $output . self::ALPHABET[$buffer] : $output;
    }
}

