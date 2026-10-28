const { chromium } = require('playwright');
const base = process.env.SP_BASE || 'http://127.0.0.1:8080/sp'; const S = require('os').tmpdir();
(async () => {
  const b = await chromium.launch(); const errs = [];
  const c = await b.newContext({ viewport: { width: 1000, height: 900 } }); const p = await c.newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('console', m => m.type() === 'error' && errs.push(m.text()));
  const ok = (l, v) => { console.log((v ? '  ok   ' : '  FAIL ') + l); if (!v) process.exitCode = 1; };
  const run = Date.now().toString(36);
  await p.goto(base + '/signup');
  await p.fill('#login_id', 'q' + run); await p.fill('#email', `q${run}@example.com`); await p.fill('#password', 'passw0rd123'); await p.fill('#name', '퀴즈왕');
  await p.click('button.btn-primary'); await p.waitForTimeout(900);
  const api = (m, path, body) => p.evaluate(async ([m, path, body]) => {
    const t = (await (await fetch('/sp/api/auth/me')).json()).data.csrf;
    const r = await fetch('/sp/api' + path, { method: m, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t }, body: body ? JSON.stringify(body) : undefined });
    return (await r.json()).data;
  }, [m, path, body]);
  const subs = await api('GET', '/subjects'); const sid = subs[0].id;
  const tb = await api('POST', '/textbooks', { subject_id: sid, title: '교과서', kind: 'textbook' });
  const unit = await api('POST', '/units', { textbook_id: tb.id, title: '1. 다항식' });
  await p.goto(base + `/quiz/questions?subject_id=${sid}&unit_id=${unit.id}`); await p.waitForTimeout(900);
  ok('빈 문제 목록 안내', (await p.textContent('body')).includes('아직 문제가 없어요'));
  await p.fill('#prompt', '1+1은 무엇인가요?');
  const choices = p.locator('input[placeholder$="번 보기"]');
  await choices.nth(0).fill('1'); await choices.nth(1).fill('2'); await choices.nth(2).fill('3');
  await p.locator('input[type=radio][name=correct]').nth(1).check();
  await p.fill('#exp', '기본 덧셈');
  await p.click('form button.btn-primary'); await p.waitForTimeout(700);
  ok('객관식 문제 추가', (await p.textContent('body')).includes('1+1은 무엇인가요?'));
  await p.click('button.type:has-text("OX")'); await p.fill('#prompt', '지구는 평평하다'); await p.check('input[value=X]'); await p.click('form button.btn-primary'); await p.waitForTimeout(600);
  await p.click('button.type:has-text("단답형")'); await p.fill('#prompt', '물의 화학식은?'); await p.fill('#ans', 'H2O'); await p.click('form button.btn-primary'); await p.waitForTimeout(600);
  await p.click('button.type:has-text("암기 카드")'); await p.fill('#prompt', '광합성 장소'); await p.fill('#ans', '엽록체'); await p.click('form button.btn-primary'); await p.waitForTimeout(600);
  ok('문제 4개', (await p.textContent('h2:has-text("내 문제")')).includes('4개'));
  await p.screenshot({ path: S + '/quiz_bank.png', fullPage: true });

  await p.goto(base + `/quiz?subject_id=${sid}&unit_id=${unit.id}`); await p.waitForTimeout(900);
  await p.fill('#count', '10'); await p.click('form button.btn-primary'); await p.waitForTimeout(700);
  // 4문제 풀이: 일부러 객관식은 틀리게
  for (let i = 0; i < 4; i++) {
    const text = await p.textContent('.prompt');
    if (text.includes('1+1')) await p.click('.choice:has-text("1. 1")'), await p.click('button:has-text("확인")');
    else if (text.includes('지구')) await p.click('.choice.ox:has-text("X")'), await p.click('button:has-text("확인")');
    else if (text.includes('물의')) await p.fill('input[placeholder="정답을 입력하세요"]', 'h2o'), await p.click('button:has-text("확인")');
    else await p.click('button:has-text("뒷면 보기")'), await p.click('button:has-text("맞았어요")');
    await p.waitForTimeout(500);
    if (i === 0) await p.screenshot({ path: S + '/quiz_play.png' });
    await p.click('button:has-text("다음 문제"), button:has-text("결과 보기")'); await p.waitForTimeout(500);
  }
  ok('결과: 3/4', (await p.textContent('.result')).includes('3 / 4'));
  await p.click('.result button.btn-primary'); await p.waitForTimeout(800);
  ok('정답률 카드', (await p.textContent('body')).includes('내 정답률'));
  await p.goto(base + '/quiz/wrong'); await p.waitForTimeout(800);
  ok('오답노트에 틀린 문제', (await p.textContent('body')).includes('1+1은 무엇인가요?'));
  await p.click('button:has-text("정답 보기")'); await p.waitForTimeout(300);
  ok('정답 보기', (await p.textContent('body')).includes('2번 2'));
  await p.screenshot({ path: S + '/quiz_wrong.png', fullPage: true });
  await p.click('button:has-text("다시 풀기")'); await p.waitForTimeout(800);
  ok('다시 풀기 → 퀴즈 화면, 방식 오답노트', (await p.inputValue('#mode')) === 'wrong');
  ok('콘솔 오류 없음', errs.length === 0 || (console.log(errs), false));
  await b.close();
})();
