<script>
	import { goto } from '$app/navigation';
	import { resolve } from '$app/paths';
	import { auth } from '#lib/auth.svelte.js';
	import { api, get, put } from '#lib/api.js';

	const STATUS = { active: '사용 중', pending: '승인 대기', blocked: '차단됨' };
	const MODES = {
		open: '누구나 바로 가입',
		approval: '관리자 승인 후 사용',
		closed: '가입 중단'
	};
	const ACTIONS = {
		approve: '가입 승인',
		block: '차단',
		unblock: '차단 해제',
		reset_password: '비밀번호 초기화',
		delete_user: '회원 삭제',
		settings: '설정 변경'
	};

	let users = $state([]);
	let stats = $state(null);
	let settings = $state({ signup_mode: 'open', ai_enabled: '1' });
	let log = $state([]);
	let filter = $state('');
	let q = $state('');
	let error = $state('');
	let notice = $state('');
	let tempPw = $state(null);
	let delTarget = $state(null);
	let delConfirm = $state('');

	$effect(() => {
		if (auth.user && !auth.user.is_admin) goto(resolve('/'), { replaceState: true });
		else if (auth.user) refresh();
	});

	async function refresh() {
		try {
			const params = new URLSearchParams();
			if (filter) params.set('status', filter);
			if (q.trim()) params.set('q', q.trim());
			const [u, s, st, l] = await Promise.all([
				get('/admin/users?' + params),
				get('/admin/stats'),
				get('/admin/settings'),
				get('/admin/log')
			]);
			users = u.users;
			stats = s;
			settings = st.settings;
			log = l.log;
		} catch (e) {
			error = e.message;
		}
	}

	async function run(fn, done = '') {
		error = notice = '';
		try {
			await fn();
			if (done) notice = done;
			await refresh();
		} catch (e) {
			error = e.message;
		}
	}

	const setStatus = (u, status, done) => run(() => api('POST', `/admin/users/${u.id}/status`, { status }), done);

	async function resetPassword(u) {
		if (!confirm(`${u.name}(${u.login_id}) 님의 비밀번호를 초기화할까요?`)) return;
		await run(async () => {
			const r = await api('POST', `/admin/users/${u.id}/reset-password`);
			tempPw = { who: `${u.name}(${u.login_id})`, value: r.temporary_password };
		});
	}

	async function removeUser(event) {
		event.preventDefault();
		await run(async () => {
			await api('DELETE', `/admin/users/${delTarget.id}`, { confirm_login_id: delConfirm });
			delTarget = null;
			delConfirm = '';
		}, '회원을 삭제했어요.');
	}

	const saveSettings = (patch) => run(async () => {
		const r = await put('/admin/settings', patch);
		settings = r.settings;
	}, '설정을 저장했어요.');

	function mb(bytes) {
		return (bytes / 1048576).toFixed(1) + 'MB';
	}
</script>

<svelte:head><title>관리 · 스터디 플래너</title></svelte:head>

{#if auth.user?.is_admin}
	<div class="stack" style="gap: 20px; max-width: 980px;">
		<h1>관리</h1>
		<p class="muted small">회원 계정만 관리해요. 회원이 입력한 공부 내용과 업로드 자료는 볼 수 없어요.</p>

		{#if error}<p class="alert" role="alert">{error}</p>{/if}
		{#if notice}<p class="chip" role="status">{notice}</p>{/if}

		{#if tempPw}
			<div class="card stack temp" role="status">
				<strong>{tempPw.who} 님의 임시 비밀번호</strong>
				<code>{tempPw.value}</code>
				<p class="muted small">지금 한 번만 보여요. 회원에게 직접 알려 주세요. 처음 로그인하면 새 비밀번호를 정해야 해요.</p>
				<button class="btn btn-small" onclick={() => (tempPw = null)}>닫기</button>
			</div>
		{/if}

		{#if stats}
			<div class="stats">
				<div class="card"><span class="muted small">사용 중</span><strong>{stats.counts.active}</strong></div>
				<div class="card" class:hot={stats.counts.pending > 0}><span class="muted small">승인 대기</span><strong>{stats.counts.pending}</strong></div>
				<div class="card"><span class="muted small">차단됨</span><strong>{stats.counts.blocked}</strong></div>
				<div class="card"><span class="muted small">최근 7일 접속</span><strong>{stats.active_last_7_days}</strong></div>
				<div class="card"><span class="muted small">업로드 용량</span><strong>{mb(stats.file_bytes)}</strong></div>
			</div>
		{/if}

		<section class="card stack">
			<h2>가입 설정</h2>
			<div class="field">
				<label for="mode">가입 방식</label>
				<select id="mode" class="input" value={settings.signup_mode} onchange={(e) => saveSettings({ signup_mode: e.currentTarget.value })}>
					{#each Object.entries(MODES) as [v, label] (v)}<option value={v}>{label}</option>{/each}
				</select>
				<span class="hint">관리자 아이디로는 어떤 설정에서도 가입할 수 있어요.</span>
			</div>
			<label class="check">
				<input type="checkbox" checked={settings.ai_enabled === '1'} onchange={(e) => saveSettings({ ai_enabled: e.currentTarget.checked })} />
				<span>AI 문제 만들기 허용</span>
			</label>
		</section>

		<section class="card stack">
			<h2>회원</h2>
			<form class="row filters" onsubmit={(e) => { e.preventDefault(); refresh(); }}>
				<input class="input grow" placeholder="아이디·이름·이메일 검색" bind:value={q} />
				<select class="input sel" bind:value={filter} onchange={refresh} aria-label="상태 필터">
					<option value="">전체</option>
					{#each Object.entries(STATUS) as [v, label] (v)}<option value={v}>{label}</option>{/each}
				</select>
				<button class="btn">검색</button>
			</form>

			{#if users.length === 0}
				<p class="empty">해당하는 회원이 없어요.</p>
			{:else}
				<ul class="users">
					{#each users as u (u.id)}
						<li class="user" class:pending={u.status === 'pending'}>
							<div class="who">
								<strong>{u.name}</strong> <span class="muted">@{u.login_id}</span>
								{#if u.is_admin}<span class="chip">관리자</span>{/if}
								<span class="chip st-{u.status}">{STATUS[u.status]}</span>
								<div class="muted small">
									{u.email}{u.school_name ? ' · ' + u.school_name : ''}{u.grade ? ' ' + u.grade + '학년' : ''}
								</div>
								<div class="muted small">
									가입 {u.created_at.slice(0, 10)} · 마지막 접속 {u.last_login_at ? u.last_login_at.slice(0, 10) : '없음'}
									· 할 일 {u.task_count} · 자료 {u.material_count} ({mb(u.file_bytes)})
								</div>
							</div>
							{#if !u.is_admin}
								<div class="actions">
									{#if u.status === 'pending'}
										<button class="btn btn-primary btn-small" onclick={() => setStatus(u, 'active', '승인했어요.')}>승인</button>
									{:else if u.status === 'active'}
										<button class="btn btn-small" onclick={() => setStatus(u, 'blocked', '차단했어요.')}>차단</button>
									{:else}
										<button class="btn btn-small" onclick={() => setStatus(u, 'active', '차단을 풀었어요.')}>차단 해제</button>
									{/if}
									<button class="btn btn-small" onclick={() => resetPassword(u)}>비밀번호 초기화</button>
									<button class="btn btn-small btn-danger" onclick={() => { delTarget = u; delConfirm = ''; }}>삭제</button>
								</div>
							{/if}
						</li>
					{/each}
				</ul>
			{/if}
		</section>

		{#if delTarget}
			<form class="card stack" onsubmit={removeUser}>
				<p class="alert">{delTarget.name}(@{delTarget.login_id}) 님과 그 데이터가 모두 삭제돼요. 되돌릴 수 없어요.</p>
				<div class="field">
					<label for="cf">확인을 위해 아이디를 입력하세요</label>
					<input id="cf" class="input" bind:value={delConfirm} autocomplete="off" required />
				</div>
				<div class="row">
					<button class="btn btn-danger" disabled={delConfirm.trim().toLowerCase() !== delTarget.login_id}>영구 삭제</button>
					<button type="button" class="btn" onclick={() => (delTarget = null)}>취소</button>
				</div>
			</form>
		{/if}

		<section class="card stack">
			<h2>관리 기록</h2>
			{#if log.length === 0}
				<p class="empty">아직 기록이 없어요.</p>
			{:else}
				<ul class="log">
					{#each log as l (l.id)}
						<li><span class="muted small">{l.created_at.slice(5, 16)}</span> {ACTIONS[l.action] ?? l.action}{l.detail ? ` · ${l.detail}` : ''}</li>
					{/each}
				</ul>
			{/if}
		</section>
	</div>
{/if}

<style>
	.stats {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
		gap: 12px;
	}
	.stats .card {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}
	.stats strong {
		font-size: 24px;
	}
	.stats .hot {
		border-color: var(--accent);
		background: var(--accent-soft);
	}
	.filters {
		gap: 8px;
		flex-wrap: wrap;
	}
	.sel {
		width: 130px;
	}
	.users,
	.log {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
	}
	.user {
		display: flex;
		justify-content: space-between;
		gap: 12px;
		flex-wrap: wrap;
		padding: 12px 0;
		border-top: 1px solid var(--line);
	}
	.user.pending {
		background: var(--accent-soft);
		margin: 0 -18px;
		padding: 12px 18px;
	}
	.actions {
		display: flex;
		gap: 6px;
		flex-wrap: wrap;
		align-items: flex-start;
	}
	.st-blocked {
		background: var(--danger-soft);
		color: var(--danger);
	}
	.log li {
		padding: 6px 0;
		border-top: 1px solid var(--line);
		font-size: 14px;
	}
	.temp code {
		font-size: 22px;
		letter-spacing: 2px;
		user-select: all;
	}
	.check {
		display: flex;
		align-items: center;
		gap: 10px;
		min-height: 44px;
	}
</style>
