<?php
declare(strict_types=1);

namespace App\Services;

/** 문제 형식 검사와 채점. DB를 쓰지 않는 순수 함수. */
final class QuizGrader
{
    public const TYPES = ['mcq' => '객관식', 'ox' => 'OX', 'short' => '단답형', 'essay' => '서술형', 'card' => '암기 카드'];
    /** 한 단원의 최근 답이 이 개수 이상이고 정답률이 이 값 미만이면 약한 단원 */
    public const WEAK_MIN_ANSWERED = 3;
    public const WEAK_ACCURACY = 0.6;

    /**
     * 저장할 문제를 검사하고 정리한다. 잘못되면 한국어 메시지의 InvalidArgumentException.
     * @param array<string,mixed> $q type, prompt, choices(list), answer, explanation
     * @return array{type:string,prompt:string,choices:?list<string>,answer:string,explanation:?string}
     */
    public static function normalize(array $q): array
    {
        $type = (string) ($q['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('문제 형식이 올바르지 않아요.');
        }
        $prompt = trim((string) ($q['prompt'] ?? ''));
        if ($prompt === '' || mb_strlen($prompt) > 2000) {
            throw new \InvalidArgumentException('문제를 1~2000자로 입력해 주세요.');
        }
        $answer = trim((string) ($q['answer'] ?? ''));
        $explanation = isset($q['explanation']) ? trim((string) $q['explanation']) : '';
        if (mb_strlen($explanation) > 2000) {
            throw new \InvalidArgumentException('해설은 2000자까지 쓸 수 있어요.');
        }
        $choices = null;

        switch ($type) {
            case 'mcq':
                $raw = is_array($q['choices'] ?? null) ? $q['choices'] : [];
                $choices = array_values(array_map(static fn($c): string => trim((string) $c), $raw));
                if (count($choices) < 2 || count($choices) > 6 || in_array('', $choices, true)) {
                    throw new \InvalidArgumentException('보기는 비어 있지 않게 2~6개를 입력해 주세요.');
                }
                if (!ctype_digit($answer) || (int) $answer >= count($choices)) {
                    throw new \InvalidArgumentException('정답 보기를 골라 주세요.');
                }
                $answer = (string) (int) $answer;
                break;
            case 'ox':
                $answer = strtoupper($answer);
                if (!in_array($answer, ['O', 'X'], true)) {
                    throw new \InvalidArgumentException('정답은 O 또는 X 여야 해요.');
                }
                break;
            case 'short':
                $parts = array_values(array_filter(array_map('trim', explode('|', $answer)), static fn(string $p): bool => $p !== ''));
                if ($parts === []) {
                    throw new \InvalidArgumentException('정답을 입력해 주세요. 정답이 여러 개면 | 로 구분해요.');
                }
                $answer = implode('|', $parts);
                break;
            default: // essay, card
                if ($answer === '') {
                    throw new \InvalidArgumentException($type === 'card' ? '카드 뒷면을 입력해 주세요.' : '모범 답안을 입력해 주세요.');
                }
        }
        if (mb_strlen($answer) > 4000) {
            throw new \InvalidArgumentException('정답이 너무 길어요.');
        }
        return ['type' => $type, 'prompt' => $prompt, 'choices' => $choices, 'answer' => $answer, 'explanation' => $explanation === '' ? null : $explanation];
    }

    /**
     * 채점. 서술형·카드는 학생이 스스로 맞았는지 표시한다($selfCorrect).
     * @param array{type:string,answer:string} $question
     */
    public static function grade(array $question, ?string $given, ?bool $selfCorrect): bool
    {
        switch ($question['type']) {
            case 'mcq':
                return $given !== null && ctype_digit(trim($given)) && (int) $given === (int) $question['answer'];
            case 'ox':
                return $given !== null && strtoupper(trim($given)) === $question['answer'];
            case 'short':
                if ($given === null || self::norm($given) === '') {
                    return false;
                }
                foreach (explode('|', $question['answer']) as $accepted) {
                    if (self::norm($accepted) === self::norm($given)) {
                        return true;
                    }
                }
                return false;
            default:
                if ($selfCorrect === null) {
                    throw new \InvalidArgumentException('맞았는지 직접 표시해 주세요.');
                }
                return $selfCorrect;
        }
    }

    /** 공백·마침표·쉼표 차이와 대소문자를 무시한다. */
    public static function norm(string $s): string
    {
        return mb_strtolower((string) preg_replace('/[\s.,;:!?\'"·]+/u', '', trim($s)));
    }

    public static function isWeak(int $answered, int $correct): bool
    {
        return $answered >= self::WEAK_MIN_ANSWERED && $correct / $answered < self::WEAK_ACCURACY;
    }

    /** 보기 정답 번호를 사람이 읽을 글로 바꾼다. @param array{type:string,answer:string,choices:?list<string>} $q */
    public static function answerText(array $q): string
    {
        if ($q['type'] === 'mcq') {
            return ((int) $q['answer'] + 1) . '번 ' . ($q['choices'][(int) $q['answer']] ?? '');
        }
        return str_replace('|', ' 또는 ', $q['answer']);
    }
}
