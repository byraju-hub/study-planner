# 스터디 플래너 (study-planner)

고등학교 1학년 학생용 스터디 플래너. 설계는 `docs/ARCHITECTURE.md`, 진행 상황은 `docs/PROGRESS.md`를 먼저 읽는다.

## 기술 스택
- 화면: Svelte 5 + SvelteKit 3 (`adapter-static`, SPA 모드, `paths.base = '/sp'`)
- 서버: PHP 8.4 (JSON API 전용, 프레임워크 없음), MariaDB
- 배포: Cafe24 웹호스팅 `/sp` 폴더 (Node 서버 없음, 정적 파일 + PHP)

## 폴더
- `frontend/` SvelteKit 프로젝트
- `backend/public/` → 서버 `/www/sp/api` (진입점 `index.php`)
- `backend/src/` → PHP 클래스 (`App\` 네임스페이스, 자체 오토로더)
- `db/migrations/` 번호순 SQL (`NNN_이름.sql`), 실행은 `php scripts/migrate.php`
- `docs/` 설계·진행 문서

## 규칙
- 모든 SQL은 PDO prepared statement. 문자열로 SQL을 이어 붙이지 않는다.
- 모든 API는 로그인한 사용자의 `user_id`로 소유권을 검사한다 (다른 사람 데이터 접근 금지).
- 로그인 외 변경 요청(POST/PUT/DELETE)은 `X-CSRF-Token` 헤더 필수.
- PHP는 8.4에서 돌지만 개발 환경은 8.3일 수 있으므로 8.4 전용 문법은 쓰지 않는다.
- 모든 테이블 이름은 `sp_` 접두어를 쓴다 (`sp_users`, `sp_tasks` …). Cafe24에서 기존에 쓰던 DB를 그대로 쓰는데, 그 안에 같은 이름의 옛 테이블이 이미 있었기 때문이다 (옛 테이블은 건드리지 않는다). 새 테이블도 `sp_`로 시작하고, `Owned::find`에는 접두어 포함 이름을 넘긴다.
- 비밀값(DB 비밀번호 등)은 `backend/config.php`에만 둔다. 이 파일은 커밋하지 않는다 (`config.example.php`만 커밋).
- 화면 문구는 한국어. 색상은 `frontend/src/app.css`의 CSS 변수만 쓴다 (파스텔 그린 계열 + 과목별 색).
- 외부 교재 데이터는 목차(단원 이름·쪽수)까지만 저장한다. 본문·문제·해설 전체는 저장하지 않는다.
- 업로드 자료는 본인만 볼 수 있다. 공유 기능은 만들지 않는다 (`docs/ARCHITECTURE.md` 9-1 참고).
- 코드 주석은 최소한으로, 이유가 분명하지 않은 곳에만 쓴다.

## SvelteKit 3 주의 (설치된 버전이 예전 문서와 다르다)
- 설정은 `svelte.config.js`가 아니라 `frontend/vite.config.js`의 `sveltekit({...})` 안에 둔다. (어댑터, `paths.base = '/sp'`)
- `$lib`는 없어졌다. `package.json`의 `imports`로 `#lib/...`를 쓴다.
- `$app/paths`의 `base`는 없어졌다. 주소는 `resolve('/subjects')`, 동적 경로는 `resolve('/subjects/[id]', { id })`.
- 화면은 SPA(`ssr = false`)라서 데이터는 모두 `frontend/src/lib/api.js`로 PHP API에서 받는다.

## 명령어
```
# 로컬 DB (MariaDB). backend/config.php(개발)와 backend/config.test.php(테스트)는 git에 올리지 않는다.
#   config.example.php를 복사해서 만든다. 개발 DB: sp_dev, 테스트 DB: sp_test
php scripts/migrate.php                                  # 개발 DB
SP_CONFIG=backend/config.test.php php scripts/migrate.php  # 테스트 DB

# 로컬 개발 서버 (정적 화면 + API, http://127.0.0.1:8080/sp/)
php -d upload_max_filesize=12M -d post_max_size=14M -S 127.0.0.1:8080 scripts/dev-router.php

# 화면
cd frontend && npm install
npm run build        # frontend/build 생성 (dev-router가 이 폴더를 /sp/ 로 서빙)
npm run dev          # Vite 개발 서버 (API는 8080으로 프록시)

# 계획 계산 테스트 (DB·서버 없이)
php backend/tests/plan_test.php

# 백엔드 API 테스트: 테스트 DB를 쓰는 서버를 8081에 따로 띄운다
SP_CONFIG=$PWD/backend/config.test.php php -d upload_max_filesize=12M -d post_max_size=14M -S 127.0.0.1:8081 scripts/dev-router.php &
php backend/tests/api_test.php

# 브라우저 통합 테스트 (8080 서버 + 빌드 필요, Playwright 필요). 다른 주소는 SP_BASE=http://호스트/sp
node scripts/e2e.cjs

# Cafe24 업로드 묶음 (dist/). 올리는 방법은 docs/DEPLOY.md
scripts/package.sh
```

## 작업 방식
- 시험·수행평가 계획 같은 계산 로직은 `backend/src/Services/`에 DB 없는 순수 함수로 두고 `plan_test.php`로 검증한다.
- 단계(`docs/PROGRESS.md`)별로 진행하고, 기능 하나가 끝날 때마다 커밋·푸시한다.
- 단계가 끝나면 `docs/PROGRESS.md`의 체크 표시와 "다음 할 일"을 갱신한다.
- 확인하지 못한 외부 서비스 명세(나이스, 알라딘 등)는 추측해서 구현하지 말고 문서의 "확인 필요"를 유지한다.
