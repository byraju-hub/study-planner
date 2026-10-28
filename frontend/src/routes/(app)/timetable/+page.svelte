<script>
	import { onMount } from 'svelte';
	import { resolve } from '$app/paths';
	import { get, put } from '#lib/api.js';
	import NeisImport from '#lib/components/NeisImport.svelte';

	const DAYS = ['월', '화', '수', '목', '금'];

	let subjects = $state([]);
	let maxPeriod = $state(8);
	/** "요일-교시" → 과목 id(문자열, 빈 문자열 = 없음) */
	let grid = $state({});
	let loaded = $state(false);
	let error = $state('');
	let message = $state('');
	let busy = $state(false);
	let importing = $state(false);

	onMount(async () => {
		try {
			const [subs, tt] = await Promise.all([get('/subjects'), get('/timetable')]);
			subjects = subs;
			maxPeriod = tt.max_period;
			const g = {};
			for (const s of tt.slots) g[`${s.weekday}-${s.period}`] = String(s.subject_id);
			grid = g;
		} catch (e) {
			error = e.message;
		}
		loaded = true;
	});

	function fill(slots) {
		const g = { ...grid };
		for (const s of slots) {
			g[`${s.weekday}-${s.period}`] = String(s.subject_id);
			if (s.period > maxPeriod) maxPeriod = Math.min(s.period, 8);
		}
		grid = g;
	}

	async function save(event) {
		event.preventDefault();
		error = message = '';
		busy = true;
		try {
			const slots = [];
			for (let d = 1; d <= 5; d++) {
				for (let p = 1; p <= maxPeriod; p++) {
					slots.push({ weekday: d, period: p, subject_id: grid[`${d}-${p}`] ? Number(grid[`${d}-${p}`]) : null });
				}
			}
			await put('/timetable', { slots });
			message = '시간표를 저장했어요. 이제 일반 스터디에서 오늘 수업별로 복습할 수 있어요.';
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head><title>시간표 · 스터디 플래너</title></svelte:head>

<div class="stack" style="gap: 20px; max-width: 900px;">
	<header class="stack" style="gap: 6px;">
		<a class="muted small" href={resolve('/study')}>← 일반 스터디</a>
		<h1>시간표</h1>
		<p class="muted">요일별로 수업 과목을 골라 두면, 그날 수업을 복습 목록으로 보여 줘요. 수업이 없는 칸은 비워 두세요.</p>
	</header>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}
	{#if message}<p class="chip" role="status">{message}</p>{/if}

	{#if loaded}
		{#if importing}
			<NeisImport onfill={fill} onclose={() => (importing = false)} />
		{:else}
			<button class="btn btn-soft" style="align-self: flex-start;" onclick={() => (importing = true)}>나이스에서 시간표 가져오기</button>
		{/if}
		<form class="card stack" onsubmit={save}>
			<div class="scroll">
				<table>
					<thead>
						<tr>
							<th scope="col"><span class="sr-only">교시</span></th>
							{#each DAYS as d (d)}<th scope="col">{d}</th>{/each}
						</tr>
					</thead>
					<tbody>
						{#each Array.from({ length: maxPeriod }, (_, i) => i + 1) as p (p)}
							<tr>
								<th scope="row">{p}교시</th>
								{#each DAYS as d, i (d)}
									<td>
										<label class="sr-only" for="c-{i + 1}-{p}">{d}요일 {p}교시</label>
										<select id="c-{i + 1}-{p}" class="input" bind:value={grid[`${i + 1}-${p}`]}>
											<option value="">—</option>
											{#each subjects as s (s.id)}<option value={String(s.id)}>{s.name}</option>{/each}
										</select>
									</td>
								{/each}
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
			<button class="btn btn-primary" disabled={busy}>{busy ? '저장 중…' : '시간표 저장'}</button>
		</form>
	{:else}
		<p class="muted">불러오는 중…</p>
	{/if}
</div>

<style>
	.scroll {
		overflow-x: auto;
	}
	table {
		border-collapse: separate;
		border-spacing: 6px;
		min-width: 640px;
		width: 100%;
	}
	th {
		font-size: 13px;
		font-weight: 500;
		color: var(--muted);
		text-align: center;
		white-space: nowrap;
	}
	td .input {
		min-height: 42px;
		padding: 0 8px;
	}
</style>
