const BASE = import.meta.env.VITE_API_BASE || '/api';
const TOKEN_KEY = 'imel.token';

export function getToken() {
  return localStorage.getItem(TOKEN_KEY) || '';
}

export function setToken(token) {
  if (token) localStorage.setItem(TOKEN_KEY, token);
  else localStorage.removeItem(TOKEN_KEY);
}

export class ApiError extends Error {
  constructor(message, status, fields) {
    super(message);
    this.status = status;
    this.fields = fields || {};
  }
}

/** Listeners fired when the server rejects our token, so the app can sign out. */
const unauthorizedHandlers = new Set();
export function onUnauthorized(handler) {
  unauthorizedHandlers.add(handler);
  return () => unauthorizedHandlers.delete(handler);
}

async function request(path, { method = 'GET', body, params, formData, signal } = {}) {
  const url = new URL(BASE + path, window.location.origin);
  if (params) {
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, value);
    });
  }

  const headers = {};
  const token = getToken();
  if (token) headers.Authorization = `Bearer ${token}`;
  if (body !== undefined) headers['Content-Type'] = 'application/json';

  const response = await fetch(url.toString(), {
    method,
    headers,
    signal,
    body: formData ?? (body !== undefined ? JSON.stringify(body) : undefined),
  });

  if (response.status === 204) return null;

  const text = await response.text();
  let payload = null;
  try {
    payload = text ? JSON.parse(text) : null;
  } catch {
    payload = { error: text.slice(0, 200) };
  }

  if (!response.ok) {
    if (response.status === 401) unauthorizedHandlers.forEach((handler) => handler());
    throw new ApiError(payload?.error || `Request gagal (${response.status})`, response.status, payload?.fields);
  }

  return payload;
}

export const api = {
  // auth
  login: (email, password) => request('/auth/login', { method: 'POST', body: { email, password } }),
  register: (data) => request('/auth/register', { method: 'POST', body: data }),
  logout: () => request('/auth/logout', { method: 'POST' }),
  me: () => request('/auth/me'),
  updateProfile: (data) => request('/auth/profile', { method: 'PUT', body: data }),
  changePassword: (data) => request('/auth/password', { method: 'PUT', body: data }),
  sessions: () => request('/auth/sessions'),
  revokeSession: (jti) => request(`/auth/sessions/${encodeURIComponent(jti)}`, { method: 'DELETE' }),

  // mail
  labels: () => request('/labels'),
  createLabel: (data) => request('/labels', { method: 'POST', body: data }),
  updateLabel: (id, data) => request(`/labels/${id}`, { method: 'PUT', body: data }),
  deleteLabel: (id) => request(`/labels/${id}`, { method: 'DELETE' }),

  threads: (params, signal) => request('/threads', { params, signal }),
  thread: (key, params) => request(`/threads/${encodeURIComponent(key)}`, { params }),
  message: (id, params) => request(`/messages/${id}`, { params }),
  messageRaw: (id) => request(`/messages/${id}/raw`),
  replyContext: (id, mode) => request(`/messages/${id}/reply-context`, { params: { mode } }),
  batch: (body) => request('/messages/batch', { method: 'POST', body }),
  send: (body) => request('/messages/send', { method: 'POST', body }),
  saveDraft: (body) => request('/drafts', { method: 'POST', body }),
  deleteDraft: (id) => request(`/drafts/${id}`, { method: 'DELETE' }),

  uploadAttachment: (file) => {
    const formData = new FormData();
    formData.append('file', file);
    return request('/attachments', { method: 'POST', formData });
  },
  deleteAttachment: (id) => request(`/attachments/${id}`, { method: 'DELETE' }),
  attachmentUrl: (id, inline = false) =>
    `${BASE}/attachments/${id}?access_token=${encodeURIComponent(getToken())}${inline ? '&inline=1' : ''}`,

  contacts: (q) => request('/contacts', { params: { q, limit: 8 } }),
  settings: () => request('/settings'),
  updateSettings: (body) => request('/settings', { method: 'PUT', body }),
  health: () => request('/health'),
};
