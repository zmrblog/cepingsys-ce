<template>
  <div class="dept-user-info">
    <el-row :gutter="20" class="mb-20">
      <el-col :span="12">
        <el-card shadow="hover">
          <template #header>
            <div class="card-header">
              <span>
                <el-icon><OfficeBuilding /></el-icon>
                部门信息
              </span>
              <el-tag type="info">共 {{ deptStats.total }} 个部门</el-tag>
            </div>
          </template>

          <el-descriptions :column="2" border size="small" class="mb-15">
            <el-descriptions-item label="部门总数">{{ deptStats.total }}</el-descriptions-item>
            <el-descriptions-item label="最后更新">{{ lastUpdateTime }}</el-descriptions-item>
          </el-descriptions>

          <el-table :data="deptData" stripe size="small" max-height="400" v-loading="deptLoading">
            <el-table-column prop="id" label="ID" width="60" />
            <el-table-column prop="unit_name" label="部门名称" min-width="180" />
            <el-table-column prop="unit_code" label="编码" width="120" />
            <el-table-column prop="sort_order" label="排序" width="70" />
          </el-table>
        </el-card>
      </el-col>

      <el-col :span="12">
        <el-card shadow="hover">
          <template #header>
            <div class="card-header">
              <span>
                <el-icon><User /></el-icon>
                用户信息
              </span>
              <div class="user-header-right">
                <el-switch
                  v-model="filterForeign"
                  active-text="屏蔽国外"
                  inactive-text="全部用户"
                  size="small"
                  @change="handleForeignFilterChange"
                />
                <el-tag type="success" style="margin-left: 10px;">共 {{ userStats.total }} 名用户</el-tag>
              </div>
            </div>
          </template>

          <el-descriptions :column="2" border size="small" class="mb-15">
            <el-descriptions-item label="用户总数">{{ userStats.total }}</el-descriptions-item>
            <el-descriptions-item label="已绑定设备">{{ userStats.bindDevice || 0 }}</el-descriptions-item>
          </el-descriptions>

          <el-table :data="userData" stripe size="small" max-height="400" v-loading="userLoading">
            <el-table-column prop="id" label="ID" width="60" />
            <el-table-column prop="name" label="姓名" width="100" />
            <el-table-column prop="unit_name" label="所属部门" min-width="150" />
            <el-table-column prop="position" label="职务" width="100" />
            <el-table-column label="状态" width="80">
              <template #default="{ row }">
                <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">
                  {{ row.status === 'active' ? '正常' : '禁用' }}
                </el-tag>
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>
    </el-row>

    <el-card>
      <template #header>
        <div class="card-header">
          <span>数据刷新设置</span>
          <div class="refresh-controls">
            <el-switch
              v-model="autoRefresh"
              active-text="自动刷新"
              inactive-text="手动刷新"
              @change="handleAutoRefreshChange"
            />
            <el-select v-model="refreshInterval" style="width: 130px; margin-left: 10px;" @change="resetTimer">
              <el-option label="10秒" :value="10" />
              <el-option label="30秒" :value="30" />
              <el-option label="1分钟" :value="60" />
              <el-option label="5分钟" :value="300" />
            </el-select>
            <el-button type="primary" @click="fetchAllData" :loading="allLoading" style="margin-left: 10px;">
              <el-icon><Refresh /></el-icon>
              立即刷新
            </el-button>
          </div>
        </div>
      </template>

      <el-alert
        :title="autoRefresh ? `数据将每 ${refreshInterval} 秒自动刷新一次` : '自动刷新已关闭，请手动刷新'"
        :type="autoRefresh ? 'success' : 'warning'"
        show-icon
        :closable="false"
      >
        <template #default>
          <p style="margin: 0;">上次更新时间：<strong>{{ lastUpdateTime }}</strong></p>
        </template>
      </el-alert>
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import request from '@/api/request'

const deptData = ref([])
const userData = ref([])
const deptLoading = ref(false)
const userLoading = ref(false)
const allLoading = ref(false)
const autoRefresh = ref(true)
const refreshInterval = ref(180)
let refreshTimer = null

const deptStats = ref({
  total: 0,
})

const userStats = ref({
  total: 0,
  bindDevice: 0,
})

const lastUpdateTime = ref('-')

const filterForeign = ref(false)

onMounted(() => {
  loadForeignFilterConfig().then(() => {
    fetchAllData()
    if (autoRefresh.value) {
      startAutoRefresh()
    }
  })
})

onUnmounted(() => {
  stopAutoRefresh()
})

async function fetchDeptData() {
  deptLoading.value = true
  try {
    const res = await request.get('/units', {
      params: { page: 1, per_page: 50 },
    })
    deptData.value = res.data?.data || []
    deptStats.value.total = res.data?.pagination?.total || 0
  } catch (error) {
    console.error('获取部门数据失败:', error)
  } finally {
    deptLoading.value = false
  }
}

async function fetchUserData() {
  userLoading.value = true
  try {
    const res = await request.get('/users', {
      params: {
        page: 1,
        per_page: 50,
        source: 'admin',
        ...(filterForeign.value ? { exclude_foreign: '1' } : {}),
      },
    })
    userData.value = res.data?.data || []
    userStats.value.total = res.data?.pagination?.total || 0

    const bindCount = userData.value.filter(u => u.device_fingerprint).length
    userStats.value.bindDevice = bindCount
  } catch (error) {
    console.error('获取用户数据失败:', error)
  } finally {
    userLoading.value = false
  }
}

async function fetchAllData() {
  allLoading.value = true
  updateLastTime()

  await Promise.all([fetchDeptData(), fetchUserData()])

  allLoading.value = false
}

function updateLastTime() {
  const now = new Date()
  lastUpdateTime.value = `${now.getHours().toString().padStart(2, '0')}:${now.getMinutes().toString().padStart(2, '0')}:${now.getSeconds().toString().padStart(2, '0')}`
}

function startAutoRefresh() {
  stopAutoRefresh()
  refreshTimer = setInterval(() => {
    fetchAllData()
  }, refreshInterval.value * 1000)
}

function stopAutoRefresh() {
  if (refreshTimer) {
    clearInterval(refreshTimer)
    refreshTimer = null
  }
}

function resetTimer() {
  if (autoRefresh.value) {
    startAutoRefresh()
  }
}

function handleAutoRefreshChange(val) {
  if (val) {
    startAutoRefresh()
  } else {
    stopAutoRefresh()
  }
}

async function loadForeignFilterConfig() {
  try {
    const res = await request.get('/system-configs', {
      params: { keys: 'dept_user_filter_foreign' },
    })
    if (res.data && res.data.dept_user_filter_foreign) {
      filterForeign.value = res.data.dept_user_filter_foreign === '1'
    }
  } catch (error) {
    console.error('加载国外用户过滤配置失败:', error)
  }
}

async function saveForeignFilterConfig(value) {
  try {
    await request.put('/system-configs', {
      config_key: 'dept_user_filter_foreign',
      config_value: value ? '1' : '0',
    })
  } catch (error) {
    console.error('保存国外用户过滤配置失败:', error)
  }
}

async function handleForeignFilterChange(value) {
  await saveForeignFilterConfig(value)
  await fetchUserData()
  updateLastTime()
}
</script>

<style lang="scss" scoped>
.dept-user-info {
  .mb-20 {
    margin-bottom: 20px;
  }

  .mb-15 {
    margin-bottom: 15px;
  }

  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;

    span {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: bold;
      font-size: 16px;
    }
  }

  .refresh-controls {
    display: flex;
    align-items: center;
  }

  .user-header-right {
    display: flex;
    align-items: center;
  }

  :deep(.el-card__header) {
    padding: 12px 20px;
  }
}
</style>
