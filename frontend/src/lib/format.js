/** 분 → "2시간 15분" / "45분" / "0분" */
export function fmtMinutes(m) {
	const h = Math.floor(m / 60);
	const r = m % 60;
	if (h === 0) return `${r}분`;
	return r === 0 ? `${h}시간` : `${h}시간 ${r}분`;
}

/** 초 → "00:42:10" */
export function fmtClock(totalSeconds) {
	const s = Math.max(0, Math.floor(totalSeconds));
	const p = (n) => String(n).padStart(2, '0');
	return `${p(Math.floor(s / 3600))}:${p(Math.floor((s % 3600) / 60))}:${p(s % 60)}`;
}

/** "2026-10-27" → "10월 27일 화요일" (시간대와 무관하게 날짜 문자열만 쓴다) */
export function fmtDate(ymd) {
	const [y, m, d] = ymd.split('-').map(Number);
	const wd = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
	return `${m}월 ${d}일 ${'일월화수목금토'[wd]}요일`;
}

export function weekdayLabel(ymd) {
	const [y, m, d] = ymd.split('-').map(Number);
	return '일월화수목금토'[new Date(Date.UTC(y, m - 1, d)).getUTCDay()];
}

/** 과목 색을 글자색으로, 같은 색의 옅은 톤을 바탕으로 쓰는 인라인 스타일 */
export function chipStyle(color) {
	return `color:${color};background:color-mix(in srgb, ${color} 14%, white);`;
}

/** "2026-11-09" → "11/9(월)" */
export function fmtMD(ymd) {
	const [y, m, d] = ymd.split('-').map(Number);
	return `${m}/${d}(${'일월화수목금토'[new Date(Date.UTC(y, m - 1, d)).getUTCDay()]})`;
}

/** 남은 일수 → "D-14" / "D-day" / "D+3" */
export function fmtDday(n) {
	return n === 0 ? 'D-day' : n > 0 ? `D-${n}` : `D+${-n}`;
}

export const EXAM_TYPES = { midterm: '중간고사', final: '기말고사', mock: '모의고사' };

export const ASSESS_KINDS = { report: '보고서', presentation: '발표', experiment: '실험', written: '서술형', portfolio: '포트폴리오', practical: '실기', etc: '기타' };
export const ASSESS_STATUS = { planned: '예정', in_progress: '진행중', submitted: '제출 완료', graded: '채점 완료' };

/** 마감까지 남은 일수 표시. 끝난 수행평가는 상태를 보여 준다. */
export function dueLabel(a) {
	if (a.status === 'submitted' || a.status === 'graded') return ASSESS_STATUS[a.status];
	return a.d_day < 0 ? `마감 ${-a.d_day}일 지남` : fmtDday(a.d_day);
}
