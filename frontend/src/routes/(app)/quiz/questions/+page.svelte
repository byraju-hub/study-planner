<script>
	import { onMount } from 'svelte';
	import { page } from '$app/state';
	import { get, post, put, del } from '#lib/api.js';
	import { QUESTION_TYPES } from '#lib/format.js';
	import QuizTabs from '#lib/components/QuizTabs.svelte';
	import UnitPicker from '#lib/components/UnitPicker.svelte';
	import AiQuestions from '#lib/components/AiQuestions.svelte';

	const blank = () => ({ type: 'mcq', prompt: '', choices: ['', '', '', ''], answer: '0', explanation: '', short: '' });

	let subjects = $state([]);
	let subjectId = $state('');
	let unitId = $state('');
	let questions = $state([]);
	let f = $state(blank());
	let editingId = $state(null);
	let error = $state('');
	let msg = $state('');

	async function load() {
		const q = new URLSearchParams();
		if (subjectId) q.set('subject_id', subjectId);
		if (unitId) q.set('unit_id', unitId);
		questions = await get('/questions?' + q);
	}

	onMount(async () => {
		try {
			subjects = await get('/subjects');
			const q = page.url.searchParams;
			if (q.get('subject_id')) subjectId = Number(q.get('subject_id'));
			if (q.get('unit_id')) unitId = Number(q.get('unit_id'));
			await load();
		} catch (e) {
			error = e.message;
		}
	});

	function payload() {
		const base = { type: f.type, prompt: f.prompt, explanation: f.explanation || null, unit_id: unitId || null, subject_id: subjectId || null };
		if (f.type === 'mcq') return { ...base, choices: f.choices.filter((c) => c.trim() !== ''), answer: f.answer };
		if (f.type === 'ox') return { ...base, answer: f.answer === 'X' ? 'X' : 'O' };
		return { ...base, answer: f.answer };
	}

	function setType(t) {
		f.type = t;
		f.answer = t === 'mcq' ? '0' : t === 'ox' ? 'O' : '';
	}

	async function save(event) {
		event.preventDefault();
		error = msg = '';
		if (!subjectId && !unitId) {
			error = '과목을 먼저 골라 주세요.';
			return;
		}
		try {
			if (editingId) await put(`/questions/${editingId}`, payload());
			else await post('/questions', payload());
			msg = editingId ? '수정했어요.' : '문제를 추가했어요.';
			editingId = null;
			f = { ...blank(), type: f.type, answer: f.type === 'mcq' ? '0' : f.type === 'ox' ? 'O' : '' };
			await load();
		} catch (e) {
			error = e.message;
		}
	}

	function edit(q) {
		editingId = q.id;
		subjectId = q.subject_id;
		unitId = q.unit_id ?? '';
		f = {
			type: q.type,
			prompt: q.prompt,
			choices: q.type === 'mcq' ? [...q.choices, ...Array(Math.max(0, 4 - q.choices.length)).fill('')] : ['', '', '', ''],
			answer: q.answer,
			explanation: q.explanation ?? ''
		};
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	async function remove(q) {
		if (!confirm('이 문제를 삭제할까요?')) return;
		try {
			await del(`/questions/${q.id}`);
			await load();
		} catch (e) {
			error = e.message;
		}
	}
</script>

<svelte:head><title>문제 만들기 · 스터디 플래너</title></svelte:head>

<div class="stack" style="gap: 20px; max-width: 720px;">
	<h1>문제 만들기</h1>
	<QuizTabs current="bank" />
	{#if error}<p class="alert" role="alert">{error}</p>{/if}
	{#if msg}<p class="chip" role="status">{msg}</p>{/if}

	<form class="card stack" onsubmit={save}>
		<h2>{editingId ? '문제 수정' : '새 문제'}</h2>
		<UnitPicker {subjects} bind:subjectId bind:unitId />
		<div class="types" role="radiogroup" aria-label="문제 형식">
			{#each Object.entries(QUESTION_TYPES) as [t, label] (t)}
				<button type="button" class="type" class:on={f.type === t} role="radio" aria-checked={f.type === t} onclick={() => setType(t)}>{label}</button>
			{/each}
		</div>
		<div class="field">
			<label for="prompt">{f.type === 'card' ? '카드 앞면' : '문제'}</label>
			<textarea id="prompt" class="input" bind:value={f.prompt} required maxlength="2000"></textarea>
		</div>

		{#if f.type === 'mcq'}
			<div class="stack" style="gap: 8px;">
				<span class="label">보기 (정답을 선택하세요)</span>
				{#each f.choices as _, i (i)}
					<div class="row">
						<input type="radio" name="correct" value={String(i)} bind:group={f.answer} aria-label="{i + 1}번을 정답으로" />
						<input class="input grow" placeholder="{i + 1}번 보기" bind:value={f.choices[i]} />
					</div>
				{/each}
				<button type="button" class="btn btn-small" onclick={() => f.choices.length < 6 && f.choices.push('')}>보기 추가</button>
			</div>
		{:else if f.type === 'ox'}
			<div class="row">
				<label class="check"><input type="radio" value="O" bind:group={f.answer} /> 정답 O</label>
				<label class="check"><input type="radio" value="X" bind:group={f.answer} /> 정답 X</label>
			</div>
		{:else if f.type === 'short'}
			<div class="field">
				<label for="ans">정답</label>
				<input id="ans" class="input" bind:value={f.answer} required />
				<span class="hint">정답이 여러 가지면 | 로 구분해요. 예: 서울|Seoul</span>
			</div>
		{:else}
			<div class="field">
				<label for="ans">{f.type === 'card' ? '카드 뒷면' : '모범 답안'}</label>
				<textarea id="ans" class="input" bind:value={f.answer} required maxlength="4000"></textarea>
			</div>
		{/if}

		<div class="field">
			<label for="exp">해설 (선택)</label>
			<input id="exp" class="input" bind:value={f.explanation} maxlength="2000" />
		</div>
		<div class="row">
			<button class="btn btn-primary">{editingId ? '수정 저장' : '문제 추가'}</button>
			{#if editingId}<button type="button" class="btn" onclick={() => { editingId = null; f = blank(); }}>취소</button>{/if}
		</div>
		<p class="hint">교재 본문이나 문제집 문제를 그대로 옮기기보다, 내가 이해한 내용으로 문제를 만들어 보세요. 만든 문제는 나만 볼 수 있어요.</p>
	</form>

	<AiQuestions {subjectId} {unitId} onsaved={load} />

	<section class="stack">
		<h2>내 문제 {questions.length}개</h2>
		{#if questions.length === 0}
			<p class="empty">이 범위에는 아직 문제가 없어요.</p>
		{:else}
			<ul class="list">
				{#each questions as q (q.id)}
					<li class="card q">
						<div class="grow">
							<span class="chip">{QUESTION_TYPES[q.type]}</span>{#if q.source === 'ai'} <span class="chip">AI</span>{/if}
							<p class="prompt">{q.prompt}</p>
						</div>
						<div class="row">
							<button class="btn btn-small" onclick={() => edit(q)}>수정</button>
							<button class="btn btn-small btn-danger" onclick={() => remove(q)}>삭제</button>
						</div>
					</li>
				{/each}
			</ul>
		{/if}
	</section>
</div>

<style>
	.types {
		display: flex;
		gap: 6px;
		flex-wrap: wrap;
	}
	.type {
		min-height: 38px;
		padding: 0 14px;
		border-radius: 19px;
		border: 1px solid var(--line);
		background: var(--surface);
		cursor: pointer;
		color: var(--ink);
	}
	.type.on {
		background: var(--accent);
		border-color: var(--accent);
		color: #fff;
		font-weight: 700;
	}
	.list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.q {
		display: flex;
		justify-content: space-between;
		gap: 12px;
		align-items: flex-start;
		flex-wrap: wrap;
	}
	.prompt {
		margin: 6px 0 0;
		white-space: pre-wrap;
	}
	.check {
		display: flex;
		align-items: center;
		gap: 8px;
		min-height: 40px;
	}
</style>
