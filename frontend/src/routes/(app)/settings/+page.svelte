<script>
	import { goto } from '$app/navigation';
	import { resolve } from '$app/paths';
	import { auth, logout } from '#lib/auth.svelte.js';
	import { api, put, setCsrf } from '#lib/api.js';

	let profile = $state({
		name: auth.user.name,
		school_name: auth.user.school_name ?? '',
		grade: auth.user.grade ? String(auth.user.grade) : '',
		class_no: auth.user.class_no ? String(auth.user.class_no) : ''
	});
	let profileMsg = $state('');
	let profileErr = $state('');

	let pw = $state({ current_password: '', new_password: '' });
	let pwMsg = $state('');
	let pwErr = $state('');

	async function saveProfile(event) {
		event.preventDefault();
		profileMsg = profileErr = '';
		try {
			const data = await put('/auth/profile', {
				name: profile.name,
				school_name: profile.school_name || null,
				grade: profile.grade ? Number(profile.grade) : null,
				class_no: profile.class_no ? Number(profile.class_no) : null
			});
			auth.user = data.user;
			profileMsg = '저장했어요.';
		} catch (e) {
			profileErr = e.message;
		}
	}

	async function changePassword(event) {
		event.preventDefault();
		pwMsg = pwErr = '';
		try {
			await put('/auth/password', pw);
			pw = { current_password: '', new_password: '' };
			pwMsg = '비밀번호를 바꿨어요.';
			auth.user = { ...auth.user, must_change_password: false };
		} catch (e) {
			pwErr = e.message;
		}
	}

	let del = $state({ password: '' });
	let delErr = $state('');
	let delOpen = $state(false);

	async function deleteAccount(event) {
		event.preventDefault();
		delErr = '';
		try {
			const data = await api('DELETE', '/auth/account', { password: del.password });
			setCsrf(data.csrf);
			auth.user = null;
			goto(resolve('/login'));
		} catch (e) {
			delErr = e.message;
		}
	}

	async function doLogout() {
		await logout();
		goto(resolve('/login'));
	}
</script>

<svelte:head><title>설정 · 스터디 플래너</title></svelte:head>

<div class="stack" style="gap: 20px; max-width: 560px;">
	<h1>설정</h1>

	{#if auth.user.must_change_password}
		<p class="alert" role="alert">관리자가 비밀번호를 초기화했어요. 아래에서 새 비밀번호를 정해야 다른 화면을 쓸 수 있어요.</p>
	{/if}

	<form class="card stack" onsubmit={saveProfile}>
		<h2>내 정보</h2>
		{#if profileErr}<p class="alert" role="alert">{profileErr}</p>{/if}
		{#if profileMsg}<p class="chip" role="status">{profileMsg}</p>{/if}
		<div class="field">
			<label for="name">이름</label>
			<input id="name" class="input" bind:value={profile.name} maxlength="30" required />
		</div>
		<div class="field">
			<label for="school">학교</label>
			<input id="school" class="input" bind:value={profile.school_name} maxlength="100" />
		</div>
		<div class="row">
			<div class="field grow">
				<label for="grade">학년</label>
				<select id="grade" class="input" bind:value={profile.grade}>
					<option value="">선택 안 함</option>
					<option value="1">1학년</option>
					<option value="2">2학년</option>
					<option value="3">3학년</option>
				</select>
			</div>
			<div class="field grow">
				<label for="class_no">반</label>
				<input id="class_no" class="input" type="number" min="1" max="30" bind:value={profile.class_no} />
			</div>
		</div>
		<button class="btn btn-primary">저장</button>
	</form>

	<form class="card stack" onsubmit={changePassword}>
		<h2>비밀번호 변경</h2>
		{#if pwErr}<p class="alert" role="alert">{pwErr}</p>{/if}
		{#if pwMsg}<p class="chip" role="status">{pwMsg}</p>{/if}
		<div class="field">
			<label for="cur">현재 비밀번호</label>
			<input id="cur" class="input" type="password" autocomplete="current-password" bind:value={pw.current_password} required />
		</div>
		<div class="field">
			<label for="new">새 비밀번호</label>
			<input id="new" class="input" type="password" autocomplete="new-password" minlength="8" bind:value={pw.new_password} required />
			<span class="hint">8자 이상, 영문과 숫자를 모두 포함</span>
		</div>
		<button class="btn">비밀번호 변경</button>
	</form>

	<button class="btn btn-danger" onclick={doLogout}>로그아웃</button>

	{#if !auth.user.is_admin}
		<section class="card stack">
			<h2>회원 탈퇴</h2>
			{#if !delOpen}
				<p class="muted small">탈퇴하면 과목·교재·자료·기록이 모두 지워지고 되돌릴 수 없어요.</p>
				<button class="btn" onclick={() => (delOpen = true)}>탈퇴하기</button>
			{:else}
				<form class="stack" onsubmit={deleteAccount}>
					<p class="alert">정말 탈퇴할까요? 모든 데이터가 바로 삭제돼요.</p>
					{#if delErr}<p class="alert" role="alert">{delErr}</p>{/if}
					<div class="field">
						<label for="delpw">비밀번호 확인</label>
						<input id="delpw" class="input" type="password" autocomplete="current-password" bind:value={del.password} required />
					</div>
					<div class="row">
						<button class="btn btn-danger">탈퇴</button>
						<button type="button" class="btn" onclick={() => (delOpen = false)}>취소</button>
					</div>
				</form>
			{/if}
		</section>
	{/if}
</div>
