<template>
  <div class="dashboard">
    <!-- 统计卡片（4 个等高） -->
    <el-row :gutter="20" class="stat-cards">
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card-clickable" @click="$router.push('/units')">
          <div class="stat-item">
            <div class="stat-icon" style="background: #409eff;">
              <el-icon :size="28"><OfficeBuilding /></el-icon>
            </div>
            <div class="stat-info">
              <h3>{{ stats.totalUnits }}</h3>
              <p>单位总数</p>
              <span class="stat-sub">已导入单位</span>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :span="6">
        <el-card shadow="hover" class="stat-card-clickable" @click="$router.push('/users')">
          <div class="stat-item">
            <div class="stat-icon" style="background: #67c23a;">
              <el-icon :size="28"><User /></el-icon>
            </div>
            <div class="stat-info">
              <h3>{{ stats.totalUsers }}</h3>
              <p>用户总数</p>
              <span class="stat-sub">导入{{ stats.importedUsers }} / 注册{{ stats.registeredUsers }}</span>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :span="6">
        <el-card shadow="hover" class="stat-card-clickable" @click="$router.push({ path: '/examines', query: { status: 'active' } })">
          <div class="stat-item">
            <div class="stat-icon" style="background: #e6a23c;">
              <el-icon :size="28"><List /></el-icon>
            </div>
            <div class="stat-info">
              <h3>{{ stats.activeExamines }}</h3>
              <p>进行中任务</p>
              <span class="stat-sub">&nbsp;</span>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :span="6">
        <el-card shadow="hover" class="stat-card-clickable" @click="$router.push({ path: '/examines', query: { status: 'finished' } })">
          <div class="stat-item">
            <div class="stat-icon" style="background: #f56c6c;">
              <el-icon :size="28"><DataAnalysis /></el-icon>
            </div>
            <div class="stat-info">
              <h3>{{ stats.completedExamines }}</h3>
              <p>已完成任务</p>
              <span class="stat-sub">&nbsp;</span>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 安全防护状态 -->
    <el-card shadow="hover" class="security-card" style="margin-top: 20px;">
      <template #header>
        <div class="card-header">
          <span>
            <el-icon><Lock /></el-icon>
            安全防护状态
          </span>
          <el-switch
            v-model="ipFilterEnabled"
            active-text="已开启"
            inactive-text="已关闭"
            @change="handleIpFilterChange"
          />
        </div>
      </template>

      <el-row :gutter="20">
        <el-col :span="8">
          <div class="security-stat">
            <span class="stat-value">{{ securityStats.todayBlocks }}</span>
            <span class="stat-label">今日拦截</span>
          </div>
        </el-col>
        <el-col :span="8">
          <div class="security-stat">
            <span class="stat-value">{{ securityStats.totalBlocks }}</span>
            <span class="stat-label">总计拦截</span>
          </div>
        </el-col>
        <el-col :span="8">
          <div class="security-stat">
            <span class="stat-value" :class="{ 'text-danger': ipFilterEnabled, 'text-success': !ipFilterEnabled }">
              {{ ipFilterEnabled ? '防护中' : '已停用' }}
            </span>
            <span class="stat-label">当前状态</span>
          </div>
        </el-col>
      </el-row>

      <div v-if="securityStats.recentBlocks.length > 0" class="recent-blocks" style="margin-top: 16px;">
        <el-divider content-position="left">最近拦截记录</el-divider>
        <el-table :data="securityStats.recentBlocks" size="small" stripe max-height="200">
          <el-table-column prop="ip" label="IP地址" width="140" />
          <el-table-column prop="country" label="国家" width="70" />
          <el-table-column prop="method" label="方法" width="60" />
          <el-table-column prop="path" label="路径" show-overflow-tooltip />
          <el-table-column prop="time" label="时间" width="160" />
        </el-table>
      </div>
    </el-card>

    <!-- 快速入门流程图 -->
    <el-card class="quick-start" shadow="hover" style="margin-top: 20px;">
      <template #header>
        <div class="quick-header">
          <span>🚀 快速入门</span>
          <el-button text type="primary" @click="$router.push('/guide')">
            查看完整使用说明 <el-icon><ArrowRight /></el-icon>
          </el-button>
        </div>
      </template>

      <div class="quick-steps">
        <div
          v-for="(qs, idx) in quickSteps"
          :key="idx"
          class="quick-step-item"
          :style="{ background: qs.gradient, cursor: qs.path ? 'pointer' : 'default' }"
          @click="qs.path && $router.push(qs.path)"
        >
          <div class="qs-number">{{ idx + 1 }}</div>
          <div class="qs-body">
            <span class="qs-label">{{ qs.label }}</span>
            <span class="qs-desc">{{ qs.desc }}</span>
          </div>
        </div>
      </div>
    </el-card>

    <!-- 待办提醒 + 今日动态 + 快捷操作 -->
    <el-row :gutter="20" style="margin-top: 20px;">
      <!-- 待办提醒 -->
      <el-col :span="8">
        <el-card class="pending-card" shadow="hover">
          <template #header>
            <div class="section-header">
              <span class="section-title">
                <el-icon><Bell /></el-icon>
                待办提醒
              </span>
              <el-tag v-if="pendingTasks.length > 0" type="warning" size="small" round effect="dark">
                {{ pendingTasks.length }} 项进行中
              </el-tag>
            </div>
          </template>

          <div v-if="pendingTasks.length > 0" class="pending-list">
            <div
              v-for="task in pendingTasks"
              :key="task.id"
              class="pending-item"
              @click="$router.push(`/examines/${task.id}/edit`)"
            >
              <div class="pending-left">
                <span class="pending-dot" :class="{ urgent: task.daysLeft <= 7 }"></span>
                <div class="pending-info">
                  <p class="pending-name">{{ task.examine_name || task.title }}</p>
                  <span class="pending-unit">{{ task.unit_name || '-' }}</span>
                </div>
              </div>
              <div class="pending-right" :class="{ urgent: task.daysLeft <= 7 }">
                <span class="days-num">{{ task.daysLeft }}</span>
                <span class="days-label">天后截止</span>
              </div>
            </div>
          </div>

          <el-empty v-else description="暂无进行中的测评任务" :image-size="60" />
        </el-card>
      </el-col>

      <!-- 今日动态 -->
      <el-col :span="8">
        <el-card class="today-card" shadow="hover">
          <template #header>
            <div class="section-header">
              <span class="section-title">
                <el-icon><TrendCharts /></el-icon>
                今日动态
              </span>
              <span class="today-date">{{ todayStr }}</span>
            </div>
          </template>

          <div class="today-grid">
            <div class="today-item">
              <div class="ti-icon" style="background: rgba(64,158,255,0.1); color: #409eff;">
                <el-icon :size="18"><UserFilled /></el-icon>
              </div>
              <div class="ti-body">
                <span class="ti-value">{{ todayStats.registeredToday }}</span>
                <span class="ti-label">新增注册</span>
              </div>
            </div>
            <div class="today-item">
              <div class="ti-icon" style="background: rgba(103,194,58,0.1); color: #67c23a;">
                <el-icon :size="18"><EditPen /></el-icon>
              </div>
              <div class="ti-body">
                <span class="ti-value">{{ todayStats.answersToday }}</span>
                <span class="ti-label">今日答题</span>
              </div>
            </div>
            <div class="today-item">
              <div class="ti-icon" style="background: rgba(230,162,60,0.1); color: #e6a23c;">
                <el-icon :size="18"><CircleCheck /></el-icon>
              </div>
              <div class="ti-body">
                <span class="ti-value">{{ todayStats.avgCompletion }}%</span>
                <span class="ti-label">平均完成率</span>
              </div>
            </div>
            <div class="today-item">
              <div class="ti-icon" style="background: rgba(245,108,108,0.1); color: #f56c6c;">
                <el-icon :size="18"><Clock /></el-icon>
              </div>
              <div class="ti-body">
                <span class="ti-value">{{ todayStats.activeExamines }}</span>
                <span class="ti-label">进行中任务</span>
              </div>
            </div>
          </div>
        </el-card>
      </el-col>

      <!-- 快捷操作 -->
      <el-col :span="8">
        <el-card class="quick-actions-card" shadow="hover">
          <template #header>
            <div class="section-header">
              <span class="section-title">
                <el-icon><Lightning /></el-icon>
                快捷操作
              </span>
            </div>
          </template>

          <div class="action-grid">
            <button
              v-for="(act, idx) in quickActions"
              :key="idx"
              class="action-btn"
              :style="{ background: act.gradient }"
              @click="$router.push(act.path)"
            >
              <div class="ab-icon">
                <el-icon :size="22"><component :is="act.icon" /></el-icon>
              </div>
              <span class="ab-label">{{ act.label }}</span>
            </button>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 最近测评任务（全宽） -->
    <el-row :gutter="20" style="margin-top: 20px;">
      <el-col :span="24">
        <el-card>
          <template #header>
            <span>最近测评任务</span>
          </template>

          <el-table :data="recentExamines" stripe v-loading="loading" size="small">
            <el-table-column prop="examine_name" label="任务名称" show-overflow-tooltip />
            <el-table-column prop="unit_name" label="所属单位" width="130" show-overflow-tooltip />
            <el-table-column prop="template_type" label="类型" width="90">
              <template #default="{ row }">
                {{ row.template_type === 'leader' ? '干部测评' : '班子测评' }}
              </template>
            </el-table-column>
            <el-table-column prop="status" label="状态" width="85">
              <template #default="{ row }">
                <el-tag :type="getStatusType(row.status)" size="small">
                  {{ getStatusText(row.status) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="完成率" width="150">
              <template #default="{ row }">
                <el-progress
                  :percentage="row.completion_rate || 0"
                  :stroke-width="7"
                  :color="getProgressColor(row.completion_rate)"
                  :show-text="false"
                />
                <span class="rate-text" :style="{ color: getProgressColor(row.completion_rate) }">
                  {{ row.completion_rate || 0 }}%
                </span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="100" fixed="right">
              <template #default="{ row }">
                <el-button
                  type="primary"
                  link
                  size="small"
                  @click="$router.push(`/examines/${row.id}/statistics`)"
                  v-if="row.status === 'finished'"
                >
                  统计
                </el-button>
                <el-button
                  type="primary"
                  link
                  size="small"
                  @click="$router.push(`/examines/${row.id}/edit`)"
                  v-else-if="row.status === 'draft'"
                >
                  编辑
                </el-button>
                <el-button type="info" link disabled size="small" v-else>
                  {{ row.status === 'active' ? '进行中' : '已归档' }}
                </el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>
    </el-row>

	  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useUserStore } from '@/stores/user'
import request from '@/api/request'
import { ElMessageBox } from 'element-plus'

const userStore = useUserStore()

const loading = ref(false)
const allExamines = ref([])
const recentExamines = ref([])

const stats = ref({
  totalUnits: 0,
  totalUsers: 0,
  importedUsers: 0,
  registeredUsers: 0,
  activeExamines: 0,
  completedExamines: 0,
})

const todayStats = ref({
  registeredToday: 0,
  answersToday: 0,
  avgCompletion: 0,
  activeExamines: 0,
})

const ipFilterEnabled = ref(false)
const securityStats = ref({
  is_enabled: false,
  todayBlocks: 0,
  totalBlocks: 0,
  recentBlocks: [],
})

const todayStr = computed(() => {
  const d = new Date()
  return `${d.getMonth() + 1}月${d.getDate()}日`
})

const quickActions = [
  { label: '新建任务', icon: 'Plus', color: '#1B5E9B', gradient: 'linear-gradient(135deg,#1B5E9B,#3B82F6)', path: '/examines/create' },
  { label: '导入人员', icon: 'Upload', color: '#2E8B57', gradient: 'linear-gradient(135deg,#2E8B57,#4ADE80)', path: '/units' },
  { label: '查看报告', icon: 'DataAnalysis', color: '#7232dd', gradient: 'linear-gradient(135deg,#7232dd,#A78BFA)', path: '/analysis' },
  { label: '模板管理', icon: 'Tickets', color: '#D4A017', gradient: 'linear-gradient(135deg,#D4A017,#FBBF24)', path: '/templates' },
]

const quickSteps = [
  { label: '准备数据', desc: '导入单位和人员', icon: 'Upload', color: '#0891B2', gradient: 'linear-gradient(135deg,#0891B2,#0EA5E9)', path: '/units' },
  { label: '创建模板', desc: '设计测评问卷', icon: 'Tickets', color: '#2563EB', gradient: 'linear-gradient(135deg,#2563EB,#6366F1)', path: '/templates' },
  { label: '创建任务', desc: '发布测评场次', icon: 'List', color: '#7C3AED', gradient: 'linear-gradient(135deg,#7C3AED,#A855F7)', path: '/examines/create' },
  { label: '分配人员', desc: '确定参评人和对象', icon: 'User', color: '#DB2777', gradient: 'linear-gradient(135deg,#DB2777,#EC4899)', path: null },
  { label: '执行测评', desc: '激活→答题→归档', icon: 'Select', color: '#65A30D', gradient: 'linear-gradient(135deg,#65A30D,#84CC16)', path: '/examines' },
  { label: '查看结果', desc: '统计分析和导出', icon: 'DataAnalysis', color: '#EA580C', gradient: 'linear-gradient(135deg,#EA580C,#F97316)', path: '/analysis' },
]

const pendingTasks = computed(() => {
  if (!allExamines.value.length) return []
  const now = new Date()
  return allExamines.value
    .filter(e => e.status === 'active')
    .map(e => {
      let daysLeft = Infinity
      if (e.end_time) {
        const end = new Date(e.end_time)
        const diff = Math.ceil((end - now) / (1000 * 60 * 60 * 24))
        daysLeft = diff > 0 ? diff : 0
      }
      return { ...e, daysLeft }
    })
    .sort((a, b) => a.daysLeft - b.daysLeft)
    .slice(0, 4)
})

onMounted(async () => {
  await Promise.all([
    fetchStats(),
    fetchAllExamines(),
  ])
})

async function fetchStats() {
  try {
    const [unitsRes, usersRes, importedRes, registeredRes] = await Promise.all([
      request.get('/units', { params: { per_page: 1 } }),
      request.get('/users', { params: { per_page: 1 } }),
      request.get('/users', { params: { per_page: 1, source: 'admin' } }),
      request.get('/users', { params: { per_page: 1, source: 'registered' } }),
    ])

    stats.value = {
      totalUnits: unitsRes.data?.pagination?.total || 0,
      totalUsers: usersRes.data?.pagination?.total || 0,
      importedUsers: importedRes.data?.pagination?.total || 0,
      registeredUsers: registeredRes.data?.pagination?.total || 0,
      activeExamines: 0,
      completedExamines: 0,
    }

    todayStats.value.activeExamines = 0
  } catch (error) {
    if (import.meta.env.DEV) {
      console.error('获取统计数据失败:', error)
    }
  }
}

async function fetchAllExamines() {
  loading.value = true
  try {
    const res = await request.get('/examines', { params: { per_page: 50 } })
    const list = res.data?.data || []
    allExamines.value = list
    recentExamines.value = list.slice(0, 5)

    const active = list.filter(e => e.status === 'active').length
    const finished = list.filter(e => e.status === 'finished').length
    stats.value.activeExamines = active
    stats.value.completedExamines = finished

    const avgRate = list.length > 0
      ? Math.round(list.reduce((s, e) => s + (e.completion_rate || 0), 0) / list.length)
      : 0
    todayStats.value.registeredToday = stats.value.registeredUsers
    todayStats.value.answersToday = finished
    todayStats.value.avgCompletion = avgRate
    todayStats.value.activeExamines = active
  } catch (error) {
    console.error('获取任务列表失败:', error)
  } finally {
    loading.value = false
  }
}

function getStatusType(status) {
  const map = { draft: 'info', active: '', finished: 'success', archived: 'warning' }
  return map[status] || 'info'
}

function getStatusText(status) {
  const map = { draft: '草稿', active: '进行中', finished: '已结束', archived: '已归档' }
  return map[status] || status
}

function getProgressColor(percentage) {
  if (percentage >= 80) return '#67c23a'
  if (percentage >= 50) return '#e6a23c'
  return '#f56c6c'
}

async function handleIpFilterChange(value) {
  if (value === true) {
    try {
      await ElMessageBox.confirm(
        'IP过滤/安全防护为企业版专属功能，包含国外IP拦截、登录频率限制等。是否联系管理员了解升级事宜？',
        '企业版功能',
        {
          confirmButtonText: '了解升级',
          cancelButtonText: '关闭',
          type: 'warning',
        }
      )
      // 用户确认了解升级，可打开升级弹窗或联系信息
      ipFilterEnabled.value = false
    } catch {
      // 用户取消
      ipFilterEnabled.value = false
    }
  }
}
</script>

<style lang="scss" scoped>
.dashboard {
  .stat-cards {
    .stat-card-clickable {
      cursor: pointer;
      transition: all 0.3s ease;
      border: 1px solid #C5CCD4;

      &:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-md);
        border-color: #5C8DB8;

        .stat-icon { transform: scale(1.05); }
      }
    }

    .stat-item {
      display: flex;
      align-items: center;
      gap: 16px;

      .stat-icon {
        width: 52px; height: 52px;
        border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        color: #fff;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        flex-shrink: 0;
        transition: transform 0.3s ease;
      }

      .stat-info {
        min-height: 52px;
        display: flex; flex-direction: column; justify-content: center;

        h3 { font-size: 26px; color: var(--color-text-primary); margin-bottom: 2px; font-weight: 700; letter-spacing: -0.5px; line-height: 1.2; }
        p { font-size: 13px; color: var(--color-text-secondary); margin: 0; font-weight: 500; line-height: 1.4; }
        .stat-sub { display: block; font-size: 11px; color: var(--color-text-muted); background: transparent; padding: 0; border-radius: 0; margin-top: 2px; line-height: 1.4; min-height: 16px; }
      }
    }
  }
}

// --- 快速入门（渐变卡片） ---
.quick-start {
  border-radius: 16px; overflow: hidden;
  box-shadow: var(--shadow-sm);

  .quick-header {
    display: flex; justify-content: space-between; align-items: center;
    border-bottom: 1px solid #DCE2EA; padding-bottom: 16px;

    span { font-weight: 600; font-size: 16px; color: var(--color-text-primary); }
  }

  .quick-steps {
    display: flex; align-items: stretch; gap: 12px; padding-top: 12px;

    .quick-step-item {
      flex: 1; display: flex; flex-direction: column; align-items: center;
      text-align: center; gap: 10px; padding: 20px 10px 16px;
      border-radius: 14px; border: none;
      box-shadow: 0 4px 15px rgba(0,0,0,0.15);
      transition: all 0.3s ease; position: relative;
      color: #fff;
      cursor: pointer;

      &:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.25);
      }

      .qs-number {
        width: 28px; height: 28px; border-radius: 50%;
        background: rgba(255,255,255,0.25);
        display: flex; align-items: center; justify-content: center;
        font-size: 13px; font-weight: 700;
        backdrop-filter: blur(4px);
        flex-shrink: 0;
      }

      .qs-body {
        display: flex; flex-direction: column;
        .qs-label { font-size: 14px; font-weight: 700; color: #fff; letter-spacing: 0.3px; }
        .qs-desc { font-size: 11px; color: rgba(255,255,255,0.85); margin-top: 3px; line-height: 1.4; }
      }
    }
  }
}

// --- 快捷操作（渐变磁贴） ---
.quick-actions-card { border-radius: 12px; }

.action-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 12px;

  .action-btn {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 8px; padding: 18px 10px 14px;
    border: none; border-radius: 14px;
    cursor: pointer; transition: all 0.3s ease;
    outline: none; font-family: inherit;
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
    color: #fff;

    &:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.25);
    }

    .ab-icon {
      width: 40px; height: 40px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      background: rgba(255,255,255,0.2);
      color: #fff; flex-shrink: 0;
      backdrop-filter: blur(4px);
    }

    .ab-label { font-size: 13px; font-weight: 600; letter-spacing: 0.3px; }
  }
}
</style>
