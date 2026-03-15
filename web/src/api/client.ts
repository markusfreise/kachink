import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: true,
})

// CSRF cookie for Sanctum SPA auth
export async function getCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

// Request interceptor: attach current organization header
api.interceptors.request.use((config) => {
  const orgId = localStorage.getItem('org:current')
  if (orgId) {
    config.headers['X-Organization-Id'] = orgId
  }
  return config
})

// Response interceptor for auth errors
// Skip redirect for /auth/* endpoints — the auth store and router guard handle those.
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (
      error.response?.status === 401 &&
      !error.config?.url?.startsWith('/auth/')
    ) {
      window.location.href = '/login'
    }
    return Promise.reject(error)
  },
)

export default api
