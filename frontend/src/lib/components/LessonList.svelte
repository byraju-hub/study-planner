<script>
	import { untrack } from 'svelte';
	import { resolve } from '$app/paths';
	import { put } from '#lib/api.js';
	import SubjectChip from './SubjectChip.svelte';

	let { lessons, date, onchange = () => {} } = $props();

	let error = $state('');
	/** 교시 → 입력 중인 배운 내용 */
	let topics = $state({});

	// 목록이 바뀌어도 입력 중인 글은 유지한다. (topics를 읽는 부분은 의존성에서 뺀다)
	$effect(() => {
		const list = lessons;
		const prev = untrack(() => topics);
		const next = {};
		for (const l of list) next[l.period] = prev[l.period] ?? l.topic ?? '';
		topics = next;
	});

	async function save(l, done) {
		error = '';
		try {
			await put('/study/lessons', { date, period: l.period, topic: topics[l.period] || null, done });
			await onchange();
		} catch (e) {
			error = e.message;
			await onchange();
		}
	}
</script>

<section class="card stack" aria-label="오늘 수업 복습">
	<div class="row-between">
		<h2>오늘 수업 → 당일 복습</h2>
		<a class="small" href={resolve('/timetable')}>시간표 설정</a>
	</div>
	<p class="muted small">수업이 끝나면 배운 내용을 한 줄로 적고 복습을 마쳐요. 내일부터 1·3·7·14·30일 간격으로 다시 복습하도록 알려 줘요.</p>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	{#if lessons.length === 0}
		<p class="empty">오늘은 시간표에 수업이 없어요. <a href={resolve('/timetable')}>시간표</a>를 입력하면 수업별 복습을 할 수 있어요.</p>
	{:else}
		<ul class="list">
			{#each lessons as l (l.period)}
				<li class="item">
					<label class="check">
						<input type="checkbox" checked={l.done} onchange={(e) => save(l, e.currentTarget.checked)} aria-label="{l.period}교시 {l.subject_name} 복습 완료" />
						<span class="period">{l.period}교시</span>
					</label>
					<SubjectChip name={l.subject_name} color={l.subject_color} />
					<label class="sr-only" for="topic-{l.period}">{l.period}교시 배운 내용</label>
					<input
						id="topic-{l.period}"
						class="input grow"
						placeholder="배운 내용 (예: 나머지정리)"
						maxlength="120"
						bind:value={topics[l.period]}
						onchange={() => (l.done || topics[l.period] !== (l.topic ?? '')) && save(l, l.done)}
					/>
					{#if l.done}<span class="ok small">복습 완료</span>{/if}
				</li>
			{/each}
		</ul>
	{/if}
</section>

<style>
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
		gap: 10px;
		flex-wrap: wrap;
	}
	.check {
		display: flex;
		align-items: center;
		gap: 10px;
		min-height: 44px;
		cursor: pointer;
	}
	.check input {
		width: 20px;
		height: 20px;
		accent-color: var(--accent);
		margin: 0;
	}
	.period {
		width: 44px;
		font-size: 14px;
		color: var(--muted);
	}
	.item .input {
		min-width: 160px;
	}
	.ok {
		color: var(--accent-dark);
		font-weight: 700;
	}
</style>
