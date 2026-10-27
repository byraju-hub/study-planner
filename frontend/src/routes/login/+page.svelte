<script>
	import { goto } from '$app/navigation';
	import { resolve } from '$app/paths';
	import { auth, login } from '#lib/auth.svelte.js';

	let loginId = $state('');
	let password = $state('');
	let error = $state('');
	let busy = $state(false);

	$effect(() => {
		if (auth.user) goto(resolve('/'), { replaceState: true });
	});

	async function submit(event) {
		event.preventDefault();
		error = '';
		busy = true;
		try {
			await login(loginId, password);
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head><title>로그인 · 스터디 플래너</title></svelte:head>

<main class="auth">
	<section class="intro">
		<div class="logo">SP</div>
		<h1>스터디 플래너</h1>
		<p>오늘 할 일부터 시험까지,<br />한 곳에서 관리해요.</p>
	</section>

	<form class="panel stack" onsubmit={submit}>
		<div class="tabs">
			<span class="tab current">로그인</span>
			<a class="tab" href={resolve('/signup')}>회원가입</a>
		</div>

		{#if error}<p class="alert" role="alert">{error}</p>{/if}

		<div class="field">
			<label for="login_id">아이디</label>
			<input id="login_id" class="input" autocomplete="username" autocapitalize="off" bind:value={loginId} required />
		</div>
		<div class="field">
			<label for="password">비밀번호</label>
			<input id="password" class="input" type="password" autocomplete="current-password" bind:value={password} required />
		</div>
		<button class="btn btn-primary" disabled={busy}>{busy ? '확인 중…' : '로그인'}</button>
		<p class="muted small center">처음이신가요? <a href={resolve('/signup')}>회원가입</a></p>
	</form>
</main>

<style>
	.auth {
		min-height: 100vh;
		display: grid;
		grid-template-columns: 1fr 1fr;
	}
	.intro {
		background: var(--pastel);
		color: #173f2e;
		padding: 80px;
		display: flex;
		flex-direction: column;
		justify-content: center;
		gap: 16px;
	}
	.intro h1 {
		font-size: 40px;
	}
	.intro p {
		font-size: 18px;
		color: #2f5b45;
	}
	.logo {
		width: 48px;
		height: 48px;
		border-radius: 14px;
		background: var(--accent);
		color: #fff;
		display: grid;
		place-items: center;
		font-weight: 700;
	}
	.panel {
		width: min(400px, 100% - 48px);
		margin: auto;
		gap: 16px;
	}
	.tabs {
		display: flex;
		background: #e9e7e0;
		padding: 4px;
		border-radius: 12px;
		gap: 4px;
	}
	.tab {
		flex: 1;
		min-height: 40px;
		display: grid;
		place-items: center;
		border-radius: 9px;
		color: var(--muted);
		text-decoration: none;
		font-weight: 500;
	}
	.tab.current {
		background: #fff;
		color: var(--ink);
		font-weight: 700;
	}
	.center {
		text-align: center;
	}
	@media (max-width: 800px) {
		.auth {
			grid-template-columns: 1fr;
			align-content: center;
			gap: 28px;
			padding: 28px 0;
		}
		.intro {
			background: none;
			padding: 0 28px;
		}
		.intro h1 {
			font-size: 28px;
		}
		.intro p {
			font-size: 15px;
			color: var(--muted);
		}
	}
</style>
