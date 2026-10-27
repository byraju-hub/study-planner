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

    /** YYYY-MM-DD. 없으면 $default(null이면 필수). */
    public static function date(array $d, string $key, string $label, ?string $default = null): string
    {
        $v = $d[$key] ?? $default;
        if ($v === null || $v === '') {
            throw new HttpException(422, 'validation', "{$label}을(를) 입력해 주세요.", $key);
        }
        $dt = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
        if ($dt === false || $dt->format('Y-m-d') !== $v) {
            throw new HttpException(422, 'validation', "{$label} 형식이 올바르지 않습니다. (예: 2026-10-27)", $key);
        }
        return $v;
    }

    /** 소유한 과목 id를 검증하고 돌려준다. null이면 null. */
    public static function ownedSubject(array $d, string $key, int $userId, bool $required = false): ?int
    {
        $id = self::int($d, $key, 1, PHP_INT_MAX, '과목', $required);
        if ($id !== null) {
            Owned::find('subjects', $id, $userId);
        }
        return $id;
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
