<script>
	import { goto } from '$app/navigation';
	import { resolve } from '$app/paths';
	import { page } from '$app/state';
	import { auth } from '#lib/auth.svelte.js';

	let { children } = $props();

	$effect(() => {
		if (auth.ready && !auth.user) goto(resolve('/login'), { replaceState: true });
	});

	const items = [
		{ href: '/', label: '홈', icon: 'M3 11l9-8 9 8M5 10v10h14V10' },
		{ href: '/study', label: '일반 스터디', icon: 'M4 4h12a3 3 0 0 1 3 3v13H7a3 3 0 0 1-3-3zM8 8h7' },
		{ href: '/exams', label: '시험 대비', icon: 'M3 5h18v16H3zM3 10h18M8 3v4M16 3v4' },
		{ href: '/subjects', label: '과목 · 교재', icon: 'M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z' },
		{ href: '/settings', label: '설정', icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19 12a7 7 0 0 0-.1-1.2l2-1.5-2-3.4-2.3 1a7 7 0 0 0-2-1.2L14.2 3h-4.4l-.4 2.7a7 7 0 0 0-2 1.2l-2.3-1-2 3.4 2 1.5A7 7 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.4 2.3-1a7 7 0 0 0 2 1.2l.4 2.7h4.4l.4-2.7a7 7 0 0 0 2-1.2l2.3 1 2-3.4-2-1.5c.1-.4.1-.8.1-1.2z' }
	];

	function active(href) {
		const target = resolve(href);
		const path = page.url.pathname.replace(/\/$/, '') || '/';
		return href === '/' ? path === target.replace(/\/$/, '') : path.startsWith(target);
	}
</script>

{#if auth.user}
	<div class="shell">
		<nav class="nav" aria-label="주 메뉴">
			<div class="brand"><span class="logo">SP</span><span class="brand-name">스터디 플래너</span></div>
			{#each items as item (item.href)}
				<a class="nav-item" class:active={active(item.href)} href={resolve(item.href)} aria-current={active(item.href) ? 'page' : undefined}>
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d={item.icon} /></svg>
					<span>{item.label}</span>
				</a>
			{/each}
			<div class="who">
				<span class="avatar" aria-hidden="true">{auth.user.name.slice(0, 1)}</span>
				<span class="who-text"><strong>{auth.user.name}</strong><span class="muted small">{auth.user.school_name ?? '고등학생'}</span></span>
			</div>
		</nav>
		<main class="content">{@render children()}</main>
	</div>
{/if}

<style>
	.shell {
		display: flex;
		min-height: 100vh;
	}
	.nav {
		width: 240px;
		flex: none;
		background: var(--surface);
		border-right: 1px solid var(--line);
		padding: 24px 16px;
		display: flex;
		flex-direction: column;
		gap: 4px;
		position: sticky;
		top: 0;
		height: 100vh;
	}
	.brand {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 0 6px 20px;
	}
	.logo {
		width: 36px;
		height: 36px;
		border-radius: 10px;
		background: var(--accent);
		color: #fff;
		display: grid;
		place-items: center;
		font-weight: 700;
		font-size: 14px;
	}
	.brand-name {
		font-size: 17px;
		font-weight: 700;
	}
	.nav-item {
		display: flex;
		align-items: center;
		gap: 12px;
		min-height: 44px;
		padding: 0 14px;
		border-radius: 10px;
		text-decoration: none;
		color: var(--muted);
		font-weight: 500;
	}
	.nav-item.active {
		background: var(--accent-soft);
		color: var(--accent-dark);
		font-weight: 700;
	}
	.who {
		margin-top: auto;
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 12px 8px;
		border-top: 1px solid var(--line);
	}
	.avatar {
		width: 36px;
		height: 36px;
		border-radius: 50%;
		background: var(--accent-soft);
		color: var(--accent-dark);
		display: grid;
		place-items: center;
		font-weight: 700;
	}
	.who-text {
		display: flex;
		flex-direction: column;
		line-height: 1.3;
		min-width: 0;
	}
	.content {
		flex: 1;
		min-width: 0;
		padding: 32px 40px;
	}

	@media (max-width: 800px) {
		.shell {
			flex-direction: column;
		}
		.nav {
			order: 2;
			position: fixed;
			left: 0;
			right: 0;
			bottom: 0;
			top: auto;
			width: auto;
			height: 64px;
			flex-direction: row;
			padding: 0;
			gap: 0;
			border-right: 0;
			border-top: 1px solid var(--line);
			z-index: 10;
		}
		.brand,
		.who {
			display: none;
		}
		.nav-item {
			flex: 1;
			flex-direction: column;
			justify-content: center;
			gap: 2px;
			border-radius: 0;
			font-size: 11px;
			padding: 0;
		}
		.content {
			padding: 20px 16px 88px;
		}
	}
</style>
