<script>
	import { onMount } from 'svelte';
	import { resolve } from '$app/paths';
	import { get } from '#lib/api.js';
	import { addDays, localToday } from '#lib/format.js';
	import ModeTabs from '#lib/components/ModeTabs.svelte';
	import TaskList from '#lib/components/TaskList.svelte';

	const today = localToday();
	const WEEK = ['일', '월', '화', '수', '목', '금', '토'];

	let year = $state(Number(today.slice(0, 4)));
	let month = $state(Number(today.slice(5, 7)));
	let selected = $state(today);
	let tasks = $state([]);
	let subjects = $state([]);
	let error = $state('');

	const pad = (n) => String(n).padStart(2, '0');

	// 6주(42칸) 고정 달력. 일요일부터 시작한다.
	const cells = $derived.by(() => {
		const first = `${year}-${pad(month)}-01`;
		const wd = new Date(Date.UTC(year, month - 1, 1)).getUTCDay();
		const start = addDays(first, -wd);
		return Array.from({ length: 42 }, (_, i) => addDays(start, i));
	});

	const byDate = $derived.by(() => {
		const m = {};
		for (const t of tasks) (m[t.task_date] ??= []).push(t);
		return m;
	});
	const dayTasks = $derived(byDate[selected] ?? []);

	async function load() {
		try {
			tasks = await get(`/tasks?from=${cells[0]}&to=${cells[41]}`);
			error = '';
		} catch (e) {
			error = e.message;
		}
	}

	function shift(delta) {
		const d = new Date(Date.UTC(year, month - 1 + delta, 1));
		year = d.getUTCFullYear();
		month = d.getUTCMonth() + 1;
		selected = `${year}-${pad(month)}-01`;
		load();
	}

	function goToday() {
		year = Number(today.slice(0, 4));
		month = Number(today.slice(5, 7));
		selected = today;
		load();
	}

	function pick(ymd) {
		selected = ymd;
		if (Number(ymd.slice(5, 7)) !== month) {
			year = Number(ymd.slice(0, 4));
			month = Number(ymd.slice(5, 7));
			load();
		}
	}

	onMount(async () => {
		try {
			subjects = await get('/subjects');
		} catch (e) {
			error = e.message;
		}
		await load();
	});
</script>

<svelte:head><title>달력 · 스터디 플래너</title></svelte:head>

<div class="stack page">
	<header class="stack" style="gap: 10px;">
		<div class="row-between">
			<div>
				<p class="muted small">날짜를 눌러 그날의 할 일을 보고 추가해요</p>
				<h1>스터디 달력</h1>
			</div>
			<a class="btn" href={resolve('/study')}>오늘 화면으로</a>
		</div>
		<ModeTabs current="study" />
	</header>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	<div class="cols">
		<section class="card stack" aria-label="달력">
			<div class="row-between">
				<div class="row">
					<button class="btn btn-small" onclick={() => shift(-1)} aria-label="이전 달">‹</button>
					<h2>{year}년 {month}월</h2>
					<button class="btn btn-small" onclick={() => shift(1)} aria-label="다음 달">›</button>
				</div>
				<button class="btn btn-small btn-soft" onclick={goToday}>오늘</button>
			</div>

			<div class="grid" role="grid" aria-label="{year}년 {month}월">
				{#each WEEK as w, i (w)}
					<div class="wd" class:sun={i === 0} class:sat={i === 6} role="columnheader">{w}</div>
				{/each}
				{#each cells as ymd, i (ymd)}
					{@const list = byDate[ymd] ?? []}
					{@const done = list.filter((t) => t.done).length}
					<button
						class="cell"
						class:other={Number(ymd.slice(5, 7)) !== month}
						class:sun={i % 7 === 0}
						class:sat={i % 7 === 6}
						class:today={ymd === today}
						class:sel={ymd === selected}
						onclick={() => pick(ymd)}
						aria-label="{Number(ymd.slice(5, 7))}월 {Number(ymd.slice(8))}일, 할 일 {list.length}개{list.length ? `, 완료 ${done}개` : ''}"
						aria-pressed={ymd === selected}
					>
						<span class="num">{Number(ymd.slice(8))}</span>
						{#if list.length}
							<span class="dots" aria-hidden="true">
								{#each list.slice(0, 6) as t (t.id)}
									<i style="background:{t.subject_color ?? '#9aa3b2'}" class:faded={t.done}></i>
								{/each}
							</span>
							<span class="cnt" aria-hidden="true">{done}/{list.length}</span>
						{/if}
					</button>
				{/each}
			</div>
		</section>

		<TaskList tasks={dayTasks} date={selected} {today} {subjects} onchange={load} />
	</div>
</div>

<style>
	.page {
		gap: 20px;
		max-width: 1200px;
	}
	.cols {
		display: grid;
		grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
		gap: 20px;
		align-items: start;
	}
	.grid {
		display: grid;
		grid-template-columns: repeat(7, minmax(0, 1fr));
		gap: 4px;
	}
	.wd {
		text-align: center;
		font-size: 12px;
		color: var(--muted);
		padding: 4px 0;
	}
	.sun {
		color: var(--danger);
	}
	.sat {
		color: #2b5fb4;
	}
	.cell {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 3px;
		min-height: 62px;
		padding: 6px 2px;
		background: var(--surface);
		border: 1px solid var(--line);
		border-radius: 10px;
		cursor: pointer;
	}
	.cell.other {
		opacity: 0.45;
	}
	.cell.today .num {
		background: var(--accent);
		color: #fff;
	}
	.cell.sel {
		border-color: var(--accent);
		background: var(--accent-soft);
	}
	.num {
		min-width: 24px;
		height: 24px;
		line-height: 24px;
		border-radius: 12px;
		text-align: center;
		font-size: 13px;
		font-weight: 700;
	}
	.dots {
		display: flex;
		flex-wrap: wrap;
		justify-content: center;
		gap: 3px;
		max-width: 44px;
	}
	.dots i {
		width: 7px;
		height: 7px;
		border-radius: 50%;
	}
	.dots i.faded {
		opacity: 0.3;
	}
	.cnt {
		font-size: 10px;
		color: var(--muted);
	}
	@media (max-width: 1000px) {
		.cols {
			grid-template-columns: minmax(0, 1fr);
		}
	}
	@media (max-width: 600px) {
		.cell {
			min-height: 54px;
		}
	}
</style>
