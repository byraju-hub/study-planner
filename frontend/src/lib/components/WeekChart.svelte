<script>
	import { get } from '#lib/api.js';
	import { fmtMinutes, weekdayLabel } from '#lib/format.js';

	/** version이 바뀌면 다시 불러온다. */
	let { version = 0 } = $props();

	let stats = $state(null);
	let error = $state('');

	$effect(() => {
		version;
		get('/stats/week')
			.then((s) => {
				stats = s;
				error = '';
			})
			.catch((e) => (error = e.message));
	});

	const max = $derived(Math.max(60, ...(stats?.days.map((d) => d.minutes) ?? [0])));
</script>

<section class="card stack" aria-label="이번 주 공부시간">
	<div class="row-between">
		<h2>최근 7일 공부시간</h2>
		{#if stats}<span class="muted small">합계 {fmtMinutes(stats.total_minutes)}</span>{/if}
	</div>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	{#if stats}
		<div class="chart" role="img" aria-label="최근 7일 공부시간: {stats.days.map((d) => `${weekdayLabel(d.date)} ${fmtMinutes(d.minutes)}`).join(', ')}">
			{#each stats.days as d, i (d.date)}
				<div class="col">
					<span class="val small" class:today={i === stats.days.length - 1}>{d.minutes > 0 ? fmtMinutes(d.minutes) : ''}</span>
					<div class="bar" class:today={i === stats.days.length - 1} style:height="{Math.max(3, (d.minutes / max) * 100)}px"></div>
					<span class="day small" class:today={i === stats.days.length - 1}>{i === stats.days.length - 1 ? '오늘' : weekdayLabel(d.date)}</span>
				</div>
			{/each}
		</div>
		{#if stats.subjects.some((s) => s.minutes > 0)}
			<ul class="subs">
				{#each stats.subjects.filter((s) => s.minutes > 0) as s (s.subject_id ?? 'none')}
					<li class="row small"><span class="dot" style:background={s.color}></span><span class="grow">{s.name}</span><span class="muted">{fmtMinutes(s.minutes)}</span></li>
				{/each}
			</ul>
		{/if}
	{/if}
</section>

<style>
	.chart {
		display: flex;
		align-items: flex-end;
		gap: 10px;
		height: 150px;
		border-bottom: 1px solid var(--line);
		padding: 0 4px;
	}
	.col {
		flex: 1;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: flex-end;
		gap: 4px;
		height: 100%;
		min-width: 0;
	}
	.bar {
		width: 100%;
		background: #bfe3cf;
		border-radius: 6px 6px 0 0;
	}
	.bar.today {
		background: var(--accent);
	}
	.val {
		color: var(--muted);
		font-size: 11px;
		white-space: nowrap;
	}
	.val.today,
	.day.today {
		color: var(--ink);
		font-weight: 700;
	}
	.subs {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
</style>
