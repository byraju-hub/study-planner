<script>
	import { post, put, del } from '#lib/api.js';
	import { addDays, fmtDate, fmtMD } from '#lib/format.js';
	import SubjectChip from './SubjectChip.svelte';

	// date: 이 목록이 보여 주는 날. today: 서버 기준 오늘(빠른 날짜 버튼의 기준). 달력에서는 date가 고른 날이다.
	let { tasks, date, today = date, subjects, onchange = () => {} } = $props();

	let title = $state('');
	let subjectId = $state('');
	let minutes = $state('');
	let error = $state('');
	let notice = $state('');
	let addDate = $state('');
	$effect(() => {
		addDate = date;
	});

	const isToday = $derived(date === today);
	const heading = $derived(isToday ? '오늘 할 일' : `${fmtDate(date)} 할 일`);
	const quick = $derived([
		{ label: '오늘', ymd: today },
		{ label: '내일', ymd: addDays(today, 1) },
		{ label: '모레', ymd: addDays(today, 2) }
	]);

	const doneCount = $derived(tasks.filter((t) => t.done).length);

	async function add(event) {
		event.preventDefault();
		error = '';
		notice = '';
		try {
			await post('/tasks', {
				title,
				task_date: addDate,
				subject_id: subjectId ? Number(subjectId) : null,
				est_minutes: minutes ? Number(minutes) : null
			});
			if (addDate !== date) notice = `${fmtMD(addDate)}에 추가했어요.`;
			title = '';
			minutes = '';
			await onchange();
		} catch (e) {
			error = e.message;
		}
	}

	async function toggle(t, checked) {
		error = '';
		try {
			await post(`/tasks/${t.id}/toggle`, { done: checked });
			await onchange();
		} catch (e) {
			error = e.message;
			await onchange();
		}
	}

	async function remove(t) {
		error = '';
		try {
			await del(`/tasks/${t.id}`);
			await onchange();
		} catch (e) {
			error = e.message;
		}
	}

	async function postpone(t) {
		error = '';
		try {
			const [y, m, d] = t.task_date.split('-').map(Number);
			const next = new Date(Date.UTC(y, m - 1, d + 1)).toISOString().slice(0, 10);
			await put(`/tasks/${t.id}`, { task_date: next });
			await onchange();
		} catch (e) {
			error = e.message;
		}
	}
</script>

<section class="card stack" aria-label={heading}>
	<div class="row-between">
		<h2>{heading} <span class="count">{doneCount}/{tasks.length}</span></h2>
	</div>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	{#if tasks.length === 0}
		<p class="empty">{isToday ? '오늘' : '이 날'} 할 일이 없어요. 아래에서 추가해 보세요.</p>
	{:else}
		<ul class="list">
			{#each tasks as t (t.id)}
				<li class="item">
					<label class="check grow">
						<input type="checkbox" checked={t.done} onchange={(e) => toggle(t, e.currentTarget.checked)} />
						<span class:done={t.done} class="title">{t.title}</span>
					</label>
					{#if t.est_minutes}<span class="muted small">{t.est_minutes}분</span>{/if}
					<SubjectChip name={t.subject_name} color={t.subject_color} />
					{#if t.source !== 'study'}<span class="chip">{t.source === 'exam' ? '시험' : t.source === 'assessment' ? '수행' : '복습'}</span>{/if}
					{#if !t.done}<button class="btn btn-small" onclick={() => postpone(t)} aria-label="{t.title} 내일로 미루기">내일로</button>{/if}
					<button class="btn btn-small btn-danger" onclick={() => remove(t)} aria-label="{t.title} 삭제">삭제</button>
				</li>
			{/each}
		</ul>
	{/if}

	{#if notice}<p class="notice" role="status">{notice}</p>{/if}

	<form class="add" onsubmit={add}>
		<label class="sr-only" for="task-title">할 일</label>
		<input id="task-title" class="input grow" placeholder="할 일 추가" maxlength="120" bind:value={title} required />
		<label class="sr-only" for="task-subject">과목</label>
		<select id="task-subject" class="input sel" bind:value={subjectId}>
			<option value="">과목</option>
			{#each subjects as s (s.id)}<option value={s.id}>{s.name}</option>{/each}
		</select>
		<label class="sr-only" for="task-min">예상 시간(분)</label>
		<input id="task-min" class="input min" type="number" min="1" max="600" placeholder="분" bind:value={minutes} />
		<div class="when">
			<label class="sr-only" for="task-date">날짜</label>
			<input id="task-date" class="input date" type="date" bind:value={addDate} required />
			{#each quick as q (q.label)}
				<button type="button" class="btn btn-small" class:btn-soft={addDate === q.ymd} onclick={() => (addDate = q.ymd)}>{q.label}</button>
			{/each}
		</div>
		<button class="btn btn-primary">추가</button>
	</form>
</section>

<style>
	.count {
		color: var(--accent);
	}
	.list {
		list-style: none;
		margin: 0;
		padding: 0;
	}
	.item {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		min-height: 48px;
		border-bottom: 1px solid var(--line);
	}
	.item:last-child {
		border-bottom: 0;
	}
	.check {
		display: flex;
		align-items: center;
		gap: 12px;
		cursor: pointer;
		min-height: 44px;
	}
	.check input {
		width: 20px;
		height: 20px;
		accent-color: var(--accent);
		margin: 0;
	}
	.title {
		overflow-wrap: anywhere;
	}
	.title.done {
		color: var(--muted);
		text-decoration: line-through;
	}
	.add {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
	}
	.sel {
		width: 120px;
	}
	.when {
		display: flex;
		align-items: center;
		gap: 6px;
		flex-wrap: wrap;
	}
	.date {
		width: 150px;
	}
	.notice {
		color: var(--accent-dark);
		font-size: 13px;
	}
	.min {
		width: 76px;
	}
	@media (max-width: 600px) {
		.add .grow {
			flex: 1 1 100%;
		}
		.sel {
			flex: 1;
		}
	}
</style>
