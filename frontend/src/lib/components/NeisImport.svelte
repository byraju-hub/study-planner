<script>
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.js';
	import { get, post } from '#lib/api.js';

	/** 나이스에서 우리 반 시간표를 불러와 칸에 채운다. 저장은 학생이 확인하고 직접 누른다. */
	let { onfill, onclose } = $props();

	let status = $state(null);
	let query = $state(auth.user?.school_name ?? '');
	let schools = $state(null);
	let school = $state(null);
	let grade = $state(auth.user?.grade ? String(auth.user.grade) : '1');
	let classNo = $state(auth.user?.class_no ? String(auth.user.class_no) : '');
	let error = $state('');
	let info = $state('');
	let busy = $state(false);

	onMount(async () => {
		try {
			status = await get('/integrations');
		} catch (e) {
			error = e.message;
		}
	});

	async function search(event) {
		event?.preventDefault();
		error = '';
		busy = true;
		try {
			schools = await get('/neis/schools?q=' + encodeURIComponent(query));
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}

	async function load(event) {
		event.preventDefault();
		error = info = '';
		busy = true;
		try {
			const r = await post('/neis/timetable', { office_code: school.office_code, school_code: school.school_code, grade: Number(grade), class_no: Number(classNo) });
			if (r.slots.length === 0) {
				info = '이 학급의 이번 주 시간표가 나이스에 없어요. 학교가 아직 올리지 않았을 수 있어요.';
				return;
			}
			onfill(r.slots.filter((s) => s.subject_id !== null));
			info = `${r.slots.filter((s) => s.subject_id !== null).length}칸을 채웠어요.` + (r.unmatched.length ? ` 내 과목에 없는 수업은 건너뛰었어요: ${r.unmatched.join(', ')}` : '') + ' 확인하고 아래에서 저장해 주세요.';
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}
</script>

<div class="card stack" style="background: var(--bg);">
	<div class="row-between">
		<h3>나이스에서 시간표 가져오기</h3>
		<button class="btn btn-small" onclick={onclose}>닫기</button>
	</div>

	{#if status && !status.neis}
		<p class="muted small">나이스 연동이 설정되지 않았어요. 관리자가 교육정보 개방 포털 인증키를 서버 설정에 넣으면 쓸 수 있어요. 그동안은 직접 입력해 주세요.</p>
	{:else}
		<p class="muted small">교육청이 공개한 학급 시간표(이번 주)를 가져와요. 로그인 정보는 쓰지 않아요. 선택 과목처럼 반마다 다른 수업은 직접 확인해 주세요.</p>
		{#if error}<p class="alert" role="alert">{error}</p>{/if}
		{#if info}<p class="chip" role="status">{info}</p>{/if}

		{#if !school}
			<form class="row" onsubmit={search}>
				<input class="input grow" placeholder="학교 이름" bind:value={query} required minlength="2" aria-label="학교 이름" />
				<button class="btn btn-soft" disabled={busy}>검색</button>
			</form>
			{#if schools}
				{#if schools.length === 0}
					<p class="muted small">검색 결과가 없어요.</p>
				{:else}
					<ul class="schools">
						{#each schools as s (s.school_code)}
							<li><button class="school" onclick={() => (school = s)}><strong>{s.name}</strong><span class="muted small">{s.office_name} · {s.kind} · {s.address}</span></button></li>
						{/each}
					</ul>
				{/if}
			{/if}
		{:else}
			<p class="small"><strong>{school.name}</strong> <button class="link" onclick={() => (school = null)}>다른 학교</button></p>
			<form class="row" onsubmit={load}>
				<div class="field">
					<label for="ng">학년</label>
					<select id="ng" class="input" bind:value={grade}><option>1</option><option>2</option><option>3</option></select>
				</div>
				<div class="field">
					<label for="nc">반</label>
					<input id="nc" class="input" type="number" min="1" max="40" bind:value={classNo} required />
				</div>
				<button class="btn btn-primary" style="align-self: flex-end;" disabled={busy}>불러오기</button>
			</form>
		{/if}
	{/if}
</div>

<style>
	.schools {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
	.school {
		width: 100%;
		display: flex;
		flex-direction: column;
		text-align: left;
		padding: 10px 12px;
		border: 1px solid var(--line);
		border-radius: 10px;
		background: var(--surface);
		cursor: pointer;
		color: var(--ink);
	}
	.link {
		background: none;
		border: 0;
		color: var(--accent-dark);
		text-decoration: underline;
		cursor: pointer;
	}
</style>
