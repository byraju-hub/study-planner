<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Http;
use App\Core\HttpException;

/**
 * 알라딘 OpenAPI (ttbkey 필요). 주소·파라미터는 공개 안내를 바탕으로 한 것이라
 * 실제 키로 한 번 확인해야 한다 (docs/PROGRESS.md "확인 필요").
 */
final class Aladin
{
    public static function configured(): bool
    {
        return (string) Config::get('aladin.ttb_key', '') !== '';
    }

    /** @return list<array<string,mixed>> */
    public static function search(string $query): array
    {
        $data = self::call('ItemSearch.aspx', [
            'Query' => $query, 'QueryType' => 'Title', 'SearchTarget' => 'Book', 'MaxResults' => 10, 'start' => 1,
        ]);
        return self::mapItems($data);
    }

    /** 목차 원문 (없으면 빈 문자열) */
    public static function toc(string $isbn13): string
    {
        $data = self::call('ItemLookUp.aspx', ['ItemId' => $isbn13, 'ItemIdType' => 'ISBN13', 'OptResult' => 'Toc']);
        return self::mapToc($data);
    }

    /** @param array<string,mixed> $data @return list<array<string,mixed>> */
    public static function mapItems(array $data): array
    {
        $out = [];
        foreach ((array) ($data['item'] ?? []) as $it) {
            if (!is_array($it) || empty($it['title'])) {
                continue;
            }
            $out[] = [
                'title' => (string) $it['title'],
                'author' => (string) ($it['author'] ?? ''),
                'publisher' => (string) ($it['publisher'] ?? ''),
                'pub_date' => (string) ($it['pubDate'] ?? ''),
                'isbn13' => (string) ($it['isbn13'] ?? ''),
                'cover' => (string) ($it['cover'] ?? ''),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    public static function mapToc(array $data): string
    {
        $item = $data['item'][0] ?? [];
        return (string) ($item['subInfo']['toc'] ?? '');
    }

    /** @return array<string,mixed> */
    private static function call(string $endpoint, array $params): array
    {
        if (!self::configured()) {
            throw new HttpException(503, 'not_configured', '알라딘 연동이 설정되지 않았어요. (관리자: config.php 의 aladin.ttb_key)');
        }
        $base = rtrim((string) Config::get('aladin.base_url', 'https://www.aladin.co.kr/ttb/api'), '/');
        $params += ['ttbkey' => Config::get('aladin.ttb_key'), 'output' => 'js', 'Version' => '20131101'];
        $data = Http::json('GET', $base . '/' . $endpoint . '?' . http_build_query($params));
        if (isset($data['errorCode'])) {
            error_log('aladin error ' . json_encode($data, JSON_UNESCAPED_UNICODE));
            throw new HttpException(502, 'upstream', '알라딘에서 오류를 돌려줬어요.');
        }
        return $data;
    }
}
