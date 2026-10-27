<script>
	import { resolve } from '$app/paths';
	import { dueLabel, fmtMD } from '#lib/format.js';
	import SubjectChip from './SubjectChip.svelte';

	let { a } = $props();

	const finished = $derived(a.status === 'submitted' || a.status === 'graded');
	const urgent = $derived(!finished && a.d_day <= 3);
	const pct = $derived(a.step_total > 0 ? Math.round((a.step_done / a.step_total) * 100) : 0);
	const meta = $derived(
		[a.kind_label, a.max_score !== null ? `${a.max_score}점` : null, a.weight_pct !== null ? `반영 ${a.weight_pct}%` : null].filter(Boolean).join(' · ')
	);
</script>

<a class="card box" href={resolve('/assessments/[id]', { id: String(a.id) })}>
	<div class="row">
		<SubjectChip name={a.subject_name} color={a.subject_color} />
		<strong class="grow title">{a.title}</strong>
		<span class="badge" class:urgent class:fin={finished}>{dueLabel(a)}</span>
	</div>
	<span class="muted small">{meta} · {fmtMD(a.due_date)} 마감</span>
	{#if a.exam_warning}<span class="warn small">{a.exam_warning}</span>{/if}
	{#if a.status === 'graded' && a.score !== null}
		<strong class="small">{Number(a.score)}{a.max_score !== null ? ` / ${a.max_score}` : ''}점</strong>
	{:else if a.step_total > 0}
		<div class="row">
			<div class="bar grow" role="progressbar" aria-valuenow={pct} aria-valuemin="0" aria-valuemax="100" aria-label="단계 진행"><span style:width="{pct}%"></span></div>
			<span class="small step">{a.step_done}/{a.step_total} 단계</span>
		</div>
	{/if}
</a>

<style>
	.box {
		display: flex;
		flex-direction: column;
		gap: 8px;
		text-decoration: none;
		color: var(--ink);
	}
	.box:hover {
		border-color: var(--accent);
	}
	.title {
		overflow-wrap: anywhere;
	}
	.badge {
		padding: 3px 10px;
		border-radius: 999px;
		background: #e9e7e0;
		font-size: 12px;
		font-weight: 700;
		white-space: nowrap;
	}
	.badge.urgent {
		background: var(--danger);
		color: #fff;
	}
	.badge.fin {
		background: var(--accent-soft);
		color: var(--accent-dark);
	}
	.warn {
		background: var(--warn-soft);
		color: #5c3d00;
		border-radius: 8px;
		padding: 6px 10px;
	}
	.bar {
		height: 6px;
		border-radius: 3px;
		background: #efede6;
	}
	.bar span {
		display: block;
		height: 6px;
		border-radius: 3px;
		background: var(--accent);
	}
	.step {
		font-weight: 700;
	}
</style>
