import { get, post, setCsrf } from './api.js';

export const auth = $state({ user: null, ready: false, signupMode: 'open' });

export async function loadMe() {
	const data = await get('/auth/me');
	setCsrf(data.csrf);
	auth.user = data.user;
	auth.signupMode = data.signup_mode ?? 'open';
	auth.ready = true;
}

export async function login(loginId, password) {
	const data = await post('/auth/login', { login_id: loginId, password });
	setCsrf(data.csrf);
	auth.user = data.user;
}

/** 승인제이면 로그인되지 않고 { pending: true } 를 돌려준다. */
export async function signup(fields) {
	const data = await post('/auth/signup', fields);
	setCsrf(data.csrf);
	if (data.pending) return { pending: true };
	auth.user = data.user;
	return { pending: false };
}

export async function logout() {
	const data = await post('/auth/logout');
	setCsrf(data.csrf);
	auth.user = null;
}
