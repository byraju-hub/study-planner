<script>
	import { onMount } from 'svelte';
	import { resolve } from '$app/paths';
	import { page } from '$app/state';
	import { get, post } from '#lib/api.js';
	import { fmtPct } from '#lib/format.js';
	import QuizTabs from '#lib/components/QuizTabs.svelte';
	import UnitPicker from '#lib/components/UnitPicker.svelte';
	import QuizPlayer from '#lib/components/QuizPlayer.svelte';

	let subjects = $state([]);
	let stats = $state(null);
	let attempts = $state([]);
	let subjectId = $state('');
	let unitId = $state('');
	let mode = $state('mixed');
	let count = $state(10);
	let running = $state(null);
	let error = $state('');

	async function load() {
		[stats, attempts] = await Promise.all([get('/quiz/stats'), get('/quiz/attempts')]);
	}

	onMount(async () => {
		try {
			subjects = await get('/subjects');
			const q = page.url.searchParams;
			if (q.get('subject_id')) subjectId = Number(q.get('subject_id'));
			if (q.get('unit_id')) unitId = Number(q.get('unit_id'));
			if (['mixed', 'wrong', 'new'].includes(q.get('mode'))) mode = q.get('mode');
			await load();
		} catch (e) {
			error = e.message;
		}
	});

	async function start(event, override) {
		event?.preventDefault();
		error = '';
		const body = override ?? { subject_id: subjectId || null, unit_id: unitId || null, mode, count: Number(count) };
		try {
			running = await post('/quiz/start', body);
		} catch (e) {
			error = e.message;
		}
	}

	async function done() {
		running = null;
		await load().catch(() => {});
	}

	const weakQuiz = (u) => start(null, { subject_id: u.subject_id, unit_id: u.unit_id, mode: 'mixed', count: 10 });
</script>

<svelte:head><title>퀴즈 · 스터디 플래너</title></svelte:head>

<div class="stack" style="gap: 20px; max-width: 720px;">
	<h1>퀴즈</h1>
	<QuizTabs current="play" />
	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	{#if running}
		<QuizPlayer attemptId={running.attempt_id} questions={running.questions} ondone={done} />
	{:else}
		{#if stats && stats.overall.questions === 0}
			<p class="empty">아직 문제가 없어요. <a href={resolve('/quiz/questions')}>문제 만들기</a>에서 교재 내용으로 문제를 만들어 보세요.</p>
		{/if}

		<form class="card stack" onsubmit={start}>
			<h2>퀴즈 시작</h2>
			<UnitPicker {subjects} bind:subjectId bind:unitId anySubject />
			<div class="row">
				<div class="field grow">
					<label for="mode">방식</label>
					<select id="mode" class="input" bind:value={mode}>
						<option value="mixed">틀린 것·안 푼 것 먼저</option>
						<option value="new">안 푼 문제만</option>
						<option value="wrong">오답노트만</option>
					</select>
				</div>
				<div class="field grow">
					<label for="count">문제 수</label>
					<input id="count" class="input" type="number" min="1" max="50" bind:value={count} />
				</div>
			</div>
			<button class="btn btn-primary">시작하기</button>
		</form>

		{#if stats && stats.overall.answered > 0}
			<section class="card stack">
				<h2>내 정답률 <span class="muted small">(문제마다 가장 최근 답 기준)</span></h2>
				<p>전체 <strong>{fmtPct(stats.overall.accuracy)}</strong> · {stats.overall.answered}문제 풀이</p>
				<ul class="bars">
					{#each stats.subjects.filter((s) => s.answered > 0) as s (s.subject_id)}
						<li>
							<span class="name">{s.subject_name}</span>
							<span class="bar"><span class="fill" style="width: {Math.round((s.accuracy ?? 0) * 100)}%; background: {s.color};"></span></span>
							<span class="pct">{fmtPct(s.accuracy)}</span>
						</li>
					{/each}
				</ul>
			</section>

			{#if stats.weak_units.length > 0}
				<section class="card stack">
					<h2>약한 단원 <span class="chip weak">집중 복습</span></h2>
					<p class="muted small">3문제 이상 풀었고 정답률이 60% 미만인 단원이에요. 시험 대비 계획에서도 ★로 표시돼요.</p>
					<ul class="weak-list">
						{#each stats.weak_units as u (u.unit_id)}
							<li>
								<span><strong>{u.unit_title}</strong> <span class="muted small">{u.subject_name} · {fmtPct(u.accuracy)} ({u.correct}/{u.answered})</span></span>
								<button class="btn btn-small btn-soft" onclick={() => weakQuiz(u)}>이 단원 풀기</button>
							</li>
						{/each}
					</ul>
				</section>
			{/if}
		{/if}

		{#if attempts.length > 0}
			<section class="card stack">
				<h2>최근 퀴즈</h2>
				<ul class="hist">
					{#each attempts as a (a.id)}
						<li><span class="muted small">{a.finished_at.slice(5, 10).replace('-', '/')}</span> {a.unit_title ?? a.subject_name ?? '전체'} <strong>{a.correct}/{a.total}</strong></li>
					{/each}
				</ul>
			</section>
		{/if}
	{/if}
</div>

<style>
	.bars,
	.weak-list,
	.hist {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.bars li {
		display: grid;
		grid-template-columns: 90px 1fr 44px;
		align-items: center;
		gap: 10px;
	}
	.bar {
		height: 10px;
		border-radius: 5px;
		background: var(--line);
		overflow: hidden;
	}
	.fill {
		display: block;
		height: 100%;
		border-radius: 5px;
	}
	.pct {
		text-align: right;
		font-size: 14px;
	}
	.weak {
		background: var(--danger-soft);
		color: var(--danger);
	}
	.weak-list li {
		display: flex;
		justify-content: space-between;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;
	}
	.hist li {
		display: flex;
		gap: 10px;
		align-items: baseline;
	}
</style>
