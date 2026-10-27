<script>
	import { onMount } from 'svelte';
	import { resolve } from '$app/paths';
	import { auth } from '#lib/auth.svelte.js';
	import { get } from '#lib/api.js';

	let subjects = $state([]);
	let error = $state('');

	onMount(async () => {
		try {
			subjects = await get('/subjects');
		} catch (e) {
			error = e.message;
		}
	});

	const today = new Date().toLocaleDateString('ko-KR', { month: 'long', day: 'numeric', weekday: 'long' });
</script>

<svelte:head><title>홈 · 스터디 플래너</title></svelte:head>

<div class="stack" style="gap: 20px; max-width: 900px;">
	<header>
		<p class="muted small">{today}</p>
		<h1>안녕하세요, {auth.user.name}님</h1>
	</header>

	{#if error}<p class="alert">{error}</p>{/if}

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
			<li>시험 대비, 수행평가, 일반 스터디, 퀴즈는 다음 단계에서 이어서 만들어요.</li>
		</ol>
	</section>
</div>

<style>
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
