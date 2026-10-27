import { get, post, setCsrf } from './api.js';

export const auth = $state({ user: null, ready: false });

export async function loadMe() {
	const data = await get('/auth/me');
	setCsrf(data.csrf);
	auth.user = data.user;
	auth.ready = true;
}

export async function login(loginId, password) {
	const data = await post('/auth/login', { login_id: loginId, password });
	setCsrf(data.csrf);
	auth.user = data.user;
}

export async function signup(fields) {
	const data = await post('/auth/signup', fields);
	setCsrf(data.csrf);
	auth.user = data.user;
}

export async function logout() {
	const data = await post('/auth/logout');
	setCsrf(data.csrf);
	auth.user = null;
}
