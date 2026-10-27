<script>
	import { put } from '#lib/api.js';

	let { date, journal = null } = $props();

	let good = $state('');
	let bad = $state('');
	let rating = $state(0);
	let message = $state('');
	let error = $state('');
	let busy = $state(false);

	$effect(() => {
		good = journal?.good ?? '';
		bad = journal?.bad ?? '';
		rating = journal?.self_rating ?? 0;
	});

	async function save(event) {
		event.preventDefault();
		error = message = '';
		busy = true;
		try {
			await put('/journal', { date, good: good || null, bad: bad || null, self_rating: rating || null });
			message = '저장했어요.';
		} catch (e) {
			error = e.message;
		} finally {
			busy = false;
		}
	}
</script>

<form class="card stack" onsubmit={save} aria-label="하루 회고">
	<h2>하루 회고</h2>
	{#if error}<p class="alert" role="alert">{error}</p>{/if}
	{#if message}<p class="chip msg" role="status">{message}</p>{/if}

	<div class="field">
		<label for="j-good">잘한 점</label>
		<textarea id="j-good" class="input" maxlength="2000" bind:value={good} placeholder="오늘 잘한 점을 적어 보세요"></textarea>
	</div>
	<div class="field">
		<label for="j-bad">아쉬운 점</label>
		<textarea id="j-bad" class="input" maxlength="2000" bind:value={bad} placeholder="내일 보완할 점을 적어 보세요"></textarea>
	</div>

	<fieldset class="rating">
		<legend class="label">오늘 나의 점수</legend>
		<div class="row">
			{#each [1, 2, 3, 4, 5] as n (n)}
				<label class="star" class:on={rating === n}>
					<input type="radio" name="rating" value={n} checked={rating === n} onchange={() => (rating = n)} />
					<span>{n}</span>
				</label>
			{/each}
		</div>
	</fieldset>

	<button class="btn btn-primary" disabled={busy}>회고 저장</button>
</form>

<style>
	.msg {
		align-self: flex-start;
	}
	.rating {
		border: 0;
		padding: 0;
		margin: 0;
	}
	.star {
		position: relative;
		width: 44px;
		height: 44px;
		display: grid;
		place-items: center;
		border: 1px solid var(--line-strong);
		border-radius: 10px;
		cursor: pointer;
		font-weight: 700;
		background: var(--surface);
	}
	.star.on {
		background: var(--accent);
		border-color: var(--accent);
		color: #fff;
	}
	.star input {
		position: absolute;
		opacity: 0;
		inset: 0;
		cursor: pointer;
	}
	.star:has(input:focus-visible) {
		outline: 3px solid color-mix(in srgb, var(--accent) 45%, transparent);
		outline-offset: 2px;
	}
</style>
