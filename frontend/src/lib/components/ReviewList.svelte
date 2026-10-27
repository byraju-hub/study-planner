<script>
	import { post, del } from '#lib/api.js';
	import SubjectChip from './SubjectChip.svelte';

	let { reviews, subjects, onchange = () => {} } = $props();

	let title = $state('');
	let subjectId = $state('');
	let error = $state('');
	let message = $state('');

	async function run(fn) {
		error = message = '';
		try {
			await fn();
			await onchange();
		} catch (e) {
			error = e.message;
		}
	}

	const finish = (r, hard) =>
		run(async () => {
			const res = await post(`/reviews/${r.id}/done`, { hard });
			message = hard
				? '내일 다시 복습하도록 잡아 뒀어요.'
				: res.finished
					? '5차 복습까지 모두 마쳤어요. 잘했어요!'
					: `${res.interval_days}일 뒤에 다시 복습해요.`;
		});

	const remove = (r) => run(() => del(`/reviews/${r.id}`));

	function add(event) {
		event.preventDefault();
		return run(async () => {
			await post('/reviews', { title, subject_id: subjectId ? Number(subjectId) : null });
			title = '';
			message = '내일 1차 복습으로 등록했어요.';
		});
	}
</script>

<section class="card stack" aria-label="복습">
	<div class="stack tight">
		<h2>오늘 복습할 것 <span class="count">{reviews.length}</span></h2>
		<p class="muted small">1 · 3 · 7 · 14 · 30일 간격으로 다시 보면 오래 기억해요.</p>
	</div>

	{#if error}<p class="alert" role="alert">{error}</p>{/if}
	{#if message}<p class="chip msg" role="status">{message}</p>{/if}

	{#if reviews.length === 0}
		<p class="empty">오늘 복습할 것이 없어요.</p>
	{:else}
		<ul class="list">
			{#each reviews as r (r.id)}
				<li class="item">
					<div class="row wrap">
						<span class="chip stage">{r.interval_days}일차 · {r.stage}/5</span>
						<strong class="grow title">{r.title}</strong>
						<SubjectChip name={r.subject_name} color={r.subject_color} />
					</div>
					<div class="row wrap">
						{#if r.overdue_days > 0}<span class="late small">{r.overdue_days}일 밀렸어요</span>{/if}
						<span class="grow"></span>
						<button class="btn btn-small btn-primary" onclick={() => finish(r, false)} aria-label="{r.title} 복습 완료">완료</button>
						<button class="btn btn-small" onclick={() => finish(r, true)} aria-label="{r.title} 어려웠어요">어려웠어요</button>
						<button class="btn btn-small btn-danger" onclick={() => remove(r)} aria-label="{r.title} 삭제">삭제</button>
					</div>
				</li>
			{/each}
		</ul>
	{/if}

	<form class="add" onsubmit={add}>
		<label class="sr-only" for="rv-title">복습할 내용</label>
		<input id="rv-title" class="input grow" placeholder="복습할 내용 추가" maxlength="120" bind:value={title} required />
		<label class="sr-only" for="rv-subject">과목</label>
		<select id="rv-subject" class="input sel" bind:value={subjectId}>
			<option value="">과목</option>
			{#each subjects as s (s.id)}<option value={s.id}>{s.name}</option>{/each}
		</select>
		<button class="btn">추가</button>
	</form>
</section>

<style>
	.tight {
		gap: 2px;
	}
	.count {
		color: var(--accent);
	}
	.msg {
		align-self: flex-start;
	}
	.list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.item {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding: 10px 12px;
		border: 1px solid var(--line);
		border-radius: 12px;
	}
	.wrap {
		flex-wrap: wrap;
	}
	.stage {
		background: #e2f3e9;
	}
	.title {
		overflow-wrap: anywhere;
	}
	.late {
		color: var(--danger);
		font-weight: 700;
	}
	.add {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
	}
	.sel {
		width: 110px;
	}
	@media (max-width: 600px) {
		.add .grow {
			flex: 1 1 100%;
		}
		.sel {
			flex: 1;
		}
	}
</style>
