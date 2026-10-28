<script>
	import { onMount } from 'svelte';
	import { get, post } from '#lib/api.js';
	import { QUESTION_TYPES } from '#lib/format.js';

	/** 내 노트(또는 붙여 넣은 글)로 AI 가 문제 후보를 만든다. 학생이 읽고 고른 것만 저장한다. */
	let { subjectId, unitId, onsaved } = $props();

	let open = $state(false);
	let status = $state(null);
	let agree = $state(false);
	let text = $state('');
	let count = $state(5);
	let types = $state({ mcq: true, ox: true, short: true, essay: false, card: false });
	let candidates = $state(null);
	let error = $state('');
	let msg = $state('');
	let busy = $state(false);

	async function loadStatus() {
		status = await get('/integrations');
	}
	onMount(() => loadStatus().catch(() => {}));

	async function consent() {
		error = '';
		try {
			await post('/ai/consent', { agree: true });
			await loadStatus();
		} catch (e) {
			error = e.message;
		}
	}

	async function generate(event) {
		event.preventDefault();
		error = msg = '';
		busy = true;
		try {
			const r = await post('/ai/questions', {
				unit_id: unitId || null,
				text: text || null,
				count: Number(count),
				types: Object.keys(types).filter((t) => types[t])
			});
			candidates = r.questions.map((q) => ({ ...q, on: true }));
			status = { ...status, ai: { ...status.ai, remaining_today: r.remaining_today } };
		} catch (e) {
			error = e.message;
			loadStatus().catch(() => {});
		} finally {
			busy = false;
		}
	}

	async function save() {
		error = '';
		const chosen = candidates.filter((c) => c.on);
		if (chosen.length === 0) {
			error = '저장할 문제를 골라 주세요.';
			return;
		}
		if (!subjectId && !unitId) {
			error = '위에서 과목(또는 단원)을 먼저 골라 주세요.';
			return;
		}
		try {
			await post('/questions/bulk', {
				source: 'ai',
				questions: chosen.map(({ on, ...q }) => ({ ...q, unit_id: unitId || null, subject_id: subjectId || null }))
			});
			msg = `${chosen.length}개를 저장했어요.`;
			candidates = null;
			await onsaved();
		} catch (e) {
			error = e.message;
		}
	}
</script>

{#if status?.ai?.available}
	<section class="card stack">
		<div class="row-between">
			<h2>AI로 문제 만들기</h2>
			<button class="btn btn-small" onclick={() => (open = !open)} aria-expanded={open}>{open ? '접기' : '열기'}</button>
		</div>
		{#if msg}<p class="chip" role="status">{msg}</p>{/if}

		{#if open}
			{#if error}<p class="alert" role="alert">{error}</p>{/if}

			{#if !status.ai.consent}
				<p class="muted small">
					AI 문제 만들기는 내가 쓴 노트(또는 붙여 넣은 글)를 외부 AI 서비스(Anthropic의 Claude)로 보내 문제 후보를 받아요.
					보내는 글은 이 용도로만 쓰이고, 만들어진 문제는 내가 확인하고 고른 것만 저장돼요. 교재나 시험지 원문은 보내지 말아 주세요.
				</p>
				<label class="check"><input type="checkbox" bind:checked={agree} /> 위 내용을 확인했고, 내가 쓴 글을 AI 서비스로 보내는 데 동의해요.</label>
				<button class="btn btn-primary" disabled={!agree} onclick={consent}>동의하고 시작</button>
			{:else if !candidates}
				<form class="stack" onsubmit={generate}>
					<p class="muted small">
						{unitId ? '위에서 고른 단원에 적어 둔 노트(과목·교재 화면의 "노트" 자료)로 만들어요.' : '단원을 고르면 그 단원의 노트로 만들고, 아래에 글을 붙여 넣어도 돼요.'}
						오늘 {status.ai.remaining_today}번 더 쓸 수 있어요.
					</p>
					<div class="field">
						<label for="ai-text">직접 붙여 넣기 (선택)</label>
						<textarea id="ai-text" class="input" maxlength="12000" placeholder="내가 정리한 내용을 붙여 넣으세요 (50자 이상)" bind:value={text}></textarea>
					</div>
					<div class="row">
						<div class="field">
							<label for="ai-count">문제 수</label>
							<input id="ai-count" class="input" type="number" min="1" max="10" bind:value={count} style="width: 90px;" />
						</div>
						<div class="types">
							{#each Object.entries(QUESTION_TYPES) as [t, label] (t)}
								<label class="check"><input type="checkbox" bind:checked={types[t]} /> {label}</label>
							{/each}
						</div>
					</div>
					<button class="btn btn-primary" disabled={busy || status.ai.remaining_today === 0}>{busy ? '만드는 중… (최대 1분)' : '문제 후보 만들기'}</button>
				</form>
			{:else}
				<p class="muted small">AI가 만든 후보예요. 틀린 내용이 있을 수 있으니 읽어 보고 저장할 문제만 골라 주세요.</p>
				<ul class="cands">
					{#each candidates as c, i (i)}
						<li class="cand">
							<label class="check top"><input type="checkbox" bind:checked={c.on} />
								<span class="stack" style="gap: 4px;">
									<span><span class="chip">{QUESTION_TYPES[c.type]}</span> {c.prompt}</span>
									{#if c.type === 'mcq'}<span class="small">{c.choices.map((x, k) => `${k + 1}) ${x}`).join('  ')}</span>{/if}
									<span class="small">정답: {c.type === 'mcq' ? `${Number(c.answer) + 1}번` : c.answer}</span>
									{#if c.explanation}<span class="muted small">{c.explanation}</span>{/if}
								</span>
							</label>
						</li>
					{/each}
				</ul>
				<div class="row">
					<button class="btn btn-primary" onclick={save}>선택한 문제 저장</button>
					<button class="btn" onclick={() => (candidates = null)}>버리고 다시</button>
				</div>
			{/if}
		{/if}
	</section>
{/if}

<style>
	.check {
		display: flex;
		align-items: center;
		gap: 8px;
		min-height: 40px;
	}
	.check.top {
		align-items: flex-start;
	}
	.types {
		display: flex;
		flex-wrap: wrap;
		gap: 4px 14px;
	}
	.cands {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	.cand {
		border: 1px solid var(--line);
		border-radius: 12px;
		padding: 6px 12px;
	}
</style>
