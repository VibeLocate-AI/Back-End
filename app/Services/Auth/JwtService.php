<?php

namespace App\Services\Auth;

class JwtService
{
    private function secret(): string
    {
        return (string) config('vibelocate.jwt_secret');
    }

    private function encodePart(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decodePart(string $value): string|false
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    public function encode(array $payload): string
    {
        $header = $this->encodePart(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $body = $this->encodePart(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = $this->encodePart(hash_hmac('sha256', "$header.$body", $this->secret(), true));
        return "$header.$body.$signature";
    }

    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $headerData = json_decode((string) $this->decodePart($header), true);
        $payloadData = json_decode((string) $this->decodePart($payload), true);

        if (!is_array($headerData) || !is_array($payloadData) || ($headerData['alg'] ?? '') !== 'HS256') {
            return null;
        }

        $expected = $this->encodePart(hash_hmac('sha256', "$header.$payload", $this->secret(), true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        if (isset($payloadData['exp']) && (int) $payloadData['exp'] < time()) {
            return null;
        }
        if (isset($payloadData['nbf']) && (int) $payloadData['nbf'] > time()) {
            return null;
        }

        return $payloadData;
    }

    public function accessToken(int $userId, string $email): string
    {
        $now = time();
        return $this->encode([
            'user_id' => $userId,
            'email' => $email,
            'type' => 'access',
            'iat' => $now,
            'exp' => $now + (int) config('vibelocate.access_expiry', 900),
            'jti' => bin2hex(random_bytes(16)),
        ]);
    }

    public function refreshToken(): string
    {
        return bin2hex(random_bytes(64));
    }

    public function emailToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
