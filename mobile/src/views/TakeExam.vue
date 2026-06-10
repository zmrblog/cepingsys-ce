<template>
  <div class="take-exam-page">
    <van-nav-bar
      :title="targetName"
      left-arrow
      @click-left="handleBack"
    >
      <template #right>
        <van-icon name="wap-home-o" size="20" @click="handleGoHome" class="home-btn" />
      </template>
    </van-nav-bar>

    <div class="progress-bar-wrapper">
      <div class="progress-header">
        <span class="progress-label">题目进度</span>
        <span class="progress-info">{{ currentIndex + 1 }} / {{ items.length }}</span>
      </div>
      <van-progress
        :percentage="progressPercentage"
        stroke-width="6"
        color="#07c160"
        pivot-color="#07c160"
        pivot-text=""
      />
    </div>

    <div class="question-card" v-if="currentItem">
      <div class="question-header">
        <h3>{{ currentIndex + 1 }}. {{ currentItem.item_title }}</h3>
        <van-tag v-if="currentItem.is_reverse" type="danger" size="medium">反向测评</van-tag>
      </div>

      <div v-if="currentItem.item_type === 'radio'" class="options-list">
        <van-radio-group
          v-model="currentAnswer"
          @change="handleRadioChange"
          :disabled="isLocked"
        >
          <van-cell-group inset>
            <van-cell
              v-for="(option, optIdx) in currentItem.options"
              :key="optIdx"
              :clickable="!isLocked"
              @click="!isLocked && (currentAnswer = getOptionValue(option), handleRadioChange(getOptionValue(option)))"
            >
              <template #title>
                <div class="option-content">
                  <van-radio :name="getOptionValue(option)">{{ getOptionLabel(option) }}</van-radio>
                </div>
              </template>
            </van-cell>
          </van-cell-group>
        </van-radio-group>

        <div class="example-input" v-if="shouldShowRadioExample">
          <p class="example-label">请填写具体事例说明：</p>
          <van-field
            v-model="exampleText"
            type="textarea"
            placeholder="请详细描述具体事例..."
            rows="3"
            maxlength="500"
            show-word-limit
            autosize
            :disabled="isLocked"
          />
        </div>
      </div>

      <div v-else-if="currentItem.item_type === 'checkbox'" class="options-list">
        <van-checkbox-group
          v-model="checkboxAnswers"
          @change="handleCheckboxChange"
          :disabled="isLocked"
        >
          <van-cell-group inset>
            <van-cell
              v-for="(option, optIdx) in currentItem.options"
              :key="optIdx"
              :clickable="!isLocked"
              @click="!isLocked && toggleCheckbox(option)"
            >
              <template #title>
                <div class="option-content">
                  <van-checkbox :name="getOptionValue(option)" shape="square">{{ getOptionLabel(option) }}</van-checkbox>
                </div>
              </template>
            </van-cell>
          </van-cell-group>
        </van-checkbox-group>

        <div class="constraint-hint" v-if="currentItem.min_select || currentItem.max_select">
          <span>已选 {{ checkboxAnswers.length }} 项</span>
          <span class="constraint-text" :class="{ 'error': !isConstraintValid }">
            （{{ currentItem.min_select || 0 }}-{{ currentItem.max_select || currentItem.options?.length }}项）
          </span>
        </div>

        <div class="example-input" v-if="shouldShowCheckboxExample">
          <p class="example-label">请填写具体事例说明：</p>
          <van-field
            v-model="exampleText"
            type="textarea"
            placeholder="请详细描述具体事例..."
            rows="3"
            maxlength="500"
            show-word-limit
            autosize
            :disabled="isLocked"
          />
        </div>
      </div>

      <div v-else-if="currentItem.item_type === 'textarea'" class="textarea-wrapper">
        <van-field
          v-model="textareaAnswer"
          type="textarea"
          placeholder="请输入您的意见或建议..."
          rows="5"
          maxlength="2000"
          show-word-limit
          autosize
          :disabled="isLocked"
          @blur="handleTextareaBlur"
        />
      </div>

      <div class="action-buttons" v-if="!isLocked">
        <van-button
          size="large"
          :disabled="currentIndex === 0"
          @click="prevQuestion"
        >
          上一题
        </van-button>

        <van-button
          size="large"
          type="primary"
          :disabled="!canGoNext"
          @click="nextQuestion"
        >
          {{ currentIndex === items.length - 1 ? '完成本对象' : '下一题' }}
        </van-button>
      </div>

      <div class="locked-notice" v-if="isLocked">
        <van-icon name="lock" />
        <span>该测评对象已完成并锁定，答案不可修改</span>
        <van-button size="small" type="default" @click="goBackToList">返回列表</van-button>
      </div>

      <p class="reverse-hint" v-if="needExampleHint">
        ⚠️ 选择负面评价时，请先填写上方的事例说明
      </p>
    </div>

    <van-empty v-else description="暂无题目" />

    <van-dialog
      v-model:show="showSubmitDialog"
      title="完成本对象"
      show-cancel-button
      confirm-button-text="确定完成"
      @confirm="handleSubmit"
    >
      <p style="padding: 20px; text-align: center; color: #666;">
        {{ isLastTarget ? '这是最后一个测评对象，确定完成？' : '确定完成？完成后将自动跳转到下一个测评对象' }}
      </p>
    </van-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute, onBeforeRouteLeave } from 'vue-router'
import request from '@/api/request'
import { showToast, showSuccessToast, showDialog } from 'vant'

const route = useRoute()
const router = useRouter()

const examineId = ref(route.params.id)
const targetId = ref(route.params.targetId)
const targetName = ref(route.query.targetName || '测评对象')

const items = ref([])
const answers = ref({})
const currentIndex = ref(0)

const currentAnswer = ref('')
const checkboxAnswers = ref([])
const textareaAnswer = ref('')
const exampleText = ref('')
const isLocked = ref(false)

const loading = ref(false)
const saving = ref(false)
const showSubmitDialog = ref(false)

const PROGRESS_KEY = computed(() => `exam_progress_${examineId.value}_${targetId.value}`)
const CONTEXT_KEY = computed(() => `examine_${examineId.value}_context`)

const isLastTarget = computed(() => {
  const targets = getTargetsContext()
  if (!targets.length) return true
  const remaining = targets.filter(t => {
    if (String(t.id) === String(targetId.value)) return false
    return !(t.is_locked)
  })
  return remaining.length === 0
})

const currentItem = computed(() => items.value[currentIndex.value] || null)

const showExampleInput = computed(() => {
  return currentItem.value?.required_example || currentItem.value?.is_reverse
})

const shouldShowRadioExample = computed(() => {
  if (!showExampleInput.value) return false
  return isReverseOption(currentAnswer.value)
})

const shouldShowCheckboxExample = computed(() => {
  if (!showExampleInput.value) return false
  return hasReverseSelection()
})

const needExampleHint = computed(() => {
  if (!currentItem.value) return false
  const hasAnswer = currentItem.value.item_type === 'radio'
    ? !!currentAnswer.value
    : (currentItem.value.item_type === 'checkbox' ? checkboxAnswers.value.length > 0 : false)
  if (!hasAnswer) return false
  return (shouldShowRadioExample.value || shouldShowCheckboxExample.value) && !exampleText.value.trim()
})

const progressPercentage = computed(() => {
  if (items.value.length === 0) return 0
  return Math.round(((currentIndex.value) / items.value.length) * 100)
})

const canGoNext = computed(() => {
  if (!currentItem.value) return false

  switch (currentItem.value.item_type) {
    case 'radio':
      if (!currentAnswer.value) return false
      if (shouldShowRadioExample.value && !exampleText.value.trim()) return false
      return true

    case 'checkbox':
      if (!isConstraintValid.value) return false
      if (checkboxAnswers.value.length === 0) return false
      if (shouldShowCheckboxExample.value && !exampleText.value.trim()) return false
      return true

    case 'textarea':
      return textareaAnswer.value.trim().length > 0

    default:
      return true
  }
})

const isConstraintValid = computed(() => {
  const item = currentItem.value
  if (!item) return true

  const count = checkboxAnswers.value.length
  const min = item.min_select || 0
  const max = item.max_select || item.options?.length || 999

  return count >= min && count <= max
})

function getTargetsContext() {
  try {
    const data = localStorage.getItem(CONTEXT_KEY.value)
    if (data) return JSON.parse(data).targets || []
  } catch {}
  try {
    if (history.state?.targets) return history.state.targets
  } catch {}
  return []
}

onMounted(async () => {
  await fetchItems()
  await fetchExistingAnswers()
  await checkTargetLocked()

  if (!isLocked.value) {
    restoreProgress()
  }

  initCurrentAnswer()
})

function restoreProgress() {
  try {
    const saved = localStorage.getItem(PROGRESS_KEY.value)
    if (saved) {
      const { index } = JSON.parse(saved)
      if (typeof index === 'number' && index >= 0 && index < items.value.length) {
        currentIndex.value = index
      }
    }
  } catch {}
}

function saveProgress() {
  try {
    localStorage.setItem(PROGRESS_KEY.value, JSON.stringify({
      index: currentIndex.value,
    }))
  } catch {}
}

function clearProgress() {
  try {
    localStorage.removeItem(PROGRESS_KEY.value)
  } catch {}
}

async function fetchItems() {
  loading.value = true
  try {
    const res = await request.get(`/answers/examine/${examineId.value}/target/${targetId.value}/items`)
    
    if (res.code === 200 && res.data) {
      items.value = res.data.map(item => ({
        ...item,
        options: item.options || [],
        reverse_options: item.reverse_options || [],
      }))
    }
  } catch (error) {
    console.error('获取题目失败:', error)
    showToast('获取题目失败')
  } finally {
    loading.value = false
  }
}

async function fetchExistingAnswers() {
  try {
    const info = JSON.parse(localStorage.getItem('userInfo') || '{}')
    
    const res = await request.get(`/answers/examine/${examineId.value}/target/${targetId.value}/answers`, {
      params: {
        phone: info.phone,
        fingerprint: info.fingerprint,
      },
    })

    if (res.code === 200 && res.data) {
      res.data.forEach(answer => {
        answers.value[answer.item_id] = answer.answer_value
      })
    }
  } catch (error) {
    console.error('获取已有答案失败:', error)
  }
}

function initCurrentAnswer() {
  const item = currentItem.value
  if (!item) return

  const savedAnswer = answers.value[item.id]

  switch (item.item_type) {
    case 'radio':
      currentAnswer.value = savedAnswer || ''
      break

    case 'checkbox':
      checkboxAnswers.value = Array.isArray(savedAnswer) ? savedAnswer : []
      break

    case 'textarea':
      textareaAnswer.value = savedAnswer || ''
      break
  }

  exampleText.value = ''
}

function isReverseOption(value) {
  if (!currentItem.value?.reverse_options) return false
  const normalizedValue = String(value || '').trim()
  return currentItem.value.reverse_options.some(opt => {
    const normalized = normalizeReverseOpt(opt)
    return normalized === normalizedValue || opt === value
  })
}

function hasReverseSelection() {
  if (!currentItem.value?.reverse_options) return false
  const reverseTexts = currentItem.value.reverse_options.map(opt => normalizeReverseOpt(opt))
  return checkboxAnswers.value.some(ans => reverseTexts.includes(String(ans || '').trim()))
}

function normalizeReverseOpt(opt) {
  if (typeof opt === 'string') {
    if (opt.includes(':')) {
      return opt.split(':').slice(1).join(':').trim()
    }
    return opt.trim()
  }
  return (opt?.text || opt?.letter || '').toString().trim()
}

function getOptionLabel(option) {
  if (typeof option === 'string') return option
  return option?.text || option?.letter || JSON.stringify(option)
}

function getOptionValue(option) {
  if (typeof option === 'string') return option
  return option?.text || option?.letter || String(option?.score || '')
}

async function handleRadioChange(value) {
  currentAnswer.value = value
  await saveCurrentAnswer()
}

function toggleCheckbox(option) {
  const val = getOptionValue(option)
  const idx = checkboxAnswers.value.indexOf(val)

  if (idx > -1) {
    checkboxAnswers.value.splice(idx, 1)
  } else {
    const maxSelect = currentItem.value?.max_select || 999
    if (checkboxAnswers.value.length >= maxSelect) {
      showToast(`最多只能选择${maxSelect}项`)
      return
    }

    checkboxAnswers.value.push(val)
  }

  handleCheckboxChange(checkboxAnswers.value)
}

async function handleCheckboxChange(values) {
  const minSelect = currentItem.value?.min_select || 0
  const maxSelect = currentItem.value?.max_select || 999
  
  if (values.length < minSelect) {
    showToast(`至少需要选择${minSelect}项`)
  } else if (values.length > maxSelect) {
    showToast(`最多只能选择${maxSelect}项`)
  }

  await saveCurrentAnswer()
}

async function handleTextareaBlur() {
  await saveCurrentAnswer()
}

async function saveCurrentAnswer() {
  if (!currentItem.value) return
  if (isLocked.value) return

  let answerValue = ''
  const example = exampleText.value.trim()

  switch (currentItem.value.item_type) {
    case 'radio':
      answerValue = currentAnswer.value
      break
    case 'checkbox':
      answerValue = JSON.stringify(checkboxAnswers.value)
      break
    case 'textarea':
      answerValue = textareaAnswer.value.trim()
      break
  }

  if (!answerValue) return

  saving.value = true

  try {
    const info = JSON.parse(localStorage.getItem('userInfo') || '{}')
    
    await request.post('/answers', {
      examine_id: parseInt(examineId.value),
      target_id: parseInt(targetId.value),
      item_id: currentItem.value.id,
      answer_value: answerValue,
      example_text: example || null,
      user_phone: info.phone,
      device_fingerprint: info.fingerprint,
    })

    answers.value[currentItem.value.id] = answerValue
    
  } catch (error) {
    console.error('保存答案失败:', error)
    const msg = error?.response?.data?.message || error?.message || '保存失败，请重试'
    showToast(msg)
  } finally {
    saving.value = false
  }
}

function prevQuestion() {
  if (currentIndex.value <= 0) return

  saveCurrentAnswer().then(() => {
    currentIndex.value--
    saveProgress()
    initCurrentAnswer()
  })
}

function nextQuestion() {
  if (currentIndex.value >= items.value.length - 1) {
    showSubmitDialog.value = true
    return
  }

  saveCurrentAnswer().then(() => {
    currentIndex.value++
    saveProgress()
    initCurrentAnswer()
  })
}

async function handleSubmit() {
  await saveCurrentAnswer()

  const info = JSON.parse(localStorage.getItem('userInfo') || '{}')
  try {
    const res = await request.post('/answers/complete-target', {
      examine_id: parseInt(examineId.value),
      target_id: parseInt(targetId.value),
      user_phone: info.phone,
      device_fingerprint: info.fingerprint,
    })
    showSuccessToast('本对象已锁定')
    isLocked.value = true
    clearProgress()
  } catch (error) {
    console.error('锁定失败:', error)
    showToast(error?.response?.data?.message || '完成失败，请重试')
    return
  }

  setTimeout(() => {
    navigateToNextTarget()
  }, 600)
}

function navigateToNextTarget() {
  const targets = getTargetsContext()

  if (!targets.length) {
    goBackToList()
    return
  }

  const next = targets.find(t => {
    if (String(t.id) === String(targetId.value)) return false
    return !(t.is_locked)
  })

  if (next) {
    router.replace({
      name: 'TakeExam',
      params: {
        id: examineId.value,
        targetId: next.id,
      },
      query: {
        targetName: next.target_name,
      },
    })
  } else {
    goBackToList()
  }
}

function goBackToList() {
  router.push({
    name: 'TargetList',
    params: { id: examineId.value },
  })
}

async function checkTargetLocked() {
  try {
    const info = JSON.parse(localStorage.getItem('userInfo') || '{}')
    const params = {}
    if (info.phone) params.phone = info.phone
    if (info.fingerprint) params.fingerprint = info.fingerprint

    const res = await request.get(`/answers/examine/${examineId.value}/targets`, { params })
    if (res.code === 200 && Array.isArray(res.data)) {
      const target = res.data.find(t => t.id === parseInt(targetId.value))
      isLocked.value = target?.is_locked === true
    }
  } catch (error) {
    console.error('检查锁定状态失败:', error)
  }
}

function handleBack() {
  showDialog({
    title: '提示',
    message: '确定要退出吗？将先保存当前答案后再退出。',
    showCancelButton: true,
  }).then(async () => {
    await saveCurrentAnswer()
    clearProgress()
    router.back()
  }).catch(() => {})
}

function handleGoHome() {
  showDialog({
    title: '提示',
    message: '确定要回到主页吗？将先保存当前答案。',
    showCancelButton: true,
  }).then(async () => {
    await saveCurrentAnswer()
    router.push('/home')
  }).catch(() => {})
}

watch(currentIndex, () => {
  initCurrentAnswer()
})

onBeforeRouteLeave((to, from, next) => {
  saveCurrentAnswer().finally(() => {
    next()
  })
})
</script>

<style scoped>
.take-exam-page {
  min-height: 100vh;
  background: linear-gradient(180deg, #F5F7FA 0%, #FFFFFF 100%);
  display: flex;
  flex-direction: column;
}

.home-btn {
  color: #1B5E9B;
  cursor: pointer;
}

.progress-bar-wrapper {
  background: #fff;
  padding: 12px 16px;
  box-shadow: 0 2px 8px rgba(27, 94, 155, 0.08);
}

.progress-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;
}

.progress-label {
  font-size: 12px;
  color: var(--color-text-muted);
}

.progress-info {
  font-size: 14px;
  color: var(--color-text-primary);
  font-weight: 600;
}

.question-card {
  flex: 1;
  margin: 14px;
  background: #FFFFFF;
  border-radius: 16px;
  padding: 20px 18px;
  box-shadow: 0 4px 16px rgba(27, 94, 155, 0.10);
  display: flex;
  flex-direction: column;
}

.question-header {
  margin-bottom: 20px;

  h3 {
    font-size: 17px;
    color: var(--color-text-primary);
    line-height: 1.7;
    margin: 8px 0 0 0;
    padding-left: 12px;
    border-left: 4px solid #1B5E9B;
    font-weight: 600;
  }
}

.options-list {
  flex: 1;

  .option-content {
    padding: 11px 0;
  }
}

.constraint-hint {
  text-align: center;
  padding: 14px;
  font-size: 14px;
  color: var(--color-text-secondary);

  .constraint-text {
    color: #2E8B57;

    &.error {
      color: #C23B22;
    }
  }
}

.example-input {
  margin-top: 20px;
  padding: 15px;
  background: rgba(232, 184, 74, 0.06);
  border-radius: 12px;
  border: 1px solid rgba(212, 160, 23, 0.3);

  .example-label {
    font-size: 14px;
    color: #A07800;
    margin-bottom: 10px;
    font-weight: 600;
  }
}

.textarea-wrapper {
  flex: 1;
}

.action-buttons {
  display: flex;
  gap: 12px;
  margin-top: 30px;
  padding-top: 20px;
  border-top: 1px solid var(--color-border-light);

  .van-button {
    flex: 1;
    border-radius: 10px !important;
    font-weight: 500;
  }

  .van-button--primary {
    --van-button-primary-background: #1B5E9B;
    --van-button-primary-border-color: #1B5E9B;
  }
}

.reverse-hint {
  text-align: center;
  font-size: 13px;
  color: #D4A017;
  margin-top: 10px;
  font-weight: 500;
}

.locked-notice {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  margin-top: 30px;
  padding: 24px;
  background: rgba(212, 160, 23, 0.05);
  border-radius: 16px;
  color: #D4A017;
  font-size: 14px;

  .van-icon {
    font-size: 32px;
    margin-bottom: 4px;
  }
}
</style>