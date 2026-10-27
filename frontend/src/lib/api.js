import { resolve } from '$app/paths';

let csrf = '';

export function setCsrf(token) {
	csrf = token;
}

export class ApiError extends Error {
	constructor(status, code, message, field = null) {
		super(message);
		this.status = status;
		this.code = code;
		this.field = field;
	}
}

async function send(method, path, body) {
	const headers = { Accept: 'application/json' };
	let payload;
	if (body instanceof FormData) {
		payload = body;
	} else if (body !== undefined) {
		headers['Content-Type'] = 'application/json';
		payload = JSON.stringify(body);
	}
	if (method !== 'GET') headers['X-CSRF-Token'] = csrf;

	let res;
	try {
		res = await fetch(resolve('/api') + path, { method, headers, body: payload, credentials: 'same-origin' });
	} catch {
		throw new ApiError(0, 'network', '서버에 연결할 수 없어요. 인터넷 연결을 확인해 주세요.');
	}
	let json = null;
	try {
		json = await res.json();
	} catch {
		// JSON이 아닌 응답은 아래에서 오류로 처리한다.
	}
	return { res, json };
}

/** JSON API 호출. 실패하면 ApiError. 변경 요청은 CSRF 토큰이 어긋났을 때 한 번 갱신해서 다시 보낸다. */
export async function api(method, path, body) {
	let { res, json } = await send(method, path, body);
	if (json?.error?.code === 'csrf') {
		const me = await send('GET', '/auth/me');
		if (me.json?.ok) {
			csrf = me.json.data.csrf;
			({ res, json } = await send(method, path, body));
		}
	}
	if (!res.ok || !json?.ok) {
		const e = json?.error;
		throw new ApiError(res.status, e?.code ?? 'unknown', e?.message ?? '알 수 없는 오류가 생겼어요.', e?.field ?? null);
	}
	return json.data;
}

export const get = (path) => api('GET', path);
export const post = (path, body) => api('POST', path, body ?? {});
export const put = (path, body) => api('PUT', path, body ?? {});
export const del = (path) => api('DELETE', path);

export function fileUrl(materialId) {
	return `${resolve('/api')}/materials/${materialId}/file`;
}
