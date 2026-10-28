<script>
	import { fmtMD } from '#lib/format.js';

	/** 시험별 과목 성적 추이. rows: /exams/results 의 행들. */
	let { rows } = $props();

	let metric = $state('score');

	const W = 640;
	const H = 240;
	const PAD = { l: 40, r: 16, t: 16, b: 40 };

	const axis = $derived(metric === 'score' ? { min: 0, max: 100, ticks: [0, 25, 50, 75, 100] } : { min: 1, max: 9, ticks: [1, 3, 5, 7, 9] });
	const field = $derived(metric === 'score' ? 'actual_score' : 'actual_grade');

	/** 시험(날짜순) 목록 = x 축 칸 */
	const exams = $derived.by(() => {
		const seen = new Map();
		for (const r of rows) if (r[field] !== null && !seen.has(r.exam_id)) seen.set(r.exam_id, { id: r.exam_id, date: r.exam_date, title: r.exam_title });
		return [...seen.values()].sort((a, b) => a.date.localeCompare(b.date));
	});

	const series = $derived.by(() => {
		const bySubject = new Map();
		for (const r of rows) {
			if (r[field] === null) continue;
			if (!bySubject.has(r.subject_name)) bySubject.set(r.subject_name, { name: r.subject_name, color: r.subject_color, points: [] });
			bySubject.get(r.subject_name).points.push({ exam: r.exam_id, value: r[field] });
		}
		return [...bySubject.values()];
	});

	const x = (i) => PAD.l + (exams.length <= 1 ? (W - PAD.l - PAD.r) / 2 : (i * (W - PAD.l - PAD.r)) / (exams.length - 1));
	const y = (v) => {
		// 등급은 숫자가 작을수록 좋으므로 위쪽이 1등급.
		const ratio = metric === 'score' ? (v - axis.min) / (axis.max - axis.min) : 1 - (v - axis.min) / (axis.max - axis.min);
		return PAD.t + (1 - ratio) * (H - PAD.t - PAD.b);
	};
	const pos = (p) => [x(exams.findIndex((e) => e.id === p.exam)), y(p.value)];
</script>

<div class="stack" style="gap: 10px;">
	<div class="row-between">
		<div class="seg" role="group" aria-label="표시 값">
			<button class:on={metric === 'score'} aria-pressed={metric === 'score'} onclick={() => (metric = 'score')}>점수</button>
			<button class:on={metric === 'grade'} aria-pressed={metric === 'grade'} onclick={() => (metric = 'grade')}>등급</button>
		</div>
		<ul class="legend">
			{#each series as s (s.name)}<li><span class="swatch" style="background: {s.color};"></span>{s.name}</li>{/each}
		</ul>
	</div>

	{#if exams.length === 0}
		<p class="empty">{metric === 'score' ? '입력한 점수가 없어요.' : '입력한 등급이 없어요.'}</p>
	{:else}
		<svg viewBox="0 0 {W} {H}" role="img" aria-label="시험별 {metric === 'score' ? '점수' : '등급'} 추이 그래프">
			{#each axis.ticks as t (t)}
				<line x1={PAD.l} x2={W - PAD.r} y1={y(t)} y2={y(t)} class="grid" />
				<text x={PAD.l - 8} y={y(t) + 4} text-anchor="end" class="tick">{t}</text>
			{/each}
			{#each exams as e, i (e.id)}
				<text x={x(i)} y={H - 18} text-anchor="middle" class="tick">{fmtMD(e.date)}</text>
				<text x={x(i)} y={H - 4} text-anchor="middle" class="tick sub">{e.title.length > 8 ? e.title.slice(0, 8) + '…' : e.title}</text>
			{/each}
			{#each series as s (s.name)}
				{#if s.points.length > 1}
					<polyline points={s.points.map((p) => pos(p).join(',')).join(' ')} fill="none" stroke={s.color} stroke-width="2.5" stroke-linejoin="round" />
				{/if}
				{#each s.points as p (p.exam)}
					{@const [px, py] = pos(p)}
					<circle cx={px} cy={py} r="4.5" fill={s.color} stroke="#fff" stroke-width="1.5"><title>{s.name} {p.value}{metric === 'score' ? '점' : '등급'}</title></circle>
				{/each}
			{/each}
		</svg>
	{/if}
</div>

<style>
	svg {
		width: 100%;
		height: auto;
		max-height: 300px;
	}
	.grid {
		stroke: var(--line);
		stroke-width: 1;
	}
	.tick {
		fill: var(--muted);
		font-size: 11px;
	}
	.tick.sub {
		font-size: 10px;
	}
	.seg {
		display: inline-flex;
		background: #e9e7e0;
		padding: 3px;
		border-radius: 10px;
		gap: 3px;
	}
	.seg button {
		min-height: 32px;
		padding: 0 14px;
		border: 0;
		border-radius: 8px;
		background: none;
		cursor: pointer;
		color: var(--muted);
	}
	.seg button.on {
		background: #fff;
		color: var(--ink);
		font-weight: 700;
	}
	.legend {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		gap: 12px;
		flex-wrap: wrap;
		font-size: 13px;
	}
	.legend li {
		display: flex;
		align-items: center;
		gap: 6px;
	}
	.swatch {
		width: 10px;
		height: 10px;
		border-radius: 3px;
	}
</style>
