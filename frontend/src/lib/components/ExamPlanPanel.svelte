<script>
	import { get, post } from '#lib/api.js';
	import { fmtMD, fmtMinutes } from '#lib/format.js';
	import SubjectChip from './SubjectChip.svelte';

	/** exam: 시험 상세(과목 포함). onchange: 계획을 확정한 뒤 상세를 다시 불러오는 함수 */
	let { exam, onchange = async () => {} } = $props();

	let preview = $state(null);
	let items = $state([]);
	let tasks = $state([]);
	let error = $state('');
	let message = $state('');
	let busy = $state(false);

	const colorOf = (id) => exam.subjects.find((s) => s.id === id)?.subject_color;
	const examDateOf = (id) => exam.subjects.find((s) => s.id === id)?.exam_date;
	const lastDayOf = (id) => {
		const d = examDateOf(id);
		if (!d) return undefined;
		const [y, m, day] = d.split('-').map(Number);
		return new Date(Date.UTC(y, m - 1, day - 1)).toISOString().slice(0, 10);
	};

	async function loadTasks() {
		try {
			tasks = await get(`/exams/${exam.id}/tasks`);
		} catch (e) {
			error = e.message;
		}
	}

	$effect(() => {
		exam.id;
		exam.subjects.map((s) => s.task_done + ':' + s.task_total);
		loadTasks();
	});

	async function makePreview() {
		error = message = '';
		busy = true;
		try {
			preview = await post(`/exams/${exam.id}/plan/preview`);
			items = preview.items.map((it, i) => ({ ...it, key: i }));
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}

	async function apply() {
		error = message = '';
		busy = true;
		try {
			const res = await post(`/exams/${exam.id}/plan/apply`, {
				items: items.map(({ exam_subject_id, title, task_date, est_minutes }) => ({ exam_subject_id, title, task_date, est_minutes }))
			});
			message = `할 일 ${res.created}개를 만들었어요.${res.kept_done > 0 ? ` (이미 끝낸 ${res.kept_done}개는 그대로 뒀어요)` : ''}`;
			preview = null;
			items = [];
			await onchange();
			await loadTasks();
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}

	async function toggle(t, checked) {
		error = '';
		try {
			await post(`/tasks/${t.id}/toggle`, { done: checked });
			await onchange();
			await loadTasks();
		} catch (e) {
			error = e.message;
		}
	}

	const remove = (key) => (items = items.filter((i) => i.key !== key));

	/** 날짜별로 묶기 */
	function byDate(list) {
		const map = new Map();
		for (const it of [...list].sort((a, b) => a.task_date.localeCompare(b.task_date))) {
			if (!map.has(it.task_date)) map.set(it.task_date, []);
			map.get(it.task_date).push(it);
		}
		return [...map.entries()];
	}

	const previewDays = $derived(byDate(items));
	const taskDays = $derived(byDate(tasks));
	const doneCount = $derived(tasks.filter((t) => t.done).length);
	const canPlan = $derived(exam.subjects.length > 0 && exam.status === 'upcoming');
</script>

<section class="card stack" aria-label="대비 계획">
	<div class="row-between wrap">
		<div class="stack tight">
			<h2>대비 계획</h2>
			<p class="muted small">오늘부터 시험 전날까지를 1회독 → 문제풀이 → 총정리로 나눠 하루 할 일을 만들어 줘요. 확정하기 전에 고칠 수 있어요.</p>
		</div>
		<button class="btn btn-primary" onclick={makePreview} disabled={busy || !canPlan}>{tasks.length > 0 ? '계획 다시 만들기' : '계획 만들기'}</button>
	</div>

	{#if exam.subjects.length === 0}
		<p class="muted small">시험 과목을 먼저 추가해 주세요.</p>
	{:else if exam.status !== 'upcoming'}
		<p class="muted small">이미 시작했거나 끝난 시험이라 새 계획은 만들 수 없어요.</p>
	{/if}

	{#if error}<p class="alert" role="alert">{error}</p>{/if}
	{#if message}<p class="chip msg" role="status">{message}</p>{/if}

	{#if preview}
		<div class="stack preview" aria-label="계획 미리보기">
			<div class="row-between wrap">
				<strong>미리보기 · 할 일 {items.length}개</strong>
				<div class="row">
					<button class="btn btn-small" onclick={() => (preview = null)}>취소</button>
					<button class="btn btn-small btn-primary" onclick={apply} disabled={busy || items.length === 0}>이대로 확정</button>
				</div>
			</div>
			{#each preview.skipped as sk (sk.exam_subject_id)}
				<p class="alert">{sk.subject_name}: {sk.reason} 이 과목은 계획에서 뺐어요.</p>
			{/each}
			{#if items.length === 0}<p class="muted small">만들 계획이 없어요.</p>{/if}
			{#each previewDays as [date, list] (date)}
				<div class="day">
					<div class="row-between">
						<strong>{fmtMD(date)}</strong>
						<span class="muted small">{fmtMinutes(list.reduce((a, i) => a + (i.est_minutes ?? 0), 0))}</span>
					</div>
					{#each list as it (it.key)}
						<div class="row wrap item">
							<SubjectChip name={it.subject_name} color={colorOf(it.exam_subject_id)} />
							<span class="grow t">{it.title}</span>
							<label class="sr-only" for="pd-{it.key}">{it.title} 날짜</label>
							<input id="pd-{it.key}" class="input date" type="date" min={preview.today} max={lastDayOf(it.exam_subject_id)} bind:value={it.task_date} />
							<button class="btn btn-small btn-danger" onclick={() => remove(it.key)} aria-label="{it.title} 빼기">빼기</button>
						</div>
					{/each}
				</div>
			{/each}
		</div>
	{/if}

	{#if tasks.length > 0}
		<div class="stack" aria-label="확정된 계획">
			<div class="row-between">
				<strong>확정된 계획</strong>
				<span class="muted small">{doneCount}/{tasks.length} 완료</span>
			</div>
			{#each taskDays as [date, list] (date)}
				<div class="day">
					<strong class="small">{fmtMD(date)}</strong>
					{#each list as t (t.id)}
						<label class="row check">
							<input type="checkbox" checked={t.done} onchange={(e) => toggle(t, e.currentTarget.checked)} />
							<SubjectChip name={t.subject_name} color={t.subject_color} />
							<span class="grow t" class:done={t.done}>{t.title}</span>
							{#if t.est_minutes}<span class="muted small">{t.est_minutes}분</span>{/if}
						</label>
					{/each}
				</div>
			{/each}
		</div>
	{/if}
</section>

<style>
	.wrap {
		flex-wrap: wrap;
	}
	.tight {
		gap: 2px;
	}
	.msg {
		align-self: flex-start;
	}
	.preview {
		background: var(--bg);
		border-radius: 12px;
		padding: 12px;
	}
	.day {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 8px 0;
		border-top: 1px solid var(--line);
	}
	.item {
		gap: 8px;
	}
	.t {
		overflow-wrap: anywhere;
	}
	.t.done {
		color: var(--muted);
		text-decoration: line-through;
	}
	.date {
		width: 150px;
		min-height: 38px;
	}
	.check {
		min-height: 40px;
		cursor: pointer;
	}
	.check input {
		width: 18px;
		height: 18px;
		accent-color: var(--accent);
		margin: 0;
	}
</style>
