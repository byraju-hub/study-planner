<script>
	import { onMount } from 'svelte';
	import { get, post } from '#lib/api.js';

	/** 서점(YES24) 목차를 검색해 단원 후보로 가져온다. 저장 전에 학생이 고치고 고른다. 단원 이름·쪽수만 저장해요. */
	let { textbookId, defaultQuery = '', onimported, onclose } = $props();

	let query = $state(defaultQuery);
	let books = $state(null);
	let items = $state(null);
	let picked = $state(null);
	let status = $state(null);
	let error = $state('');
	let busy = $state(false);

	onMount(async () => {
		try {
			status = await get('/integrations');
		} catch (e) {
			error = e.message;
		}
	});

	async function search(event) {
		event?.preventDefault();
		error = '';
		items = null;
		busy = true;
		try {
			books = await get('/catalog/books?q=' + encodeURIComponent(query));
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}

	async function choose(book) {
		error = '';
		busy = true;
		picked = book;
		try {
			const r = await get('/catalog/toc?isbn=' + book.isbn13);
			if (r.empty) error = '이 책은 서점에 목차가 등록돼 있지 않아요. 직접 입력해 주세요.';
			items = r.items.map((it) => ({ ...it, on: true, page_start: it.page_start ?? '', page_end: it.page_end ?? '' }));
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}

	async function save() {
		error = '';
		busy = true;
		try {
			const chosen = items
				.filter((i) => i.on && i.title.trim())
				.map((i) => ({ title: i.title, level: i.level, page_start: i.page_start ? Number(i.page_start) : null, page_end: i.page_end ? Number(i.page_end) : null }));
			if (chosen.length === 0) {
				error = '저장할 단원을 하나 이상 골라 주세요.';
				return;
			}
			await post(`/textbooks/${textbookId}/import-toc`, { items: chosen });
			onimported();
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}
</script>

<div class="card stack" style="background: var(--bg);">
	<div class="row-between">
		<h3>서점 목차 가져오기</h3>
		<button class="btn btn-small" onclick={onclose}>닫기</button>
	</div>

	{#if status && !status.yes24}
		<p class="muted small">YES24 연동이 설정되지 않았어요. 관리자가 YES24 API 키를 서버 설정에 넣으면 쓸 수 있어요. 그동안은 단원을 직접 추가해 주세요.</p>
	{:else}
		<p class="muted small">단원 이름과 쪽수만 가져와요. 서점 목차는 형식이 제각각이라 저장 전에 꼭 확인하고 고쳐 주세요.</p>
		{#if error}<p class="alert" role="alert">{error}</p>{/if}

		<form class="row" onsubmit={search}>
			<input class="input grow" placeholder="책 제목" bind:value={query} required minlength="2" aria-label="책 제목" />
			<button class="btn btn-soft" disabled={busy}>검색</button>
		</form>

		{#if books && !items}
			{#if books.length === 0}
				<p class="muted small">검색 결과가 없어요.</p>
			{:else}
				<ul class="books">
					{#each books as b (b.isbn13)}
						<li>
							<button class="book" onclick={() => choose(b)} disabled={busy}>
								<strong>{b.title}</strong>
								<span class="muted small">{b.publisher} · {b.author} · {b.pub_date}</span>
							</button>
						</li>
					{/each}
				</ul>
			{/if}
		{/if}

		{#if items}
			<p class="small"><strong>{picked.title}</strong> — {items.length}개 항목</p>
			{#if items.length > 0}
				<ul class="toc">
					{#each items as it, i (i)}
						<li class:sub={it.level === 2}>
							<input type="checkbox" bind:checked={it.on} aria-label="가져오기" />
							<input class="input t" bind:value={it.title} maxlength="120" aria-label="단원 이름" />
							<input class="input p" type="number" min="1" max="5000" placeholder="쪽" bind:value={it.page_start} aria-label="시작 쪽" />
							<button class="btn btn-small" title={it.level === 2 ? '대단원으로' : '중단원으로'} onclick={() => (it.level = it.level === 2 ? 1 : 2)}>{it.level === 2 ? '↰' : '↳'}</button>
						</li>
					{/each}
				</ul>
				<div class="row">
					<button class="btn btn-primary" disabled={busy} onclick={save}>선택한 단원 저장</button>
					<button class="btn" onclick={() => (items = null)}>다른 책 고르기</button>
				</div>
			{:else}
				<button class="btn" onclick={() => (items = null)}>다른 책 고르기</button>
			{/if}
		{/if}
	{/if}
</div>

<style>
	.books,
	.toc {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.book {
		width: 100%;
		display: flex;
		flex-direction: column;
		text-align: left;
		gap: 2px;
		padding: 10px 12px;
		border: 1px solid var(--line);
		border-radius: 10px;
		background: var(--surface);
		cursor: pointer;
		color: var(--ink);
	}
	.toc li {
		display: flex;
		gap: 6px;
		align-items: center;
	}
	.toc li.sub {
		margin-left: 20px;
	}
	.t {
		flex: 1;
		min-height: 38px;
	}
	.p {
		width: 72px;
		min-height: 38px;
	}
</style>
