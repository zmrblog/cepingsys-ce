<template>
  <div class="exam-list-page">
    <van-nav-bar :title="pageTitle" left-arrow @click-left="$router.back()">
      <template #right>
        <van-icon name="wap-home-o" size="20" @click="$router.push('/home')" style="color:#1989fa;cursor:pointer" />
      </template>
    </van-nav-bar>

    <div class="user-info" v-if="userInfo">
      <van-icon name="user-o" />
      <span>{{ userInfo.name || userInfo.phone }}</span>
      <span class="unit">{{ userInfo.unit_name || '未分配单位' }}</span>
    </div>

    <van-pull-refresh v-model="refreshing" @refresh="onRefresh">
      <van-list
        v-model:loading="loading"
        :finished="finished"
        finished-text="没有更多了"
        @load="onLoad"
      >
        <div class="exam-card" v-for="item in filteredExamList" :key="item.id" @click="goToExam(item)">
          <div class="card-header">
            <h3>{{ item.examine_name }}</h3>
            <van-tag :type="getStatusType(item.examine_status)" size="medium">
              {{ getStatusText(item.examine_status) }}
            </van-tag>
          </div>

          <div class="card-body">
            <p><van-icon name="location-o" /> {{ item.unit_name }}</p>
            <p><van-icon name="clock-o" /> {{ item.start_time }} ~ {{ item.end_time }}</p>
            <p><van-icon name="label-o" /> {{ item.template_type === 'leader' ? '干部测评' : '班子测评' }}</p>
          </div>

          <div class="card-footer">
            <van-progress
              :percentage="item.progress || 0"
              stroke-width="6"
              color="#07c160"
            />
            <span class="progress-text">
              已完成 {{ item.answered_targets || 0 }} / {{ item.total_targets || 0 }} 个对象
            </span>
          </div>
        </div>

        <van-empty v-if="!loading && filteredExamList.length === 0" :description="emptyText" />
      </van-list>
    </van-pull-refresh>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import request from '@/api/request'

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const refreshing = ref(false)
const finished = ref(false)
const examList = ref([])
const userInfo = ref(null)

const page = ref(1)

const pageTitle = computed(() => {
  const filter = route.query.filter
  if (filter === 'active') return '待完成的测评'
  if (filter === 'finished') return '已完成的测评'
  return '我的测评任务'
})

const emptyText = computed(() => {
  const filter = route.query.filter
  if (filter === 'active') return '没有待完成的测评任务'
  if (filter === 'finished') return '没有已完成的测评任务'
  return '暂无测评任务'
})

function getEffectiveStatus(item) {
  if (item.examine_status === 'finished') return 'finished'
  if (item.examine_status === 'draft') return 'draft'
  const total = item.total_targets || 0
  const answered = item.answered_targets || 0
  if (total > 0 && answered >= total) return 'finished'
  return 'active'
}

const filteredExamList = computed(() => {
  const filter = route.query.filter
  if (!filter) return examList.value
  return examList.value.filter(item => getEffectiveStatus(item) === filter)
})

onMounted(() => {
  const savedInfo = localStorage.getItem('userInfo')
  if (savedInfo) {
    userInfo.value = JSON.parse(savedInfo)
  }
  
  onRefresh()
})

async function onLoad() {
  // 已通过 pull-refresh 加载数据
}

async function onRefresh() {
  refreshing.value = true
  loading.value = true
  finished.value = false
  page.value = 1

  try {
    const info = JSON.parse(localStorage.getItem('userInfo') || '{}')
    
    const res = await request.get('/answers/my-examines', {
      params: {
        phone: info.phone,
        fingerprint: info.fingerprint,
      },
    })

    if (res.code === 200 && res.data?.data) {
      examList.value = res.data.data
      finished.value = true
    } else {
      examList.value = []
      finished.value = true
    }
  } catch (error) {
    console.error('获取数据失败:', error)
    examList.value = []
    finished.value = true
  } finally {
    refreshing.value = false
    loading.value = false
  }
}

function goToExam(item) {
  if (item.examine_status !== 'active') {
    showToast(item.examine_status === 'finished' ? '该任务已结束' : '该任务尚未开始')
    return
  }

  router.push({
    name: 'TargetList',
    params: { id: item.id },
  })
}

function getStatusType(status) {
  return {
    draft: 'default',
    active: 'success',
    finished: 'warning',
  }[status] || 'default'
}

function getStatusText(status) {
  return {
    draft: '草稿',
    active: '进行中',
    finished: '已结束',
  }[status] || status
}
</script>

<style scoped>
.exam-list-page {
  min-height: 100vh;
  background: #f5f5f5;
}

.user-info {
  background: #fff;
  padding: 12px 16px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  color: #666;
  border-bottom: 1px solid #eee;

  .unit {
    margin-left: auto;
    color: #999;
    font-size: 12px;
  }
}

.exam-card {
  background: #fff;
  margin: 12px;
  border-radius: 8px;
  padding: 16px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);

  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;

    h3 {
      font-size: 16px;
      color: #333;
      margin: 0;
      flex: 1;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      margin-right: 10px;
    }
  }

  .card-body {
    p {
      font-size: 13px;
      color: #666;
      margin: 6px 0;
      display: flex;
      align-items: center;
      gap: 4px;
    }
  }

  .card-footer {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #f0f0f0;

    .progress-text {
      font-size: 12px;
      color: #999;
      margin-top: 6px;
      display: block;
      text-align: right;
    }
  }
}
</style>