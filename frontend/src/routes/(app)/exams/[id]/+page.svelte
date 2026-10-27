<script>
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { resolve } from '$app/paths';
	import { page } from '$app/state';
	import { get, post, put, del } from '#lib/api.js';
	import { EXAM_TYPES, fmtDday, fmtMD } from '#lib/format.js';
	import ExamSubjectCard from '#lib/components/ExamSubjectCard.svelte';
	import ExamPlanPanel from '#lib/components/ExamPlanPanel.svelte';

	const examId = $derived(Number(page.params.id));

	let exam = $state(null);
	let subjects = $state([]);
	let notFound = $state(false);
	let error = $state('');
	let editing = $state(false);
	let form = $state({ title: '', start_date: '', end_date: '', memo: '' });
	let adding = $state(false);
	let add = $state({ subject_id: '', exam_date: '', period: '', target_score: '', target_grade: '' });

	async function reload() {
		try {
			exam = await get(`/exams/${examId}`);
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
		await reload();
	});

	const free = $derived(subjects.filter((s) => !exam?.subjects.some((x) => x.subject_id === s.id)));
	const num = (v) => (v === '' || v === null ? null : Number(v));

	function startEdit() {
		form = { title: exam.title, start_date: exam.start_date, end_date: exam.end_date, memo: exam.memo ?? '' };
		editing = true;
	}

	async function saveExam(event) {
		event.preventDefault();
		error = '';
		try {
			exam = await put(`/exams/${examId}`, { ...form, memo: form.memo || null });
			editing = false;
		} catch (e) {
			error = e.message;
		}
	}

	async function removeExam() {
		if (!confirm(`'${exam.title}' 시험을 삭제할까요?\n과목, 범위, 대비 계획 할 일도 모두 삭제돼요.`)) return;
		try {
			await del(`/exams/${examId}`);
			goto(resolve('/exams'));
		} catch (e) {
			error = e.message;
		}
	}

	function openAdd() {
		adding = !adding;
		add = { subject_id: '', exam_date: exam.start_date, period: '', target_score: '', target_grade: '' };
	}

	async function addSubject(event) {
		event.preventDefault();
		error = '';
		try {
			exam = await post(`/exams/${examId}/subjects`, {
				subject_id: Number(add.subject_id),
				exam_date: add.exam_date,
				period: num(add.period),
				target_score: num(add.target_score),
				target_grade: num(add.target_grade)
			});
			adding = false;
		} catch (e) {
			error = e.message;
		}
	}
</script>

<svelte:head><title>{exam?.title ?? '시험'} · 스터디 플래너</title></svelte:head>

<div class="stack page">
	<header class="stack" style="gap: 6px;">
		<a class="muted small" href={resolve('/exams')}>← 시험 대비</a>
		{#if notFound}
			<h1>찾을 수 없는 시험이에요</h1>
		{:else if exam}
			<div class="head">
				<div class="grow stack" style="gap: 2px;">
					<span class="muted small">{EXAM_TYPES[exam.type]} · {exam.start_date === exam.end_date ? fmtMD(exam.start_date) : `${fmtMD(exam.start_date)} – ${fmtMD(exam.end_date)}`}</span>
					<h1>{exam.title}</h1>
				</div>
				<span class="dday">{exam.status === 'done' ? '종료' : exam.status === 'ongoing' ? '시험 중' : fmtDday(exam.d_day)}</span>
			</div>
			<div class="row">
				<button class="btn btn-small" onclick={startEdit}>시험 정보 수정</button>
				<button class="btn btn-small btn-danger" onclick={removeExam}>시험 삭제</button>
			</div>
			{#if exam.memo && !editing}<p class="memo muted">{exam.memo}</p>{/if}
		{/if}
	</header>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	{#if exam}
		{#if editing}
			<form class="card stack" onsubmit={saveExam}>
				<h2>시험 정보</h2>
				<div class="field">
					<label for="e-title">시험 이름</label>
					<input id="e-title" class="input" maxlength="80" bind:value={form.title} required />
				</div>
				<div class="row wrap">
					<div class="field grow">
						<label for="e-start">시작일</label>
						<input id="e-start" class="input" type="date" bind:value={form.start_date} required />
					</div>
					<div class="field grow">
						<label for="e-end">종료일</label>
						<input id="e-end" class="input" type="date" min={form.start_date} bind:value={form.end_date} required />
					</div>
				</div>
				<div class="field">
					<label for="e-memo">메모</label>
					<textarea id="e-memo" class="input" maxlength="2000" bind:value={form.memo}></textarea>
				</div>
				<div class="row">
					<button class="btn btn-primary btn-small">저장</button>
					<button type="button" class="btn btn-small" onclick={() => (editing = false)}>취소</button>
				</div>
			</form>
		{/if}

		<div class="row-between">
			<h2>시험 과목</h2>
			<button class="btn btn-small btn-soft" onclick={openAdd} aria-expanded={adding} disabled={free.length === 0 && !adding}>{adding ? '닫기' : '+ 과목 추가'}</button>
		</div>

		{#if adding}
			<form class="card stack" onsubmit={addSubject}>
				<div class="grid">
					<div class="field">
						<label for="a-subject">과목</label>
						<select id="a-subject" class="input" bind:value={add.subject_id} required>
							<option value="">선택</option>
							{#each free as s (s.id)}<option value={s.id}>{s.name}</option>{/each}
						</select>
					</div>
					<div class="field">
						<label for="a-date">시험일</label>
						<input id="a-date" class="input" type="date" min={exam.start_date} max={exam.end_date} bind:value={add.exam_date} required />
					</div>
					<div class="field">
						<label for="a-period">교시</label>
						<input id="a-period" class="input" type="number" min="1" max="10" bind:value={add.period} />
					</div>
					<div class="field">
						<label for="a-ts">목표 점수</label>
						<input id="a-ts" class="input" type="number" min="0" max="100" bind:value={add.target_score} />
					</div>
					<div class="field">
						<label for="a-tg">목표 등급</label>
						<input id="a-tg" class="input" type="number" min="1" max="9" bind:value={add.target_grade} />
					</div>
				</div>
				<button class="btn btn-primary btn-small">과목 추가</button>
			</form>
		{/if}

		{#if exam.subjects.length === 0}
			<p class="empty">시험 볼 과목을 추가해 주세요. 과목마다 시험일과 범위를 입력할 수 있어요.</p>
		{:else}
			<div class="stack" style="gap: 14px;">
				{#each exam.subjects as s (s.id)}
					<ExamSubjectCard {exam} subject={s} onchange={reload} />
				{/each}
			</div>
		{/if}

		<ExamPlanPanel {exam} onchange={reload} />
	{:else if !notFound && !error}
		<p class="muted">불러오는 중…</p>
	{/if}
</div>

<style>
	.page {
		gap: 18px;
		max-width: 900px;
	}
	.head {
		display: flex;
		align-items: center;
		gap: 16px;
	}
	.dday {
		font-size: 36px;
		font-weight: 700;
		color: var(--accent-dark);
		white-space: nowrap;
	}
	.memo {
		white-space: pre-wrap;
	}
	.wrap {
		flex-wrap: wrap;
	}
	.grid {
		display: grid;
		grid-template-columns: repeat(5, minmax(0, 1fr));
		gap: 10px;
	}
	@media (max-width: 800px) {
		.grid {
			grid-template-columns: repeat(2, minmax(0, 1fr));
		}
		.dday {
			font-size: 28px;
		}
	}
</style>
