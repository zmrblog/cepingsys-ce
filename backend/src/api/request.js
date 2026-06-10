import axios from 'axios'
import { ElMessage } from 'element-plus'
import { useUserStore } from '@/stores/user'
import router from '@/router'

const SAFE_ERROR_MESSAGES = {
  400: '请求参数有误，请检查输入',
  401: '登录已过期，请重新登录',
  402: '此功能为企业版专属，请升级后使用',
  403: '没有权限执行此操作',
  404: '请求的资源不存在',
  405: '请求方法不允许',
  429: '操作过于频繁，请稍后重试',
  500: '服务器繁忙，请稍后重试',
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
    const userStore = useUserStore()
    
    if (userStore.token) {
      config.headers.Authorization = `Bearer ${userStore.token}`
    }
    
    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

async function readBlobMessage(blob) {
  try {
    const text = await blob.text()
    const data = JSON.parse(text)
    return data.message || null
  } catch {
    return null
  }
}

request.interceptors.response.use(
  (response) => {
    const res = response.data
    
    if (res instanceof Blob) {
      return res
    }
    
    if (res.code !== 200 && res.code !== undefined) {
      ElMessage.error(res.message || '请求失败')
      
      if (res.code === 401) {
        const userStore = useUserStore()
        userStore.logout()
        router.push('/login')
      }
      
      return Promise.reject(new Error(res.message || 'Error'))
    }
    
    return res
  },
  async (error) => {
    let message = '网络错误，请稍后重试'
    
    if (error.response) {
      if (error.response.data instanceof Blob) {
        const msg = await readBlobMessage(error.response.data)
        if (msg) {
          ElMessage.error(msg)
          return Promise.reject(error)
        }
      }
      
      const status = error.response.status
      const apiMessage = error.response.data?.message
      message = apiMessage || SAFE_ERROR_MESSAGES[status] || `请求失败(${status || '网络异常'})`

      if (status === 401) {
        const userStore = useUserStore()
        userStore.logout()
        router.push('/login')
      }
    }
    
    ElMessage.error(message)
    return Promise.reject(error)
  }
)

export default request
