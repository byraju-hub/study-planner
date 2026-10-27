<script>
	import { onMount } from 'svelte';
	import { resolve } from '$app/paths';
	import { auth } from '#lib/auth.svelte.js';
	import { get } from '#lib/api.js';

	import { fmtDate, fmtMinutes } from '#lib/format.js';

	let subjects = $state([]);
	let study = $state(null);
	let error = $state('');

	onMount(async () => {
		try {
			[subjects, study] = await Promise.all([get('/subjects'), get('/study/today')]);
		} catch (e) {
			error = e.message;
		}
	});

	const doneTasks = $derived(study ? study.tasks.filter((t) => t.done).length : 0);
</script>

<svelte:head><title>홈 · 스터디 플래너</title></svelte:head>

<div class="stack" style="gap: 20px; max-width: 900px;">
	<header>
		<p class="muted small">{study ? fmtDate(study.date) : ''}</p>
		<h1>안녕하세요, {auth.user.name}님</h1>
	</header>

	{#if error}<p class="alert">{error}</p>{/if}

	{#if study}
		<a class="today card" href={resolve('/study')}>
			<div class="stat"><span class="muted small">오늘 할 일</span><strong>{doneTasks}/{study.tasks.length}</strong></div>
			<div class="stat"><span class="muted small">오늘 복습</span><strong>{study.reviews_due.length}개</strong></div>
			<div class="stat"><span class="muted small">오늘 공부시간</span><strong>{fmtMinutes(study.minutes_today)}</strong></div>
			<span class="go">일반 스터디로 →</span>
		</a>
	{/if}

	<section class="card stack">
		<h2>내 과목</h2>
		{#if subjects.length === 0}
			<p class="empty">과목이 없어요. <a href={resolve('/subjects')}>과목 · 교재</a>에서 추가해 보세요.</p>
		{:else}
			<div class="grid">
				{#each subjects as s (s.id)}
					<a class="subject" href={resolve('/subjects/[id]', { id: String(s.id) })}>
						<span class="dot" style:background={s.color}></span>
						<span class="grow">{s.name}</span>
						<span class="muted small">교재 {s.textbook_count}</span>
					</a>
				{/each}
			</div>
		{/if}
	</section>

	<section class="card stack">
		<h2>시작하기</h2>
		<ol class="steps muted">
			<li><strong>과목 · 교재</strong>에서 교과서와 문제집을 등록하세요.</li>
			<li>교재마다 단원(목차)을 입력하고, 단원별로 메모·링크·사진 자료를 모아 두세요.</li>
			<li><strong>일반 스터디</strong>에서 오늘 할 일, 수업 복습, 공부 타이머를 써 보세요. (시간표를 먼저 입력해요)</li>
			<li>시험 대비, 수행평가, 퀴즈는 이어서 만들어요.</li>
		</ol>
	</section>
</div>

<style>
	.today {
		display: flex;
		align-items: center;
		gap: 28px;
		flex-wrap: wrap;
		text-decoration: none;
		color: var(--ink);
		background: var(--pastel);
		border-color: transparent;
	}
	.stat {
		display: flex;
		flex-direction: column;
	}
	.stat strong {
		font-size: 24px;
		color: #173f2e;
	}
	.go {
		margin-left: auto;
		font-weight: 700;
		color: var(--accent-dark);
	}
	.grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
		gap: 10px;
	}
	.subject {
		display: flex;
		align-items: center;
		gap: 10px;
		min-height: 48px;
		padding: 0 14px;
		border: 1px solid var(--line);
		border-radius: 12px;
		text-decoration: none;
		color: var(--ink);
		background: var(--surface);
	}
	.subject:hover {
		border-color: var(--accent);
	}
	.steps {
		margin: 0;
		padding-left: 20px;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
</style>
