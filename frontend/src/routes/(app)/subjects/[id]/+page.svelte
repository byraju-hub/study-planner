<script>
	import { onMount } from 'svelte';
	import { resolve } from '$app/paths';
	import { page } from '$app/state';
	import { get } from '#lib/api.js';
	import TextbookPanel from '#lib/components/TextbookPanel.svelte';
	import UnitPanel from '#lib/components/UnitPanel.svelte';
	import MaterialPanel from '#lib/components/MaterialPanel.svelte';

	const subjectId = $derived(Number(page.params.id));

	let subject = $state(null);
	let notFound = $state(false);
	let textbookId = $state(null);
	let textbookTitle = $state('');
	let unitId = $state(null);
	let unitVersion = $state(0);
	let units = $state([]);

	onMount(async () => {
		const all = await get('/subjects');
		subject = all.find((s) => s.id === subjectId) ?? null;
		notFound = subject === null;
	});

	$effect(() => {
		textbookId;
		unitId = null;
	});

	// 자료 패널 제목에 선택한 단원 이름을 쓰기 위해 단원 목록을 넘겨받는다.
	const unit = $derived(units.find((u) => u.id === unitId) ?? null);
</script>

<svelte:head><title>{subject?.name ?? '과목'} · 스터디 플래너</title></svelte:head>

<div class="stack" style="gap: 20px;">
	<header class="stack" style="gap: 6px;">
		<a class="muted small" href={resolve('/subjects')}>← 과목 · 교재</a>
		{#if subject}
			<h1 class="row"><span class="dot big" style:background={subject.color}></span>{subject.name}</h1>
		{:else if notFound}
			<h1>찾을 수 없는 과목이에요</h1>
		{/if}
	</header>

	{#if subject}
		<div class="panes">
			<TextbookPanel subjectId={subject.id} bind:selectedId={textbookId} bind:selectedTitle={textbookTitle} liveUnitCount={textbookId === null ? null : units.length} />
			{#if textbookId !== null}
				<UnitPanel {textbookId} {textbookTitle} bind:selectedId={unitId} bind:units version={unitVersion} />
			{/if}
			{#if unit}
				<MaterialPanel {unit} onchange={() => unitVersion++} />
			{/if}
		</div>
	{/if}
</div>

<style>
	.panes {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 16px;
		align-items: start;
	}
	.big {
		width: 14px;
		height: 14px;
	}
	@media (max-width: 1100px) {
		.panes {
			grid-template-columns: minmax(0, 1fr);
		}
	}
</style>
