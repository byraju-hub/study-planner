# Cafe24 배포 안내

대상: Cafe24 웹호스팅의 `www/sp/` (주소 예: `https://내도메인/sp/`). PHP 8.4, MariaDB(MySQL).

## 0. 묶음 만들기
```
scripts/package.sh        # dist/ 와 dist/sp-deploy.zip 생성 (Node, npm 필요)
```
| 만들어진 폴더 | 서버에 올릴 위치 |
|---|---|
| `dist/www_sp/` | `www/sp/` (화면 + `api/`) |
| `dist/sp_private/` | 홈 폴더의 `sp_private/` — `www`와 **같은 위치**(www 바깥) |
| `dist/schema.sql` | phpMyAdmin에서 가져오기 |

## 1. 호스팅 설정 (Cafe24 관리자)
1. PHP 버전을 8.4로 선택한다.
2. 데이터베이스(MariaDB) 정보를 확인한다: 호스트(보통 `localhost`), DB 이름, 아이디, 비밀번호.
3. SSL(HTTPS)을 적용한다. 세션 쿠키가 HTTPS에서 `Secure`로 설정된다.

## 2. 데이터베이스
phpMyAdmin → 내 DB 선택 → 가져오기 → `dist/schema.sql` 실행. 테이블(사용자, 과목, 교재, 단원, 자료, 할 일, 복습, 시험, 수행평가 등)과 `sp_schema_migrations`가 만들어진다. 테이블 이름은 모두 `sp_` 접두어라서 기존 DB에 있던 같은 이름의 옛 테이블과 겹치지 않는다. (옛 테이블은 그대로 둔다.) 이미 한 번 가져왔다면, 새로 생긴 `db/migrations/*.sql`만 phpMyAdmin에서 실행한다.

## 3. 파일 올리기 (FTP, 예: FileZilla)
1. `dist/www_sp/` 안의 내용을 `www/sp/`에 올린다. (`.htaccess` 같은 점(.)으로 시작하는 파일도 함께 올라가야 한다. FTP 프로그램의 "숨김 파일 표시"를 켠다.)
2. `dist/sp_private/`를 홈 폴더(`www`와 같은 위치)에 `sp_private/`로 올린다.
3. `sp_private/config.example.php`를 `sp_private/config.php`로 복사(이름 변경)하고 값을 채운다.
   - `db.host`, `db.name`, `db.user`, `db.pass`: 1-2에서 확인한 값
   - `base_path`: `/sp/api` 그대로
   - `debug`: `false`
4. `sp_private/uploads/` 폴더에 PHP가 쓸 수 있게 권한을 준다(필요하면 707 또는 755).

### www 바깥에 폴더를 만들 수 없는 상품이라면
1. `sp_private/`를 `www/sp/api/_private/`로 올린다.
2. `www/sp/api/root.php`를 열어 내용을 `<?php return __DIR__ . '/_private';` 로 바꾼다.
3. `.htaccess`가 `_private` 접근을 막지만, 가능하면 www 바깥을 쓰는 편이 안전하다.

## 4. 확인
1. `https://내도메인/sp/api/auth/me` → `{"ok":true,"data":{"user":null,"csrf":"..."}}` 가 보이면 PHP와 DB 연결이 된 것이다.
2. `https://내도메인/sp/` → 로그인 화면이 열리는지, 회원가입 후 과목이 8개 생기는지 확인한다.
3. 과목 상세에서 사진을 올려 본다 (업로드 폴더 권한 확인).
4. 주소창에서 `https://내도메인/sp/subjects` 로 바로 들어가도 화면이 열려야 한다(`.htaccess` 동작 확인).
5. `https://내도메인/sp_private/config.php` 가 **열리지 않아야** 한다(404/403).

## 5. 문제 해결
| 증상 | 확인 |
|---|---|
| `/sp/api/...`가 500 또는 빈 화면 | `config.php` 위치와 DB 정보, PHP 8.4 선택 여부, `pdo_mysql` 확장 |
| `{"error":{"code":"config"...` | `sp_private/config.php`가 없거나 `root.php` 경로가 틀림 |
| 화면은 열리는데 새로고침하면 404 | `www/sp/.htaccess`가 올라갔는지(숨김 파일), `mod_rewrite` 허용 여부 |
| 로그인이 계속 풀림 | HTTPS 적용 여부, 서버 세션 저장 경로 쓰기 권한 |
| 사진 업로드 실패 | `uploads/` 권한, PHP `upload_max_filesize`(기본 2MB일 수 있음 → 호스팅 PHP 설정에서 10MB 이상) |

## 5-1. 3~5단계 올리기 (관리자·알림·퀴즈·외부 연동)
이미 1~2단계(마이그레이션 001~004)를 올린 DB라면:
1. `dist/migrations/005_admin.sql` ~ `008_integrations.sql`을 **번호순으로** phpMyAdmin에서 실행한다. (각 파일 끝에 실행 기록 `INSERT IGNORE`가 들어 있다.)
2. `sp_private/src/`와 `www/sp/` 전체(`_app/`, `index.html`, `sw.js`), `www/sp/api/`를 교체한다. `config.php`, `uploads/`는 건드리지 않는다.
3. `sp_private/config.php`에 필요한 값을 추가한다 (모두 선택. 없으면 해당 기능만 꺼진다). 예시는 `config.example.php`:
   - `admin_login_id`: 최고 관리자 아이디. **이 아이디로 가입·로그인**하면 왼쪽 메뉴에 "관리"가 생긴다. 먼저 이 아이디를 안 쓰는 사람이 가입해 버리지 않도록, 설정 직후 바로 가입한다.
   - `cron_token`: 임의의 긴 문자열. 외부 크론(또는 호스팅 크론)이 `https://도메인/sp/api/cron/notify?token=값`을 하루 여러 번(예: 30분마다) 호출하면 앱을 안 열어도 푸시가 간다. 없으면 앱을 열 때만 알림이 만들어진다.
   - `vapid`: 휴대폰·브라우저 푸시. 개발 PC에서 `php scripts/vapid-keys.php`를 한 번 실행해 나온 블록을 붙인다. 키를 바꾸면 기존 구독이 모두 끊긴다.
   - `yes24.api_key`, `neis.key`, `anthropic.api_key`(+`model`, `daily_limit`): 각 서비스에서 발급. **키는 저장소에 올리지 않는다.**
4. 호스팅 PHP에 `curl`, `openssl` 확장이 켜져 있어야 외부 연동·푸시가 된다.
5. 확인: 관리자 계정으로 "관리" 화면이 열리는지, 새 계정으로 알림 종 아이콘·퀴즈 화면이 열리는지.
6. 실제 외부 서비스와 맞는지는 아직 확인하지 못했다. `docs/PROGRESS.md`의 "실제 서비스로 확인하지 못한 것"을 먼저 읽는다.

## 6. 업데이트할 때
- 화면만 바뀌면 `www/sp/`의 `_app/`, `index.html`을 교체한다.
- API가 바뀌면 `sp_private/src/`와 `www/sp/api/`를 교체한다 (`config.php`, `uploads/`는 건드리지 않는다).
- DB가 바뀌면 `db/migrations/`에 새 SQL이 생긴다. SSH를 쓸 수 있으면 `php scripts/migrate.php`, 아니면 새 SQL 파일만 phpMyAdmin에서 실행하고 `sp_schema_migrations`에 파일 이름을 추가한다.
- 배포 전 확인: 로컬에서 `php backend/tests/api_test.php`와 `node scripts/e2e.cjs`가 통과해야 한다.

## 검증 기록
2026-10-27: 같은 폴더 구조(홈/www/sp, 홈/sp_private)를 Apache 2.4 + mod_php 8.3 + MariaDB 10.11로 흉내 내어 `scripts/e2e.cjs`가 통과했다. 실제 Cafe24 서버에서는 아직 확인하지 않았다.
2026-10-28: 3~5단계 묶음(`dist/schema.sql` 새 DB 가져오기 포함)을 같은 방식(Apache + 홈/www/sp + 홈/sp_private)으로 흉내 내어 `scripts/e2e.cjs`와 `scripts/e2e/*.cjs` 4종이 모두 통과했다(외부 서비스는 가짜 서버). `sw.js` 제공, `sp_private` 접근 차단(404), 크론 토큰 검사 확인. 실제 Cafe24 서버와 실제 외부 서비스에서는 아직 확인하지 못했다.
