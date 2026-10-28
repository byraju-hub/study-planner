<script>
	import { get } from '#lib/api.js';

	/** 과목을 고르면 그 과목의 모든 교재 단원을 불러와 고르게 한다. 단원은 선택 사항. */
	let { subjects, subjectId = $bindable(''), unitId = $bindable(''), subjectLabel = '과목', anySubject = false } = $props();

	let units = $state([]);

	$effect(() => {
		const sid = subjectId;
		units = [];
		if (!sid) return;
		let stale = false;
		(async () => {
			const out = [];
			for (const t of await get(`/textbooks?subject_id=${sid}`)) {
				for (const u of await get(`/units?textbook_id=${t.id}`)) out.push({ id: u.id, label: `${t.title} · ${u.parent_id ? '  ' : ''}${u.title}` });
			}
			if (!stale) units = out;
		})().catch(() => {});
		return () => (stale = true);
	});

	function changeSubject() {
		unitId = '';
	}
</script>

<div class="row">
	<div class="field grow">
		<label for="pick-subject">{subjectLabel}</label>
		<select id="pick-subject" class="input" bind:value={subjectId} onchange={changeSubject}>
			{#if anySubject}<option value="">전체</option>{:else}<option value="" disabled>선택</option>{/if}
			{#each subjects as s (s.id)}<option value={s.id}>{s.name}</option>{/each}
		</select>
	</div>
	<div class="field grow">
		<label for="pick-unit">단원 (선택)</label>
		<select id="pick-unit" class="input" bind:value={unitId} disabled={!subjectId}>
			<option value="">전체</option>
			{#each units as u (u.id)}<option value={u.id}>{u.label}</option>{/each}
		</select>
	</div>
</div>
