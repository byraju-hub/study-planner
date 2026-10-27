<script>
	import { resolve } from '$app/paths';

	/** 3개 모드를 오가는 칩. 아직 없는 모드는 목록에 넣지 않는다. */
	let { current } = $props();

	const modes = [
		{ id: 'study', href: '/study', label: '일반 스터디' },
		{ id: 'exam', href: '/exams', label: '시험 대비' }
	];
</script>

<nav class="modes" aria-label="모드">
	{#each modes as m (m.id)}
		<a class="mode" class:on={current === m.id} href={resolve(m.href)} aria-current={current === m.id ? 'page' : undefined}>{m.label}</a>
	{/each}
</nav>

<style>
	.modes {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
	}
	.mode {
		display: inline-flex;
		align-items: center;
		min-height: 38px;
		padding: 0 16px;
		border-radius: 19px;
		border: 1px solid var(--line);
		background: var(--surface);
		color: var(--ink);
		text-decoration: none;
		font-size: 14px;
	}
	.mode.on {
		background: var(--accent);
		border-color: var(--accent);
		color: #fff;
		font-weight: 700;
	}
</style>
