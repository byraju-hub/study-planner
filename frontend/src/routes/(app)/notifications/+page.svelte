<script>
	import { goto } from '$app/navigation';
	import { resolve } from '$app/paths';
	import { onMount } from 'svelte';
	import { get, put } from '#lib/api.js';
	import { currentSubscription, disablePush, enablePush, inbox, markAllRead, markRead, pushSupported, refreshInbox } from '#lib/notify.svelte.js';


	let settings = $state(null);
	let push = $state({ available: false, public_key: null });
	let pushOn = $state(false);
	let msg = $state('');
	let err = $state('');

	onMount(async () => {
		try {
			await refreshInbox();
			const data = await get('/notifications/settings');
			settings = { ...data.settings, evening_on: data.settings.evening_time !== null, evening_time: data.settings.evening_time ?? '21:00' };
			push = data.push;
			pushOn = (await currentSubscription()) !== null;
		} catch (e) {
			err = e.message;
		}
	});

	async function open(n) {
		if (!n.read_at) await markRead(n.id);
		goto(resolve(n.link === '/' ? '/' : n.link));
	}

	async function save(event) {
		event.preventDefault();
		msg = err = '';
		try {
			await put('/notifications/settings', {
				tasks_on: settings.tasks_on,
				review_on: settings.review_on,
				exam_on: settings.exam_on,
				assessment_on: settings.assessment_on,
				morning_time: settings.morning_time,
				evening_time: settings.evening_on ? settings.evening_time : null
			});
			msg = '저장했어요.';
		} catch (e) {
			err = e.message;
		}
	}

	async function togglePush() {
		msg = err = '';
		try {
			if (pushOn) {
				await disablePush();
				pushOn = false;
			} else if (await enablePush(push.public_key)) {
				pushOn = true;
				msg = '이 기기에서 푸시 알림을 켰어요.';
			} else {
				err = '브라우저에서 알림 권한이 거절됐어요. 주소창의 사이트 설정에서 허용해 주세요.';
			}
		} catch (e) {
			err = e.message || '푸시 알림을 켜지 못했어요.';
		}
	}

	const when = (t) => t.slice(5, 16).replace('-', '/');
</script>

<svelte:head><title>알림 · 스터디 플래너</title></svelte:head>

<div class="stack" style="gap: 20px; max-width: 640px;">
	<div class="row-between">
		<h1>알림</h1>
		{#if inbox.unread > 0}<button class="btn btn-small" onclick={markAllRead}>모두 읽음</button>{/if}
	</div>

	{#if err}<p class="alert" role="alert">{err}</p>{/if}
	{#if msg}<p class="chip" role="status">{msg}</p>{/if}

	{#if inbox.loaded && inbox.items.length === 0}
		<p class="empty">아직 알림이 없어요. 오늘 할 일·복습·시험·수행평가가 생기면 알려 드려요.</p>
	{:else}
		<ul class="list">
			{#each inbox.items as n (n.id)}
				<li>
					<button class="item" class:unread={!n.read_at} onclick={() => open(n)}>
						<span class="dot" class:on={!n.read_at} aria-hidden="true"></span>
						<span class="text">
							<strong>{n.title}</strong>
							{#if n.body}<span class="muted small">{n.body}</span>{/if}
						</span>
						<span class="muted small when">{when(n.created_at)}</span>
					</button>
				</li>
			{/each}
		</ul>
	{/if}

	{#if settings}
		<form class="card stack" onsubmit={save}>
			<h2>알림 설정</h2>
			<label class="check"><input type="checkbox" bind:checked={settings.tasks_on} /> 오늘 할 일</label>
			<label class="check"><input type="checkbox" bind:checked={settings.review_on} /> 복습할 내용</label>
			<label class="check"><input type="checkbox" bind:checked={settings.exam_on} /> 시험 D-7 · 3 · 1 · 당일</label>
			<label class="check"><input type="checkbox" bind:checked={settings.assessment_on} /> 수행평가 마감 D-3 · 1 · 당일 · 지남</label>
			<div class="row">
				<div class="field grow">
					<label for="morning">아침 알림 시각</label>
					<input id="morning" class="input" type="time" bind:value={settings.morning_time} required />
				</div>
				<div class="field grow">
					<label for="evening">저녁 알림 시각</label>
					<input id="evening" class="input" type="time" bind:value={settings.evening_time} disabled={!settings.evening_on} />
				</div>
			</div>
			<label class="check"><input type="checkbox" bind:checked={settings.evening_on} /> 저녁에 못 한 할 일 알려 받기</label>
			<button class="btn btn-primary">저장</button>
		</form>

		<section class="card stack">
			<h2>이 기기로 푸시 받기</h2>
			{#if !push.available}
				<p class="muted small">서버에 푸시 설정이 아직 없어요. 앱을 열 때 알림함으로 알려 드려요.</p>
			{:else if !pushSupported()}
				<p class="muted small">이 브라우저는 푸시 알림을 지원하지 않아요. (아이폰은 홈 화면에 추가한 뒤에 가능해요)</p>
			{:else}
				<p class="muted small">앱을 닫아 둬도 아침·저녁 알림을 받을 수 있어요.</p>
				<button class="btn" class:btn-soft={!pushOn} onclick={togglePush}>{pushOn ? '이 기기 푸시 끄기' : '이 기기 푸시 켜기'}</button>
			{/if}
		</section>
	{/if}
</div>

<style>
	.list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.item {
		width: 100%;
		display: flex;
		align-items: center;
		gap: 12px;
		text-align: left;
		padding: 12px 14px;
		min-height: 56px;
		border: 1px solid var(--line);
		border-radius: var(--radius);
		background: var(--surface);
		cursor: pointer;
		color: var(--ink);
	}
	.item.unread {
		background: var(--accent-soft);
		border-color: transparent;
	}
	.dot {
		background: transparent;
	}
	.dot.on {
		background: var(--accent);
	}
	.text {
		display: flex;
		flex-direction: column;
		flex: 1;
		min-width: 0;
	}
	.when {
		flex: none;
	}
	.check {
		display: flex;
		align-items: center;
		gap: 10px;
		min-height: 40px;
	}
</style>
