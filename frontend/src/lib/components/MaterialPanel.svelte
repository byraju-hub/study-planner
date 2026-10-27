<script>
	import { get, post, put, del, api, fileUrl } from '#lib/api.js';

	let { unit, onchange = () => {} } = $props();

	const MAX_BYTES = 10 * 1024 * 1024;

	let items = $state([]);
	let loaded = $state(false);
	let error = $state('');
	/** 열려 있는 추가 폼: 'note' | 'link' | 'file' | null */
	let mode = $state(null);
	let form = $state({ title: '', content: '', url: '' });
	let fileInput = $state(null);
	let busy = $state(false);
	let editingId = $state(null);
	let edit = $state({ title: '', content: '', url: '' });

	async function load(id) {
		try {
			items = await get(`/materials?unit_id=${id}`);
			error = '';
		} catch (e) {
			error = e.message;
		}
		loaded = true;
	}

	$effect(() => {
		const id = unit.id;
		loaded = false;
		mode = null;
		editingId = null;
		load(id);
	});

	function open(next) {
		mode = mode === next ? null : next;
		form = { title: '', content: '', url: '' };
	}

	async function add(event) {
		event.preventDefault();
		error = '';
		busy = true;
		try {
			let m;
			if (mode === 'file') {
				const file = fileInput?.files?.[0];
				if (!file) throw new Error('파일을 선택해 주세요.');
				if (file.size > MAX_BYTES) throw new Error('파일이 너무 커요. 10MB 이하만 올릴 수 있어요.');
				const body = new FormData();
				body.append('unit_id', String(unit.id));
				if (form.title) body.append('title', form.title);
				body.append('file', file);
				m = await api('POST', '/materials/upload', body);
			} else {
				m = await post('/materials', { unit_id: unit.id, type: mode, title: form.title, content: form.content, url: form.url });
			}
			items = [m, ...items];
			mode = null;
			onchange();
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}

	function startEdit(m) {
		editingId = m.id;
		edit = { title: m.title, content: m.content ?? '', url: m.url ?? '' };
	}

	async function save(event, m) {
		event.preventDefault();
		error = '';
		try {
			const body = { title: edit.title };
			if (m.type === 'note') body.content = edit.content;
			if (m.type === 'link') body.url = edit.url;
			const u = await put(`/materials/${m.id}`, body);
			items = items.map((x) => (x.id === m.id ? u : x));
			editingId = null;
		} catch (e) {
			error = e.message;
		}
	}

	async function remove(m) {
		if (!confirm(`'${m.title}' 자료를 삭제할까요?`)) return;
		error = '';
		try {
			await del(`/materials/${m.id}`);
			items = items.filter((x) => x.id !== m.id);
			onchange();
		} catch (e) {
			error = e.message;
		}
	}

	const size = (n) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)}MB` : `${Math.max(1, Math.round(n / 1024))}KB`);
	const TYPE_LABEL = { note: '메모', link: '링크', file: '파일' };
</script>

<section class="card stack" aria-label="학습 자료">
	<div class="stack tight">
		<h2>학습 자료</h2>
		<p class="muted small">{unit.title}</p>
	</div>

	<div class="row wrap">
		<button class="btn btn-small btn-soft" onclick={() => open('note')} aria-expanded={mode === 'note'}>+ 메모</button>
		<button class="btn btn-small btn-soft" onclick={() => open('link')} aria-expanded={mode === 'link'}>+ 링크</button>
		<button class="btn btn-small btn-soft" onclick={() => open('file')} aria-expanded={mode === 'file'}>+ 사진 · PDF</button>
	</div>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	{#if mode}
		<form class="stack form" onsubmit={add}>
			<div class="field">
				<label for="m-title">제목{mode === 'file' ? ' (비우면 파일 이름)' : ''}</label>
				<input id="m-title" class="input" maxlength="120" bind:value={form.title} required={mode !== 'file'} />
			</div>
			{#if mode === 'note'}
				<div class="field">
					<label for="m-content">내용</label>
					<textarea id="m-content" class="input" maxlength="20000" bind:value={form.content} required></textarea>
				</div>
			{:else if mode === 'link'}
				<div class="field">
					<label for="m-url">주소</label>
					<input id="m-url" class="input" type="url" placeholder="https://" maxlength="500" bind:value={form.url} required />
				</div>
			{:else}
				<div class="field">
					<label for="m-file">파일 (PDF, JPG, PNG · 10MB 이하)</label>
					<input id="m-file" class="input file" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" bind:this={fileInput} required />
				</div>
				<p class="hint">시험이 끝나고 학교에서 나눠 준 자료나 내가 필기한 자료만 올려 주세요. 올린 자료는 나만 볼 수 있어요.</p>
			{/if}
			<div class="row">
				<button class="btn btn-primary btn-small" disabled={busy}>{busy ? '올리는 중…' : '추가'}</button>
				<button type="button" class="btn btn-small" onclick={() => (mode = null)}>취소</button>
			</div>
		</form>
	{/if}

	{#if !loaded}
		<p class="muted small">불러오는 중…</p>
	{:else if items.length === 0}
		<p class="empty">이 단원의 자료가 아직 없어요.</p>
	{:else}
		<ul class="list">
			{#each items as m (m.id)}
				<li class="card inner stack tight">
					{#if editingId === m.id}
						<form class="stack" onsubmit={(e) => save(e, m)}>
							<label class="sr-only" for="me-title">제목</label>
							<input id="me-title" class="input" maxlength="120" bind:value={edit.title} required />
							{#if m.type === 'note'}
								<label class="sr-only" for="me-content">내용</label>
								<textarea id="me-content" class="input" maxlength="20000" bind:value={edit.content} required></textarea>
							{:else if m.type === 'link'}
								<label class="sr-only" for="me-url">주소</label>
								<input id="me-url" class="input" type="url" maxlength="500" bind:value={edit.url} required />
							{/if}
							<div class="row">
								<button class="btn btn-primary btn-small">저장</button>
								<button type="button" class="btn btn-small" onclick={() => (editingId = null)}>취소</button>
							</div>
						</form>
					{:else}
						<div class="row-between">
							<div class="row grow">
								<span class="chip">{TYPE_LABEL[m.type]}</span>
								<strong class="title">{m.title}</strong>
							</div>
							<div class="row">
								{#if m.type !== 'file'}<button class="btn btn-small" onclick={() => startEdit(m)} aria-label="{m.title} 수정">수정</button>{/if}
								<button class="btn btn-small btn-danger" onclick={() => remove(m)} aria-label="{m.title} 삭제">삭제</button>
							</div>
						</div>
						{#if m.type === 'note'}
							<p class="note">{m.content}</p>
						{:else if m.type === 'link'}
							<a href={m.url} target="_blank" rel="noopener noreferrer" class="url">{m.url}</a>
						{:else}
							{#if m.mime?.startsWith('image/')}
								<a href={fileUrl(m.id)} target="_blank" rel="noopener noreferrer"><img class="thumb" src={fileUrl(m.id)} alt={m.title} /></a>
							{/if}
							<a href={fileUrl(m.id)} target="_blank" rel="noopener noreferrer">{m.file_name}</a>
							<span class="muted small">{size(m.file_size)}</span>
						{/if}
					{/if}
				</li>
			{/each}
		</ul>
	{/if}
</section>

<style>
	.wrap {
		flex-wrap: wrap;
	}
	.tight {
		gap: 4px;
	}
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
		gap: 8px;
	}
	.inner {
		padding: 12px 14px;
		border-radius: 12px;
	}
	.title {
		overflow-wrap: anywhere;
	}
	.note {
		white-space: pre-wrap;
		overflow-wrap: anywhere;
	}
	.url {
		overflow-wrap: anywhere;
	}
	.thumb {
		max-width: 100%;
		max-height: 220px;
		border-radius: 8px;
		border: 1px solid var(--line);
		display: block;
	}
	.file {
		padding-top: 10px;
	}
</style>
