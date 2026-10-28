<script>
	import { get, post, put, del } from '#lib/api.js';
	import TocImport from './TocImport.svelte';

	let { textbookId, textbookTitle = '', selectedId = $bindable(null), version = 0, units = $bindable([]) } = $props();
	let importing = $state(false);

	let loaded = $state(false);
	let error = $state('');
	/** 열려 있는 추가 폼: 'root' | 대단원 id | null */
	let adding = $state(null);
	let form = $state({ title: '', page_start: '', page_end: '' });
	let editingId = $state(null);
	let edit = $state({ title: '', page_start: '', page_end: '' });

	const tree = $derived(
		units
			.filter((u) => u.parent_id === null)
			.map((p) => ({ ...p, children: units.filter((c) => c.parent_id === p.id) }))
	);

	const pages = (f) => ({
		title: f.title,
		page_start: f.page_start ? Number(f.page_start) : null,
		page_end: f.page_end ? Number(f.page_end) : null
	});
	const range = (u) => (u.page_start ? `p.${u.page_start}${u.page_end ? `–${u.page_end}` : ''}` : '');

	async function load(id) {
		try {
			units = await get(`/units?textbook_id=${id}`);
			error = '';
			if (selectedId !== null && !units.some((u) => u.id === selectedId)) selectedId = null;
		} catch (e) {
			error = e.message;
		}
		loaded = true;
	}

	$effect(() => {
		const id = textbookId;
		version;
		load(id);
	});

	$effect(() => {
		textbookId;
		adding = null;
		editingId = null;
	});

	function openAdd(where) {
		adding = adding === where ? null : where;
		form = { title: '', page_start: '', page_end: '' };
	}

	async function add(event) {
		event.preventDefault();
		error = '';
		try {
			const u = await post('/units', {
				textbook_id: textbookId,
				parent_id: adding === 'root' ? null : adding,
				...pages(form)
			});
			units = [...units, u];
			adding = null;
		} catch (e) {
			error = e.message;
		}
	}

	function startEdit(u) {
		editingId = u.id;
		edit = { title: u.title, page_start: u.page_start ? String(u.page_start) : '', page_end: u.page_end ? String(u.page_end) : '' };
	}

	async function save(event, u) {
		event.preventDefault();
		error = '';
		try {
			const r = await put(`/units/${u.id}`, pages(edit));
			units = units.map((x) => (x.id === u.id ? { ...x, ...r } : x));
			editingId = null;
		} catch (e) {
			error = e.message;
		}
	}

	/** 같은 부모 아래에서 위(-1)·아래(1)로 한 칸 옮긴다. 순서 번호를 1부터 다시 매겨 같은 번호가 있어도 옮겨진다. */
	async function move(u, dir) {
		const sibs = units.filter((x) => x.parent_id === u.parent_id).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
		const i = sibs.findIndex((x) => x.id === u.id);
		const j = i + dir;
		if (j < 0 || j >= sibs.length) return;
		[sibs[i], sibs[j]] = [sibs[j], sibs[i]];
		error = '';
		try {
			for (const [idx, x] of sibs.entries()) {
				if (x.sort_order !== idx + 1) await put(`/units/${x.id}`, { sort_order: idx + 1 });
			}
			units = units.map((x) => {
				const k = sibs.findIndex((s) => s.id === x.id);
				return k >= 0 ? { ...x, sort_order: k + 1 } : x;
			}).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
		} catch (e) {
			error = e.message;
			await load(textbookId);
		}
	}

	async function remove(u) {
		const extra = u.parent_id === null ? '\n안의 중단원과 자료도 모두 삭제돼요.' : '\n안의 자료도 모두 삭제돼요.';
		if (!confirm(`'${u.title}' 단원을 삭제할까요?${extra}`)) return;
		error = '';
		try {
			await del(`/units/${u.id}`);
			units = units.filter((x) => x.id !== u.id && x.parent_id !== u.id);
			if (selectedId === u.id) selectedId = null;
		} catch (e) {
			error = e.message;
		}
	}
</script>

{#snippet addForm()}
	<form class="stack form" onsubmit={add}>
		<div class="field">
			<label for="u-title">단원 이름</label>
			<input id="u-title" class="input" maxlength="120" bind:value={form.title} required />
		</div>
		<div class="row">
			<div class="field grow">
				<label for="u-start">시작 쪽</label>
				<input id="u-start" class="input" type="number" min="1" max="5000" bind:value={form.page_start} />
			</div>
			<div class="field grow">
				<label for="u-end">끝 쪽</label>
				<input id="u-end" class="input" type="number" min="1" max="5000" bind:value={form.page_end} />
			</div>
		</div>
		<div class="row">
			<button class="btn btn-primary btn-small">추가</button>
			<button type="button" class="btn btn-small" onclick={() => (adding = null)}>취소</button>
		</div>
	</form>
{/snippet}

{#snippet unitRow(u)}
	{#if editingId === u.id}
		<form class="stack form grow" onsubmit={(e) => save(e, u)}>
			<label class="sr-only" for="eu-title">단원 이름</label>
			<input id="eu-title" class="input" maxlength="120" bind:value={edit.title} required />
			<div class="row">
				<label class="sr-only" for="eu-start">시작 쪽</label>
				<input id="eu-start" class="input grow" type="number" min="1" max="5000" placeholder="시작 쪽" bind:value={edit.page_start} />
				<label class="sr-only" for="eu-end">끝 쪽</label>
				<input id="eu-end" class="input grow" type="number" min="1" max="5000" placeholder="끝 쪽" bind:value={edit.page_end} />
			</div>
			<div class="row">
				<button class="btn btn-primary btn-small">저장</button>
				<button type="button" class="btn btn-small" onclick={() => (editingId = null)}>취소</button>
			</div>
		</form>
	{:else}
		<button class="pick grow" onclick={() => (selectedId = u.id)} aria-pressed={selectedId === u.id}>
			<span class:big={u.parent_id === null}>{u.title}</span>
			{#if range(u)}<span class="muted small">{range(u)}</span>{/if}
			{#if u.material_count > 0}<span class="chip">자료 {u.material_count}</span>{/if}
		</button>
		<button class="btn btn-small" onclick={() => move(u, -1)} aria-label="{u.title} 위로">↑</button>
		<button class="btn btn-small" onclick={() => move(u, 1)} aria-label="{u.title} 아래로">↓</button>
		<button class="btn btn-small" onclick={() => startEdit(u)} aria-label="{u.title} 수정">수정</button>
		<button class="btn btn-small btn-danger" onclick={() => remove(u)} aria-label="{u.title} 삭제">삭제</button>
	{/if}
{/snippet}

<section class="card stack" aria-label="단원">
	<div class="row-between">
		<h2>단원 (목차)</h2>
		<div class="row">
			<button class="btn btn-small" onclick={() => (importing = !importing)} aria-expanded={importing}>서점 목차 가져오기</button>
			<button class="btn btn-small btn-soft" onclick={() => openAdd('root')} aria-expanded={adding === 'root'}>+ 대단원</button>
		</div>
	</div>
	{#if importing}
		<TocImport {textbookId} defaultQuery={textbookTitle} onclose={() => (importing = false)} onimported={() => { importing = false; load(textbookId); }} />
	{/if}

	{#if error}<p class="alert" role="alert">{error}</p>{/if}
	{#if adding === 'root'}{@render addForm()}{/if}

	{#if !loaded}
		<p class="muted small">불러오는 중…</p>
	{:else if tree.length === 0}
		<p class="empty">대단원부터 추가해 보세요. 자료는 단원마다 모아 둘 수 있어요.</p>
	{:else}
		<ul class="list">
			{#each tree as big (big.id)}
				<li class="group">
					<div class="item" class:selected={selectedId === big.id}>{@render unitRow(big)}</div>
					<ul class="list sub">
						{#each big.children as small (small.id)}
							<li class="item" class:selected={selectedId === small.id}>{@render unitRow(small)}</li>
						{/each}
					</ul>
					{#if adding === big.id}
						{@render addForm()}
					{:else}
						<button class="link-btn" onclick={() => openAdd(big.id)}>+ 중단원 추가</button>
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
	.sub {
		margin-left: 16px;
	}
	.group {
		display: flex;
		flex-direction: column;
		gap: 6px;
		padding-bottom: 8px;
		border-bottom: 1px solid var(--line);
	}
	.group:last-child {
		border-bottom: 0;
	}
	.item {
		display: flex;
		align-items: center;
		gap: 6px;
		border: 1px solid var(--line);
		border-radius: 12px;
		padding: 4px 8px;
	}
	.item.selected {
		border-color: var(--accent);
		background: var(--accent-soft);
	}
	.pick {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 4px 8px;
		text-align: left;
		background: none;
		border: 0;
		padding: 8px 6px;
		cursor: pointer;
		min-width: 0;
	}
	.big {
		font-weight: 700;
	}
	.link-btn {
		align-self: flex-start;
		background: none;
		border: 0;
		padding: 6px 4px 6px 16px;
		color: var(--accent-dark);
		font-weight: 500;
		cursor: pointer;
	}
</style>
