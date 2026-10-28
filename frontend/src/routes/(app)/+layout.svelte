<script>
	import { goto } from '$app/navigation';
	import { resolve } from '$app/paths';
	import { page } from '$app/state';
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.js';
	import { inbox, refreshInbox } from '#lib/notify.svelte.js';
	import Logo from '#lib/components/Logo.svelte';

	let { children } = $props();

	$effect(() => {
		if (auth.ready && !auth.user) goto(resolve('/login'), { replaceState: true });
	});

	// 알림함은 앱을 열 때와 5분마다, 탭으로 돌아올 때 새로 가져온다. (알림 생성은 서버가 이 요청에서 한다)
	onMount(() => {
		const tick = () => {
			if (auth.user && !auth.user.must_change_password && !document.hidden) refreshInbox().catch(() => {});
		};
		tick();
		const timer = setInterval(tick, 5 * 60 * 1000);
		document.addEventListener('visibilitychange', tick);
		return () => {
			clearInterval(timer);
			document.removeEventListener('visibilitychange', tick);
		};
	});

	// 관리자가 비밀번호를 초기화한 회원은 새 비밀번호를 정할 때까지 설정 화면에만 머문다.
	$effect(() => {
		if (auth.user?.must_change_password && !page.url.pathname.replace(/\/$/, '').endsWith('/settings')) {
			goto(resolve('/settings'), { replaceState: true });
		}
	});

	const baseItems = [
		{ href: '/', label: '홈', icon: 'M3 11l9-8 9 8M5 10v10h14V10' },
		{ href: '/study', label: '스터디', icon: 'M4 4h12a3 3 0 0 1 3 3v13H7a3 3 0 0 1-3-3zM8 8h7' },
		{ href: '/exams', label: '시험 대비', icon: 'M3 5h18v16H3zM3 10h18M8 3v4M16 3v4' },
		{ href: '/quiz', label: '퀴즈', icon: 'M9 11l3 3 8-8M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h9' },
		{ href: '/assessments', label: '수행평가', icon: 'M5 4h14v17H5zM9 4h6v3H9zM9 12h6M9 16h4' },
		{ href: '/subjects', label: '과목·교재', icon: 'M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z' },
		{ href: '/settings', label: '설정', icon: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19 12a7 7 0 0 0-.1-1.2l2-1.5-2-3.4-2.3 1a7 7 0 0 0-2-1.2L14.2 3h-4.4l-.4 2.7a7 7 0 0 0-2 1.2l-2.3-1-2 3.4 2 1.5A7 7 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.4 2.3-1a7 7 0 0 0 2 1.2l.4 2.7h4.4l.4-2.7a7 7 0 0 0 2-1.2l2.3 1 2-3.4-2-1.5c.1-.4.1-.8.1-1.2z' }
	];

	const adminItem = { href: '/admin', label: '관리', icon: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM9 12l2 2 4-4' };
	const items = $derived(auth.user?.is_admin ? [...baseItems, adminItem] : baseItems);

	function active(href) {
		const target = resolve(href);
		const path = page.url.pathname.replace(/\/$/, '') || '/';
		return href === '/' ? path === target.replace(/\/$/, '') : path.startsWith(target);
	}
</script>

{#if auth.user}
	<div class="shell">
		<nav class="nav" aria-label="주 메뉴">
			<a class="brand" href={resolve('/')} aria-label="스터디 플래너 홈">
				<Logo size={36} />
				<span class="brand-text"><span class="brand-name">스터디 플래너</span><span class="brand-sub">Study Planner</span></span>
			</a>
			<a class="bell" href={resolve('/notifications')} aria-label={inbox.unread > 0 ? `알림 ${inbox.unread}개` : '알림'}>
			<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9a6 6 0 0 1 12 0c0 6 2 7 2 7H4s2-1 2-7zM10 20a2 2 0 0 0 4 0" /></svg>
			{#if inbox.unread > 0}<span class="badge">{inbox.unread > 9 ? '9+' : inbox.unread}</span>{/if}
		</a>
			{#each items as item (item.href)}
				<a class="nav-item" class:active={active(item.href)} href={resolve(item.href)} aria-current={active(item.href) ? 'page' : undefined}>
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d={item.icon} /></svg>
					<span>{item.label}</span>
				</a>
			{/each}
			<a class="nav-item help" class:active={active('/manual')} href={resolve('/manual')} aria-current={active('/manual') ? 'page' : undefined} aria-label="사용 매뉴얼">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01" /></svg>
				<span>사용 매뉴얼</span>
			</a>
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
		text-decoration: none;
		color: inherit;
	}
	.brand-text {
		display: flex;
		flex-direction: column;
		line-height: 1.2;
	}
	.brand-name {
		font-size: 17px;
		font-weight: 700;
	}
	.brand-sub {
		font-size: 11px;
		letter-spacing: 0.04em;
		color: #a3a8b3;
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
	.help {
		margin-top: auto;
	}
	.who {
		margin-top: 4px;
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
	.bell {
		position: absolute;
		top: 24px;
		right: 12px;
		z-index: 5;
		width: 40px;
		height: 40px;
		display: grid;
		place-items: center;
		border-radius: 50%;
		background: var(--surface);
		border: 1px solid var(--line);
		color: var(--ink);
	}
	.badge {
		position: absolute;
		top: -2px;
		right: -2px;
		min-width: 18px;
		height: 18px;
		padding: 0 5px;
		border-radius: 9px;
		background: var(--danger);
		color: #fff;
		font-size: 11px;
		font-weight: 700;
		display: grid;
		place-items: center;
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
		.bell {
			position: fixed;
			top: 10px;
			right: 12px;
			width: 44px;
			height: 44px;
		}
		.help {
			position: fixed;
			top: 10px;
			right: 64px;
			width: 44px;
			height: 44px;
			min-height: 0;
			padding: 0;
			margin: 0;
			flex: none;
			justify-content: center;
			border-radius: 50%;
			background: var(--surface);
			border: 1px solid var(--line);
			color: var(--ink);
		}
		.help span {
			display: none;
		}
		.content {
			padding: 64px 16px 88px;
		}
	}
</style>
