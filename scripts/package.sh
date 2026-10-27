#!/usr/bin/env bash
# Cafe24 업로드용 묶음을 dist/ 에 만든다.
#   dist/www_sp/       → 서버의 www/sp/ 로 올린다 (화면 + api/)
#   dist/sp_private/   → 서버의 홈 폴더 sp_private/ 로 올린다 (www 바깥: PHP 코드, 설정, 업로드)
#   dist/schema.sql    → phpMyAdmin에서 가져오기(Import)
#   dist/sp-deploy.zip → 위 세 가지를 한 파일로 묶은 것
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="$root/dist"

rm -rf "$out"
mkdir -p "$out/www_sp/api" "$out/sp_private/uploads"

(cd "$root/frontend" && npm run build)
cp -R "$root/frontend/build/." "$out/www_sp/"

cp "$root/backend/public/index.php" "$root/backend/public/.htaccess" "$out/www_sp/api/"
# www/sp/api 에서 세 단계 위가 홈 폴더(www의 부모)다. 그 아래 sp_private 를 코드·설정 위치로 쓴다.
printf '%s\n' "<?php return dirname(__DIR__, 3) . '/sp_private';" > "$out/www_sp/api/root.php"

cat > "$out/www_sp/.htaccess" <<'EOF'
RewriteEngine On
# API는 api/ 폴더가 처리한다.
RewriteRule ^api/ - [L]
# 없는 주소(새로고침한 /sp/subjects 등)는 화면 진입점으로 보낸다.
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.html [L]
EOF

cp -R "$root/backend/src" "$out/sp_private/src"
cp "$root/backend/config.example.php" "$out/sp_private/config.example.php"
printf 'Require all denied\n' > "$out/sp_private/uploads/.htaccess"
printf 'Require all denied\n' > "$out/sp_private/.htaccess"

{
	echo "-- Cafe24 phpMyAdmin 가져오기용. 사용할 데이터베이스를 선택한 뒤 실행한다."
	for f in $(ls "$root"/db/migrations/*.sql | sort); do
		echo "-- $(basename "$f")"
		cat "$f"
		echo
	done
	echo "CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(100) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
	for f in $(ls "$root"/db/migrations/*.sql | sort); do
		echo "INSERT IGNORE INTO schema_migrations (name) VALUES ('$(basename "$f")');"
	done
} > "$out/schema.sql"

(cd "$out" && rm -f sp-deploy.zip && zip -qr sp-deploy.zip www_sp sp_private schema.sql)
echo "완료: $out"
ls -la "$out"
