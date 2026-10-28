<?php
// 테스트용 가짜 외부 서비스: YES24 · 나이스 · Claude Messages API.
// 실제 서비스의 응답을 흉내 낸 것이라, 이 테스트가 통과해도 실제 서비스와 맞는지는 키를 넣고 따로 확인해야 한다.
//   EXT_LOG=/tmp/ext.log php -S 127.0.0.1:8098 backend/tests/fake_external_server.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$q = $_GET;
header('Content-Type: application/json; charset=utf-8');
$log = static function (array $e): void {
    file_put_contents(getenv('EXT_LOG') ?: '/tmp/ext.log', json_encode($e, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
};

if (str_ends_with($path, '/v1/goods/itemList')) {
    $log(['path' => $path, 'query' => $q, 'key' => $_SERVER['HTTP_X_API_KEY'] ?? '']);
    if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'test-yes24') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '유효하지 않은 API Key', 'errorCode' => 'AUTH_002', 'data' => null]);
        return;
    }
    echo json_encode(['success' => true, 'message' => '성공', 'errorCode' => null, 'data' => ['items' => [
        ['title' => '테스트 수학 개념서', 'author' => '홍길동', 'publisher' => '테스트출판', 'publishDate' => '2025-01-02', 'isbn13' => '9781234567890', 'cover' => 'https://img.example/c.jpg'],
        ['author' => '제목 없는 항목'],
    ], 'currentPage' => 1, 'pageSize' => 10, 'totalCount' => 2]]);
} elseif (str_ends_with($path, '/v1/goods/content')) {
    $log(['path' => $path, 'query' => $q, 'key' => $_SERVER['HTTP_X_API_KEY'] ?? '']);
    if ($q['query'] !== '9781234567890') {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '목차 없음', 'errorCode' => 'GOODS_001', 'data' => null]);
        return;
    }
    echo json_encode(['success' => true, 'message' => '성공', 'errorCode' => null, 'data' => ['data' => ['itemId' => 1, 'contents' =>
        "Ⅰ. 다항식<br/>1-1 다항식의 연산 ........ 8<br/>1-2 나머지정리 ........ 20<br/>Ⅱ. 방정식<br/>2-1 복소수 ........ 40<br/>"]]]);
} elseif (str_ends_with($path, '/schoolInfo')) {
    $log(['path' => $path, 'query' => $q]);
    echo json_encode(['schoolInfo' => [
        ['head' => [['list_total_count' => 1], ['RESULT' => ['CODE' => 'INFO-000', 'MESSAGE' => '정상 처리되었습니다.']]]],
        ['row' => [['ATPT_OFCDC_SC_CODE' => 'J10', 'ATPT_OFCDC_SC_NM' => '경기도교육청', 'SD_SCHUL_CODE' => '7530000', 'SCHUL_NM' => '테스트고등학교', 'SCHUL_KND_SC_NM' => '고등학교', 'ORG_RDNMA' => '경기도 어딘가']]],
    ]]);
} elseif (str_ends_with($path, '/hisTimetable')) {
    $log(['path' => $path, 'query' => $q]);
    $mon = new DateTimeImmutable($q['TI_FROM_YMD']);
    $rows = [];
    foreach ([[0, 1, '공통국어1'], [0, 2, '공통수학1'], [1, 1, '영어'], [1, 2, '체육'], [2, 1, '자율활동']] as [$d, $p, $n]) {
        $rows[] = ['ALL_TI_YMD' => $mon->modify("+$d days")->format('Ymd'), 'PERIO' => (string) $p, 'ITRT_CNTNT' => $n, 'GRADE' => $q['GRADE'], 'CLASS_NM' => $q['CLASS_NM']];
    }
    if (($q['CLASS_NM'] ?? '') === '40') {
        echo json_encode(['RESULT' => ['CODE' => 'INFO-200', 'MESSAGE' => '해당하는 데이터가 없습니다.']]);
        return;
    }
    echo json_encode(['hisTimetable' => [['head' => [['list_total_count' => count($rows)], ['RESULT' => ['CODE' => 'INFO-000']]]], ['row' => $rows]]]);
} elseif (str_ends_with($path, '/v1/messages')) {
    $body = json_decode(file_get_contents('php://input'), true);
    $log(['path' => $path, 'key' => $_SERVER['HTTP_X_API_KEY'] ?? '', 'version' => $_SERVER['HTTP_ANTHROPIC_VERSION'] ?? '', 'body' => $body]);
    if (($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'test-claude') {
        http_response_code(401);
        echo json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]);
        return;
    }
    if (str_contains($body['messages'][0]['content'] ?? '', 'FAILME')) {
        http_response_code(529);
        echo json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]);
        return;
    }
    $questions = ['questions' => [
        ['type' => 'mcq', 'prompt' => '나머지정리란?', 'choices' => ['가', '나', '다', '라'], 'answer' => '2', 'explanation' => '해설'],
        ['type' => 'ox', 'prompt' => 'x=1은 방정식의 해다', 'choices' => [], 'answer' => 'O', 'explanation' => ''],
        ['type' => 'mcq', 'prompt' => '정답 번호가 범위 밖인 잘못된 문제', 'choices' => ['가', '나'], 'answer' => '7', 'explanation' => ''],
        ['type' => 'essay', 'prompt' => '허용하지 않은 형식', 'choices' => [], 'answer' => '답', 'explanation' => ''],
    ]];
    echo json_encode(['id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'], 'stop_reason' => 'end_turn', 'content' => [
        ['type' => 'thinking', 'thinking' => '', 'signature' => 'x'],
        ['type' => 'text', 'text' => json_encode($questions, JSON_UNESCAPED_UNICODE)],
    ], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]]);
} else {
    http_response_code(404);
    echo '{}';
}
