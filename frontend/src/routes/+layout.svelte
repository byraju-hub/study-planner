<script>
	import '../app.css';
	import { onMount } from 'svelte';
	import { auth, loadMe } from '#lib/auth.svelte.js';

	let { children } = $props();
	let failed = $state('');

	onMount(async () => {
		try {
			await loadMe();
		} catch (e) {
			failed = e.message;
		}
	});
</script>

<svelte:head>
	<title>스터디 플래너</title>
</svelte:head>

{#if failed}
	<main class="boot"><p class="alert">{failed}</p></main>
{:else if auth.ready}
	{@render children()}
{:else}
	<main class="boot muted">불러오는 중…</main>
{/if}

<style>
	.boot {
		display: grid;
		place-items: center;
		min-height: 100vh;
		padding: 24px;
	}
</style>
