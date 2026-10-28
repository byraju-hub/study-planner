# 스터디 플래너 (study-planner)

고등학교 1학년 학생을 위한 스터디 플래너 웹앱입니다. 매일 할 일과 복습, 시험 대비, 수행평가, 퀴즈를 한곳에서 관리하고, 서점(YES24)과 나이스에서 교재 정보·시간표를 가져와 입력을 줄여 줍니다.

- 서비스 주소(예): `https://도메인/sp/` — Cafe24 웹호스팅의 `/sp` 폴더
- 화면 문구는 모두 한국어이고, 휴대폰과 PC 모두에서 쓸 수 있습니다.
- 앱 안에서는 왼쪽 메뉴 맨 아래 **? 사용 매뉴얼**에서 쓰는 방법을 볼 수 있습니다.

## 주요 기능

| 영역 | 내용 |
|---|---|
| 과목·교재·단원 | 과목, 교재(교과서·참고서·문제집·학원 교재), 단원(목차), 학습 자료(메모·링크·PDF/사진) 관리 |
| 서점 검색 (YES24) | 교재 추가 시 책을 검색해 이름·출판사·쪽수를 채우고, 목차를 단원 후보로 가져와 확인 후 저장 |
| 일반 스터디 | 오늘 할 일, 공부 타이머와 7일 그래프, 시간표, 수업 후 당일 복습, 복습 주기(1·3·7·14·30일), 하루 회고 |
| 시간표 (나이스) | 학교를 검색해 학년·반 시간표를 가져와 내 과목에 맞춰 채우기 |
| 시험 대비 | 중간·기말·모의고사, 과목별 시험일·목표, 시험 범위와 진도, D-day, 1회독→문제풀이→총정리 계획 자동 생성(미리보기 후 확정), 결과·성적 기록과 그래프 |
| 수행평가 | 종류별 진행 단계와 목표일 자동 생성, 단계 체크 ↔ 오늘 할 일 양방향 연동, 시험 기간 겹침 경고, 점수 기록 |
| 퀴즈 | 문제 은행(객관식·O/X·단답·카드), 서버 채점, 오답노트, 단원별 정답률과 약한 단원(시험 계획에 반영) |
| AI 문제 만들기 | 학생이 쓴 노트로 Claude가 문제 후보를 만들고, 학생이 골라 저장(동의·하루 한도·관리자 스위치) |
| 알림 | 앱 안 알림함, 아침·저녁 요약, 시험·수행평가 D-day, 웹 푸시(PWA) |
| 관리자 | 가입 방식(자유/승인/닫기), 회원 승인·차단·비밀번호 초기화·삭제, AI 사용·모델 선택, 작업 기록 (회원의 공부 내용은 볼 수 없음) |

## 기술 스택

- 화면: Svelte 5 + SvelteKit 3 (`adapter-static`, SPA, `paths.base = '/sp'`)
- 서버: PHP 8.4 (프레임워크 없는 JSON API), MariaDB, PDO prepared statement
- 외부 연동: YES24 Open API(도서 검색·목차), 나이스 교육정보 개방 포털(학교·시간표), Claude API(문제 후보), 웹 푸시(VAPID)
- 배포: Node 서버 없이 정적 파일 + PHP

## 폴더 구조

```
frontend/            SvelteKit 화면
backend/public/      API 진입점 (서버의 www/sp/api)
backend/src/         PHP 코드 (App\ 네임스페이스, Controllers / Services / Core)
backend/tests/       계획 계산·API 테스트와 가짜 외부 서버
db/migrations/       번호순 SQL (모든 테이블은 sp_ 접두어)
scripts/             마이그레이션, 개발 서버, 배포 묶음, 브라우저 테스트
docs/                설계(ARCHITECTURE), 진행 상황(PROGRESS), 배포(DEPLOY)
```

## 개발 환경 시작하기

필요한 것: PHP 8.3 이상(`pdo_mysql`, `curl`, `openssl`, `mbstring`), MariaDB, Node.js.

```bash
# 1. 설정 파일 만들기 (git에 올라가지 않음)
cp backend/config.example.php backend/config.php        # 개발 DB: sp_dev
cp backend/config.example.php backend/config.test.php   # 테스트 DB: sp_test
# DB 접속 정보 등을 채운 뒤 마이그레이션
php scripts/migrate.php
SP_CONFIG=backend/config.test.php php scripts/migrate.php

# 2. 화면 빌드
cd frontend && npm install && npm run build && cd ..

# 3. 개발 서버 (화면 + API): http://127.0.0.1:8080/sp/
php -d upload_max_filesize=12M -d post_max_size=14M -S 127.0.0.1:8080 scripts/dev-router.php
```

화면만 빠르게 고칠 때는 `cd frontend && npm run dev` (API는 8080으로 프록시).

### 테스트

```bash
php backend/tests/plan_test.php        # 계획 계산 (DB·서버 없이)

# API 테스트: 테스트 DB를 쓰는 서버를 8081에 띄운 뒤 실행
SP_CONFIG=$PWD/backend/config.test.php php -d upload_max_filesize=12M -d post_max_size=14M -S 127.0.0.1:8081 scripts/dev-router.php &
php backend/tests/api_test.php

node scripts/e2e.cjs                   # 브라우저 통합 테스트 (Playwright 필요)
```

외부 서비스(YES24·나이스·Claude·푸시) 테스트는 가짜 서버로 하므로, 실제 서비스와 맞는지는 키를 넣고 따로 확인해야 합니다.

## 설정 (`config.php`)

비밀값은 `config.php`에만 두고 **저장소에 올리지 않습니다** (`config.example.php`에 항목 예시가 있습니다).

| 항목 | 용도 | 없으면 |
|---|---|---|
| `db` | DB 접속 정보 | (필수) |
| `admin_login_id` | 관리자 아이디. 이 아이디로 가입·로그인하면 "관리" 메뉴 표시 | 관리자 없음 |
| `yes24.api_key` | YES24 Open API 키 ([developers.yes24.com](https://developers.yes24.com/)) | 서점 검색·목차 가져오기 꺼짐 |
| `neis.key` | 나이스 교육정보 개방 포털 인증키 | 학교·시간표 가져오기 꺼짐 |
| `anthropic.api_key` (+ `daily_limit`) | Claude API 키, 회원별 하루 사용 한도 | AI 문제 만들기 꺼짐 |
| `vapid` | 웹 푸시 키 (`php scripts/vapid-keys.php`로 생성) | 앱 안 알림함만 사용 |
| `cron_token` | 외부 크론이 `/sp/api/cron/notify?token=…`을 불러 알림을 만드는 비밀 토큰 | 앱을 열 때만 알림 생성 |

AI 모델은 관리자 화면에서 Haiku / Sonnet / Opus 중에서 고릅니다(기본 Haiku).

## 배포 (Cafe24)

1. `scripts/package.sh`로 `dist/`를 만든다 (화면 빌드 포함).
2. `dist/www_sp/`를 `www/sp/`에, PHP 코드·설정은 웹에서 접근할 수 없는 폴더(`sp_private`, 또는 `www/sp/api/_private`)에 올린다.
3. phpMyAdmin에서 `dist/schema.sql`을 가져오거나(처음), 새 마이그레이션 SQL만 번호순으로 실행한다(업데이트).
4. `config.php`를 채운다.

자세한 순서와 문제 해결은 [`docs/DEPLOY.md`](docs/DEPLOY.md)를 보세요. 같은 DB를 다른 서비스와 나눠 쓰는 호스팅에서도 충돌하지 않도록 모든 테이블 이름에 `sp_`를 붙였습니다.

## 설계 원칙

- 모든 API는 로그인한 사용자의 `user_id`로 소유권을 검사하고, 변경 요청은 `X-CSRF-Token`이 필요합니다.
- 서점·나이스에서 가져온 정보는 학생이 확인하고 고친 뒤에만 저장하며, 교재는 **단원 이름·쪽수까지만** 저장합니다 (본문·문제 전체는 저장하지 않음).
- AI에는 학생이 쓴 노트만 보내고, 사용 동의·하루 한도·관리자 스위치를 지킵니다.
- 업로드 자료는 본인만 볼 수 있고 공유 기능은 없습니다. 관리자는 회원 계정만 관리합니다.

## 문서

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — 설계와 데이터 구조
- [`docs/PROGRESS.md`](docs/PROGRESS.md) — 진행 상황, 구현하면서 정한 것, 실제 서비스로 확인한 것
- [`docs/DEPLOY.md`](docs/DEPLOY.md) — Cafe24 배포 안내
- [`CLAUDE.md`](CLAUDE.md) — 개발 규칙과 명령어 (AI 코딩 도구용 안내)
