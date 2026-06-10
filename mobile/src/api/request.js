import axios from 'axios'
import { showToast } from 'vant'

const SAFE_ERROR_MESSAGES = {
  400: '请求参数有误',
  401: '登录已过期',
  403: '没有权限',
  404: '资源不存在',
  429: '操作频繁',
  500: '服务器繁忙',
}

const request = axios.create({
  baseURL: '/api',
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
  },
})

request.interceptors.request.use(
  (config) => {
    const userInfo = JSON.parse(localStorage.getItem('userInfo') || '{}')
    const token = localStorage.getItem('token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    if (userInfo.fingerprint) {
      config.headers['X-Device-Fingerprint'] = userInfo.fingerprint
    }
    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

request.interceptors.response.use(
  (response) => {
    const res = response.data
    
    if (res.code !== 200 && res.code !== undefined) {
      showToast(res.message || '请求失败')
      return Promise.reject(new Error(res.message || 'Error'))
    }
    
    return res
  },
  (error) => {
    let message = '网络错误，请稍后重试'

    if (error.response) {
      // 优先使用后端返回的具体错误消息
      const data = error.response.data
      if (data && data.message) {
        message = data.message
      } else {
        const status = error.response.status
        message = SAFE_ERROR_MESSAGES[status] || '请求失败'
      }

      if (error.response.status === 401) {
        localStorage.removeItem('userInfo')
        localStorage.removeItem('token')
        window.location.href = '/login'
        return Promise.reject(error)
      }
    }

    showToast(message)
    return Promise.reject(error)
  }
)

export default request
