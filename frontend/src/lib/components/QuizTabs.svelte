<script>
	import { resolve } from '$app/paths';

	let { current } = $props();

	const tabs = [
		{ id: 'play', href: '/quiz', label: '퀴즈 풀기' },
		{ id: 'bank', href: '/quiz/questions', label: '문제 만들기' },
		{ id: 'wrong', href: '/quiz/wrong', label: '오답노트' }
	];
</script>

<nav class="tabs" aria-label="퀴즈 메뉴">
	{#each tabs as t (t.id)}
		<a class="tab" class:on={current === t.id} href={resolve(t.href)} aria-current={current === t.id ? 'page' : undefined}>{t.label}</a>
	{/each}
</nav>

<style>
	.tabs {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
	}
	.tab {
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
	.tab.on {
		background: var(--accent);
		border-color: var(--accent);
		color: #fff;
		font-weight: 700;
	}
</style>
