<script>
	import { onMount } from 'svelte';
	import { resolve } from '$app/paths';
	import { get, post, put, del } from '#lib/api.js';

	let subjects = $state([]);
	let loaded = $state(false);
	let error = $state('');

	let form = $state({ name: '', color: '#2e7d5b' });
	let editingId = $state(null);
	let edit = $state({ name: '', color: '#2e7d5b' });

	onMount(async () => {
		try {
			subjects = await get('/subjects');
		} catch (e) {
			error = e.message;
		}
		loaded = true;
	});

	async function add(event) {
		event.preventDefault();
		error = '';
		try {
			const s = await post('/subjects', form);
			subjects = [...subjects, s];
			form = { name: '', color: form.color };
		} catch (e) {
			error = e.message;
		}
	}

	function startEdit(s) {
		editingId = s.id;
		edit = { name: s.name, color: s.color.toLowerCase() };
	}

	async function save(event, s) {
		event.preventDefault();
		error = '';
		try {
			const u = await put(`/subjects/${s.id}`, edit);
			subjects = subjects.map((x) => (x.id === s.id ? { ...x, name: u.name, color: u.color } : x));
			editingId = null;
		} catch (e) {
			error = e.message;
		}
	}

	async function remove(s) {
		if (!confirm(`'${s.name}' 과목을 삭제할까요?\n교재·단원·자료도 모두 삭제돼요.`)) return;
		error = '';
		try {
			await del(`/subjects/${s.id}`);
			subjects = subjects.filter((x) => x.id !== s.id);
		} catch (e) {
			error = e.message;
		}
	}
</script>

<svelte:head><title>과목 · 교재 · 스터디 플래너</title></svelte:head>

<div class="stack" style="gap: 20px; max-width: 720px;">
	<h1>과목 · 교재</h1>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	<form class="card row wrap" onsubmit={add}>
		<label class="sr-only" for="new-name">새 과목 이름</label>
		<input id="new-name" class="input grow" placeholder="새 과목 이름" maxlength="40" bind:value={form.name} required />
		<label class="sr-only" for="new-color">색상</label>
		<input id="new-color" class="color" type="color" bind:value={form.color} />
		<button class="btn btn-primary">추가</button>
	</form>

	{#if !loaded}
		<p class="muted">불러오는 중…</p>
	{:else if subjects.length === 0}
		<p class="empty">아직 과목이 없어요. 위에서 추가해 보세요.</p>
	{:else}
		<ul class="list">
			{#each subjects as s (s.id)}
				<li class="card item">
					{#if editingId === s.id}
						<form class="row grow wrap" onsubmit={(e) => save(e, s)}>
							<label class="sr-only" for="edit-name">과목 이름</label>
							<input id="edit-name" class="input grow" maxlength="40" bind:value={edit.name} required />
							<label class="sr-only" for="edit-color">색상</label>
							<input id="edit-color" class="color" type="color" bind:value={edit.color} />
							<button class="btn btn-primary btn-small">저장</button>
							<button type="button" class="btn btn-small" onclick={() => (editingId = null)}>취소</button>
						</form>
					{:else}
						<span class="dot" style:background={s.color}></span>
						<a class="grow name" href={resolve('/subjects/[id]', { id: String(s.id) })}>{s.name}</a>
						<a class="btn btn-small btn-soft" href={resolve('/subjects/[id]', { id: String(s.id) })}>교재 {s.textbook_count} · 추가</a>
						<button class="btn btn-small" onclick={() => startEdit(s)}>수정</button>
						<button class="btn btn-small btn-danger" onclick={() => remove(s)}>삭제</button>
					{/if}
				</li>
			{/each}
		</ul>
	{/if}
</div>

<style>
	.wrap {
		flex-wrap: wrap;
	}
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
		padding: 10px 14px;
		min-height: 56px;
	}
	.name {
		color: var(--ink);
		text-decoration: none;
		font-weight: 500;
	}
	.name:hover {
		text-decoration: underline;
	}
	.color {
		width: 46px;
		height: 46px;
		padding: 2px;
		border: 1px solid var(--line-strong);
		border-radius: 10px;
		background: var(--surface);
	}
</style>
