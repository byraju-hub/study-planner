<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Http;
use App\Core\HttpException;

/**
 * YES24 Open API (https://developers.yes24.com, X-Api-Key 필요).
 * 문서(itemList, content)를 바탕으로 만들었고 실제 키로는 아직 호출해 보지 못했다 (docs/PROGRESS.md "확인 필요").
 * 약관: 책 정보를 보여 주는 곳에 YES24 출처를 적고, 책 정보를 모아 DB에 쌓지 않는다 (목차는 학생이 확인한 단원만 저장).
 */
final class Yes24
{
    /** 결과가 없다는 뜻의 오류 코드 */
    private const NOT_FOUND = ['SEARCH_001', 'SEARCH_002', 'GOODS_001', 'GOODS_002'];

    public static function configured(): bool
    {
        return (string) Config::get('yes24.api_key', '') !== '';
    }

    /** @return list<array<string,mixed>> */
    public static function search(string $query): array
    {
        $data = self::call('/v1/goods/itemList', ['query' => $query, 'category' => 'BOOK', 'pageSize' => 10, 'page' => 1, 'detail' => 'Y']);
        return self::mapItems($data);
    }

    /** 목차 원문 (없으면 빈 문자열) */
    public static function toc(string $isbn13): string
    {
        return self::mapToc(self::call('/v1/goods/content', ['searchType' => 'ISBN13', 'query' => $isbn13]));
    }

    /** @param array<string,mixed> $data @return list<array<string,mixed>> */
    public static function mapItems(array $data): array
    {
        $out = [];
        foreach ((array) ($data['data']['items'] ?? []) as $it) {
            if (!is_array($it) || empty($it['title'])) {
                continue;
            }
            $out[] = [
                'title' => (string) $it['title'],
                'author' => (string) ($it['author'] ?? ''),
                'publisher' => (string) ($it['publisher'] ?? ''),
                'pub_date' => (string) ($it['publishDate'] ?? ''),
                'isbn13' => (string) ($it['isbn13'] ?? ''),
                'cover' => (string) ($it['cover'] ?? ''),
                'pages' => isset($it['pages']) && (int) $it['pages'] > 0 ? (int) $it['pages'] : null,
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    public static function mapToc(array $data): string
    {
        return (string) ($data['data']['data']['contents'] ?? '');
    }

    /** @return array<string,mixed> */
    private static function call(string $path, array $params): array
    {
        if (!self::configured()) {
            throw new HttpException(503, 'not_configured', 'YES24 연동이 설정되지 않았어요. (관리자: config.php 의 yes24.api_key)');
        }
        $base = rtrim((string) Config::get('yes24.base_url', 'https://apis.yes24.com'), '/');
        $data = Http::json('GET', $base . $path . '?' . http_build_query($params), null, ['X-Api-Key: ' . Config::get('yes24.api_key')]);
        if (($data['success'] ?? false) !== true) {
            $code = (string) ($data['errorCode'] ?? '');
            if (in_array($code, self::NOT_FOUND, true)) {
                return [];
            }
            error_log('yes24 error ' . json_encode($data, JSON_UNESCAPED_UNICODE));
            throw new HttpException(502, 'upstream', 'YES24에서 오류를 돌려줬어요.');
        }
        return $data;
    }
}
