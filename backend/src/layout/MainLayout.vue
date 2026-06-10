<template>
  <el-container class="main-layout">
    <el-aside width="220px" class="aside-menu">
      <div class="logo">
        <h2>测评系统</h2>
      </div>

      <el-menu
        :default-active="activeMenu"
        :default-openeds="['examine-center', 'dept-user-manage', 'system-settings']"
        @select="handleMenuSelect"
        background-color="#1B4D7C"
        text-color="rgba(255,255,255,0.85)"
        active-text-color="#FFFFFF"
      >
        <el-menu-item index="/dashboard">
          <el-icon><DataAnalysis /></el-icon>
          <span>工作台</span>
        </el-menu-item>

        <!-- 测评中心 -->
        <el-sub-menu index="examine-center" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
          <template #title>
            <el-icon><FolderOpened /></el-icon>
            <span>测评中心</span>
          </template>

          <el-menu-item index="/templates">
            <el-icon><Tickets /></el-icon>
            <span>模板管理</span>
          </el-menu-item>

          <el-menu-item index="/examines">
            <el-icon><List /></el-icon>
            <span>测评任务</span>
          </el-menu-item>

          <el-menu-item index="/examines/archive">
            <el-icon><Box /></el-icon>
            <span>测评归档</span>
          </el-menu-item>
        </el-sub-menu>

        <!-- 测评分析 -->
        <el-menu-item index="/analysis">
          <el-icon><TrendCharts /></el-icon>
          <span>测评分析</span>
        </el-menu-item>

        <!-- 参评信息 -->
        <el-sub-menu index="dept-user-manage">
          <template #title>
            <el-icon><UserFilled /></el-icon>
            <span>参评信息</span>
          </template>

          <el-menu-item index="/dept-user-info">
            <el-icon><Monitor /></el-icon>
            <span>信息总览</span>
          </el-menu-item>

          <el-menu-item index="/import-data" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
            <el-icon><Upload /></el-icon>
            <span>导入信息</span>
          </el-menu-item>

          <el-menu-item index="/units" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
            <el-icon><OfficeBuilding /></el-icon>
            <span>部门管理</span>
          </el-menu-item>

          <el-menu-item index="/users" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
            <el-icon><User /></el-icon>
            <span>用户管理</span>
          </el-menu-item>
        </el-sub-menu>

        <!-- 系统设置 -->
        <el-sub-menu index="system-settings" v-if="userStore.isSuperAdmin || userStore.isTemplateAdmin || userStore.isAdmin">
          <template #title>
            <el-icon><Setting /></el-icon>
            <span>系统设置</span>
          </template>

          <el-menu-item index="/admins" v-if="userStore.isSuperAdmin">
            <el-icon><Lock /></el-icon>
            <span>管理员管理</span>
          </el-menu-item>

          <el-menu-item index="/registered-users" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
            <el-icon><Avatar /></el-icon>
            <span>注册用户管理</span>
          </el-menu-item>

          <el-menu-item index="/logs">
            <el-icon><DocumentChecked /></el-icon>
            <span>操作日志</span>
          </el-menu-item>
        </el-sub-menu>

        <el-menu-item index="/guide">
          <el-icon><QuestionFilled /></el-icon>
          <span>使用说明</span>
        </el-menu-item>
      </el-menu>
    </el-aside>

    <el-container>
      <el-header class="header">
        <div class="header-left">
          <el-breadcrumb separator="/">
            <el-breadcrumb-item :to="{ path: '/' }">首页</el-breadcrumb-item>
            <el-breadcrumb-item v-if="$route.meta.title">{{ $route.meta.title }}</el-breadcrumb-item>
          </el-breadcrumb>
        </div>

        <div class="header-right">
          <el-dropdown @command="handleCommand">
            <span class="user-info">
              <el-avatar :size="32" icon="UserFilled" />
              <span class="username">{{ userStore.adminInfo.real_name || userStore.adminInfo.username }}</span>
              <el-icon><ArrowDown /></el-icon>
            </span>

            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item disabled>
                  角色：{{ getRoleText(userStore.adminInfo.role) }}
                </el-dropdown-item>
                <el-dropdown-item command="logout" divided>
                  退出登录
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </el-header>

      <el-main class="main-content">
        <router-view />
      </el-main>
    </el-container>
  </el-container>

  <UpgradeDialog ref="upgradeDialogRef" />
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { useEditionStore } from '@/stores/edition'
import UpgradeDialog from '@/components/UpgradeDialog.vue'
import { ElMessageBox } from 'element-plus'

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const editionStore = useEditionStore()
const upgradeDialogRef = ref(null)

const activeMenu = computed(() => route.path)

function getRoleText(role) {
  const map = {
    super: '超级管理员',
    template: '模板管理员',
    viewer: '查看管理员',
  }
  return map[role] || role
}

function handleMenuSelect(index) {
  if (index === '/logs') {
    upgradeDialogRef.value?.open()
    return
  }
  router.push(index)
}

async function handleCommand(command) {
  if (command === 'logout') {
    try {
      await ElMessageBox.confirm('确定要退出登录吗？', '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning',
      })

      userStore.logout()
      router.push('/login')
    } catch {
      // 取消操作
    }
  }
}
</script>

<style lang="scss" scoped>
.main-layout {
  height: 100vh;
}

.aside-menu {
  background: #1B4D7C;
  box-shadow: 2px 0 12px rgba(0, 0, 0, 0.15);

  .logo {
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    position: relative;

    h2 {
      color: #FFFFFF;
      font-size: 18px;
      font-weight: 600;
      letter-spacing: 2px;
    }
  }

  :deep(.el-menu) {
    border-right: none;

    .el-menu-item,
    .el-sub-menu__title {
      color: rgba(255, 255, 255, 0.85);
      height: 48px;
      line-height: 48px;
      transition: all 0.25s ease;
      position: relative;

      &:hover {
        background: rgba(255, 255, 255, 0.08) !important;
        color: #FFFFFF !important;
      }

      &.is-active {
        background: rgba(255, 255, 255, 0.14) !important;
        color: #FFFFFF !important;
        font-weight: 600;
        border-radius: 0 8px 8px 0;
        margin-right: 8px;

        &::after {
          content: '';
          position: absolute;
          right: 0;
          top: 50%;
          transform: translateY(-50%);
          width: 3px;
          height: 20px;
          background: #E8B84A;
          border-radius: 3px 0 0 3px;
        }

        .el-icon {
          color: #E8B84A !important;
        }
      }

      .el-icon {
        width: 18px;
        margin-right: 10px;
      }
    }

    // 分组标题样式
    .el-sub-menu > .el-sub-menu__title {
      font-weight: 500;
      letter-spacing: 0.5px;
    }

    .el-sub-menu {
      .el-sub-menu__title:hover,
      .el-menu-item:hover {
        background: rgba(255, 255, 255, 0.08) !important;
      }

      .el-menu-item {
        padding-left: 52px !important;
        height: 44px;
        line-height: 44px;
        font-size: 13px;
      }
    }
  }
}

.header {
  background: #FFFFFF;
  box-shadow: 0 2px 8px rgba(27, 94, 155, 0.08);
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 24px;
  border-bottom: 1px solid var(--color-border);
  height: 56px;

  .header-left {
    .el-breadcrumb {
      font-size: 14px;
    }
  }

  .header-right {
    display: flex;
    align-items: center;
  }
}

.user-info {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  padding: 6px 14px;
  border-radius: 20px;
  background: #F5F7FA;
  transition: all 0.3s ease;

  &:hover {
    background: #E8EEF5;
  }

  .el-avatar {
    background: var(--color-primary);
  }

  .username {
    color: var(--color-text-primary);
    font-size: 14px;
    font-weight: 500;
  }
}

.main-content {
  background: var(--color-bg-primary);
  padding: 20px;
}
</style>