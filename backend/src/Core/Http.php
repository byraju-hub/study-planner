<?php
declare(strict_types=1);

namespace App\Core;

/** 외부 서비스(YES24·나이스·Claude)를 부르는 얇은 curl 래퍼. 실패하면 502. */
final class Http
{
    /** @return array<string,mixed> */
    public static function json(string $method, string $url, ?array $body = null, array $headers = [], int $timeout = 15): array
    {
        $ch = curl_init($url);
        // 나이스는 Accept: application/json 을 보내면 500 을 돌려준다. 호출하는 쪽이 'Accept: */*' 로 덮어쓸 수 있다.
        $h = array_filter($headers, static fn(string $x): bool => stripos($x, 'Accept:') === 0) === [] ? ['Accept: application/json'] : [];
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge($h, $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            error_log("http: $method $url failed: $err");
            throw new HttpException(502, 'upstream', '외부 서비스에 연결하지 못했어요. 잠시 뒤에 다시 시도해 주세요.');
        }
        $data = json_decode(trim((string) $raw, "; \t\n\r"), true);
        if (!is_array($data)) {
            error_log("http: $method $url status $status non-JSON");
            throw new HttpException(502, 'upstream', '외부 서비스의 응답을 읽을 수 없어요.');
        }
        $data['__status'] = $status;
        return $data;
    }
}
