<?php
class JWT
{
    public static function encode(array $payload)
    {
        $header = ['typ' => 'JWT', 'alg' => JWT_ALGO];
        $payload['iat'] = time();
        $payload['exp'] = time() + JWT_EXPIRY_SECONDS;

        $segments   = [];
        $segments[] = self::base64UrlEncode(json_encode($header));
        $segments[] = self::base64UrlEncode(json_encode($payload));

        $signingInput = implode('.', $segments);
        $signature    = hash_hmac('sha256', $signingInput, JWT_SECRET, true);
        $segments[]   = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    public static function decode($jwt)
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return false;

        list($headerB64, $payloadB64, $signatureB64) = $parts;

        $signingInput      = $headerB64 . '.' . $payloadB64;
        $expectedSignature = hash_hmac('sha256', $signingInput, JWT_SECRET, true);
        $actualSignature   = self::base64UrlDecode($signatureB64);

        if (!hash_equals($expectedSignature, $actualSignature)) return false;

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) return false;

        return $payload;
    }

    private static function base64UrlEncode($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
    private static function base64UrlDecode($data)
    {
        $padded = str_pad($data, strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');
        return base64_decode(strtr($padded, '-_', '+/'));
    }
}
