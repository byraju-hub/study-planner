<script>
	import { post } from '#lib/api.js';
	import { fmtClock, fmtMinutes } from '#lib/format.js';

	let { session, serverNow, minutesToday, subjects, onchange = () => {} } = $props();

	let subjectId = $state('');
	let now = $state(Date.now());
	let skew = $state(0);
	let error = $state('');
	let busy = $state(false);

	// 서버 시계와 이 기기의 시계 차이를 보정해서 경과 시간을 계산한다.
	$effect(() => {
		skew = Date.parse(serverNow) - Date.now();
	});

	$effect(() => {
		if (!session) return;
		now = Date.now();
		const t = setInterval(() => (now = Date.now()), 1000);
		return () => clearInterval(t);
	});

	const elapsed = $derived(session ? (now + skew - Date.parse(session.started_at)) / 1000 : 0);

	async function start() {
		error = '';
		busy = true;
		try {
			await post('/sessions', { subject_id: subjectId ? Number(subjectId) : null });
			await onchange();
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}

	async function stop() {
		error = '';
		busy = true;
		try {
			await post(`/sessions/${session.id}/stop`);
			await onchange();
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}
</script>

<section class="timer" aria-label="공부 타이머">
	<div class="grow stack tight">
		<span class="label">{session ? `공부 중${session.subject_name ? ` · ${session.subject_name}` : ''}` : '공부 타이머'}</span>
		<output class="clock" aria-live="off">{fmtClock(elapsed)}</output>
		<span class="label">오늘 총 {fmtMinutes(minutesToday)}</span>
		{#if error}<span class="err" role="alert">{error}</span>{/if}
	</div>
	{#if session}
		<button class="btn big" onclick={stop} disabled={busy}>정지</button>
	{:else}
		<div class="stack tight start">
			<label class="sr-only" for="timer-subject">과목</label>
			<select id="timer-subject" class="input" bind:value={subjectId}>
				<option value="">과목 선택 안 함</option>
				{#each subjects as s (s.id)}<option value={s.id}>{s.name}</option>{/each}
			</select>
			<button class="btn big" onclick={start} disabled={busy}>시작</button>
		</div>
	{/if}
</section>

<style>
	.timer {
		display: flex;
		align-items: center;
		gap: 16px;
		flex-wrap: wrap;
		background: #24483a;
		color: #fff;
		border-radius: 18px;
		padding: 20px 24px;
	}
	.tight {
		gap: 2px;
	}
	.label {
		font-size: 13px;
		color: #c9dcd2;
	}
	.clock {
		font-size: 44px;
		font-weight: 700;
		letter-spacing: 2px;
		font-variant-numeric: tabular-nums;
		line-height: 1.15;
	}
	.err {
		color: #ffd0cc;
		font-size: 13px;
	}
	.start {
		min-width: 160px;
		gap: 8px;
	}
	.start .input {
		color: var(--ink);
	}
	.timer .grow {
		flex: 1 1 220px;
	}
	@media (max-width: 600px) {
		.clock {
			font-size: 38px;
		}
		.start {
			flex: 1 1 100%;
			flex-direction: row;
		}
		.start .input {
			flex: 1;
		}
	}
	.big {
		min-width: 96px;
		min-height: 48px;
		border-radius: 24px;
		border: 0;
		background: #fff;
		color: #1d2433;
		font-weight: 700;
	}
</style>
