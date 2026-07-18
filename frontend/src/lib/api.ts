const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? '/api';

export interface ApiUser {
  id: number;
  email: string;
  role_id: number;
}

class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message);
  }
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });

  const body = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new ApiError(
      body.error ?? `Request failed with status ${response.status}`,
      response.status,
    );
  }

  return body as T;
}

export const api = {
  login: (email: string, password: string) =>
    request<{ user: ApiUser }>('/session/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    }),

  me: () => request<{ user: ApiUser }>('/session/me'),

  logout: () => request<{ ok: true }>('/session/logout', { method: 'POST' }),
};

export { ApiError };
