# 고1 스터디 플래너 — 프로그램 구조 설계

> 대상: 고등학교 1학년 학생 / 배포: Cafe24 웹호스팅 `/sp` 폴더
> 스택: Svelte(SvelteKit, 정적 빌드) + PHP 8.4(API 전용) + MariaDB

---

## 0. 기술 스택 결정 (확정)

**결정: 화면 = Svelte(SvelteKit, 정적 빌드) / 서버 = PHP 8.4 API 전용 / DB = MariaDB**

### 왜 PHP 단독이 아닌 Svelte + PHP API인가
이 앱은 화면에서 즉시 반응해야 하는 기능이 많다.
- 퀴즈 풀이(문항 이동, 즉시 채점, 오답 표시)
- 공부 타이머·뽀모도로(화면을 옮겨도 계속 동작)
- 시험 대비 계획 드래그 조정, 수행평가 칸반 이동, 할 일 체크
- PWA(홈 화면 추가) + Web Push → SPA 구조와 잘 맞음
- 휴대폰 위주 사용 → 페이지 새로고침 없는 앱 같은 사용감

PHP 단독으로도 가능하지만 결국 JS 코드가 많이 섞여 유지보수가 어려워진다.
(대안으로 검토: PHP + Alpine.js/htmx — 빌드가 없어 배포는 쉽지만 기능 확장성에서 불리)

| 항목 | Svelte + PHP API | PHP (+ Alpine/htmx) |
|---|---|---|
| 앱 같은 사용감 / PWA | ◎ | △ |
| 퀴즈·타이머·드래그 | ◎ | ○ |
| 배포 편의 (Cafe24) | ○ (빌드 후 업로드) | ◎ (파일만 업로드) |
| 초기 개발 속도 | ○ | ◎ |
| 기능 확장 시 유지보수 | ◎ | △ |

### 왜 React·Vue가 아닌 Svelte인가
| 항목 | Svelte | React | Vue |
|---|---|---|---|
| 배우기 쉬움 | ◎ HTML/CSS/JS에 가까움 | △ JSX·Hook 규칙 | ○ |
| 코드 양 | ◎ 가장 적음 | △ | ○ |
| 번들 크기·속도 | ◎ 가장 가벼움(모바일 유리) | △ | ○ |
| 생태계·자료 | ○ | ◎ | ○ (한국어 자료 많음) |
| 정적 빌드 → Cafe24 | ◎ | ◎ | ◎ |

- 1인 개발, 빠른 완성, 모바일 성능이 우선이므로 Svelte 선택.
- React는 팀 개발이나 React Native 앱 확장 계획이 있을 때 고려 대상.
- 화면 계층과 PHP API가 분리되어 있으므로, 나중에 프론트엔드를 바꿔도 **API·DB·알림 구조는 그대로 재사용**된다.
- 사용 예정 라이브러리: `svelte-dnd-action`(드래그), Chart.js(통계), 경량 캘린더 컴포넌트.

---

## 1. 전체 구조

```
[브라우저 / PWA]
  Svelte SPA (정적 파일: HTML/JS/CSS)
  Service Worker (오프라인 캐시, 푸시 알림 수신)
        │  fetch (JSON, 세션 쿠키)
        ▼
[Cafe24 Apache]  https://도메인/sp/
  /sp/            → Svelte 빌드 결과물
  /sp/api/*.php   → PHP 8.4 REST API (DB 읽기/쓰기만 담당)
        │  PDO (prepared statement)
        ▼
[MariaDB]  study_planner DB

[외부 크론 서비스] ──(5~10분마다 호출, 비밀 토큰)──▶ /sp/api/cron/notify.php
  (Cafe24 일반 호스팅은 상시 프로세스/크론이 제한적이므로 외부 크론으로 알림 발송 트리거)
```

**역할 분리 원칙**
- 화면, 라우팅, 상태관리, 계산(D-day, 일정 배분 미리보기, 퀴즈 채점 UI)은 **Svelte**.
- 인증, 데이터 저장/조회, 권한 검사, 알림 발송, 파일 업로드 저장은 **PHP**.
- Cafe24에는 Node.js 서버가 없으므로 SvelteKit은 `adapter-static`(SPA 모드, `fallback: index.html`)로 빌드해서 업로드한다.
- 같은 도메인(`/sp`)에서 페이지와 API를 함께 서비스 → CORS 불필요, 세션 쿠키 인증 사용.

---

## 2. 서버 디렉터리 구조 (Cafe24)

```
/www/sp/                      ← 웹 공개 영역
├─ index.html                 ← Svelte SPA 진입점
├─ _app/ ...                  ← 빌드된 JS/CSS
├─ manifest.webmanifest       ← PWA (홈 화면 추가)
├─ sw.js                      ← Service Worker
├─ .htaccess                  ← SPA fallback (없는 경로 → index.html), /api 제외
└─ api/
   ├─ .htaccess               ← 라우팅을 index.php로 집중
   ├─ index.php               ← 프론트 컨트롤러(라우터)
   ├─ cron/notify.php         ← 알림 발송(토큰 검사)
   └─ (src는 아래 비공개 영역에서 require)

/sp_private/                  ← 웹에서 접근 불가 영역 (www 바깥)
├─ config.php                 ← DB 접속정보, 비밀키, VAPID 키
├─ src/                       ← PHP 클래스 (Controller / Repository / Service)
├─ vendor/                    ← composer 라이브러리 (web-push 등)
└─ uploads/                   ← 업로드 자료 (다운로드는 PHP를 통해서만)
```

> www 바깥 폴더를 쓸 수 없는 상품이면 `/www/sp/api/_private/`에 두고 `.htaccess`로 `Require all denied` 처리.

---

## 3. 소스 저장소 구조 (이 Git 저장소)

```
study-planner/
├─ frontend/                  ← SvelteKit 프로젝트
│  ├─ src/
│  │  ├─ routes/              ← 화면 (아래 6장)
│  │  ├─ lib/
│  │  │  ├─ api/              ← fetch 래퍼 (auth, subjects, exams, ...)
│  │  │  ├─ stores/           ← 로그인 사용자, 오늘 할 일, 알림
│  │  │  ├─ components/       ← 공통 UI (캘린더, D-day 카드, 체크리스트, 퀴즈 카드)
│  │  │  └─ utils/            ← 날짜/D-day, 복습 주기 계산
│  │  └─ service-worker.js
│  └─ svelte.config.js        ← adapter-static, paths.base = '/sp'
├─ backend/
│  ├─ public/                 ← → /www/sp/api 로 업로드
│  ├─ src/                    ← → /sp_private/src 로 업로드
│  └─ composer.json
├─ db/
│  └─ migrations/             ← 001_init.sql, 002_..., (순서대로 실행)
├─ docs/
└─ scripts/deploy.sh          ← 빌드 + (S)FTP 업로드
```

---

## 4. 기능 구성 (모듈)

### 4.1 회원 / 인증
- 회원가입(아이디·이메일·비밀번호·이름·학교/학년 선택), 로그인, 로그아웃, 비밀번호 변경.
- `password_hash()`(Argon2id 또는 bcrypt), PHP 세션 쿠키(`HttpOnly`, `Secure`, `SameSite=Lax`).
- CSRF 토큰(로그인 시 발급, 변경 요청 헤더 `X-CSRF-Token`로 검사).
- 로그인 실패 횟수 제한(5회 실패 → 10분 잠금).
- 모든 API는 `user_id`로 소유권 검사 → 다른 사람 데이터 접근 불가.
- 로그인하지 않은 사용자는 `/login`, `/signup` 외 화면 진입 시 로그인으로 이동.

### 4.2 과목 · 교과서 · 자료 관리 (공통 기반)
- **과목**: 기본 과목 템플릿 제공(2022 개정 고1: 공통국어, 공통수학, 공통영어, 통합사회, 통합과학, 한국사, 과학탐구실험, 정보, 기타) + 직접 추가/수정, 색상 지정.
- **교과서/교재**: 과목별 여러 권(교과서, 자습서, 문제집, 학원 교재). 출판사, 총 페이지.
- **단원(목차)**: 교재별 대단원 > 중단원 트리, 페이지 범위. → 시험 범위·복습·퀴즈가 모두 "단원"을 기준으로 연결됨.
- **학습 자료**: 단원에 메모(마크다운), 링크, 파일(PDF/이미지, 용량 제한) 첨부.

### 4.3 모드 ① 시험 대비 모드 (중간·기말·모의고사)
- 시험 등록: 종류(중간/기말/모의), 이름(예: 1학기 중간고사), 시작~종료일.
- 과목별 시험일·교시, 시험 범위(단원 + 페이지 선택), 목표 점수/등급.
- **D-day 대시보드**: 가장 가까운 시험 카운트다운, 과목별 진도율.
- **대비 계획 자동 생성**: 시험일 역산으로 "범위 공부(1회독) → 문제풀이 → 오답·총정리" 단계를 날짜에 배분 → 일일 할 일(`tasks`)로 생성. 학생이 드래그로 조정.
- 모의고사: 학교 범위가 아닌 전 범위 과목(국·수·영·한국사·통합사회·통합과학) 기준, 시험 후 점수/등급/틀린 문항 기록 → 성적 추이 그래프.
- 시험 종료 후 결과 입력(점수, 소감) → 다음 시험 계획에 참고.

### 4.4 모드 ② 수행평가 관리 모드
- 과목별 수행평가: 제목, 유형(보고서/발표/실험/서술형/포트폴리오/실기 등), 배점·반영비율, 마감일, 평가 기준(루브릭 메모).
- 진행 단계 체크리스트(예: 주제 정하기 → 자료조사 → 초안 → 수정 → 제출), 단계별 목표일.
- 상태: 예정 / 진행중 / 제출완료 / 채점완료(점수 기록).
- 칸반 보기(상태별) + 마감 임박 목록, 시험 기간과 겹치면 경고 표시.

### 4.5 모드 ③ 일반 스터디 모드 (당일 복습 포함)
- **오늘 할 일**: 시험/수행평가에서 생성된 할 일 + 직접 추가한 할 일을 한 화면에.
- **시간표 기반 당일 복습**: 요일별 학교 시간표 등록 → 그날 수업한 과목이 자동으로 "오늘 복습" 목록에 들어감(어느 단원을 배웠는지 한 줄 기록).
- **망각곡선 복습**: 복습 완료 시 1일 → 3일 → 7일 → 14일 → 30일 후 재복습 자동 예약.
- 공부 기록: 과목별 타이머(뽀모도로 옵션), 일/주간 공부시간 통계.
- 주간 계획 / 하루 회고(잘한 점·아쉬운 점, 자기 평가).

> 3개 모드는 **같은 할 일(tasks) 테이블**을 공유하고 `source`(exam / assessment / study / review)로 구분 → 홈 화면에서 통합 표시, 모드별 화면에서 필터링.

### 4.6 알림
| 단계 | 방식 | 설명 |
|---|---|---|
| 1차 | 앱 내 알림 | 접속 시 PHP가 오늘/임박 항목을 계산해 알림함에 표시(종 아이콘 배지) |
| 1차 | 브라우저 알림 | 앱이 열려 있을 때 Notification API로 시간 맞춰 팝업 |
| 2차 | Web Push (PWA) | 앱을 닫아도 알림. 외부 크론 → `notify.php` → 발송 대상 조회 → VAPID 푸시 전송 (`minishlink/web-push`). iPhone은 "홈 화면에 추가" 후 지원 |
| 선택 | 이메일 | 하루 요약(아침 7시) 메일 |

- 알림 종류: 오늘 할 일 요약(아침), 복습 예정, 시험 D-7/D-3/D-1, 수행평가 마감 D-3/D-1/당일, 미완료 할 일(저녁).
- 사용자 설정: 알림 종류별 on/off, 받는 시간대, 방해금지 시간(예: 23:00~07:00).
- 중복 발송 방지: `notifications` 테이블에 `scheduled_at`, `sent_at` 기록.

### 4.7 퀴즈 · 자가평가
- 단원별 문제 직접 만들기: 객관식(4/5지선다), OX, 단답형, 서술형(자기채점), 암기카드(앞/뒤).
- 퀴즈 실행: 과목/단원/시험범위/오답만/랜덤 N문항 선택 → 풀이 → 자동 채점(서술형은 모범답안 보고 자기채점).
- **오답노트**: 틀린 문제 자동 수집, 다시 맞힐 때까지 복습 일정에 포함.
- 단원별 정답률 → 취약 단원 표시 → 시험 대비 계획에서 우선순위 반영.
- (확장) 입력한 학습 자료 메모로 AI 문제 자동 생성: PHP에서 Claude API 호출(키는 서버 config에만 저장), 생성 문제는 학생이 검토 후 저장.

---

### 4.8 교재 · 학교 데이터 연동

> 조사 결과 요약. 일부는 공식 명세를 직접 확인하지 못한 항목이라 **개발 착수 전 확인 필요**로 표시했다.

**연동 대상**

| 목적 | 데이터 | 방식 | 상태 |
|---|---|---|---|
| 학교 검색 | 나이스 교육정보 개방 포털(open.neis.go.kr) Open API `schoolInfo` (학교명, 시도교육청코드, 표준학교코드) | 서버(PHP)에서 호출, 무료 인증키 | 공식 제공 |
| 학급 시간표 | 나이스 Open API 고등학교 시간표 (학교코드 + 학년 + 반) | 서버에서 호출 → `timetable` 자동 채움 | 확인 필요: 고교학점제로 선택과목은 학생마다 시간표가 달라 학급 시간표와 어긋날 수 있음 → 자동 채움 후 학생이 수정 |
| 학교별 사용 교과서 | 공개된 통합 데이터 없음 (학교알리미·나이스에서 확인 못 함) | 앱에 2022 개정 고1 공통과목 교과서 목록(과목·출판사·교과서명)을 시드로 넣고 학생이 선택 | 직접 구축 |
| 문제집 검색·목차 | YES24 Open API 상품 목차 조회(`/v1/goods/content`) | 서버에서 ISBN/제목 검색 → 목차를 대단원·중단원으로 변환 | 확인 필요: 목차 옵션명, 호출 제한, 출처 표기 조건 |
| 서지정보 보조 | 국립중앙도서관 ISBN 서지정보 API (제목·저자·출판사·ISBN) | YES24에 없는 책의 기본 정보 보충 | 확인 필요: 목차 항목 제공 여부 |

**교재 등록 흐름**
1. 학교 등록: 학교명 검색 → 학교 선택 → 학년·반 입력 → (선택) 시간표 가져오기.
2. 교과서: 과목 선택 → 시드 목록에서 교과서 선택 → 단원은 직접 입력하거나 출판사 목차를 보고 입력.
3. 문제집: 제목/ISBN 검색 → 결과 선택 → 목차를 단원 트리로 변환 → **미리보기에서 학생이 수정** 후 저장. 목차가 없으면 수동 입력.
4. 한 번 가져온 책은 `book_catalog`에 캐시해 외부 호출을 줄이고, 인증키는 서버 설정 파일에만 둔다.

**추가 DB 항목**
- `users`: `school_code`, `office_code`(시도교육청), `grade`, `class_no` 컬럼 추가.
- `book_catalog`: id, isbn(UNIQUE), title, publisher, author, toc_raw(TEXT), source(yes24/nl), fetched_at.
- `textbooks`: `catalog_id`(NULL 허용) 컬럼 추가 → 카탈로그 책과 연결.
- `materials`: `type`에 `scan`(사진/스캔) 추가, `is_private` 항상 1.

**수집하지 않는 것**
- 교과서·문제집의 본문, 문제, 해설 전체는 외부에서 가져와 저장하지 않는다. 목차(단원 이름·쪽수)까지만 사용한다.
- 나이스·학교 사이트의 로그인 화면이나 화면 긁어오기(스크래핑)는 쓰지 않는다. 공식 Open API만 쓴다.

---

## 5. 데이터베이스 설계 (MariaDB, utf8mb4)

```
users ─┬─ subjects ─┬─ textbooks ── units(트리) ──┬─ materials
       │            │                            ├─ questions ── quiz_answers
       │            │                            └─ review_items
       ├─ exams ── exam_subjects ── exam_scopes(→units)
       ├─ assessments ── assessment_steps
       ├─ timetable
       ├─ tasks  (source: exam / assessment / study / review)
       ├─ study_sessions
       ├─ quiz_attempts ── quiz_answers
       ├─ notifications, notification_settings, push_subscriptions
       └─ daily_journals
```

| 테이블 | 주요 컬럼 |
|---|---|
| `users` | id, login_id(UNIQUE), email(UNIQUE), password_hash, name, school, grade, created_at, last_login_at |
| `login_attempts` | id, login_id, ip, success, created_at |
| `subjects` | id, user_id, name, color, sort_order, is_active |
| `textbooks` | id, user_id, subject_id, title, kind(textbook/workbook/academy/etc), publisher, total_pages |
| `units` | id, user_id, textbook_id, parent_id(NULL=대단원), title, page_start, page_end, sort_order |
| `materials` | id, user_id, unit_id, type(note/link/file), title, content(TEXT), url, file_path, file_size, created_at |
| `exams` | id, user_id, type(midterm/final/mock), title, start_date, end_date, memo |
| `exam_subjects` | id, exam_id, subject_id, exam_date, period, target_score, actual_score, grade_result |
| `exam_scopes` | id, exam_subject_id, unit_id, page_start, page_end, progress(0~100) |
| `assessments` | id, user_id, subject_id, title, kind, weight_pct, due_at, rubric(TEXT), status, score |
| `assessment_steps` | id, assessment_id, title, target_date, done_at, sort_order |
| `timetable` | id, user_id, weekday(1~5), period(1~7), subject_id |
| `tasks` | id, user_id, subject_id, source, ref_id, title, task_date, start_time, est_minutes, done_at, sort_order |
| `review_items` | id, user_id, unit_id, question_id(NULL), stage(0~5), next_review_date, last_reviewed_at |
| `study_sessions` | id, user_id, subject_id, task_id, started_at, ended_at, minutes |
| `daily_journals` | id, user_id, date(UNIQUE w/ user), good, bad, self_rating |
| `questions` | id, user_id, unit_id, type(mcq/ox/short/essay/card), prompt, choices(JSON), answer, explanation, source(manual/ai) |
| `quiz_attempts` | id, user_id, title, filter(JSON), started_at, finished_at, score, total |
| `quiz_answers` | id, attempt_id, question_id, user_answer, is_correct, answered_at |
| `notification_settings` | user_id(PK), morning_time, evening_time, dnd_start, dnd_end, types(JSON) |
| `push_subscriptions` | id, user_id, endpoint, p256dh, auth, user_agent, created_at |
| `notifications` | id, user_id, kind, title, body, link, scheduled_at, sent_at, read_at |

- 모든 테이블 `user_id` 인덱스 + 외래키 `ON DELETE CASCADE`.
- 날짜는 `DATE`/`DATETIME`, 타임존은 `Asia/Seoul` 고정(PHP `date_default_timezone_set`, DB 세션 `time_zone='+09:00'`).

---

## 6. 화면(라우트) 구성 — Svelte

```
/sp/login, /sp/signup                     비로그인 전용
/sp/                    홈 대시보드: 오늘 할 일, 오늘 복습, 가까운 D-day, 마감 임박 수행평가
/sp/study               일반 스터디 모드: 오늘/주간 계획, 타이머, 하루 회고
/sp/study/review        당일 복습 + 망각곡선 복습 목록
/sp/exams               시험 목록 (중간/기말/모의 탭)
/sp/exams/[id]          시험 상세: 과목별 범위·진도, 대비 계획 캘린더, 결과 입력
/sp/assessments         수행평가 (칸반/목록)
/sp/assessments/[id]    단계 체크리스트, 루브릭, 점수
/sp/subjects            과목 관리
/sp/subjects/[id]       교재·단원 트리·자료
/sp/quiz                문제 은행 / 퀴즈 시작
/sp/quiz/play           퀴즈 풀이
/sp/quiz/wrong          오답노트
/sp/calendar            월간 캘린더 (시험·수행평가·할 일 통합)
/sp/stats               공부시간·정답률·성적 추이
/sp/settings            프로필, 시간표, 알림 설정
```

- 하단 탭(모바일 우선): 홈 / 스터디 / 시험 / 수행평가 / 퀴즈.
- 상단: 알림 종, 모드 전환 칩.

---

## 6-1. 화면 시안 (와이어프레임)

화면 구성(6장)을 시각화한 시안. 실제 구현 코드가 아니라 레이아웃·흐름 확인용이며, 화면 안의 과목명·날짜·문제는 예시 데이터다.

| 구분 | 크기 | 링크 |
|---|---|---|
| 모바일 (하단 탭 내비게이션) | 390×844 | https://claude.ai/artifact/QUkrhSJDozEiZCS93LAuaP |
| PC (왼쪽 사이드바 내비게이션) | 1440×900 | https://claude.ai/artifact/9F4MnA7CPgJZ83aAfoCpUq |

두 시안 모두 같은 8개 화면으로 구성된다.

1. 로그인 / 회원가입
2. 홈 대시보드
3. 일반 스터디 모드
4. 시험 대비 모드
5. 수행평가 모드
6. 과목 · 교재 · 단원
7. 퀴즈 풀이
8. 알림 · 설정

> 링크는 소유자 계정에서만 열린다(비공개). 다른 사람과 공유하려면 해당 페이지의 공유 메뉴에서 접근 권한을 줘야 한다.
> 아직 없는 화면: 시험 상세, 퀴즈 결과·오답노트, 통계, 캘린더.

---

## 7. API 설계 (PHP, JSON)

공통: `/sp/api/...`, 응답 `{ ok: true, data }` / `{ ok: false, error: { code, message } }`

| 영역 | 엔드포인트 |
|---|---|
| 인증 | `POST /auth/signup`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`, `PUT /auth/password` |
| 과목/교재 | `GET·POST /subjects`, `PUT·DELETE /subjects/{id}`, `GET·POST /textbooks`, `GET·POST /units`, `PUT /units/{id}`, `PUT /units/reorder` |
| 자료 | `GET·POST /materials`, `DELETE /materials/{id}`, `POST /materials/upload`, `GET /materials/{id}/file` |
| 시험 | `GET·POST /exams`, `GET·PUT·DELETE /exams/{id}`, `PUT /exams/{id}/subjects`, `POST /exams/{id}/plan` (계획 생성), `PUT /exam-scopes/{id}/progress` |
| 수행평가 | `GET·POST /assessments`, `PUT·DELETE /assessments/{id}`, `POST·PUT /assessments/{id}/steps` |
| 할 일 | `GET /tasks?from=&to=&source=`, `POST /tasks`, `PUT /tasks/{id}`, `POST /tasks/{id}/toggle` |
| 복습 | `GET /reviews/today`, `POST /reviews/{id}/done` (다음 단계 예약) |
| 공부기록 | `POST /sessions/start`, `POST /sessions/{id}/stop`, `GET /stats?range=week` |
| 시간표/회고 | `GET·PUT /timetable`, `GET·PUT /journals/{date}` |
| 퀴즈 | `GET·POST /questions`, `PUT·DELETE /questions/{id}`, `POST /quiz/start`, `POST /quiz/{id}/answer`, `POST /quiz/{id}/finish`, `GET /quiz/wrong` |
| 알림 | `GET /notifications`, `POST /notifications/{id}/read`, `GET·PUT /notification-settings`, `POST /push/subscribe`, `DELETE /push/subscribe` |
| 크론 | `GET /cron/notify?token=...` (외부 크론 전용) |

**PHP 내부 계층**
```
index.php (라우터) → Middleware(세션·CSRF·JSON) → Controller → Service(비즈니스 로직) → Repository(PDO) → MariaDB
```
- Service 예: `ExamPlanService`(역산 일정 배분), `ReviewScheduler`(망각곡선), `NotificationService`(대상 산출·발송), `QuizService`(채점·오답 반영).
- 프레임워크 없이 가벼운 자체 라우터로 시작(Cafe24 업로드가 간단). composer는 web-push 등 필요한 것만.

---

## 8. 핵심 흐름

**시험 대비 계획 생성**
1. 시험 등록 → 과목별 시험일·범위(단원) 입력
2. `POST /exams/{id}/plan` → 오늘~시험일 사이 가용일 계산(시간표·수행평가 마감 고려)
3. 범위 페이지 수 / 취약 단원 가중치로 분량 배분 → 1회독 60% · 문제풀이 25% · 총정리 15%
4. `tasks`(source=exam) 일괄 생성 → 학생이 미리보기에서 조정 후 확정

**당일 복습**
1. 오늘 요일 시간표 → 수업 과목 목록 → "오늘 배운 단원" 체크
2. 복습 완료 → `review_items` 생성(stage 1, 다음날 예약)
3. 이후 복습할 때마다 stage↑, 다음 날짜 1→3→7→14→30일 / "어려웠음" 선택 시 stage 유지

**퀴즈 → 복습 연결**
1. 퀴즈에서 틀린 문제 → 오답노트 + `review_items`(question_id) 등록
2. 단원 정답률 낮음 → 시험 계획 배분 시 가중치↑

**알림 발송**
1. 외부 크론이 10분마다 `notify.php` 호출(토큰 검증)
2. 지금 보낼 알림(아침 요약, D-day, 마감, 복습) 산출 → 방해금지·설정 확인
3. `notifications`에 기록 → 푸시 구독이 있으면 Web Push 전송 → `sent_at` 갱신

---

## 9. 보안 · 운영
- PDO prepared statement만 사용(SQL 인젝션 방지), 출력은 Svelte 기본 이스케이프(마크다운은 sanitize).
- HTTPS 필수(Cafe24 SSL 적용) — Web Push·Service Worker는 HTTPS에서만 동작.
- 업로드: 확장자/MIME 화이트리스트(pdf, jpg, png), 파일당 10MB, 비공개 폴더 저장, 랜덤 파일명.
- 설정 파일·비밀키는 Git에 올리지 않음(`config.example.php`만 커밋).
- 백업: Cafe24 DB 백업 + 주 1회 mysqldump 내보내기.
- 배포: `npm run build` → `frontend/build/*`를 `/www/sp/`로, `backend/public`→`/www/sp/api`, `backend/src`·`vendor`→`/sp_private` (SFTP, 추후 GitHub Actions 자동화 가능).

---

## 9-1. 법률 · 개인정보 유의사항

> 법률 자문이 아니라 설계상 주의점 정리이며, 서비스를 다른 사람에게 공개하기 전에 한국저작권위원회 상담이나 변호사 검토를 받는다. 나이스 Open API 이용약관은 아직 직접 확인하지 못했다.

**1) 나이스 시간표 연동**
- 공식 Open API(인증키 발급, 이용약관 동의)를 쓰는 것은 제공 취지에 맞는 이용이다. 학급 단위의 과목·교시 정보라 개인정보 성격은 약하다. 약관의 출처 표시, 호출 제한, 재배포 조건을 확인해 지킨다.
- 나이스 로그인 화면 자동화나 화면 긁어오기는 약관 위반과 무단 접근 문제가 될 수 있어 쓰지 않는다.
- 시간표 호출은 서버에서 하고 결과를 캐시한다(과도한 호출 방지).
- 학교·학년·반은 개인정보이므로 최소한만 수집한다. 만 14세 이상은 본인 동의로 가입할 수 있다(고1은 해당). 가입 시 개인정보처리방침과 수집 항목을 고지한다.

**2) 학생이 시험지·자료를 촬영/스캔해 개인용으로 보관**
- 저작권법 제30조(사적이용을 위한 복제)는 개인적으로 또는 가정 등 한정된 범위에서 이용할 목적의 복제를 허용한다. 학생 본인이 공부하려고 찍어 두는 것은 대체로 이 범위로 본다.
- 앱이 지켜야 할 선: ① 저장 자료는 **본인만** 볼 수 있다(비공개 고정). ② 다른 사용자와 공유·검색·추천 기능을 만들지 않는다. ③ 관리자 화면에서도 내용을 열람하지 않는다. ④ 공유가 가능해지면 복제·전송 문제가 생기므로 기능 추가 전 재검토한다.
- 시험 중 촬영·반출은 학교 규정상 부정행위가 될 수 있다. 앱 안내 문구에 "시험이 끝나고 학교가 배부한 자료만 올려요"를 넣는다.
- 시험지에는 교사·학교의 저작권이 있고 학교가 반출을 제한할 수 있다. 이용 책임은 사용자에게 있음을 약관에 적고, 권리자 신고 시 삭제하는 절차를 둔다.
- 사진에 다른 학생 이름·성적 등 개인정보가 찍힐 수 있다. 업로드 전 안내하고, 파일은 비공개 폴더에 랜덤 파일명으로 저장하며 회원 탈퇴 시 함께 삭제한다.
- 외부 AI(문제 자동 생성, OCR)에 자료를 보내는 기능을 넣으면 개인정보처리방침에 위탁·국외 전송 사실을 알리고 동의를 받는다.

---

## 10. 개발 단계 (로드맵)

| 단계 | 내용 |
|---|---|
| 1단계 (기반) | DB 스키마, PHP 라우터·인증, Svelte 로그인/회원가입, 과목·교재·단원·자료 관리 |
| 2단계 (3개 모드) | 할 일 공통 모듈 → 일반 스터디(오늘 할 일·시간표·당일 복습) → 시험 대비(D-day·범위·계획 생성) → 수행평가 |
| 3단계 (알림) | 앱 내 알림함 + 브라우저 알림 → PWA + Web Push + 외부 크론 |
| 4단계 (평가) | 문제 은행, 퀴즈 풀이·채점, 오답노트, 복습 연동, 통계 |
| 5단계 (확장) | AI 문제 생성, 모의고사 성적 분석, 학부모 열람(읽기 전용 계정) 등 |

---

## 11. 결정이 필요한 사항
1. 사용자 범위: 본인(한 명)만 쓰는지, 친구/반 친구도 가입해서 쓰는지 → 회원가입 승인제/초대코드 여부.
2. Cafe24 상품에서 www 바깥 폴더 사용 가능 여부, composer 사용 가능 여부(불가 시 web-push 라이브러리를 vendor째 업로드).
3. 알림 채널: Web Push만으로 충분한지, 이메일/카카오 알림톡도 필요한지(알림톡은 유료).
4. AI 문제 생성 사용 여부(API 비용 발생).

## 12. 구현하면서 설계와 달라진 점 (3~5단계, 2026-10)
- **관리자**: 별도 역할 컬럼 없이 `config.php`의 `admin_login_id`와 같은 아이디가 관리자다(한 명). 회원 상태(`active/pending/blocked`), `must_change_password`, `session_version`(차단·초기화 시 기존 세션 무효), `sp_settings`(가입 방식, AI 스위치), `sp_admin_log`.
- **알림**: `sp_notifications`(+`dedupe_key` 유일), `sp_notification_settings`, `sp_push_subscriptions`. 알림 계산은 순수 함수 `NotificationPlanner`, 저장·푸시는 `NotificationService`. 생성 시점은 (1) 앱이 알림함을 불러올 때 (2) `/cron/notify?token=`. 푸시는 새로 만든 알림에만 한 번 보낸다.
- **퀴즈**: `sp_questions`, `sp_quiz_attempts`, `sp_quiz_answers`, `sp_review_items.question_id`. 설계의 "문제 은행"에 해당하며, 오답노트는 별도 테이블 없이 "문제별 가장 최근 답이 오답"으로 계산한다.
- **외부 연동**: `sp_book_catalog` 캐시는 만들지 않았다(검색 결과를 저장하지 않고, 학생이 고른 목차만 단원으로 저장). AI는 `sp_ai_usage`(하루 호출 수)와 `sp_users.ai_consent_at`(동의)로 제한한다.
- 외부 서비스 명세 중 확인하지 못한 부분은 `docs/PROGRESS.md`의 "실제 서비스로 확인하지 못한 것"에 적어 두었다.
