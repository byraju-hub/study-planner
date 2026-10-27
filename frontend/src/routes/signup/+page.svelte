<script>
	import { goto } from '$app/navigation';
	import { resolve } from '$app/paths';
	import { auth, signup } from '#lib/auth.svelte.js';

	let f = $state({ login_id: '', email: '', password: '', name: '', school_name: '', grade: '' });
	let error = $state('');
	let errorField = $state('');
	let busy = $state(false);

	$effect(() => {
		if (auth.user) goto(resolve('/'), { replaceState: true });
	});

	async function submit(event) {
		event.preventDefault();
		error = '';
		errorField = '';
		busy = true;
		try {
			await signup({
				login_id: f.login_id,
				email: f.email,
				password: f.password,
				name: f.name,
				school_name: f.school_name || null,
				grade: f.grade ? Number(f.grade) : null
			});
		} catch (e) {
			error = e.message;
			errorField = e.field ?? '';
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head><title>회원가입 · 스터디 플래너</title></svelte:head>

<main class="auth">
	<form class="panel stack" onsubmit={submit}>
		<div class="tabs">
			<a class="tab" href={resolve('/login')}>로그인</a>
			<span class="tab current">회원가입</span>
		</div>

		{#if error}<p class="alert" role="alert">{error}</p>{/if}

		<div class="field">
			<label for="login_id">아이디</label>
			<input id="login_id" class="input" autocomplete="username" autocapitalize="off" minlength="4" maxlength="20" bind:value={f.login_id} required aria-invalid={errorField === 'login_id'} />
			<span class="hint">영문 소문자, 숫자, _ 로 4~20자</span>
		</div>
		<div class="field">
			<label for="email">이메일</label>
			<input id="email" class="input" type="email" autocomplete="email" bind:value={f.email} required aria-invalid={errorField === 'email'} />
		</div>
		<div class="field">
			<label for="password">비밀번호</label>
			<input id="password" class="input" type="password" autocomplete="new-password" minlength="8" bind:value={f.password} required aria-invalid={errorField === 'password'} />
			<span class="hint">8자 이상, 영문과 숫자를 모두 포함</span>
		</div>
		<div class="field">
			<label for="name">이름</label>
			<input id="name" class="input" autocomplete="name" maxlength="30" bind:value={f.name} required />
		</div>
		<div class="row">
			<div class="field grow">
				<label for="school">학교 (선택)</label>
				<input id="school" class="input" maxlength="100" bind:value={f.school_name} />
			</div>
			<div class="field grade">
				<label for="grade">학년</label>
				<select id="grade" class="input" bind:value={f.grade}>
					<option value="">선택</option>
					<option value="1">1학년</option>
					<option value="2">2학년</option>
					<option value="3">3학년</option>
				</select>
			</div>
		</div>
		<p class="hint">수집하는 정보는 서비스 이용에 필요한 최소한이며, 가입하면 기본 과목이 만들어져요.</p>
		<button class="btn btn-primary" disabled={busy}>{busy ? '가입 중…' : '가입하기'}</button>
	</form>
</main>

<style>
	.auth {
		min-height: 100vh;
		display: grid;
		place-items: center;
		padding: 28px 0;
	}
	.panel {
		width: min(420px, 100% - 48px);
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
	.grade {
		width: 110px;
	}
</style>
