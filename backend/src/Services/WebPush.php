<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 웹 푸시 전송 (RFC 8030 전송 + RFC 8291 aes128gcm 암호화 + RFC 8292 VAPID).
 * 외부 라이브러리 없이 openssl 만 쓴다. Cafe24 에서 curl·openssl 확장이 필요하다.
 */
final class WebPush
{
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64u(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64u(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** @return array{public:string,private_pem:string} public 은 비압축 점(65바이트)의 base64url */
    public static function generateVapidKeys(): array
    {
        $key = self::newKey();
        openssl_pkey_export($key, $pem);
        return ['public' => self::b64u(self::publicPoint($key)), 'private_pem' => $pem];
    }

    /** 푸시 서비스 주소로 보낼 Authorization 헤더 값 */
    public static function vapidAuthorization(string $endpoint, array $vapid, ?int $now = null): string
    {
        $parts = parse_url($endpoint);
        $aud = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $head = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64u(json_encode([
            'aud' => $aud,
            'exp' => ($now ?? time()) + 12 * 3600,
            'sub' => $vapid['subject'] ?? 'mailto:admin@example.com',
        ]));
        $input = "$head.$claims";
        $key = openssl_pkey_get_private($vapid['private_pem']);
        openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256);
        return 'vapid t=' . $input . '.' . self::b64u(self::derToRaw($der)) . ', k=' . $vapid['public'];
    }

    /**
     * 받는 쪽 공개키(raw 65바이트)와 auth 비밀(16바이트)로 payload 를 암호화한 본문.
     * $asKey, $salt 는 테스트에서 고정값을 넣을 때만 쓴다.
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, $asKey = null, ?string $salt = null): string
    {
        $asKey ??= self::newKey();
        $salt ??= random_bytes(16);
        $asPublic = self::publicPoint($asKey);

        $secret = openssl_pkey_derive(self::publicPem($uaPublic), $asKey, 32);
        if ($secret === false) {
            throw new \RuntimeException('ECDH 실패');
        }
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('암호화 실패');
        }
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    /**
     * 구독 한 건에 알림을 보낸다.
     * @param array{endpoint:string,p256dh:string,auth:string} $sub
     * @return int HTTP 상태 (0 = 연결 실패). 404/410 은 구독이 사라졌다는 뜻.
     */
    public static function send(array $sub, array $message, array $vapid): int
    {
        $body = self::encrypt(json_encode($message, JSON_UNESCAPED_UNICODE), self::unb64u($sub['p256dh']), self::unb64u($sub['auth']));
        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . self::vapidAuthorization($sub['endpoint'], $vapid),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: 86400',
                'Urgency: normal',
            ],
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status;
    }

    /** 사용자가 보낸 endpoint 로 서버가 요청을 보내므로, https 이고 IP 직접 지정이 아닌 주소만 허용한다. */
    public static function endpointAllowed(string $endpoint, bool $allowAny = false): bool
    {
        if ($allowAny) {
            return (bool) preg_match('#^https?://#', $endpoint);
        }
        $p = parse_url($endpoint);
        $host = strtolower((string) ($p['host'] ?? ''));
        return ($p['scheme'] ?? '') === 'https'
            && $host !== '' && $host !== 'localhost'
            && !filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)
            && str_contains($host, '.');
    }

    private static function newKey()
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new \RuntimeException('EC 키를 만들 수 없어요 (openssl 확장 확인).');
        }
        return $key;
    }

    public static function publicPoint($key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'];
        return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    public static function publicPem(string $point): string
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derToRaw(string $der): string
    {
        $offset = 3; // 30 len 02
        $rLen = ord($der[$offset]);
        $r = substr($der, $offset + 1, $rLen);
        $offset += 1 + $rLen + 1; // 02
        $sLen = ord($der[$offset]);
        $s = substr($der, $offset + 1, $sLen);
        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }
}
