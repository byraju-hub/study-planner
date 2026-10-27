<script>
	import { chipStyle } from '#lib/format.js';

	let { name, color } = $props();
</script>

{#if name}
	<span class="chip" style={chipStyle(color ?? '#5b6475')}>{name}</span>
{/if}
