<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Http;
use App\Core\HttpException;

/**
 * 나이스 교육정보 개방 포털 (open.neis.go.kr). 인증키 필요. 학교 검색(schoolInfo)과 고등학교 시간표(hisTimetable).
 * 요청·응답 필드는 공개 안내를 바탕으로 한 것이라 실제 키로 한 번 확인해야 한다 (docs/PROGRESS.md "확인 필요").
 */
final class Neis
{
    public static function configured(): bool
    {
        return (string) Config::get('neis.key', '') !== '';
    }

    /** @return list<array<string,string>> */
    public static function schools(string $name): array
    {
        return self::rows(self::call('schoolInfo', ['SCHUL_NM' => $name, 'pSize' => 20]), 'schoolInfo', static fn(array $r): array => [
            'office_code' => (string) ($r['ATPT_OFCDC_SC_CODE'] ?? ''),
            'office_name' => (string) ($r['ATPT_OFCDC_SC_NM'] ?? ''),
            'school_code' => (string) ($r['SD_SCHUL_CODE'] ?? ''),
            'name' => (string) ($r['SCHUL_NM'] ?? ''),
            'kind' => (string) ($r['SCHUL_KND_SC_NM'] ?? ''),
            'address' => (string) ($r['ORG_RDNMA'] ?? ''),
        ]);
    }

    /**
     * 한 주(월~금)의 시간표. 요일·교시마다 과목 이름 하나.
     * @return list<array{weekday:int,period:int,name:string}>
     */
    public static function timetable(string $office, string $school, int $grade, int $classNo, string $anyDateInWeek): array
    {
        $monday = (new \DateTimeImmutable($anyDateInWeek))->modify('monday this week');
        $data = self::call('hisTimetable', [
            'ATPT_OFCDC_SC_CODE' => $office, 'SD_SCHUL_CODE' => $school,
            'GRADE' => $grade, 'CLASS_NM' => $classNo,
            'TI_FROM_YMD' => $monday->format('Ymd'), 'TI_TO_YMD' => $monday->modify('+4 days')->format('Ymd'),
            'pSize' => 100,
        ]);
        return self::mapTimetable(self::rows($data, 'hisTimetable', static fn(array $r): array => $r));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{weekday:int,period:int,name:string}>
     */
    public static function mapTimetable(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $ymd = (string) ($r['ALL_TI_YMD'] ?? '');
            $period = (int) ($r['PERIO'] ?? 0);
            $name = trim((string) ($r['ITRT_CNTNT'] ?? ''));
            if (!preg_match('/^\d{8}$/', $ymd) || $period < 1 || $name === '') {
                continue;
            }
            $weekday = (int) (new \DateTimeImmutable($ymd))->format('N');
            if ($weekday > 5) {
                continue;
            }
            $out[$weekday . '-' . $period] = ['weekday' => $weekday, 'period' => $period, 'name' => $name];
        }
        ksort($out);
        return array_values($out);
    }

    /**
     * 나이스 응답: { "<서비스>": [ {"head": [...]}, {"row": [...]} ] } 이고, 결과가 없으면 { "RESULT": { "CODE": "INFO-200" } }.
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    public static function rows(array $data, string $service, callable $map): array
    {
        $code = $data['RESULT']['CODE'] ?? null;
        if ($code !== null && $code !== 'INFO-000') {
            if ($code === 'INFO-200') {
                return [];
            }
            error_log('neis error ' . json_encode($data['RESULT'], JSON_UNESCAPED_UNICODE));
            throw new HttpException(502, 'upstream', '나이스에서 오류를 돌려줬어요.');
        }
        $out = [];
        foreach ((array) ($data[$service] ?? []) as $block) {
            foreach ((array) ($block['row'] ?? []) as $row) {
                if (is_array($row)) {
                    $out[] = $map($row);
                }
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function call(string $service, array $params): array
    {
        if (!self::configured()) {
            throw new HttpException(503, 'not_configured', '나이스 연동이 설정되지 않았어요. (관리자: config.php 의 neis.key)');
        }
        $base = rtrim((string) Config::get('neis.base_url', 'https://open.neis.go.kr/hub'), '/');
        return Http::json('GET', $base . '/' . $service . '?' . http_build_query(['KEY' => Config::get('neis.key'), 'Type' => 'json', 'pIndex' => 1] + $params), null, ['Accept: */*']);
    }
}
