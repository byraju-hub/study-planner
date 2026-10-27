// 사용: php -S 127.0.0.1:8080 scripts/dev-router.php 를 띄우고 frontend/build 를 만든 뒤  node scripts/e2e.cjs
// (Playwright가 설치되어 있어야 한다. 스크린샷은 임시 폴더의 sp-e2e/ 에 저장된다.)
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const os = require('os');
const OUT = path.join(os.tmpdir(), 'sp-e2e');
fs.mkdirSync(OUT, { recursive: true });
const BASE = 'http://127.0.0.1:8080/sp';
const run = Math.random().toString(36).slice(2, 8);
const loginId = `e2e${run}`;
let failures = 0;

function ok(label, cond, extra = '') {
	console.log(`  ${cond ? 'ok  ' : 'FAIL'} ${label}${cond ? '' : ' ' + extra}`);
	if (!cond) failures++;
}

(async () => {
	const png = Buffer.from(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
		'base64'
	);
	fs.writeFileSync(path.join(OUT, 'note.png'), png);

	const browser = await chromium.launch();
	const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
	const page = await ctx.newPage();
	const errors = [];
	page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
	page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
	page.on('dialog', (d) => d.accept());

	console.log('[인증]');
	await page.goto(`${BASE}/`);
	await page.waitForURL(`${BASE}/login`);
	ok('비로그인 → 로그인 화면으로 이동', true);
	await page.screenshot({ path: path.join(OUT, '01-login.png') });

	await page.click('a.tab:has-text("회원가입")');
	await page.waitForURL(`${BASE}/signup`);
	await page.fill('#login_id', loginId);
	await page.fill('#email', `${loginId}@example.com`);
	await page.fill('#password', 'passw0rd123');
	await page.fill('#name', '서연');
	await page.fill('#school', '테스트고등학교');
	await page.selectOption('#grade', '1');
	await page.click('button:has-text("가입하기")');
	await page.waitForSelector('h1:has-text("안녕하세요, 서연님")');
	ok('가입 후 홈 화면', true);
	ok('기본 과목 8개 표시', (await page.locator('a.subject').count()) === 8);
	await page.screenshot({ path: path.join(OUT, '02-home.png') });

	console.log('[과목]');
	await page.click('nav a:has-text("과목 · 교재")');
	await page.waitForSelector('h1:has-text("과목 · 교재")');
	await page.fill('#new-name', '논술');
	await page.click('form button:has-text("추가")');
	await page.waitForSelector('li:has-text("논술")');
	ok('과목 추가', true);
	await page.fill('#new-name', '논술');
	await page.click('form button:has-text("추가")');
	await page.waitForSelector('.alert:has-text("이미 있는 과목")');
	ok('중복 과목 오류 메시지', true);
	await page.locator('li:has-text("논술") button:has-text("삭제")').click();
	await page.waitForSelector('li:has-text("논술")', { state: 'detached' });
	ok('과목 삭제', true);
	await page.screenshot({ path: path.join(OUT, '03-subjects.png') });

	console.log('[교재·단원·자료]');
	await page.click('a.name:has-text("공통수학")');
	await page.waitForSelector('h1:has-text("공통수학")');
	await page.click('button:has-text("+ 교재")');
	await page.fill('#tb-title', '공통수학1 교과서');
	await page.fill('#tb-pub', '테스트출판');
	await page.fill('#tb-pages', '300');
	await page.click('button:has-text("교재 추가")');
	await page.waitForSelector('.item.selected:has-text("공통수학1 교과서")');
	ok('교재 추가 + 자동 선택', true);

	await page.click('button:has-text("+ 대단원")');
	await page.fill('#u-title', '1. 다항식');
	await page.fill('#u-start', '8');
	await page.fill('#u-end', '41');
	await page.click('form button:has-text("추가")');
	await page.waitForSelector('.item:has-text("1. 다항식")');
	ok('대단원 추가', true);

	await page.click('button:has-text("+ 중단원 추가")');
	await page.fill('#u-title', '1-1 다항식의 연산');
	await page.fill('#u-start', '12');
	await page.fill('#u-end', '10');
	await page.click('form button:has-text("추가")');
	await page.waitForSelector('.alert:has-text("끝 쪽은 시작 쪽보다")');
	ok('쪽수 오류 메시지', true);
	await page.fill('#u-end', '25');
	await page.click('form button:has-text("추가")');
	await page.waitForSelector('.item:has-text("1-1 다항식의 연산")');
	ok('중단원 추가', true);
	await page.waitForSelector('.item:has-text("공통수학1 교과서"):has-text("단원 2")');
	ok('교재 카드의 단원 수 즉시 갱신 (2)', true);

	await page.click('.item:has-text("1-1 다항식의 연산") button.pick');
	await page.waitForSelector('section[aria-label="학습 자료"]');
	ok('단원 선택 → 자료 패널', true);

	await page.click('button:has-text("+ 메모")');
	await page.fill('#m-title', '곱셈 공식');
	await page.fill('#m-content', '(a+b)^2 = a^2 + 2ab + b^2\n줄바꿈 확인');
	await page.click('section[aria-label="학습 자료"] form button:has-text("추가")');
	await page.waitForSelector('.note:has-text("줄바꿈 확인")');
	ok('메모 추가', true);

	await page.click('button:has-text("+ 링크")');
	await page.fill('#m-title', '개념 강의');
	await page.fill('#m-url', 'https://example.com/lecture');
	await page.click('section[aria-label="학습 자료"] form button:has-text("추가")');
	await page.waitForSelector('a.url[href="https://example.com/lecture"]');
	ok('링크 추가', true);

	await page.click('button:has-text("+ 사진 · PDF")');
	await page.setInputFiles('#m-file', path.join(OUT, 'note.png'));
	await page.click('section[aria-label="학습 자료"] form button:has-text("추가")');
	await page.waitForSelector('img.thumb');
	const natural = await page.$eval('img.thumb', (img) => new Promise((r) => (img.complete ? r(img.naturalWidth) : (img.onload = () => r(img.naturalWidth)))));
	ok('사진 업로드 + 미리보기 표시', natural === 1, `naturalWidth=${natural}`);
	await page.waitForSelector('.item:has-text("1-1 다항식의 연산") .chip:has-text("자료 3")');
	ok('단원의 자료 개수 갱신 (3)', true);
	await page.screenshot({ path: path.join(OUT, '04-subject-detail.png'), fullPage: true });

	console.log('[모바일]');
	await page.setViewportSize({ width: 390, height: 844 });
	await page.screenshot({ path: path.join(OUT, '05-mobile-detail.png'), fullPage: false });
	await page.goto(`${BASE}/`);
	await page.waitForSelector('h1:has-text("안녕하세요")');
	await page.screenshot({ path: path.join(OUT, '06-mobile-home.png') });
	const navVisible = await page.locator('nav.nav').isVisible();
	ok('모바일 하단 메뉴 표시', navVisible);
	await page.setViewportSize({ width: 1440, height: 900 });

	console.log('[로그아웃/로그인]');
	await page.click('nav a:has-text("설정")');
	await page.waitForSelector('h1:has-text("설정")');
	await page.click('button:has-text("로그아웃")');
	await page.waitForURL(`${BASE}/login`);
	ok('로그아웃 → 로그인 화면', true);
	await page.goto(`${BASE}/subjects`);
	await page.waitForURL(`${BASE}/login`);
	ok('로그아웃 후 보호된 화면 접근 → 로그인으로', true);
	await page.fill('#login_id', loginId);
	await page.fill('#password', 'wrongpass1');
	await page.click('button:has-text("로그인")');
	await page.waitForSelector('.alert:has-text("올바르지 않습니다")');
	ok('틀린 비밀번호 오류 메시지', true);
	await page.fill('#password', 'passw0rd123');
	await page.click('button:has-text("로그인")');
	await page.waitForSelector('h1:has-text("안녕하세요, 서연님")');
	ok('다시 로그인', true);

	// 새로고침해도 세션 유지
	await page.reload();
	await page.waitForSelector('h1:has-text("안녕하세요, 서연님")');
	ok('새로고침 후에도 로그인 유지', true);

	const realErrors = errors.filter((e) => !/favicon|fonts\.g|status of (401|409|422)/.test(e));
	ok('콘솔 오류 없음', realErrors.length === 0, JSON.stringify(realErrors));

	await browser.close();
	console.log(failures === 0 ? '\n전체 통과' : `\n실패 ${failures}`);
	process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
	console.error('실행 중 오류:', e.message);
	process.exit(2);
});
