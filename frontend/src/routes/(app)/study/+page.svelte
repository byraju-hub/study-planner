<script>
	import { onMount } from 'svelte';
	import { get } from '#lib/api.js';
	import { resolve } from '$app/paths';
	import { fmtDate } from '#lib/format.js';
	import ModeTabs from '#lib/components/ModeTabs.svelte';
	import TimerCard from '#lib/components/TimerCard.svelte';
	import TaskList from '#lib/components/TaskList.svelte';
	import LessonList from '#lib/components/LessonList.svelte';
	import ReviewList from '#lib/components/ReviewList.svelte';
	import WeekChart from '#lib/components/WeekChart.svelte';
	import JournalCard from '#lib/components/JournalCard.svelte';

	let data = $state(null);
	let subjects = $state([]);
	let error = $state('');
	/** 타이머를 멈추면 주간 차트도 다시 불러온다. */
	let statsVersion = $state(0);

	async function reload() {
		try {
			data = await get('/study/today');
			statsVersion++;
			error = '';
		} catch (e) {
			error = e.message;
		}
	}

	onMount(async () => {
		try {
			subjects = await get('/subjects');
		} catch (e) {
			error = e.message;
		}
		await reload();
	});
</script>

<svelte:head><title>일반 스터디 · 스터디 플래너</title></svelte:head>

<div class="stack page">
	<header class="stack" style="gap: 10px;">
		<div class="row-between">
			<div>
				<p class="muted small">{data ? fmtDate(data.date) : ''}</p>
				<h1>일반 스터디</h1>
			</div>
			<a class="btn btn-soft" href={resolve('/study/calendar')}>달력으로 일정 관리</a>
		</div>
		<ModeTabs current="study" />
	</header>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}

	{#if data}
		<div class="cols">
			<div class="stack col">
				<TimerCard session={data.session} serverNow={data.server_now} minutesToday={data.minutes_today} {subjects} onchange={reload} />
				<TaskList tasks={data.tasks} date={data.date} today={data.date} {subjects} onchange={reload} />
				<LessonList lessons={data.lessons} date={data.date} onchange={reload} />
			</div>
			<div class="stack col">
				<ReviewList reviews={data.reviews_due} {subjects} onchange={reload} />
				<WeekChart version={statsVersion} />
				<JournalCard date={data.date} journal={data.journal} />
			</div>
		</div>
	{:else if !error}
		<p class="muted">불러오는 중…</p>
	{/if}
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
	.col {
		gap: 16px;
	}
	@media (max-width: 1000px) {
		.cols {
			grid-template-columns: minmax(0, 1fr);
		}
	}
</style>
