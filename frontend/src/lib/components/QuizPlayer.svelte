<script>
	import { post } from '#lib/api.js';
	import { QUESTION_TYPES } from '#lib/format.js';

	/** 시작된 퀴즈 하나를 한 문제씩 푼다. 채점은 서버가 한다. */
	let { attemptId, questions, ondone } = $props();

	let index = $state(0);
	let given = $state('');
	let feedback = $state(null);
	let revealed = $state(false);
	let result = $state(null);
	let error = $state('');
	let busy = $state(false);

	const q = $derived(questions[index]);
	const isSelf = $derived(q && (q.type === 'essay' || q.type === 'card'));

	async function submit(extra = {}) {
		if (busy) return;
		busy = true;
		error = '';
		try {
			feedback = await post(`/quiz/attempts/${attemptId}/answer`, { question_id: q.id, given: given === '' ? null : String(given), ...extra });
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}

	async function next() {
		feedback = null;
		given = '';
		revealed = false;
		if (index + 1 < questions.length) {
			index += 1;
			return;
		}
		try {
			result = await post(`/quiz/attempts/${attemptId}/finish`);
		} catch (e) {
			error = e.message;
		}
	}
</script>

{#if result}
	<div class="card stack result" role="status">
		<h2>{result.correct} / {result.total} 맞혔어요</h2>
		{#if result.wrong.length === 0}
			<p>모두 맞혔어요! 🎉</p>
		{:else}
			<p class="muted small">틀린 문제는 오답노트에 담기고, 내일 복습 목록에도 올라가요.</p>
			<ul class="wrong">
				{#each result.wrong as w (w.id)}
					<li><strong>{w.prompt}</strong><span class="muted small">정답: {w.answer_text}</span></li>
				{/each}
			</ul>
		{/if}
		<button class="btn btn-primary" onclick={ondone}>돌아가기</button>
	</div>
{:else if q}
	<div class="card stack">
		<div class="row-between">
			<span class="chip">{QUESTION_TYPES[q.type]}</span>
			<span class="muted small">{index + 1} / {questions.length}</span>
		</div>
		<p class="prompt">{q.prompt}</p>
		{#if error}<p class="alert" role="alert">{error}</p>{/if}

		{#if !feedback}
			{#if q.type === 'mcq'}
				<div class="stack" style="gap: 8px;">
					{#each q.choices as c, i (i)}
						<button class="choice" class:picked={given === String(i)} onclick={() => (given = String(i))}>{i + 1}. {c}</button>
					{/each}
				</div>
			{:else if q.type === 'ox'}
				<div class="row">
					{#each ['O', 'X'] as v (v)}
						<button class="choice ox" class:picked={given === v} onclick={() => (given = v)}>{v}</button>
					{/each}
				</div>
			{:else if q.type === 'short'}
				<input class="input" placeholder="정답을 입력하세요" bind:value={given} onkeydown={(e) => e.key === 'Enter' && given && submit()} />
			{:else if q.type === 'essay'}
				<textarea class="input" placeholder="내 답을 써 보세요 (채점은 스스로 해요)" bind:value={given}></textarea>
			{/if}

			{#if isSelf}
				{#if !revealed}
					<button class="btn btn-primary" onclick={() => (revealed = true)}>{q.type === 'card' ? '뒷면 보기' : '모범 답안 보기'}</button>
				{:else}
					<p class="muted small">정답을 확인하고 스스로 채점해 주세요.</p>
					<div class="row">
						<button class="btn btn-primary" disabled={busy} onclick={() => submit({ self_correct: true })}>맞았어요</button>
						<button class="btn" disabled={busy} onclick={() => submit({ self_correct: false })}>틀렸어요</button>
					</div>
				{/if}
			{:else}
				<button class="btn btn-primary" disabled={busy || given === ''} onclick={() => submit()}>확인</button>
			{/if}
		{:else}
			<p class="verdict" class:ok={feedback.is_correct}>{feedback.is_correct ? '정답이에요! 👍' : '아쉬워요, 오답이에요.'}</p>
			<p><span class="muted small">정답</span><br /><strong>{feedback.answer_text}</strong></p>
			{#if feedback.explanation}<p class="muted">{feedback.explanation}</p>{/if}
			<button class="btn btn-primary" onclick={next}>{index + 1 < questions.length ? '다음 문제' : '결과 보기'}</button>
		{/if}
	</div>
{/if}

<style>
	.prompt {
		font-size: 18px;
		line-height: 1.6;
		white-space: pre-wrap;
		margin: 4px 0;
	}
	.choice {
		min-height: 48px;
		padding: 10px 14px;
		text-align: left;
		border: 1px solid var(--line-strong);
		border-radius: 12px;
		background: var(--surface);
		color: var(--ink);
		cursor: pointer;
		font-size: 16px;
	}
	.choice.ox {
		flex: 1;
		text-align: center;
		font-size: 24px;
		font-weight: 700;
	}
	.choice.picked {
		border-color: var(--accent);
		background: var(--accent-soft);
		font-weight: 700;
	}
	.verdict {
		font-weight: 700;
		color: var(--danger);
		margin: 0;
	}
	.verdict.ok {
		color: var(--accent-dark);
	}
	.wrong {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 10px;
	}
	.wrong li {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}
</style>
