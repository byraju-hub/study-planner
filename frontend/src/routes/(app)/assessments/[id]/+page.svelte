<script>
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { resolve } from '$app/paths';
	import { page } from '$app/state';
	import { get, post, put, del } from '#lib/api.js';
	import { ASSESS_KINDS, ASSESS_STATUS, dueLabel, fmtMD } from '#lib/format.js';
	import SubjectChip from '#lib/components/SubjectChip.svelte';

	const id = $derived(Number(page.params.id));

	let a = $state(null);
	let subjects = $state([]);
	let notFound = $state(false);
	let error = $state('');
	let message = $state('');
	let form = $state(null);
	let newStep = $state({ title: '', target_date: '' });

	function fill(data) {
		a = data;
		form = {
			subject_id: String(data.subject_id),
			title: data.title,
			kind: data.kind,
			max_score: data.max_score === null ? '' : String(data.max_score),
			weight_pct: data.weight_pct === null ? '' : String(data.weight_pct),
			due_date: data.due_date,
			rubric: data.rubric ?? '',
			status: data.status,
			score: data.score === null ? '' : String(Number(data.score))
		};
	}

	async function load() {
		try {
			fill(await get(`/assessments/${id}`));
			error = '';
		} catch (e) {
			if (e.status === 404) notFound = true;
			else error = e.message;
		}
	}

	onMount(async () => {
		try {
			subjects = await get('/subjects');
		} catch (e) {
			error = e.message;
		}
		await load();
	});

	const num = (v) => (v === '' || v === null ? null : Number(v));

	async function run(fn, okMessage = '') {
		error = message = '';
		try {
			await fn();
			if (okMessage) message = okMessage;
		} catch (e) {
			error = e.message;
		}
	}

	const save = (event) => {
		event.preventDefault();
		return run(async () => {
			fill(
				await put(`/assessments/${id}`, {
					subject_id: Number(form.subject_id),
					title: form.title,
					kind: form.kind,
					max_score: num(form.max_score),
					weight_pct: num(form.weight_pct),
					due_date: form.due_date,
					rubric: form.rubric || null,
					status: form.status,
					score: num(form.score)
				})
			);
		}, '저장했어요.');
	};

	const setStatus = (status) => run(async () => fill(await put(`/assessments/${id}`, { status })));

	const toggle = (s, checked) => run(async () => fill(await post(`/assessment-steps/${s.id}/toggle`, { done: checked })));
	const saveStep = (s, patch) => run(async () => fill(await put(`/assessment-steps/${s.id}`, patch)));
	const removeStep = (s) => run(async () => fill(await del(`/assessment-steps/${s.id}`)));

	const addStep = (event) => {
		event.preventDefault();
		return run(async () => {
			fill(await post(`/assessments/${id}/steps`, { title: newStep.title, target_date: newStep.target_date || null }));
			newStep = { title: '', target_date: '' };
		});
	};

	async function removeAll() {
		if (!confirm(`'${a.title}' 수행평가를 삭제할까요?\n단계와 오늘 할 일에 들어간 계획도 함께 삭제돼요.`)) return;
		try {
			await del(`/assessments/${id}`);
			goto(resolve('/assessments'));
		} catch (e) {
			error = e.message;
		}
	}

	const finished = $derived(a ? a.status === 'submitted' || a.status === 'graded' : false);
	const doneCount = $derived(a ? a.steps.filter((s) => s.done).length : 0);
</script>

<svelte:head><title>{a?.title ?? '수행평가'} · 스터디 플래너</title></svelte:head>

<div class="stack page">
	<header class="stack" style="gap: 6px;">
		<a class="muted small" href={resolve('/assessments')}>← 수행평가</a>
		{#if notFound}
			<h1>찾을 수 없는 수행평가예요</h1>
		{:else if a}
			<div class="row wrap">
				<SubjectChip name={a.subject_name} color={a.subject_color} />
				<span class="muted small">{a.kind_label} · {fmtMD(a.due_date)} 마감</span>
			</div>
			<div class="head">
				<h1 class="grow">{a.title}</h1>
				<span class="dday" class:urgent={!finished && a.d_day <= 3}>{dueLabel(a)}</span>
			</div>
		{/if}
	</header>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}
	{#if message}<p class="chip msg" role="status">{message}</p>{/if}

	{#if a && form}
		{#if a.exam_warning}<p class="warn">{a.exam_warning}</p>{/if}

		<div class="row wrap">
			{#if a.status === 'planned'}<button class="btn btn-small btn-soft" onclick={() => setStatus('in_progress')}>시작했어요</button>{/if}
			{#if a.status === 'planned' || a.status === 'in_progress'}<button class="btn btn-small btn-primary" onclick={() => setStatus('submitted')}>제출했어요</button>{/if}
			{#if a.status === 'submitted'}<button class="btn btn-small" onclick={() => setStatus('in_progress')}>제출 취소</button>{/if}
			<span class="muted small">상태: <strong>{ASSESS_STATUS[a.status]}</strong></span>
		</div>

		<section class="card stack" aria-label="진행 단계">
			<div class="row-between">
				<h2>진행 단계</h2>
				<span class="muted small">{doneCount}/{a.steps.length} 완료</span>
			</div>
			<p class="muted small">목표일을 정한 단계는 그날 "오늘 할 일"에 자동으로 나타나요. 할 일에서 체크해도 여기에 반영돼요.</p>
			{#if a.steps.length === 0}<p class="empty">단계를 추가해 보세요.</p>{/if}
			<ul class="steps">
				{#each a.steps as s (s.id)}
					<li class="step">
						<label class="row grow check">
							<input type="checkbox" checked={s.done} onchange={(e) => toggle(s, e.currentTarget.checked)} />
							<span class:done={s.done}>{s.title}</span>
						</label>
						<label class="sr-only" for="sd-{s.id}">{s.title} 목표일</label>
						<input id="sd-{s.id}" class="input date" type="date" value={s.target_date ?? ''} onchange={(e) => saveStep(s, { target_date: e.currentTarget.value || null })} />
						<button class="btn btn-small btn-danger" onclick={() => removeStep(s)} aria-label="{s.title} 단계 삭제">삭제</button>
					</li>
				{/each}
			</ul>
			<form class="row wrap add" onsubmit={addStep}>
				<label class="sr-only" for="ns-title">새 단계 이름</label>
				<input id="ns-title" class="input grow" maxlength="80" placeholder="단계 추가 (예: 리허설)" bind:value={newStep.title} required />
				<label class="sr-only" for="ns-date">목표일</label>
				<input id="ns-date" class="input date" type="date" bind:value={newStep.target_date} />
				<button class="btn">추가</button>
			</form>
		</section>

		<form class="card stack" onsubmit={save}>
			<h2>정보</h2>
			<div class="grid">
				<div class="field wide">
					<label for="d-title">이름</label>
					<input id="d-title" class="input" maxlength="120" bind:value={form.title} required />
				</div>
				<div class="field">
					<label for="d-subject">과목</label>
					<select id="d-subject" class="input" bind:value={form.subject_id}>
						{#each subjects as s (s.id)}<option value={String(s.id)}>{s.name}</option>{/each}
					</select>
				</div>
				<div class="field">
					<label for="d-kind">종류</label>
					<select id="d-kind" class="input" bind:value={form.kind}>
						{#each Object.entries(ASSESS_KINDS) as [value, label] (value)}<option {value}>{label}</option>{/each}
					</select>
				</div>
				<div class="field">
					<label for="d-due">마감일</label>
					<input id="d-due" class="input" type="date" bind:value={form.due_date} required />
				</div>
				<div class="field">
					<label for="d-status">상태</label>
					<select id="d-status" class="input" bind:value={form.status}>
						{#each Object.entries(ASSESS_STATUS) as [value, label] (value)}<option {value}>{label}</option>{/each}
					</select>
				</div>
				<div class="field">
					<label for="d-max">배점</label>
					<input id="d-max" class="input" type="number" min="1" max="1000" bind:value={form.max_score} />
				</div>
				<div class="field">
					<label for="d-weight">반영 비율 (%)</label>
					<input id="d-weight" class="input" type="number" min="0" max="100" bind:value={form.weight_pct} />
				</div>
				<div class="field">
					<label for="d-score">받은 점수</label>
					<input id="d-score" class="input" type="number" min="0" max="1000" step="0.5" bind:value={form.score} />
				</div>
			</div>
			<div class="field">
				<label for="d-rubric">평가 기준</label>
				<textarea id="d-rubric" class="input" maxlength="5000" bind:value={form.rubric}></textarea>
			</div>
			<button class="btn btn-primary">저장</button>
		</form>

		<button class="btn btn-danger" onclick={removeAll}>수행평가 삭제</button>
	{:else if !notFound && !error}
		<p class="muted">불러오는 중…</p>
	{/if}
</div>

<style>
	.page {
		gap: 16px;
		max-width: 800px;
	}
	.wrap {
		flex-wrap: wrap;
	}
	.head {
		display: flex;
		align-items: center;
		gap: 12px;
	}
	.dday {
		font-size: 28px;
		font-weight: 700;
		color: var(--accent-dark);
		white-space: nowrap;
	}
	.dday.urgent {
		color: var(--danger);
	}
	.msg {
		align-self: flex-start;
	}
	.warn {
		background: var(--warn-soft);
		color: #5c3d00;
		border-radius: 12px;
		padding: 10px 14px;
	}
	.steps {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
	}
	.step {
		display: flex;
		align-items: center;
		gap: 8px;
		flex-wrap: wrap;
		min-height: 52px;
		border-bottom: 1px solid var(--line);
	}
	.step:last-child {
		border-bottom: 0;
	}
	.check {
		cursor: pointer;
		min-height: 44px;
	}
	.check input {
		width: 20px;
		height: 20px;
		accent-color: var(--accent);
		margin: 0;
	}
	.done {
		color: var(--muted);
		text-decoration: line-through;
	}
	.date {
		width: 150px;
		min-height: 40px;
	}
	.grid {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 12px;
	}
	.wide {
		grid-column: span 3;
	}
	@media (max-width: 700px) {
		.grid {
			grid-template-columns: repeat(2, minmax(0, 1fr));
		}
		.wide {
			grid-column: span 2;
		}
	}
</style>
