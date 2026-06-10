<template>
  <div class="target-list-page">
    <van-nav-bar
      :title="examineName"
      left-arrow
      @click-left="$router.back()"
    >
      <template #right>
        <van-icon name="wap-home-o" size="20" @click="$router.push('/home')" class="home-btn" />
      </template>
    </van-nav-bar>

    <div class="target-scroll-area">
      <van-pull-refresh v-model="refreshing" @refresh="fetchTargets">
        <div class="target-card" v-for="(target, index) in sortedTargets" :key="target.id"
          :class="{ 'target-completed': target.is_locked || target.answered }"
          @click="goToTakeExam(target)">
          <div class="target-header">
            <span class="index">{{ index + 1 }}</span>
            <h3>{{ target.target_name }}</h3>
            <van-tag :type="target.target_type === 'team' ? 'success' : 'primary'" size="medium">
              {{ target.target_type === 'team' ? '班子' : '干部' }}
            </van-tag>
          </div>

          <p class="position" v-if="target.position">{{ target.position }}</p>
          <p class="unit" v-if="target.unit_name">{{ target.unit_name }}</p>

          <div class="status" v-if="target.is_locked">
            <van-icon name="lock" color="#e6a23c" />
            <span>已完成</span>
          </div>

          <div class="status" v-else-if="target.answered">
            <van-icon name="checked" color="#07c160" />
            <span>已测评 · 点击修改</span>
          </div>
        </div>

        <van-empty v-if="!loading && targets.length === 0" description="暂无测评对象" />
      </van-pull-refresh>
    </div>

    <div class="bottom-bar">
      <van-button
        v-if="allAnswered"
        type="primary"
        size="large"
        :loading="submitting"
        @click="handleSubmitAll"
        block
      >
        全部提交
      </van-button>
      <div v-else class="bottom-progress">
        <van-progress
          :percentage="completionProgress"
          stroke-width="6"
          color="#07c160"
        />
        <span class="progress-text">已完成 {{ completedCount }} / {{ targets.length }} 个对象</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import request from '@/api/request'
import { showToast, showSuccessToast, showDialog } from 'vant'

const route = useRoute()
const router = useRouter()

const examineId = ref(route.params.id)
const examineName = ref('测评任务')
const loading = ref(false)
const refreshing = ref(false)
const submitting = ref(false)
const targets = ref([])

const completedCount = computed(() => targets.value.filter(t => t.is_locked || t.answered).length)

const completionProgress = computed(() => {
  if (targets.value.length === 0) return 0
  return Math.round((completedCount.value / targets.value.length) * 100)
})

const allAnswered = computed(() => {
  return targets.value.length > 0 && targets.value.every(t => t.answered)
})

const sortedTargets = computed(() => {
  const list = [...targets.value]
  list.sort((a, b) => {
    const aDone = a.is_locked || a.answered
    const bDone = b.is_locked || b.answered
    if (aDone && !bDone) return 1
    if (!aDone && bDone) return -1
    return 0
  })
  return list
})

onMounted(async () => {
  await fetchTargets()
})

async function fetchTargets() {
  refreshing.value = true
  loading.value = true

  try {
    const res = await request.get(`/answers/examine/${examineId.value}/targets`)

    if (res.code === 200 && res.data) {
      targets.value = res.data
    }
  } catch (error) {
    console.error('获取测评对象失败:', error)
    showToast('获取数据失败')
  } finally {
    refreshing.value = false
    loading.value = false
  }
}

function goToTakeExam(target) {
  if (target.is_locked) {
    showToast('该测评对象已完成锁定')
    return
  }
  router.push({
    name: 'TakeExam',
    params: {
      id: examineId.value,
      targetId: target.id,
    },
    query: {
      targetName: target.target_name,
    },
  })
  const plainTargets = JSON.parse(JSON.stringify(targets.value))
  const context = {
    targets: plainTargets,
    examineId: examineId.value,
    examineName: examineName.value,
  }
  localStorage.setItem(`examine_${examineId.value}_context`, JSON.stringify(context))
  history.replaceState(context, '')
}

async function handleSubmitAll() {
  try {
    await showDialog({
      title: '确认提交',
      message: `已完成全部 ${targets.value.length} 个测评对象的作答，确定要提交吗？提交后将无法修改。`,
      confirmButtonText: '确定提交',
      cancelButtonText: '取消',
    })

    submitting.value = true
    const info = JSON.parse(localStorage.getItem('userInfo') || '{}')

    const res = await request.post('/answers/submit-all', {
      examine_id: parseInt(examineId.value),
      user_phone: info.phone,
      device_fingerprint: info.fingerprint,
    })

    if (res.code === 200) {
      showSuccessToast('提交成功！感谢您的参与')
      setTimeout(() => {
        router.push({ name: 'ExamList' })
      }, 1500)
    }
  } catch (error) {
    if (error !== 'cancel') {
      console.error('提交失败:', error)
      const msg = error?.response?.data?.message || error?.message || '提交失败'
      showToast(msg)
    }
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.target-list-page {
  min-height: 100vh;
  background: #f5f5f5;
  display: flex;
  flex-direction: column;
}

.home-btn {
  color: #1989fa;
  cursor: pointer;
}

.target-scroll-area {
  flex: 1;
  overflow-y: auto;
  padding-bottom: 80px;
}

.target-card {
  background: #fff;
  margin: 12px;
  border-radius: 8px;
  padding: 16px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
  transition: background .2s;

  &.target-completed {
    background: #f7f8fa;
    opacity: .75;
  }

  .target-header {
    display: flex;
    align-items: center;
    gap: 10px;

    .index {
      width: 28px;
      height: 28px;
      background: linear-gradient(135deg, #667eea, #764ba2);
      color: #fff;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14px;
      font-weight: bold;
    }
  }

  &.target-completed .target-header .index {
    background: linear-gradient(135deg, #a0aec0, #cbd5e0);
  }

  .target-header h3 {
    flex: 1;
    font-size: 16px;
    color: #333;
    margin: 0;
  }

  .position, .unit {
    font-size: 13px;
    color: #666;
    margin: 6px 0 0 38px;
  }

  .status {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    color: #07c160;
  }
}

.bottom-bar {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  background: #fff;
  padding: 12px 16px;
  padding-bottom: max(12px, env(safe-area-inset-bottom));
  box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.05);
  z-index: 100;
}

.bottom-progress {
  .progress-text {
    font-size: 12px;
    color: #999;
    display: block;
    text-align: right;
    margin-top: 6px;
  }
}
</style>