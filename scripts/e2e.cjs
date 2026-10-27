// 사용: php -S 127.0.0.1:8080 scripts/dev-router.php 를 띄우고 frontend/build 를 만든 뒤  node scripts/e2e.cjs
// (Playwright가 설치되어 있어야 한다. 스크린샷은 임시 폴더의 sp-e2e/ 에 저장된다.)
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const os = require('os');
const OUT = path.join(os.tmpdir(), 'sp-e2e');
fs.mkdirSync(OUT, { recursive: true });
const BASE = process.env.SP_BASE || 'http://127.0.0.1:8080/sp';
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
	await page.click('nav.nav a:has-text("과목·교재")');
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
	await page.click('nav.nav a:has-text("설정")');
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


	console.log('[일반 스터디]');
	const seoulDay = new Date().toLocaleString('en-US', { timeZone: 'Asia/Seoul', weekday: 'short' });
	const isWeekday = !['Sat', 'Sun'].includes(seoulDay);
	await page.click('nav.nav a:has-text("스터디")');
	await page.waitForSelector('h1:has-text("일반 스터디")');
	await page.click('a:has-text("시간표 설정")');
	await page.waitForSelector('h1:has-text("시간표")');
	for (let d = 1; d <= 5; d++) {
		await page.selectOption(`#c-${d}-1`, { label: '공통국어' });
		await page.selectOption(`#c-${d}-2`, { label: '공통수학' });
	}
	await page.click('button:has-text("시간표 저장")');
	await page.waitForSelector('.chip:has-text("시간표를 저장했어요")');
	ok('시간표 저장', true);
	await page.screenshot({ path: path.join(OUT, '07-timetable.png') });

	await page.click('a:has-text("일반 스터디")');
	await page.waitForSelector('section[aria-label="오늘 수업 복습"]');
	if (isWeekday) {
		ok('오늘 수업 2개 표시', (await page.locator('section[aria-label="오늘 수업 복습"] li').count()) === 2);
		await page.fill('#topic-2', '나머지정리');
		await page.getByLabel('2교시 공통수학 복습 완료').check();
		await page.waitForSelector('section[aria-label="오늘 수업 복습"] li:has-text("복습 완료")');
		ok('수업 복습 완료 표시', true);
	} else {
		ok('주말: 수업 없음 안내', (await page.locator('section[aria-label="오늘 수업 복습"] .empty').count()) === 1);
	}

	await page.fill('#task-title', '수학 문제집 풀기');
	await page.selectOption('#task-subject', { label: '공통수학' });
	await page.fill('#task-min', '30');
	await page.click('section[aria-label="오늘 할 일"] button:has-text("추가")');
	await page.waitForSelector('section[aria-label="오늘 할 일"] li:has-text("수학 문제집 풀기")');
	ok('할 일 추가', true);
	await page.getByLabel('수학 문제집 풀기', { exact: true }).check();
	await page.waitForSelector('section[aria-label="오늘 할 일"] h2:has-text("1/1")');
	ok('할 일 완료 → 1/1', true);

	await page.fill('#rv-title', '이차방정식');
	await page.click('section[aria-label="복습"] button:has-text("추가")');
	await page.waitForSelector('.chip:has-text("내일 1차 복습으로 등록했어요")');
	ok('복습 직접 추가 (내일 1차)', true);

	const clock = page.locator('output.clock');
	ok('타이머 대기 상태 00:00:00', (await clock.textContent()) === '00:00:00');
	await page.selectOption('#timer-subject', { label: '공통수학' });
	await page.click('section[aria-label="공부 타이머"] button:has-text("시작")');
	await page.waitForSelector('section[aria-label="공부 타이머"] button:has-text("정지")');
	await page.waitForTimeout(2300);
	const running = await clock.textContent();
	ok('타이머가 흐름', running !== '00:00:00', running);
	await page.reload();
	await page.waitForSelector('section[aria-label="공부 타이머"] button:has-text("정지")');
	ok('새로고침해도 타이머 유지', (await clock.textContent()) !== '00:00:00');
	await page.click('section[aria-label="공부 타이머"] button:has-text("정지")');
	await page.waitForSelector('section[aria-label="공부 타이머"] button:has-text("시작")');
	ok('타이머 정지', true);

	await page.fill('#j-good', '수학 문제집 풀었다');
	await page.fill('#j-bad', '영어를 못했다');
	await page.locator('label.star:has-text("4")').click();
	await page.click('form[aria-label="하루 회고"] button:has-text("회고 저장")');
	await page.waitForSelector('.chip:has-text("저장했어요")');
	ok('하루 회고 저장', true);
	await page.reload();
	await page.waitForSelector('#j-good');
	ok('새로고침 후 회고 유지', (await page.inputValue('#j-good')) === '수학 문제집 풀었다');
	ok('새로고침 후 할 일 완료 유지', await page.getByLabel('수학 문제집 풀기', { exact: true }).isChecked());
	await page.screenshot({ path: path.join(OUT, '08-study.png'), fullPage: true });

	await page.setViewportSize({ width: 390, height: 844 });
	await page.screenshot({ path: path.join(OUT, '09-study-mobile.png'), fullPage: true });
	await page.setViewportSize({ width: 1440, height: 900 });

	await page.click('nav.nav a:has-text("홈")');
	await page.waitForSelector('a.today');
	ok('홈에 오늘 요약 표시', (await page.locator('a.today').textContent()).includes('1/1'));


	console.log('[시험 대비]');
	const seoulYmd = (plusDays) => {
		const [y, m, d] = new Date().toLocaleDateString('sv-SE', { timeZone: 'Asia/Seoul' }).split('-').map(Number);
		return new Date(Date.UTC(y, m - 1, d + plusDays)).toISOString().slice(0, 10);
	};
	await page.click('nav.nav a:has-text("시험 대비")');
	await page.waitForSelector('h1:has-text("시험 대비")');
	await page.click('button:has-text("+ 시험 추가")');
	await page.fill('#x-title', '2학기 중간고사');
	await page.fill('#x-start', seoulYmd(14));
	await page.fill('#x-end', seoulYmd(17));
	await page.click('form button:has-text("시험 만들기")');
	await page.waitForSelector('a.card.item:has-text("2학기 중간고사")');
	ok('시험 등록 → 목록에 표시 (D-14)', (await page.locator('a.hero').textContent()).includes('D-14'));
	await page.click('a.card.item:has-text("2학기 중간고사")');
	await page.waitForSelector('h1:has-text("2학기 중간고사")');

	await page.click('button:has-text("+ 과목 추가")');
	await page.selectOption('#a-subject', { label: '공통수학' });
	await page.fill('#a-period', '2');
	await page.fill('#a-ts', '90');
	await page.click('form button:has-text("과목 추가")');
	await page.waitForSelector('section[aria-label="공통수학 시험"]');
	ok('시험 과목 추가', true);
	await page.click('button:has-text("+ 과목 추가")');
	await page.selectOption('#a-subject', { label: '공통국어' });
	await page.fill('#a-date', seoulYmd(15));
	await page.click('form button:has-text("과목 추가")');
	await page.waitForSelector('section[aria-label="공통국어 시험"]');
	ok('두 번째 과목 (다른 시험일)', true);

	const math = page.locator('section[aria-label="공통수학 시험"]');
	await math.locator('button:has-text("교재에서 고르기")').click();
	await math.locator('label.unit:has-text("1. 다항식") input').check();
	await math.locator('button:has-text("선택한 단원 1개 추가")').click();
	await math.locator('li.scope:has-text("1. 다항식")').waitFor();
	ok('교재 단원을 시험 범위로 추가', true);
	await math.locator('input[type=range]').fill('60');
	await math.locator('.pct:has-text("60%")').waitFor();
	ok('범위 진도 60% 저장', true);

	const kor = page.locator('section[aria-label="공통국어 시험"]');
	await kor.locator('button:has-text("직접 입력")').click();
	await kor.locator('input[id^="sc-label"]').fill('문학 1~2단원');
	await kor.locator('input[id^="sc-s"]').fill('10');
	await kor.locator('input[id^="sc-e"]').fill('49');
	await kor.locator('button:has-text("범위 추가")').click();
	await kor.locator('li.scope:has-text("문학 1~2단원")').waitFor();
	ok('범위 직접 입력', true);

	await page.click('button:has-text("계획 만들기")');
	await page.waitForSelector('[aria-label="계획 미리보기"]');
	const previewCount = await page.locator('[aria-label="계획 미리보기"] .item').count();
	ok('대비 계획 미리보기 생성', previewCount >= 20, `items=${previewCount}`);
	await page.screenshot({ path: path.join(OUT, '10-exam-preview.png'), fullPage: true });
	await page.locator('[aria-label="계획 미리보기"] .item button:has-text("빼기")').first().click();
	ok('미리보기에서 항목 빼기', (await page.locator('[aria-label="계획 미리보기"] .item').count()) === previewCount - 1);
	await page.click('button:has-text("이대로 확정")');
	await page.waitForSelector('.chip:has-text("할 일")');
	await page.waitForSelector('[aria-label="확정된 계획"]');
	const confirmed = await page.locator('[aria-label="확정된 계획"] label.check').count();
	ok('계획 확정 → 할 일 생성', confirmed === previewCount - 1, `confirmed=${confirmed}`);
	await page.locator('[aria-label="확정된 계획"] label.check input').first().check();
	await page.waitForSelector('[aria-label="확정된 계획"] :text("1/' + confirmed + ' 완료")');
	ok('계획 항목 완료 체크 → 달성 수 갱신', true);

	await page.locator('section[aria-label="공통수학 시험"] input[id^="ra-"]').fill('85');
	await page.locator('section[aria-label="공통수학 시험"] input[id^="rg-"]').fill('2');
	await page.locator('section[aria-label="공통수학 시험"] form.result button:has-text("저장")').click();
	await page.waitForSelector('section[aria-label="공통수학 시험"] .chip:has-text("결과를 저장했어요")');
	ok('시험 결과 입력', true);
	await page.screenshot({ path: path.join(OUT, '11-exam-detail.png'), fullPage: true });

	await page.click('nav.nav a:has-text("스터디")');
	await page.waitForSelector('section[aria-label="오늘 할 일"]');
	await page.waitForSelector('section[aria-label="오늘 할 일"] li:has-text("시험")');
	ok('오늘 할 일에 시험 계획이 함께 보임', true);

	await page.click('nav.nav a:has-text("홈")');
	await page.waitForSelector('a.exam');
	ok('홈에 시험 D-day 카드', (await page.locator('a.exam').textContent()).includes('D-14'));
	await page.screenshot({ path: path.join(OUT, '12-home.png'), fullPage: true });

	await page.click('nav.nav a:has-text("시험 대비")');
	await page.click('a.card.item:has-text("2학기 중간고사")');
	await page.waitForSelector('h1:has-text("2학기 중간고사")');
	await page.click('button:has-text("시험 삭제")');
	await page.waitForURL(`${BASE}/exams`);
	await page.waitForSelector('.empty:has-text("등록된 시험이 없어요")');
	ok('시험 삭제 → 목록이 비어 있음', true);
	await page.click('nav.nav a:has-text("스터디")');
	await page.waitForSelector('section[aria-label="오늘 할 일"]');
	ok('시험을 지우면 오늘 할 일의 계획도 사라짐', (await page.locator('section[aria-label="오늘 할 일"] li:has-text("시험")').count()) === 0);


	console.log('[수행평가]');
	await page.click('nav.nav a:has-text("수행평가")');
	await page.waitForSelector('h1:has-text("수행평가")');
	await page.click('button:has-text("+ 수행평가 추가")');
	await page.selectOption('#n-subject', { label: '통합사회' });
	await page.selectOption('#n-kind', { label: '발표' });
	await page.fill('#n-title', '모둠 발표');
	await page.fill('#n-due', seoulYmd(10));
	await page.fill('#n-max', '20');
	await page.fill('#n-weight', '20');
	await page.click('form button:has-text("수행평가 만들기")');
	await page.waitForSelector('section[aria-label="예정"] a.card:has-text("모둠 발표")');
	const card = await page.locator('section[aria-label="예정"] a.card:has-text("모둠 발표")').textContent();
	ok('수행평가 등록 → 예정 칸에 표시 (D-10, 0/5 단계)', card.includes('D-10') && card.includes('0/5'), card);
	await page.screenshot({ path: path.join(OUT, '13-assessments.png'), fullPage: true });

	await page.click('a.card:has-text("모둠 발표")');
	await page.waitForSelector('h1:has-text("모둠 발표")');
	ok('기본 단계 5개 자동 생성', (await page.locator('ul.steps li').count()) === 5);
	await page.locator('ul.steps li').first().locator('input[type=checkbox]').check();
	await page.waitForSelector('text=1/5 완료');
	await page.waitForSelector('text=상태: 진행중');
	ok('첫 단계 완료 → 상태가 진행중으로', true);
	await page.screenshot({ path: path.join(OUT, '14-assessment-detail.png'), fullPage: true });

	await page.click('nav.nav a:has-text("스터디")');
	await page.waitForSelector('section[aria-label="오늘 할 일"] li:has-text("모둠 발표 · ")');
	ok('오늘 할 일에 수행평가 단계가 들어옴 (완료 표시)', await page.locator('section[aria-label="오늘 할 일"] li:has-text("모둠 발표 · ")').first().locator('input[type=checkbox]').isChecked());

	await page.click('nav.nav a:has-text("홈")');
	await page.waitForSelector('section[aria-label="마감 임박 수행평가"]');
	ok('홈에 마감 임박 수행평가 표시', (await page.locator('section[aria-label="마감 임박 수행평가"]').textContent()).includes('모둠 발표'));

	await page.click('nav.nav a:has-text("수행평가")');
	await page.click('a.card:has-text("모둠 발표")');
	await page.click('button:has-text("제출했어요")');
	await page.waitForSelector('text=상태: 제출 완료');
	await page.click('a:has-text("← 수행평가")');
	await page.waitForSelector('section[aria-label="제출 · 채점 완료"] a.card:has-text("모둠 발표")');
	ok('제출 → 완료 칸으로 이동', true);

	await page.click('a.card:has-text("모둠 발표")');
	await page.click('button:has-text("수행평가 삭제")');
	await page.waitForURL(`${BASE}/assessments`);
	await page.waitForSelector('.empty:has-text("등록된 수행평가가 없어요")');
	ok('수행평가 삭제 → 목록이 비어 있음', true);
	await page.click('nav.nav a:has-text("스터디")');
	await page.waitForSelector('section[aria-label="오늘 할 일"]');
	ok('삭제하면 오늘 할 일의 단계도 사라짐', (await page.locator('section[aria-label="오늘 할 일"] li:has-text("모둠 발표")').count()) === 0);

	const realErrors = errors.filter((e) => !/favicon|fonts\.g|status of (401|409|422)/.test(e));
	ok('콘솔 오류 없음', realErrors.length === 0, JSON.stringify(realErrors));

	await browser.close();
	console.log(failures === 0 ? '\n전체 통과' : `\n실패 ${failures}`);
	process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
	console.error('실행 중 오류:', e.message);
	process.exit(2);
});
