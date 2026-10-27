<?php
declare(strict_types=1);

namespace App\Core;

/** 요청 값 검증. 실패하면 422 HttpException. */
final class Input
{
    public static function str(array $d, string $key, int $min, int $max, string $label, bool $required = true): ?string
    {
        $v = $d[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                throw new HttpException(422, 'validation', "{$label}을(를) 입력해 주세요.", $key);
            }
            return null;
        }
        if (!is_string($v)) {
            throw new HttpException(422, 'validation', "{$label} 형식이 올바르지 않습니다.", $key);
        }
        $v = trim($v);
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) {
            throw new HttpException(422, 'validation', "{$label}은(는) {$min}~{$max}자로 입력해 주세요.", $key);
        }
        return $v;
    }

    /** 줄바꿈이 필요한 긴 글. trim 하지 않고 길이만 본다. */
    public static function text(array $d, string $key, int $max, string $label): ?string
    {
        $v = $d[$key] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || mb_strlen($v) > $max) {
            throw new HttpException(422, 'validation', "{$label}은(는) {$max}자 이하로 입력해 주세요.", $key);
        }
        return $v;
    }

    public static function int(array $d, string $key, int $min, int $max, string $label, bool $required = true): ?int
    {
        $v = $d[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                throw new HttpException(422, 'validation', "{$label}을(를) 입력해 주세요.", $key);
            }
            return null;
        }
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            $v = (int) $v;
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            throw new HttpException(422, 'validation', "{$label}은(는) {$min}~{$max} 사이 숫자로 입력해 주세요.", $key);
        }
        return $v;
    }

    /** @param list<string> $allowed */
    public static function enum(array $d, string $key, array $allowed, string $label, ?string $default = null): string
    {
        $v = $d[$key] ?? $default;
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            throw new HttpException(422, 'validation', "{$label} 값이 올바르지 않습니다.", $key);
        }
        return $v;
    }

    public static function color(array $d, string $key, string $default): string
    {
        $v = $d[$key] ?? $default;
        if (!is_string($v) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $v)) {
            throw new HttpException(422, 'validation', '색상 형식이 올바르지 않습니다.', $key);
        }
        return strtoupper($v);
    }
}
