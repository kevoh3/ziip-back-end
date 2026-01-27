<?php

namespace App\Support;

class ChoiceSigner
{
    public static function sign(array $payload, string $privateKey): array
    {
        $payload['salt'] = $payload['salt'] ?? self::randomSalt(32);

        // Add senderKey only for signing
        $payloadWithKey = $payload;
        $payloadWithKey['senderKey'] = $privateKey;

        $flat = self::flatten($payloadWithKey);
        ksort($flat, SORT_STRING);

        $pairs = [];
        foreach ($flat as $k => $v) {
            if (is_bool($v)) $v = $v ? 'true' : 'false';
            if ($v === null) $v = '';
            $pairs[] = $k . '=' . (string) $v;
        }

        $payload['signature'] = hash('sha256', implode('&', $pairs));
        return $payload;
    }

    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;

            if (is_array($value)) {
                // Support both associative arrays and lists
                if (array_is_list($value)) {
                    foreach ($value as $idx => $v) {
                        if (is_array($v)) {
                            $out += self::flatten($v, $fullKey . '.' . $idx);
                        } else {
                            $out[$fullKey . '.' . $idx] = $v;
                        }
                    }
                } else {
                    $out += self::flatten($value, $fullKey);
                }
            } else {
                $out[$fullKey] = $value;
            }
        }
        return $out;
    }

    private static function randomSalt(int $length): string
    {
        $bytes = (int) ceil($length / 2);
        return substr(bin2hex(random_bytes($bytes)), 0, $length);
    }
}
