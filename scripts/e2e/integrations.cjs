const { chromium } = require('playwright');
const base = process.env.SP_BASE || 'http://127.0.0.1:8082/sp'; const S = require('os').tmpdir();
(async () => {
  const b = await chromium.launch(); const errs = [];
  const c = await b.newContext({ viewport: { width: 1000, height: 1000 } }); const p = await c.newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('console', m => m.type() === 'error' && errs.push(m.text()));
  p.on('dialog', d => d.accept());
  const ok = (l, v) => { console.log((v ? '  ok   ' : '  FAIL ') + l); if (!v) process.exitCode = 1; };
  const run = Date.now().toString(36);
  await p.goto(base + '/signup');
  await p.fill('#login_id', 's' + run); await p.fill('#email', `s${run}@example.com`); await p.fill('#password', 'passw0rd123'); await p.fill('#name', '확장이');
  await p.click('button.btn-primary'); await p.waitForTimeout(900);
  const api = (m, path, body) => p.evaluate(async ([m, path, body]) => {
    const t = (await (await fetch('/sp/api/auth/me')).json()).data.csrf;
    const r = await fetch('/sp/api' + path, { method: m, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t }, body: body ? JSON.stringify(body) : undefined });
    return (await r.json()).data;
  }, [m, path, body]);
  const subs = await api('GET', '/subjects'); const sid = subs[0].id;
  await api('POST', '/textbooks', { subject_id: sid, title: '테스트 수학 개념서', kind: 'workbook' });

  // 목차 가져오기 + 순서 바꾸기
  await p.goto(base + '/subjects/' + sid); await p.waitForTimeout(1000);
  await p.click('button:has-text("서점 목차 가져오기")'); await p.waitForTimeout(500);
  await p.click('button:has-text("검색")'); await p.waitForTimeout(700);
  ok('검색 결과 표시', (await p.textContent('body')).includes('테스트출판'));
  await p.click('.book'); await p.waitForTimeout(700);
  ok('목차 후보 5개', (await p.locator('.toc li').count()) === 5);
  await p.screenshot({ path: S + '/toc.png', fullPage: true });
  await p.click('button:has-text("선택한 단원 저장")'); await p.waitForTimeout(900);
  const body = await p.textContent('body');
  ok('단원으로 저장됨', body.includes('Ⅰ. 다항식') && body.includes('1-1 다항식의 연산'));
  const firstBefore = await p.locator('.group').first().locator('.pick').first().textContent();
  await p.locator('.group').nth(1).locator('button[aria-label$="위로"]').first().click(); await p.waitForTimeout(900);
  const firstAfter = await p.locator('.group').first().locator('.pick').first().textContent();
  ok('대단원 ↑ 로 순서 바뀜', firstBefore !== firstAfter && firstAfter.includes('방정식'), [firstBefore, firstAfter]);

  // 나이스 시간표
  await p.goto(base + '/timetable'); await p.waitForTimeout(900);
  await p.click('button:has-text("나이스에서 시간표 가져오기")'); await p.waitForTimeout(400);
  await p.fill('input[aria-label="학교 이름"]', '테스트'); await p.click('form:has(input[aria-label="학교 이름"]) button'); await p.waitForTimeout(700);
  await p.click('.school'); await p.fill('#nc', '3'); await p.click('button:has-text("불러오기")'); await p.waitForTimeout(900);
  ok('시간표 칸이 채워짐 + 건너뛴 수업 안내', (await p.textContent('body')).includes('칸을 채웠어요') && (await p.textContent('body')).includes('체육'));
  await p.screenshot({ path: S + '/neis.png', fullPage: true });

  // AI 문제 만들기
  await p.goto(base + '/quiz/questions?subject_id=' + sid); await p.waitForTimeout(1000);
  await p.click('button[aria-expanded]:has-text("열기")'); await p.waitForTimeout(300);
  await p.check('text=위 내용을 확인했고 >> input'); await p.click('button:has-text("동의하고 시작")'); await p.waitForTimeout(600);
  await p.fill('#ai-text', '나머지정리는 다항식을 일차식으로 나눈 나머지를 구하는 정리다. '.repeat(3));
  await p.click('button:has-text("문제 후보 만들기")'); await p.waitForTimeout(1200);
  ok('AI 후보 2개 표시', (await p.locator('.cand').count()) === 2);
  await p.screenshot({ path: S + '/ai.png', fullPage: true });
  await p.click('button:has-text("선택한 문제 저장")'); await p.waitForTimeout(900);
  ok('저장 안내와 내 문제 목록에 AI 표시', (await p.textContent('body')).includes('2개를 저장했어요') && (await p.locator('.chip:text-is("AI")').count()) === 2);

  // 모의고사 성적 그래프
  const mk = async (d, title, scores) => { const e = await api('POST', '/exams', { type: 'mock', title, start_date: d, end_date: d }); const r = await api('POST', `/exams/${e.id}/subjects`, { subject_id: sid, exam_date: d }); await api('PUT', `/exam-subjects/${r.subjects[0].id}`, { actual_score: scores[0], actual_grade: scores[1] }); };
  await mk('2026-03-12', '3월 모의고사', [72, 3]); await mk('2026-06-04', '6월 모의고사', [85, 2]); await mk('2026-09-03', '9월 모의고사', [78, 3]);
  await p.goto(base + '/exams'); await p.waitForTimeout(1000);
  ok('성적 그래프 선 + 점 3개', (await p.locator('svg polyline').count()) === 1 && (await p.locator('svg circle').count()) === 3);
  await p.click('.seg button:has-text("등급")'); await p.waitForTimeout(300);
  ok('등급 보기 전환', (await p.locator('svg circle').count()) === 3);
  await p.screenshot({ path: S + '/chart.png', fullPage: true });
  ok('콘솔 오류 없음', errs.length === 0 || (console.log(errs), false));
  await b.close();
})();
