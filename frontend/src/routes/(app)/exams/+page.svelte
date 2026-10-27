<script>
	import { onMount } from 'svelte';
	import { resolve } from '$app/paths';
	import { get, post } from '#lib/api.js';
	import { EXAM_TYPES, fmtDday, fmtMD } from '#lib/format.js';
	import ModeTabs from '#lib/components/ModeTabs.svelte';

	let exams = $state([]);
	let results = $state([]);
	let loaded = $state(false);
	let error = $state('');
	let tab = $state('all');
	let showForm = $state(false);
	let form = $state({ type: 'midterm', title: '', start_date: '', end_date: '', memo: '' });
	let busy = $state(false);

	async function load() {
		try {
			[exams, results] = await Promise.all([get('/exams'), get('/exams/results')]);
			error = '';
		} catch (e) {
			error = e.message;
		}
		loaded = true;
	}
	onMount(load);

	const shown = $derived(tab === 'all' ? exams : exams.filter((e) => e.type === tab));
	const next = $derived(exams.find((e) => e.status !== 'done') ?? null);
	const shownResults = $derived(tab === 'all' ? results : results.filter((r) => r.type === tab));

	async function create(event) {
		event.preventDefault();
		error = '';
		busy = true;
		try {
			await post('/exams', {
				type: form.type,
				title: form.title,
				start_date: form.start_date,
				end_date: form.type === 'mock' || !form.end_date ? form.start_date : form.end_date,
				memo: form.memo || null
			});
			form = { type: form.type, title: '', start_date: '', end_date: '', memo: '' };
			showForm = false;
			await load();
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}

	const range = (e) => (e.start_date === e.end_date ? fmtMD(e.start_date) : `${fmtMD(e.start_date)} – ${fmtMD(e.end_date)}`);
	const score = (r) => [r.actual_score !== null ? `${r.actual_score}점` : null, r.actual_grade !== null ? `${r.actual_grade}등급` : null].filter(Boolean).join(' · ');
	const target = (r) => [r.target_score !== null ? `${r.target_score}점` : null, r.target_grade !== null ? `${r.target_grade}등급` : null].filter(Boolean).join(' · ') || '—';
</script>

<svelte:head><title>시험 대비 · 스터디 플래너</title></svelte:head>

<div class="stack page">
	<header class="stack" style="gap: 10px;">
		<div class="row-between">
			<h1>시험 대비</h1>
			<button class="btn btn-small btn-soft" onclick={() => (showForm = !showForm)} aria-expanded={showForm}>{showForm ? '닫기' : '+ 시험 추가'}</button>
		</div>
		<ModeTabs current="exam" />
	</header>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	{#if showForm}
		<form class="card stack" onsubmit={create}>
			<h2>새 시험</h2>
			<div class="grid2">
				<div class="field">
					<label for="x-type">종류</label>
					<select id="x-type" class="input" bind:value={form.type}>
						{#each Object.entries(EXAM_TYPES) as [value, label] (value)}<option {value}>{label}</option>{/each}
					</select>
				</div>
				<div class="field">
					<label for="x-title">시험 이름</label>
					<input id="x-title" class="input" maxlength="80" placeholder="예: 2학기 중간고사" bind:value={form.title} required />
				</div>
				<div class="field">
					<label for="x-start">{form.type === 'mock' ? '시험일' : '시작일'}</label>
					<input id="x-start" class="input" type="date" bind:value={form.start_date} required />
				</div>
				{#if form.type !== 'mock'}
					<div class="field">
						<label for="x-end">종료일</label>
						<input id="x-end" class="input" type="date" min={form.start_date} bind:value={form.end_date} required />
					</div>
				{/if}
			</div>
			<div class="field">
				<label for="x-memo">메모 (선택)</label>
				<textarea id="x-memo" class="input" maxlength="2000" bind:value={form.memo}></textarea>
			</div>
			<button class="btn btn-primary" disabled={busy}>시험 만들기</button>
		</form>
	{/if}

	<div class="tabs" role="tablist" aria-label="시험 종류">
		{#each [['all', '전체'], ['midterm', '중간고사'], ['final', '기말고사'], ['mock', '모의고사']] as [id, label] (id)}
			<button role="tab" class="tab" class:on={tab === id} aria-selected={tab === id} onclick={() => (tab = id)}>{label}</button>
		{/each}
	</div>

	{#if next && (tab === 'all' || tab === next.type)}
		<a class="hero" href={resolve('/exams/[id]', { id: String(next.id) })}>
			<div class="grow stack" style="gap: 6px;">
				<span class="small">{EXAM_TYPES[next.type]} · {range(next)}</span>
				<strong class="hero-title">{next.title}</strong>
				<div class="bar" role="progressbar" aria-valuenow={next.progress ?? 0} aria-valuemin="0" aria-valuemax="100" aria-label="범위 진도"><span style:width="{next.progress ?? 0}%"></span></div>
				<span class="small">과목 {next.subject_count}개 · 범위 진도 {next.progress ?? 0}%</span>
			</div>
			<span class="dday">{next.status === 'ongoing' ? '시험 중' : fmtDday(next.d_day)}</span>
		</a>
	{/if}

	{#if !loaded}
		<p class="muted">불러오는 중…</p>
	{:else if shown.length === 0}
		<p class="empty">등록된 시험이 없어요. 위에서 시험을 추가해 보세요.</p>
	{:else}
		<ul class="list">
			{#each shown as e (e.id)}
				<li>
					<a class="card item" href={resolve('/exams/[id]', { id: String(e.id) })}>
						<div class="grow stack" style="gap: 2px;">
							<span class="muted small">{EXAM_TYPES[e.type]} · {range(e)}</span>
							<strong>{e.title}</strong>
							<span class="muted small">과목 {e.subject_count}개{e.progress !== null ? ` · 범위 진도 ${e.progress}%` : ''}</span>
						</div>
						<span class="badge" class:done={e.status === 'done'}>{e.status === 'done' ? '종료' : e.status === 'ongoing' ? '시험 중' : fmtDday(e.d_day)}</span>
					</a>
				</li>
			{/each}
		</ul>
	{/if}

	{#if shownResults.length > 0}
		<section class="card stack" aria-label="성적">
			<h2>성적 기록</h2>
			<div class="scroll">
				<table>
					<thead><tr><th scope="col">날짜</th><th scope="col">시험</th><th scope="col">과목</th><th scope="col">목표</th><th scope="col">결과</th></tr></thead>
					<tbody>
						{#each shownResults as r (r.exam_id + '-' + r.subject_name)}
							<tr><td>{fmtMD(r.exam_date)}</td><td>{r.exam_title}</td><td>{r.subject_name}</td><td>{target(r)}</td><td><strong>{score(r)}</strong></td></tr>
						{/each}
					</tbody>
				</table>
			</div>
		</section>
	{/if}
</div>

<style>
	.page {
		gap: 18px;
		max-width: 900px;
	}
	.grid2 {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 12px;
	}
	.tabs {
		display: flex;
		background: #e9e7e0;
		padding: 4px;
		border-radius: 12px;
		gap: 4px;
	}
	.tab {
		flex: 1;
		min-height: 38px;
		border: 0;
		border-radius: 9px;
		background: none;
		color: var(--muted);
		cursor: pointer;
	}
	.tab.on {
		background: #fff;
		color: var(--ink);
		font-weight: 700;
	}
	.hero {
		display: flex;
		align-items: center;
		gap: 20px;
		background: var(--pastel);
		color: #173f2e;
		border-radius: 18px;
		padding: 20px 24px;
		text-decoration: none;
	}
	.hero-title {
		font-size: 20px;
	}
	.dday {
		font-size: 40px;
		font-weight: 700;
	}
	.bar {
		height: 8px;
		border-radius: 4px;
		background: rgba(255, 255, 255, 0.7);
	}
	.bar span {
		display: block;
		height: 8px;
		border-radius: 4px;
		background: var(--accent);
	}
	.list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.item {
		display: flex;
		align-items: center;
		gap: 12px;
		text-decoration: none;
		color: var(--ink);
	}
	.badge {
		padding: 4px 12px;
		border-radius: 999px;
		background: var(--accent);
		color: #fff;
		font-weight: 700;
		font-size: 14px;
		white-space: nowrap;
	}
	.badge.done {
		background: #e9e7e0;
		color: var(--muted);
	}
	.scroll {
		overflow-x: auto;
	}
	table {
		width: 100%;
		border-collapse: collapse;
		font-size: 14px;
	}
	th,
	td {
		text-align: left;
		padding: 8px 10px;
		border-bottom: 1px solid var(--line);
		white-space: nowrap;
	}
	th {
		color: var(--muted);
		font-weight: 500;
		font-size: 13px;
	}
	@media (max-width: 600px) {
		.grid2 {
			grid-template-columns: minmax(0, 1fr);
		}
		.dday {
			font-size: 30px;
		}
	}
</style>
