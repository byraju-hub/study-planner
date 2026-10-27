<script>
	import { onMount } from 'svelte';
	import { get, post } from '#lib/api.js';
	import { ASSESS_KINDS } from '#lib/format.js';
	import ModeTabs from '#lib/components/ModeTabs.svelte';
	import AssessmentCard from '#lib/components/AssessmentCard.svelte';

	let items = $state([]);
	let subjects = $state([]);
	let loaded = $state(false);
	let error = $state('');
	let showForm = $state(false);
	let busy = $state(false);
	let form = $state(blank());

	function blank() {
		return { subject_id: '', title: '', kind: 'report', max_score: '', weight_pct: '', due_date: '', rubric: '', auto_steps: true };
	}

	async function load() {
		try {
			[items, subjects] = await Promise.all([get('/assessments'), get('/subjects')]);
			error = '';
		} catch (e) {
			error = e.message;
		}
		loaded = true;
	}
	onMount(load);

	const columns = $derived([
		{ id: 'planned', label: '예정', list: items.filter((a) => a.status === 'planned') },
		{ id: 'in_progress', label: '진행중', list: items.filter((a) => a.status === 'in_progress') },
		{ id: 'done', label: '제출 · 채점 완료', list: items.filter((a) => a.status === 'submitted' || a.status === 'graded') }
	]);
	const warnings = $derived(items.filter((a) => a.exam_warning));
	const num = (v) => (v === '' || v === null ? null : Number(v));

	async function create(event) {
		event.preventDefault();
		error = '';
		busy = true;
		try {
			await post('/assessments', {
				subject_id: Number(form.subject_id),
				title: form.title,
				kind: form.kind,
				max_score: num(form.max_score),
				weight_pct: num(form.weight_pct),
				due_date: form.due_date,
				rubric: form.rubric || null,
				auto_steps: form.auto_steps
			});
			form = blank();
			showForm = false;
			await load();
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head><title>수행평가 · 스터디 플래너</title></svelte:head>

<div class="stack page">
	<header class="stack" style="gap: 10px;">
		<div class="row-between">
			<h1>수행평가</h1>
			<button class="btn btn-small btn-soft" onclick={() => (showForm = !showForm)} aria-expanded={showForm}>{showForm ? '닫기' : '+ 수행평가 추가'}</button>
		</div>
		<ModeTabs current="assess" />
	</header>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	{#if warnings.length > 0}
		<div class="warn" role="status">
			<strong>시험 기간 주의</strong>
			<ul>
				{#each warnings as a (a.id)}<li>{a.title}: {a.exam_warning}</li>{/each}
			</ul>
		</div>
	{/if}

	{#if showForm}
		<form class="card stack" onsubmit={create}>
			<h2>새 수행평가</h2>
			<div class="grid">
				<div class="field">
					<label for="n-subject">과목</label>
					<select id="n-subject" class="input" bind:value={form.subject_id} required>
						<option value="">선택</option>
						{#each subjects as s (s.id)}<option value={s.id}>{s.name}</option>{/each}
					</select>
				</div>
				<div class="field">
					<label for="n-kind">종류</label>
					<select id="n-kind" class="input" bind:value={form.kind}>
						{#each Object.entries(ASSESS_KINDS) as [value, label] (value)}<option {value}>{label}</option>{/each}
					</select>
				</div>
				<div class="field wide">
					<label for="n-title">이름</label>
					<input id="n-title" class="input" maxlength="120" placeholder="예: 모둠 발표" bind:value={form.title} required />
				</div>
				<div class="field">
					<label for="n-due">마감일</label>
					<input id="n-due" class="input" type="date" bind:value={form.due_date} required />
				</div>
				<div class="field">
					<label for="n-max">배점 (선택)</label>
					<input id="n-max" class="input" type="number" min="1" max="1000" bind:value={form.max_score} />
				</div>
				<div class="field">
					<label for="n-weight">반영 비율 % (선택)</label>
					<input id="n-weight" class="input" type="number" min="0" max="100" bind:value={form.weight_pct} />
				</div>
			</div>
			<div class="field">
				<label for="n-rubric">평가 기준 (선택)</label>
				<textarea id="n-rubric" class="input" maxlength="5000" placeholder="선생님이 알려 준 평가 기준을 적어 두세요" bind:value={form.rubric}></textarea>
			</div>
			<label class="row check">
				<input type="checkbox" bind:checked={form.auto_steps} />
				<span>종류에 맞는 기본 단계를 오늘부터 마감일까지 자동으로 만들기</span>
			</label>
			<button class="btn btn-primary" disabled={busy}>수행평가 만들기</button>
		</form>
	{/if}

	{#if !loaded}
		<p class="muted">불러오는 중…</p>
	{:else if items.length === 0}
		<p class="empty">등록된 수행평가가 없어요. 위에서 추가해 보세요.</p>
	{:else}
		<div class="board">
			{#each columns as col (col.id)}
				<section class="col" aria-label={col.label}>
					<h2 class="col-title">{col.label} <span class="muted small">{col.list.length}</span></h2>
					{#each col.list as a (a.id)}<AssessmentCard {a} />{/each}
					{#if col.list.length === 0}<p class="muted small none">없어요</p>{/if}
				</section>
			{/each}
		</div>
	{/if}
</div>

<style>
	.page {
		gap: 18px;
		max-width: 1200px;
	}
	.grid {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 12px;
	}
	.wide {
		grid-column: span 2;
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
	.warn {
		background: var(--warn-soft);
		color: #5c3d00;
		border-radius: 12px;
		padding: 12px 16px;
	}
	.warn ul {
		margin: 4px 0 0;
		padding-left: 18px;
	}
	.board {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 16px;
		align-items: start;
	}
	.col {
		display: flex;
		flex-direction: column;
		gap: 10px;
		background: #ece9e1;
		border-radius: 16px;
		padding: 12px;
	}
	.col-title {
		font-size: 15px;
		padding: 2px 4px;
	}
	.none {
		padding: 4px;
	}
	@media (max-width: 900px) {
		.board {
			grid-template-columns: minmax(0, 1fr);
		}
		.grid {
			grid-template-columns: repeat(2, minmax(0, 1fr));
		}
		.wide {
			grid-column: span 2;
		}
	}
</style>
