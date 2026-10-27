<script>
	import { get, post, put, del } from '#lib/api.js';
	import { fmtDday, fmtMD } from '#lib/format.js';
	import SubjectChip from './SubjectChip.svelte';

	let { exam, subject, onchange = async () => {} } = $props();

	let error = $state('');
	let editing = $state(false);
	let form = $state({ exam_date: '', period: '', target_score: '', target_grade: '' });
	let result = $state({ actual_score: '', actual_grade: '' });
	let resultMsg = $state('');

	/** 범위 추가 방식: null | 'units' | 'manual' */
	let addMode = $state(null);
	let textbooks = $state([]);
	let picked = $state([]);
	let loadingUnits = $state(false);
	let manual = $state({ label: '', page_start: '', page_end: '' });

	const addedUnitIds = $derived(subject.scopes.map((s) => s.unit_id).filter((id) => id !== null));

	$effect(() => {
		result = {
			actual_score: subject.actual_score === null ? '' : String(subject.actual_score),
			actual_grade: subject.actual_grade === null ? '' : String(subject.actual_grade)
		};
	});

	const num = (v) => (v === '' || v === null ? null : Number(v));

	async function run(fn) {
		error = '';
		try {
			await fn();
			await onchange();
		} catch (e) {
			error = e.message;
		}
	}

	function startEdit() {
		form = {
			exam_date: subject.exam_date,
			period: subject.period === null ? '' : String(subject.period),
			target_score: subject.target_score === null ? '' : String(subject.target_score),
			target_grade: subject.target_grade === null ? '' : String(subject.target_grade)
		};
		editing = true;
	}

	const saveInfo = (event) => {
		event.preventDefault();
		return run(async () => {
			await put(`/exam-subjects/${subject.id}`, {
				exam_date: form.exam_date,
				period: num(form.period),
				target_score: num(form.target_score),
				target_grade: num(form.target_grade)
			});
			editing = false;
		});
	};

	const saveResult = (event) => {
		event.preventDefault();
		resultMsg = '';
		return run(async () => {
			await put(`/exam-subjects/${subject.id}`, { actual_score: num(result.actual_score), actual_grade: num(result.actual_grade) });
			resultMsg = '결과를 저장했어요.';
		});
	};

	const removeSubject = () => {
		if (!confirm(`'${subject.subject_name}' 과목을 이 시험에서 뺄까요?\n범위와 대비 계획도 함께 삭제돼요.`)) return;
		return run(() => del(`/exam-subjects/${subject.id}`));
	};

	async function openUnits() {
		addMode = addMode === 'units' ? null : 'units';
		picked = [];
		if (addMode !== 'units') return;
		loadingUnits = true;
		error = '';
		try {
			const books = await get(`/textbooks?subject_id=${subject.subject_id}`);
			textbooks = await Promise.all(
				books.map(async (b) => {
					const units = await get(`/units?textbook_id=${b.id}`);
					const tree = units
						.filter((u) => u.parent_id === null)
						.flatMap((p) => [{ ...p, child: false }, ...units.filter((c) => c.parent_id === p.id).map((c) => ({ ...c, child: true }))]);
					return { ...b, units: tree };
				})
			);
		} catch (e) {
			error = e.message;
		}
		loadingUnits = false;
	}

	const addUnits = (event) => {
		event.preventDefault();
		return run(async () => {
			await post(`/exam-subjects/${subject.id}/scopes`, { unit_ids: picked });
			picked = [];
			addMode = null;
		});
	};

	const addManual = (event) => {
		event.preventDefault();
		return run(async () => {
			await post(`/exam-subjects/${subject.id}/scopes`, { label: manual.label, page_start: num(manual.page_start), page_end: num(manual.page_end) });
			manual = { label: '', page_start: '', page_end: '' };
			addMode = null;
		});
	};

	const setProgress = (scope, value) => run(() => put(`/exam-scopes/${scope.id}`, { progress: Number(value) }));
	const removeScope = (scope) => run(() => del(`/exam-scopes/${scope.id}`));

	const pages = (s) => (s.page_start ? `p.${s.page_start}${s.page_end ? `–${s.page_end}` : ''}` : '');
	const goal = $derived([subject.target_score !== null ? `${subject.target_score}점` : null, subject.target_grade !== null ? `${subject.target_grade}등급` : null].filter(Boolean).join(' · '));
</script>

<section class="card stack" aria-label="{subject.subject_name} 시험">
	<div class="row-between wrap">
		<div class="row wrap">
			<SubjectChip name={subject.subject_name} color={subject.subject_color} />
			<strong>{fmtMD(subject.exam_date)}{subject.period ? ` ${subject.period}교시` : ''}</strong>
			<span class="muted small">{subject.d_day >= 0 ? fmtDday(subject.d_day) : '지남'}</span>
			{#if goal}<span class="muted small">목표 {goal}</span>{/if}
		</div>
		<div class="row">
			<button class="btn btn-small" onclick={startEdit}>수정</button>
			<button class="btn btn-small btn-danger" onclick={removeSubject} aria-label="{subject.subject_name} 과목 빼기">빼기</button>
		</div>
	</div>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	{#if editing}
		<form class="stack form" onsubmit={saveInfo}>
			<div class="grid">
				<div class="field">
					<label for="es-date-{subject.id}">시험일</label>
					<input id="es-date-{subject.id}" class="input" type="date" min={exam.start_date} max={exam.end_date} bind:value={form.exam_date} required />
				</div>
				<div class="field">
					<label for="es-period-{subject.id}">교시</label>
					<input id="es-period-{subject.id}" class="input" type="number" min="1" max="10" bind:value={form.period} />
				</div>
				<div class="field">
					<label for="es-ts-{subject.id}">목표 점수</label>
					<input id="es-ts-{subject.id}" class="input" type="number" min="0" max="100" bind:value={form.target_score} />
				</div>
				<div class="field">
					<label for="es-tg-{subject.id}">목표 등급</label>
					<input id="es-tg-{subject.id}" class="input" type="number" min="1" max="9" bind:value={form.target_grade} />
				</div>
			</div>
			<div class="row">
				<button class="btn btn-primary btn-small">저장</button>
				<button type="button" class="btn btn-small" onclick={() => (editing = false)}>취소</button>
			</div>
		</form>
	{/if}

	<div class="row">
		<div class="bar grow" role="progressbar" aria-valuenow={subject.progress ?? 0} aria-valuemin="0" aria-valuemax="100" aria-label="{subject.subject_name} 범위 진도"><span style:width="{subject.progress ?? 0}%" style:background={subject.subject_color}></span></div>
		<strong class="small">{subject.progress ?? 0}%</strong>
		{#if subject.task_total > 0}<span class="muted small">계획 {subject.task_done}/{subject.task_total}</span>{/if}
	</div>

	<div class="stack tight">
		<div class="row-between">
			<h3>시험 범위</h3>
			<div class="row">
				<button class="btn btn-small btn-soft" onclick={openUnits} aria-expanded={addMode === 'units'}>교재에서 고르기</button>
				<button class="btn btn-small" onclick={() => (addMode = addMode === 'manual' ? null : 'manual')} aria-expanded={addMode === 'manual'}>직접 입력</button>
			</div>
		</div>

		{#if addMode === 'units'}
			<form class="stack form" onsubmit={addUnits}>
				{#if loadingUnits}
					<p class="muted small">불러오는 중…</p>
				{:else if textbooks.length === 0}
					<p class="muted small">이 과목에 등록된 교재가 없어요. 과목 · 교재에서 교재와 단원을 먼저 입력하거나 직접 입력해 주세요.</p>
				{:else}
					{#each textbooks as b (b.id)}
						<fieldset class="units">
							<legend class="label">{b.title}</legend>
							{#if b.units.length === 0}<p class="muted small">단원이 없어요.</p>{/if}
							{#each b.units as u (u.id)}
								<label class="unit" class:child={u.child}>
									<input type="checkbox" value={u.id} bind:group={picked} disabled={addedUnitIds.includes(u.id)} checked={addedUnitIds.includes(u.id)} />
									<span>{u.title}</span>
									{#if pages(u)}<span class="muted small">{pages(u)}</span>{/if}
									{#if addedUnitIds.includes(u.id)}<span class="muted small">(추가됨)</span>{/if}
								</label>
							{/each}
						</fieldset>
					{/each}
					<button class="btn btn-primary btn-small" disabled={picked.length === 0}>선택한 단원 {picked.length}개 추가</button>
				{/if}
			</form>
		{:else if addMode === 'manual'}
			<form class="stack form" onsubmit={addManual}>
				<div class="field">
					<label for="sc-label-{subject.id}">범위 이름</label>
					<input id="sc-label-{subject.id}" class="input" maxlength="120" placeholder="예: 문학 1~2단원" bind:value={manual.label} required />
				</div>
				<div class="row">
					<div class="field grow">
						<label for="sc-s-{subject.id}">시작 쪽</label>
						<input id="sc-s-{subject.id}" class="input" type="number" min="1" max="5000" bind:value={manual.page_start} />
					</div>
					<div class="field grow">
						<label for="sc-e-{subject.id}">끝 쪽</label>
						<input id="sc-e-{subject.id}" class="input" type="number" min="1" max="5000" bind:value={manual.page_end} />
					</div>
				</div>
				<button class="btn btn-primary btn-small">범위 추가</button>
			</form>
		{/if}

		{#if subject.scopes.length === 0}
			<p class="empty">시험 범위를 추가하면 범위에 맞춰 대비 계획을 짜 줘요.</p>
		{:else}
			<ul class="scopes">
				{#each subject.scopes as sc (sc.id)}
					<li class="scope">
						<div class="row wrap">
							<span class="grow name">{sc.label}</span>
							{#if pages(sc)}<span class="muted small">{pages(sc)}</span>{/if}
							<button class="btn btn-small btn-danger" onclick={() => removeScope(sc)} aria-label="{sc.label} 범위 삭제">삭제</button>
						</div>
						<div class="row">
							<label class="sr-only" for="pg-{sc.id}">{sc.label} 진도</label>
							<input id="pg-{sc.id}" class="range grow" type="range" min="0" max="100" step="5" value={sc.progress} onchange={(e) => setProgress(sc, e.currentTarget.value)} />
							<output class="pct small">{sc.progress}%</output>
						</div>
					</li>
				{/each}
			</ul>
		{/if}
	</div>

	<form class="row wrap result" onsubmit={saveResult}>
		<span class="label">시험 결과</span>
		<label class="sr-only" for="ra-{subject.id}">실제 점수</label>
		<input id="ra-{subject.id}" class="input num" type="number" min="0" max="100" placeholder="점수" bind:value={result.actual_score} />
		<label class="sr-only" for="rg-{subject.id}">실제 등급</label>
		<input id="rg-{subject.id}" class="input num" type="number" min="1" max="9" placeholder="등급" bind:value={result.actual_grade} />
		<button class="btn btn-small">저장</button>
		{#if resultMsg}<span class="chip" role="status">{resultMsg}</span>{/if}
	</form>
</section>

<style>
	h3 {
		font-size: 15px;
	}
	.wrap {
		flex-wrap: wrap;
	}
	.tight {
		gap: 8px;
	}
	.form {
		background: var(--bg);
		border-radius: 12px;
		padding: 12px;
	}
	.grid {
		display: grid;
		grid-template-columns: repeat(4, minmax(0, 1fr));
		gap: 10px;
	}
	.bar {
		height: 8px;
		border-radius: 4px;
		background: #efede6;
	}
	.bar span {
		display: block;
		height: 8px;
		border-radius: 4px;
	}
	.units {
		border: 0;
		padding: 0;
		margin: 0;
		display: flex;
		flex-direction: column;
	}
	.unit {
		display: flex;
		align-items: center;
		gap: 8px;
		min-height: 40px;
		cursor: pointer;
	}
	.unit.child {
		padding-left: 24px;
	}
	.unit input {
		width: 18px;
		height: 18px;
		accent-color: var(--accent);
		margin: 0;
	}
	.scopes {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.scope {
		border: 1px solid var(--line);
		border-radius: 12px;
		padding: 8px 12px;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}
	.name {
		font-weight: 500;
		overflow-wrap: anywhere;
	}
	.range {
		accent-color: var(--accent);
		min-height: 32px;
	}
	.pct {
		width: 40px;
		text-align: right;
	}
	.result {
		border-top: 1px solid var(--line);
		padding-top: 10px;
	}
	.num {
		width: 90px;
		min-height: 38px;
	}
	@media (max-width: 600px) {
		.grid {
			grid-template-columns: repeat(2, minmax(0, 1fr));
		}
	}
</style>
