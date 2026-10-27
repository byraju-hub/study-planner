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
