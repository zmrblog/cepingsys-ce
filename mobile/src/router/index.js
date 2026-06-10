import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    redirect: '/login',
  },
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/Login.vue'),
  },
  {
    path: '/home',
    name: 'Home',
    component: () => import('@/views/Home.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/exam-list',
    name: 'ExamList',
    component: () => import('@/views/ExamList.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/examine/:id/targets',
    name: 'TargetList',
    component: () => import('@/views/TargetList.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/examine/:id/target/:targetId',
    name: 'TakeExam',
    component: () => import('@/views/TakeExam.vue'),
    meta: { requiresAuth: true },
  },
]

const router = createRouter({
  history: createWebHistory('/webapp/'),
  routes,
})

router.beforeEach((to, from, next) => {
  const userInfo = localStorage.getItem('userInfo')
  
  if (to.meta.requiresAuth && !userInfo) {
    next({ name: 'Login', query: { redirect: to.fullPath } })
  } else if (to.name === 'Login' && userInfo) {
    next({ name: 'ExamList' })
  } else {
    next()
  }
})

export default router
