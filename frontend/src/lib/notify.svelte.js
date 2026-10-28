import { resolve } from '$app/paths';
import { get, post } from './api.js';

export const inbox = $state({ items: [], unread: 0, loaded: false });

export async function refreshInbox() {
	const data = await get('/notifications');
	inbox.items = data.notifications;
	inbox.unread = data.unread;
	inbox.loaded = true;
}

export async function markRead(id) {
	await post(`/notifications/${id}/read`);
	await refreshInbox();
}

export async function markAllRead() {
	await post('/notifications/read-all');
	await refreshInbox();
}

export const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

function toKey(b64u) {
	const pad = '='.repeat((4 - (b64u.length % 4)) % 4);
	const raw = atob((b64u + pad).replace(/-/g, '+').replace(/_/g, '/'));
	return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

async function registration() {
	await navigator.serviceWorker.register(resolve('/sw.js'), { scope: resolve('/') });
	return navigator.serviceWorker.ready;
}

export async function currentSubscription() {
	if (!pushSupported()) return null;
	const reg = await navigator.serviceWorker.getRegistration(resolve('/'));
	return reg ? reg.pushManager.getSubscription() : null;
}

/** 이 기기에서 푸시 알림을 켠다. 권한이 거절되면 false. */
export async function enablePush(publicKey) {
	if ((await Notification.requestPermission()) !== 'granted') return false;
	const reg = await registration();
	const sub =
		(await reg.pushManager.getSubscription()) ??
		(await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toKey(publicKey) }));
	await post('/push/subscribe', sub.toJSON());
	return true;
}

export async function disablePush() {
	const sub = await currentSubscription();
	if (!sub) return;
	await post('/push/unsubscribe', { endpoint: sub.endpoint });
	await sub.unsubscribe();
}
