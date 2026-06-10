<template>
  <div class="home-page">
    <div class="user-bar">
      <div class="avatar">{{ userInfo?.real_name?.charAt(0) || 'U' }}</div>
      <div class="user-detail">
        <div class="greeting">您好，{{ userInfo?.real_name || '用户' }}</div>
        <div class="phone">{{ maskPhone(userInfo?.phone) }}</div>
      </div>
      <div class="logout-btn" @click="handleLogout">
        <van-icon name="revoke" size="18" />
        <span>退出</span>
      </div>
    </div>

    <div class="stats-row">
      <div class="stat-card active-count" @click="$router.push({ path: '/exam-list', query: { filter: 'active' } })">
        <div class="stat-num">{{ activeCount }}</div>
        <div class="stat-label">待完成</div>
      </div>
      <div class="stat-card finished-count" @click="$router.push({ path: '/exam-list', query: { filter: 'finished' } })">
        <div class="stat-num">{{ finishedCount }}</div>
        <div class="stat-label">已完成</div>
      </div>
      <div class="stat-card total-count" @click="$router.push('/exam-list')">
        <div class="stat-num">{{ totalCount }}</div>
        <div class="stat-label">全部任务</div>
      </div>
    </div>

    <div class="section-header">
      <h3>我的测评任务</h3>
      <span @click="$router.push('/exam-list')">查看全部</span>
    </div>

    <van-pull-refresh v-model="refreshing" @refresh="fetchExams">
      <div v-if="examList.length > 0" class="exam-list">
        <div
          v-for="item in examList"
          :key="item.id"
          class="exam-card"
          :class="{ 'card-active': getEffectiveStatus(item) === 'active', 'card-finished': getEffectiveStatus(item) === 'finished' }"
          @click="goToExam(item)"
        >
          <div class="card-top">
            <h4>{{ item.examine_name }}</h4>
            <van-tag :type="getStatusType(getEffectiveStatus(item))" size="medium">
              {{ getStatusText(getEffectiveStatus(item)) }}
            </van-tag>
          </div>
          <p class="time-range">
            <van-icon name="clock-o" /> {{ formatDate(item.start_time) }} ~ {{ formatDate(item.end_time) }}
          </p>
          <div class="progress-area">
            <van-progress
              :percentage="calcProgress(item)"
              stroke-width="6"
              :color="getEffectiveStatus(item) === 'active' ? '#07c160' : '#999'"
            />
            <span class="progress-label">
              已答 {{ item.answered_items || 0 }} / {{ item.total_items || 0 }} 题 · {{ item.answered_targets || 0 }}/{{ item.total_targets || 0 }} 人
            </span>
          </div>
        </div>
      </div>
      <van-empty v-else description="暂无测评任务" image="search" />
    </van-pull-refresh>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { showDialog, showSuccessToast } from 'vant'
import request from '@/api/request'

const router = useRouter()
const refreshing = ref(false)
const userInfo = ref(null)
const examList = ref([])

const activeCount = computed(() => examList.value.filter(e => {
  if (e.examine_status !== 'active') return false
  return (e.answered_targets || 0) < (e.total_targets || 0)
}).length)
const finishedCount = computed(() => examList.value.filter(e => {
  if (e.examine_status === 'finished') return true
  if (e.examine_status === 'active' && (e.total_targets || 0) > 0) {
    return (e.answered_targets || 0) >= (e.total_targets || 0)
  }
  return false
}).length)
const totalCount = computed(() => examList.value.length)

function maskPhone(phone) {
  if (!phone) return ''
  return phone.replace(/(\d{3})\d{4}(\d{4})/, '$1****$2')
}

function formatDate(dateStr) {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  return `${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function calcProgress(item) {
  const totalItems = item.total_items || 0
  const answeredItems = item.answered_items || 0
  if (totalItems <= 0) return 0
  return Math.round((answeredItems / totalItems) * 100)
}

function getStatusType(status) {
  return { draft: 'default', active: 'success', finished: 'warning' }[status] || 'default'
}

function getStatusText(status) {
  return { draft: '草稿', active: '进行中', finished: '已结束' }[status] || status
}

function getEffectiveStatus(item) {
  if (item.examine_status === 'finished') return 'finished'
  if (item.examine_status === 'draft') return 'draft'
  const total = item.total_targets || 0
  const answered = item.answered_targets || 0
  if (total > 0 && answered >= total) return 'finished'
  return 'active'
}

async function fetchExams() {
  refreshing.value = true
  try {
    const info = JSON.parse(localStorage.getItem('userInfo') || '{}')
    const res = await request.get('/answers/my-examines', {
      params: { phone: info.phone, fingerprint: info.fingerprint },
    })
    examList.value = res.code === 200 && res.data?.data ? res.data.data : []
  } catch {}
  refreshing.value = false
}

onMounted(async () => {
  const stored = localStorage.getItem('userInfo')
  if (stored) try { userInfo.value = JSON.parse(stored) } catch {}
  await fetchExams()
})

function goToExam(item) {
  if (item.examine_status !== 'active') {
    showSuccessToast(item.examine_status === 'finished' ? '该任务已结束' : '该任务尚未开始')
    return
  }
  router.push({ name: 'TargetList', params: { id: item.id } })
}

async function handleLogout() {
  const ok = await showDialog({ message: '确定退出登录？', showCancelButton: true })
  if (ok === 'confirm') {
    localStorage.removeItem('userInfo')
    localStorage.removeItem('token')
    router.push('/login')
  }
}
</script>

<style lang="scss" scoped>
.home-page {
  min-height: 100vh;
  background: #f5f6fa;
}

.user-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px 20px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);

  .avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: rgba(255,255,255,0.25);
    color: white;
    font-size: 20px;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .user-detail {
    flex: 1;
    min-width: 0;

    .greeting {
      color: white;
      font-size: 17px;
      font-weight: 600;
    }
    .phone {
      color: rgba(255,255,255,0.75);
      font-size: 13px;
      margin-top: 2px;
    }
  }

  .logout-btn {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    border-radius: 14px;
    background: rgba(255,255,255,0.15);
    color: rgba(255,255,255,0.85);
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
    flex-shrink: 0;

    &:active {
        background: rgba(255,255,255,0.25);
      }
    }
}

.stats-row {
  display: flex;
  gap: 12px;
  padding: 16px 16px 12px;

  .stat-card {
    flex: 1;
    background: white;
    border-radius: 10px;
    padding: 14px 8px;
    text-align: center;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
    cursor: pointer;
    transition: transform .15s;

    &:active {
      transform: scale(0.96);
    }

    .stat-num {
      font-size: 24px;
      font-weight: 700;
      line-height: 1.2;
    }

    .stat-label {
      font-size: 12px;
      color: #999;
      margin-top: 4px;
    }

    &.active-count .stat-num { color: #07c160; }
    &.finished-count .stat-num { color: #ff976a; }
    &.total-count .stat-num { color: #1989fa; }
  }
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 20px 10px;

  h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
    color: #333;
  }

  span {
    font-size: 13px;
    color: #1989fa;
    cursor: pointer;
  }
}

.exam-list {
  padding: 0 16px 30px;
}

.exam-card {
  background: white;
  border-radius: 10px;
  padding: 16px;
  margin-bottom: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.05);

  &.card-active {
    border-left: 4px solid #07c160;
  }
  &.card-finished {
    border-left: 4px solid #ff976a;
  }

  .card-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;

    h4 {
      font-size: 15px;
      font-weight: 600;
      color: #333;
      margin: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }

  .time-range {
    font-size: 12px;
    color: #999;
    margin: 8px 0 12px;
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .progress-area {
    .progress-label {
      font-size: 12px;
      color: #999;
      display: block;
      text-align: right;
      margin-top: 6px;
    }
  }
}
</style>
