import adapter from '@sveltejs/adapter-static';
import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vite';

export default defineConfig({
	plugins: [
		sveltekit({
			// Cafe24에는 Node 서버가 없어 정적 SPA로 빌드한다. 없는 주소는 index.html이 받는다.
			adapter: adapter({ pages: 'build', assets: 'build', fallback: 'index.html', strict: false }),
			paths: { base: '/sp' }
		})
	],
	server: {
		// 개발 중 API는 PHP 개발 서버(php -S 127.0.0.1:8080 scripts/dev-router.php)로 보낸다.
		proxy: { '/sp/api': 'http://127.0.0.1:8080' }
	}
});
