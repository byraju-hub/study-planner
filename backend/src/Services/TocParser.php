<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 서점 목차 글(HTML 일부)을 단원 목록으로 바꾼다. DB를 쓰지 않는 순수 함수.
 * 서점마다 형식이 달라 100% 맞출 수 없으므로 결과는 학생이 미리 보고 고친 뒤에 저장한다.
 */
final class TocParser
{
    public const MAX_ITEMS = 300;

    /** @return list<array{title:string,page_start:?int,page_end:?int,level:int}> */
    public static function parse(string $raw): array
    {
        $text = preg_replace('#<\s*(br|/p|/li|/div)\s*/?\s*>#i', "\n", $raw);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $items = [];
        $sawTop = false;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/[\x{00A0}\t]+/u', ' ', $line));
            if (mb_strlen($line) < 2) {
                continue;
            }
            $page = null;
            if (preg_match('/^(.*?)(?:\s*[.·…\-]{2,}\s*|\s{2,}|\s+p\.?\s*)(\d{1,4})$/u', $line, $m) && mb_strlen(trim($m[1])) >= 2) {
                $line = trim($m[1]);
                $page = (int) $m[2];
            }
            $level = self::level($line, $sawTop);
            if ($level === 1) {
                $sawTop = true;
            }
            $items[] = ['title' => mb_substr($line, 0, 120), 'page_start' => $page, 'page_end' => null, 'level' => $level];
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }
        return self::fillPageEnds($items);
    }

    private static function level(string $line, bool $sawTop): int
    {
        if (preg_match('/^(\d{1,2}\s*[-.]\s*\d{1,2}\b|\d{1,2}\)|[①-⑩]|[가-힣]\.|[·•\-]\s)/u', $line)) {
            return $sawTop ? 2 : 1;
        }
        if (preg_match('/^(제?\s*\d+\s*[장부편]|[ⅠⅡⅢⅣⅤⅥⅦⅧⅨⅩ]+|[IVX]+[.)\s]|Chapter\s*\d+|Part\s*\d+|\d{1,2}[.\s])/iu', $line)) {
            return 1;
        }
        return $sawTop ? 2 : 1;
    }

    /** 다음 항목의 시작 쪽 - 1 을 끝 쪽으로 (같은 단계 이상의 다음 항목 기준). */
    private static function fillPageEnds(array $items): array
    {
        foreach ($items as $i => $it) {
            if ($it['page_start'] === null) {
                continue;
            }
            for ($j = $i + 1, $n = count($items); $j < $n; $j++) {
                if ($items[$j]['level'] <= $it['level'] && $items[$j]['page_start'] !== null) {
                    $end = $items[$j]['page_start'] - 1;
                    if ($end >= $it['page_start']) {
                        $items[$i]['page_end'] = $end;
                    }
                    break;
                }
            }
        }
        return $items;
    }
}
