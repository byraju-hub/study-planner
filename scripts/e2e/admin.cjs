const { chromium } = require('playwright');
const base = process.env.SP_BASE || 'http://127.0.0.1:8080/sp';
(async () => {
  const b = await chromium.launch(); const errs = [];
  const mk = async () => { const c = await b.newContext({ viewport: { width: 1100, height: 900 } }); const p = await c.newPage(); p.on('pageerror', e => errs.push(e.message)); return p; };
  const run = Date.now().toString(36);
  const ok = (l, c) => { console.log((c ? '  ok   ' : '  FAIL ') + l); if (!c) process.exitCode = 1; };
  const admin = await mk();
  await admin.goto(base + '/login'); await admin.fill('#login_id, input[autocomplete=username]', 'admin').catch(()=>{});
  // 관리자 계정이 없으면 가입
  await admin.goto(base + '/signup');
  await admin.fill('#login_id', 'admin'); await admin.fill('#email', 'admin@example.com'); await admin.fill('#password', 'adminpass1'); await admin.fill('#name', '관리자');
  await admin.click('button.btn-primary');
  await admin.waitForTimeout(800);
  if (!/\/sp\/?$/.test(admin.url())) { await admin.goto(base + '/login'); await admin.fill('input[autocomplete=username]', 'admin'); await admin.fill('input[type=password]', 'adminpass1'); await admin.click('button.btn-primary'); await admin.waitForTimeout(800); }
  ok('관리자 로그인', /\/sp\/?$/.test(admin.url()));
  ok('관리 메뉴 보임', (await admin.locator('a.nav-item', { hasText: '관리' }).count()) > 0);
  await admin.click('a.nav-item:has-text("관리")'); await admin.waitForTimeout(600);
  await admin.selectOption('#mode', 'approval'); await admin.waitForTimeout(500);
  // 대기 회원
  const u = await mk(); await u.goto(base + '/signup');
  await u.waitForTimeout(600); ok('승인제 안내 문구', (await u.textContent('body')).includes('승인해야'));
  await u.fill('#login_id', 'w' + run); await u.fill('#email', `w${run}@example.com`); await u.fill('#password', 'passw0rd123'); await u.fill('#name', '대기생');
  await u.click('button.btn-primary'); await u.waitForTimeout(700);
  ok('가입 신청 완료 문구', (await u.textContent('body')).includes('가입 신청을 받았어요'));
  await admin.reload(); await admin.waitForTimeout(700);
  await admin.screenshot({ path: '' + require('os').tmpdir() + '/admin.png', fullPage: true });
  ok('대기 회원이 목록에 표시', (await admin.textContent('body')).includes('대기생'));
  await admin.click('li.pending button:has-text("승인")'); await admin.waitForTimeout(600);
  ok('승인 안내', (await admin.textContent('body')).includes('승인했어요'));
  await admin.selectOption('#mode', 'open'); await admin.waitForTimeout(400);
  // 비번 초기화 → 강제 변경
  admin.once('dialog', d => d.accept());
  await admin.fill('input[placeholder^="아이디"]', 'w' + run); await admin.click('form.filters .btn'); await admin.waitForTimeout(500);
  await admin.click('button:has-text("비밀번호 초기화")'); await admin.waitForTimeout(700);
  const temp = (await admin.locator('.temp code').textContent()).trim();
  ok('임시 비밀번호 표시', temp.length === 10);
  await u.goto(base + '/login'); await u.fill('input[autocomplete=username]', 'w' + run); await u.fill('input[type=password]', temp); await u.click('button.btn-primary'); await u.waitForTimeout(900);
  ok('임시 비밀번호 로그인 → 설정으로 이동', /settings/.test(u.url()));
  await u.goto(base + '/subjects'); await u.waitForTimeout(700);
  ok('변경 전에는 다른 화면 접근 시 설정으로 되돌아옴', /settings/.test(u.url()));
  await u.fill('#cur', temp); await u.fill('#new', 'newpass999'); await u.click('button:has-text("비밀번호 변경")'); await u.waitForTimeout(700);
  await u.goto(base + '/subjects'); await u.waitForTimeout(700);
  ok('변경 후 정상 이용', /subjects/.test(u.url()));
  // 탈퇴
  await u.goto(base + '/settings'); await u.click('button:has-text("탈퇴하기")'); await u.fill('#delpw', 'newpass999'); await u.click('form button.btn-danger'); await u.waitForTimeout(900);
  ok('탈퇴 후 로그인 화면', /login/.test(u.url()));
  // 일반 회원은 /admin 접근 시 홈으로
  ok('콘솔 오류 없음', errs.length === 0 || (console.log(errs), false));
  await b.close();
})();
