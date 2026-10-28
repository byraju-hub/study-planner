<script>
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { resolve } from '$app/paths';
	import { get } from '#lib/api.js';
	import { QUESTION_TYPES } from '#lib/format.js';
	import QuizTabs from '#lib/components/QuizTabs.svelte';

	let notes = $state([]);
	let subjects = $state([]);
	let subjectId = $state('');
	let loaded = $state(false);
	let error = $state('');
	let openId = $state(null);

	async function load() {
		try {
			notes = await get('/wrong-notes' + (subjectId ? `?subject_id=${subjectId}` : ''));
		} catch (e) {
			error = e.message;
		}
		loaded = true;
	}

	onMount(async () => {
		subjects = await get('/subjects').catch(() => []);
		await load();
	});

	function retry() {
		goto(resolve('/quiz') + `?mode=wrong${subjectId ? `&subject_id=${subjectId}` : ''}`);
	}
</script>

<svelte:head><title>오답노트 · 스터디 플래너</title></svelte:head>

<div class="stack" style="gap: 20px; max-width: 720px;">
	<h1>오답노트</h1>
	<QuizTabs current="wrong" />
	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	<div class="row">
		<select class="input grow" bind:value={subjectId} onchange={load} aria-label="과목">
			<option value="">전체 과목</option>
			{#each subjects as s (s.id)}<option value={s.id}>{s.name}</option>{/each}
		</select>
		<button class="btn btn-primary" onclick={retry}>다시 풀기</button>
	</div>

	{#if loaded && notes.length === 0}
		<p class="empty">틀린 문제가 없어요. 퀴즈에서 틀리면 여기에 모이고, 다시 맞히면 자동으로 빠져요.</p>
	{:else}
		<ul class="list">
			{#each notes as n (n.id)}
				<li class="card stack" style="gap: 8px;">
					<div class="row-between">
						<span class="muted small">{n.subject_name}{n.unit_title ? ' · ' + n.unit_title : ''} · {QUESTION_TYPES[n.type]}</span>
						<span class="chip">{n.wrong_count}번 틀림</span>
					</div>
					<p class="prompt">{n.prompt}</p>
					<button class="btn btn-small" onclick={() => (openId = openId === n.id ? null : n.id)}>{openId === n.id ? '정답 가리기' : '정답 보기'}</button>
					{#if openId === n.id}
						<p><span class="muted small">정답</span><br /><strong>{n.answer_text}</strong></p>
						{#if n.last_given && n.type !== 'essay' && n.type !== 'card'}<p class="muted small">내가 쓴 답: {n.last_given}</p>{/if}
						{#if n.explanation}<p class="muted">{n.explanation}</p>{/if}
					{/if}
				</li>
			{/each}
		</ul>
	{/if}
</div>

<style>
	.list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 12px;
	}
	.prompt {
		white-space: pre-wrap;
		margin: 0;
		line-height: 1.6;
	}
</style>
