<script>
	import { get, post, put, del } from '#lib/api.js';

	// liveUnitCount: 선택한 교재의 단원 수. 단원을 추가·삭제해도 목록을 다시 불러오지 않고 바로 반영한다.
	let { subjectId, selectedId = $bindable(null), selectedTitle = $bindable(''), liveUnitCount = null } = $props();

	const KINDS = { textbook: '교과서', guide: '참고서·자습서', workbook: '문제집', academy: '학원 교재', etc: '기타' };
	const blank = () => ({ title: '', kind: 'textbook', publisher: '', total_pages: '' });

	let items = $state([]);
	let loaded = $state(false);
	let error = $state('');
	let showForm = $state(false);
	let form = $state(blank());
	let editingId = $state(null);
	let edit = $state(blank());

	const payload = (f) => ({
		title: f.title,
		kind: f.kind,
		publisher: f.publisher || null,
		total_pages: f.total_pages ? Number(f.total_pages) : null
	});

	async function load(id) {
		try {
			items = await get(`/textbooks?subject_id=${id}`);
			error = '';
			if (!items.some((t) => t.id === selectedId)) selectedId = items[0]?.id ?? null;
		} catch (e) {
			error = e.message;
		}
		loaded = true;
	}

	$effect(() => {
		const id = subjectId;
		loaded = false;
		selectedId = null;
		showForm = false;
		editingId = null;
		load(id);
	});

	$effect(() => {
		selectedTitle = items.find((t) => t.id === selectedId)?.title ?? '';
	});

	async function add(event) {
		event.preventDefault();
		error = '';
		try {
			const t = await post('/textbooks', { subject_id: subjectId, ...payload(form) });
			items = [...items, t];
			selectedId = t.id;
			form = blank();
			showForm = false;
		} catch (e) {
			error = e.message;
		}
	}

	function startEdit(t) {
		editingId = t.id;
		edit = { title: t.title, kind: t.kind, publisher: t.publisher ?? '', total_pages: t.total_pages ? String(t.total_pages) : '' };
	}

	async function save(event, t) {
		event.preventDefault();
		error = '';
		try {
			const u = await put(`/textbooks/${t.id}`, payload(edit));
			items = items.map((x) => (x.id === t.id ? { ...x, ...u } : x));
			editingId = null;
		} catch (e) {
			error = e.message;
		}
	}

	async function remove(t) {
		if (!confirm(`'${t.title}' 교재를 삭제할까요?\n안의 단원과 자료도 모두 삭제돼요.`)) return;
		error = '';
		try {
			await del(`/textbooks/${t.id}`);
			items = items.filter((x) => x.id !== t.id);
			if (selectedId === t.id) selectedId = items[0]?.id ?? null;
		} catch (e) {
			error = e.message;
		}
	}
</script>

<section class="card stack" aria-label="교재">
	<div class="row-between">
		<h2>교재</h2>
		<button class="btn btn-small btn-soft" onclick={() => (showForm = !showForm)} aria-expanded={showForm}>
			{showForm ? '닫기' : '+ 교재'}
		</button>
	</div>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	{#if showForm}
		<form class="stack form" onsubmit={add}>
			<div class="field">
				<label for="tb-title">교재 이름</label>
				<input id="tb-title" class="input" maxlength="120" bind:value={form.title} required />
			</div>
			<div class="row">
				<div class="field grow">
					<label for="tb-kind">종류</label>
					<select id="tb-kind" class="input" bind:value={form.kind}>
						{#each Object.entries(KINDS) as [value, label] (value)}<option {value}>{label}</option>{/each}
					</select>
				</div>
				<div class="field grow">
					<label for="tb-pages">전체 쪽수</label>
					<input id="tb-pages" class="input" type="number" min="1" max="5000" bind:value={form.total_pages} />
				</div>
			</div>
			<div class="field">
				<label for="tb-pub">출판사</label>
				<input id="tb-pub" class="input" maxlength="60" bind:value={form.publisher} />
			</div>
			<button class="btn btn-primary">교재 추가</button>
		</form>
	{/if}

	{#if !loaded}
		<p class="muted small">불러오는 중…</p>
	{:else if items.length === 0}
		<p class="empty">교재를 추가해 주세요.</p>
	{:else}
		<ul class="list">
			{#each items as t (t.id)}
				<li class="item" class:selected={selectedId === t.id}>
					{#if editingId === t.id}
						<form class="stack grow" onsubmit={(e) => save(e, t)}>
							<label class="sr-only" for="e-title">교재 이름</label>
							<input id="e-title" class="input" maxlength="120" bind:value={edit.title} required />
							<div class="row">
								<label class="sr-only" for="e-kind">종류</label>
								<select id="e-kind" class="input grow" bind:value={edit.kind}>
									{#each Object.entries(KINDS) as [value, label] (value)}<option {value}>{label}</option>{/each}
								</select>
								<label class="sr-only" for="e-pages">전체 쪽수</label>
								<input id="e-pages" class="input grow" type="number" min="1" max="5000" placeholder="쪽수" bind:value={edit.total_pages} />
							</div>
							<label class="sr-only" for="e-pub">출판사</label>
							<input id="e-pub" class="input" maxlength="60" placeholder="출판사" bind:value={edit.publisher} />
							<div class="row">
								<button class="btn btn-primary btn-small">저장</button>
								<button type="button" class="btn btn-small" onclick={() => (editingId = null)}>취소</button>
							</div>
						</form>
					{:else}
						<button class="pick grow" onclick={() => (selectedId = t.id)} aria-pressed={selectedId === t.id}>
							<span class="title">{t.title}</span>
							<span class="muted small">{KINDS[t.kind]}{t.publisher ? ` · ${t.publisher}` : ''}{t.total_pages ? ` · ${t.total_pages}쪽` : ''} · 단원 {selectedId === t.id && liveUnitCount !== null ? liveUnitCount : t.unit_count}</span>
						</button>
						<button class="btn btn-small" onclick={() => startEdit(t)} aria-label="{t.title} 수정">수정</button>
						<button class="btn btn-small btn-danger" onclick={() => remove(t)} aria-label="{t.title} 삭제">삭제</button>
					{/if}
				</li>
			{/each}
		</ul>
	{/if}
</section>

<style>
	.form {
		background: var(--bg);
		border-radius: 12px;
		padding: 12px;
	}
	.list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.item {
		display: flex;
		align-items: center;
		gap: 6px;
		border: 1px solid var(--line);
		border-radius: 12px;
		padding: 6px 8px;
	}
	.item.selected {
		border-color: var(--accent);
		background: var(--accent-soft);
	}
	.pick {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 2px;
		text-align: left;
		background: none;
		border: 0;
		padding: 6px;
		cursor: pointer;
		min-width: 0;
	}
	.title {
		font-weight: 700;
		overflow-wrap: anywhere;
	}
</style>
