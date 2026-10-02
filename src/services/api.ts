import axios, { AxiosInstance, AxiosResponse } from 'axios'

const API_BASE_URL = '/api/v1'

const api: AxiosInstance = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
})

api.interceptors.response.use(
  (response: AxiosResponse) => {
    const data = response.data
    if (data && data.success !== undefined) {
      return {
        ...response,
        data: {
          ...data,
          data: data.data,
          meta: data.meta || {},
        },
      }
    }
    return response
  },
  (error) => {
    if (error.response?.status === 419) {
      window.dispatchEvent(new CustomEvent('auth:session-expired'))
    }
    if (error.response?.status === 401) {
      window.dispatchEvent(new CustomEvent('auth-error'))
    }
    return Promise.reject(error)
  },
)

/** Bootstrap the session and obtain the XSRF-TOKEN cookie for stateful requests. */
export async function bootstrapCsrf(): Promise<void> {
  await axios.get(`${API_BASE_URL}/csrf-cookie`, { withCredentials: true })
}

export default api
export { API_BASE_URL }