import { createRouter, createWebHashHistory } from 'vue-router'
import { useUserStore } from '@/stores/user'

const routes = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/Login.vue'),
    meta: { requiresAuth: false },
  },
  {
    path: '/',
    component: () => import('@/layout/MainLayout.vue'),
    redirect: '/dashboard',
    meta: { requiresAuth: true },
    children: [
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: () => import('@/views/Dashboard.vue'),
        meta: { title: '工作台' },
      },
      {
        path: 'units',
        name: 'Units',
        component: () => import('@/views/units/Index.vue'),
        meta: { title: '单位管理' },
      },
      {
        path: 'users',
        name: 'Users',
        component: () => import('@/views/users/Index.vue'),
        meta: { title: '用户管理' },
      },
      {
        path: 'templates',
        name: 'Templates',
        component: () => import('@/views/templates/Index.vue'),
        meta: { title: '模板管理' },
      },
      {
        path: 'templates/create',
        name: 'TemplateCreate',
        component: () => import('@/views/templates/Form.vue'),
        meta: { title: '创建模板' },
      },
      {
        path: 'templates/:id/edit',
        name: 'TemplateEdit',
        component: () => import('@/views/templates/Form.vue'),
        meta: { title: '编辑模板' },
      },
      {
        path: 'examines',
        name: 'Examines',
        component: () => import('@/views/examines/Index.vue'),
        meta: { title: '测评任务' },
      },
      {
        path: 'examines/create',
        name: 'ExamineCreate',
        component: () => import('@/views/examines/Form.vue'),
        meta: { title: '创建测评任务' },
      },
      {
        path: 'examines/:id/edit',
        name: 'ExamineEdit',
        component: () => import('@/views/examines/Form.vue'),
        meta: { title: '编辑测评任务' },
      },
      {
        path: 'examines/:id/users',
        name: 'ExamineUsers',
        component: () => import('@/views/examines/UserSelection.vue'),
        meta: { title: '分配参评人员' },
      },
      {
        path: 'examines/archive',
        name: 'ExamineArchive',
        component: () => import('@/views/examines/Archive.vue'),
        meta: { title: '测评归档' },
      },
      {
        path: 'analysis',
        name: 'Analysis',
        component: () => import('@/views/analysis/Index.vue'),
        meta: { title: '测评分析管理' },
      },
      {
        path: 'admins',
        name: 'Admins',
        component: () => import('@/views/admins/Index.vue'),
        meta: { title: '管理员管理', requiresSuperAdmin: true },
      },
      {
        path: 'dept-user-info',
        name: 'DeptUserInfo',
        component: () => import('@/views/dept-user/Info.vue'),
        meta: { title: '部门及用户信息' },
      },
      {
        path: 'import-data',
        name: 'ImportData',
        component: () => import('@/views/dept-user/ImportData.vue'),
        meta: { title: '导入单位及用户' },
      },
      {
        path: 'registered-users',
        name: 'RegisteredUsers',
        component: () => import('@/views/registered-users/Index.vue'),
        meta: { title: '注册用户管理' },
      },
      {
        path: 'feature-comparison',
        name: 'FeatureComparison',
        component: () => import('@/views/Help/FeatureComparison.vue'),
        meta: { title: '功能对比' },
      },
      {
        path: 'guide',
        name: 'Guide',
        component: () => import('@/views/Help/Guide.vue'),
        meta: { title: '使用说明' },
      },
    ],
  },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

router.beforeEach((to, from, next) => {
  const userStore = useUserStore()

  if (to.meta.requiresAuth !== false && !userStore.isLoggedIn) {
    next({ name: 'Login', query: { redirect: to.fullPath } })
    return
  }

  if (to.meta.requiresSuperAdmin && !userStore.isSuperAdmin) {
    next({ name: 'Dashboard' })
    return
  }

  if (to.name === 'Login' && userStore.isLoggedIn) {
    next({ name: 'Dashboard' })
    return
  }

  next()
})

export default router
