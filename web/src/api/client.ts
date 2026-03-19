import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
})

// Request interceptor: attach token and current organization header
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth:token')
  if (token) {
    config.headers['Authorization'] = `Bearer ${token}`
  }

  const orgId = localStorage.getItem('org:current')
  if (orgId) {
    config.headers['X-Organization-Id'] = orgId
  }

  return config
})

// Response interceptor: only log out when the token itself is rejected
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && error.config?.url === '/auth/me') {
      localStorage.removeItem('auth:token')
      window.location.href = '/'
    }
    return Promise.reject(error)
  },
)

export default api
