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
phpMyAdmin → 내 DB 선택 → 가져오기 → `dist/schema.sql` 실행. 테이블 7개(`users`, `login_attempts`, `subjects`, `textbooks`, `units`, `materials`, `schema_migrations`)가 만들어진다.

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

## 6. 업데이트할 때
- 화면만 바뀌면 `www/sp/`의 `_app/`, `index.html`을 교체한다.
- API가 바뀌면 `sp_private/src/`와 `www/sp/api/`를 교체한다 (`config.php`, `uploads/`는 건드리지 않는다).
- DB가 바뀌면 `db/migrations/`에 새 SQL이 생긴다. SSH를 쓸 수 있으면 `php scripts/migrate.php`, 아니면 새 SQL 파일만 phpMyAdmin에서 실행하고 `schema_migrations`에 파일 이름을 추가한다.
- 배포 전 확인: 로컬에서 `php backend/tests/api_test.php`와 `node scripts/e2e.cjs`가 통과해야 한다.

## 검증 기록
2026-10-27: 같은 폴더 구조(홈/www/sp, 홈/sp_private)를 Apache 2.4 + mod_php 8.3 + MariaDB 10.11로 흉내 내어 `scripts/e2e.cjs`가 통과했다. 실제 Cafe24 서버에서는 아직 확인하지 않았다.
