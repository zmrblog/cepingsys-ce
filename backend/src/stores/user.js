import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import request from '@/api/request'

export const useUserStore = defineStore('user', () => {
  const token = ref(localStorage.getItem('token') || '')
  const adminInfo = ref(JSON.parse(localStorage.getItem('adminInfo') || '{}'))

  const isLoggedIn = computed(() => !!token.value)
  const isAdmin = computed(() => adminInfo.value?.role === 'super')
  const isSuperAdmin = computed(() => adminInfo.value?.role === 'super')
  const isTemplateAdmin = computed(() => adminInfo.value?.role === 'template' || isAdmin.value)
  const canViewData = computed(() => true) // 所有登录用户都能查看数据

  function setToken(newToken) {
    token.value = newToken
    localStorage.setItem('token', newToken)
  }

  function setAdminInfo(info) {
    adminInfo.value = info
    localStorage.setItem('adminInfo', JSON.stringify(info))
  }

  async function login(loginForm) {
    try {
      const res = await request.post('/auth/login', loginForm)
      
      if (res.code === 200) {
        setToken(res.data.token)
        setAdminInfo(res.data.admin)
        return res
      }
      
      throw new Error(res.message || '登录失败')
    } catch (error) {
      throw error
    }
  }

  function logout() {
    token.value = ''
    adminInfo.value = {}
    localStorage.removeItem('token')
    localStorage.removeItem('adminInfo')
  }

  return {
    token,
    adminInfo,
    isLoggedIn,
    isAdmin,
    isSuperAdmin,
    isTemplateAdmin,
    canViewData,
    login,
    logout,
    setToken,
    setAdminInfo,
  }
})
