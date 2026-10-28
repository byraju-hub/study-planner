// 웹 푸시를 받아 알림으로 보여 주는 서비스 워커. 화면 코드와 따로 동작한다.
self.addEventListener('push', (event) => {
	let data = {};
	try {
		data = event.data ? event.data.json() : {};
	} catch {
		data = { title: '스터디 플래너', body: event.data ? event.data.text() : '' };
	}
	event.waitUntil(
		self.registration.showNotification(data.title || '스터디 플래너', {
			body: data.body || '',
			tag: data.link || '/',
			data: { link: data.link || '/' }
		})
	);
});

self.addEventListener('notificationclick', (event) => {
	event.notification.close();
	const url = self.registration.scope.replace(/\/$/, '') + (event.notification.data?.link || '/');
	event.waitUntil(
		self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
			for (const c of list) {
				if (c.url.startsWith(self.registration.scope) && 'focus' in c) {
					c.navigate(url);
					return c.focus();
				}
			}
			return self.clients.openWindow(url);
		})
	);
});
