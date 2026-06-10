<template>
  <div class="analysis-page">
    <div class="task-panel">
      <el-input v-model="searchText" placeholder="搜索测评任务..." clearable :prefix-icon="Search" class="search-input" />

      <div class="select-all-bar">
        <el-checkbox
          v-model="checkAll"
          :indeterminate="isIndeterminate"
          class="select-all-checkbox"
        >
          全选
        </el-checkbox>
        <span v-if="selectedTaskIds.length" class="selected-count">
          已选 {{ selectedTaskIds.length }} 项
        </span>
      </div>

      <div class="task-list">
        <div
          v-for="task in filteredTasks"
          :key="task.id"
          class="task-card-wrapper"
        >
          <el-checkbox
            v-model="selectedTaskIds"
            :label="task.id"
            class="task-checkbox"
          />
          <div
            :class="['task-card', { active: currentTask?.id === task.id }]"
            @click="selectTask(task)"
          >
            <div class="task-name">{{ task.examine_name }}</div>
            <div class="task-time">{{ task.start_time }} 至 {{ task.end_time }}</div>
          </div>
        </div>
      </div>
    </div>

    <div class="main-content">
      <template v-if="currentTask">
        <div class="toolbar">
          <el-button type="primary" :icon="Download" @click="handleExport">
            导出
          </el-button>
          <el-button
            type="primary"
            :disabled="!hasSelectedTasks"
            :icon="Select"
            @click="handleBatchExport"
          >
            导出选中 ({{ selectedTaskIds.length }})
          </el-button>
          <span class="progress-text">
            当前测评完成进度为 <strong>{{ voteData.progress?.completion_rate ?? 0 }}%</strong>
          </span>
        </div>

        <el-tabs v-model="activeTab" class="data-tabs">
          <el-tab-pane label="得票汇总" name="vote">
            <div class="table-wrapper" ref="voteTableRef">
              <el-table :data="voteData.targets || []" border stripe size="small">
                <el-table-column prop="target_name" label="姓名" width="100" fixed />
                <el-table-column prop="returned_votes" label="收回票数" width="90" align="center" fixed />
                <el-table-column prop="valid_votes" label="有效票数" width="90" align="center" fixed />

                <template v-for="(item, itemIdx) in allItems" :key="'item-' + itemIdx">
                  <el-table-column :label="item.item_title" align="center">
                    <template v-for="(opt, optIdx) in item.options" :key="'opt-' + itemIdx + '-' + optIdx">
                      <el-table-column :label="opt.option" align="center">
                        <el-table-column label="A" align="center" width="60">
                          <template #default="{ row }">
                            {{ getOptionACount(row, itemIdx, optIdx) }}
                          </template>
                        </el-table-column>
                        <el-table-column label="B" align="center" width="60">
                          <template #default="{ row }">
                            {{ getOptionBCount(row, itemIdx, optIdx) }}
                          </template>
                        </el-table-column>
                      </el-table-column>
                    </template>
                  </el-table-column>
                </template>
              </el-table>
            </div>
          </el-tab-pane>

          <el-tab-pane label="得分汇总" name="score">
            <div class="table-wrapper">
              <el-table :data="scoreData.targets || []" border stripe size="small">
                <el-table-column prop="target_name" label="姓名" width="120" />

                <el-table-column
                  v-for="(item, idx) in scoreItemTitles"
                  :key="'s-item-' + idx"
                  :label="item"
                  align="center"
                  min-width="120"
                >
                  <template #default="{ row }">
                    {{ getScoreValue(row, idx) }}
                  </template>
                </el-table-column>

                <el-table-column prop="total_score" label="综合得分" width="100" align="center" sortable>
                  <template #default="{ row }">
                    <strong>{{ row.total_score }}</strong>
                  </template>
                </el-table-column>

                <el-table-column prop="rank" label="排名" width="70" align="center" sortable />
              </el-table>
            </div>
          </el-tab-pane>
        </el-tabs>
      </template>

      <div v-else class="empty-hint">
        <el-empty description="请从左侧选择一个测评任务" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { ElMessage } from 'element-plus'
import { Search, Download, Select } from '@element-plus/icons-vue'
import request from '@/api/request'

const searchText = ref('')
const tasks = ref([])
const currentTask = ref(null)
const selectedTaskIds = ref([])
const activeTab = ref('vote')
const voteData = ref({})
const scoreData = ref({})
const loading = ref(false)
const voteTableRef = ref(null)

const filteredTasks = computed(() => {
  if (!searchText.value) return tasks.value
  const kw = searchText.value.toLowerCase()
  return tasks.value.filter(
    (t) =>
      t.examine_name?.toLowerCase().includes(kw) ||
      t.unit_name?.toLowerCase().includes(kw)
  )
})

const hasSelectedTasks = computed(() => selectedTaskIds.value.length > 0)

const allTaskIds = computed(() => filteredTasks.value.map(t => t.id))

const checkAll = computed({
  get: () => filteredTasks.value.length > 0 && allTaskIds.value.every(id => selectedTaskIds.value.includes(id)),
  set: (val) => {
    selectedTaskIds.value = val ? [...allTaskIds.value] : []
  }
})

const isIndeterminate = computed(() => {
  const len = selectedTaskIds.value.length
  return len > 0 && len < filteredTasks.value.length
})

const allItems = computed(() => {
  const targets = voteData.value.targets
  return targets?.[0]?.items || []
})

const scoreItemTitles = computed(() => {
  const targets = scoreData.value.targets
  return targets?.[0]?.item_scores?.map((i) => i.item_title) || []
})

onMounted(fetchTasks)

function fixTableScrollbars() {
  nextTick(() => {
    const wrappers = document.querySelectorAll('.table-wrapper .el-table__body-wrapper')
    wrappers.forEach(el => {
      el.style.overflowY = 'scroll'
      el.style.scrollbarWidth = 'thin'
      el.style.scrollbarColor = '#a0aab8 #f5f7fa'
    })
  })
}

watch(voteData, () => { fixTableScrollbars() }, { deep: true })
watch(scoreData, () => { fixTableScrollbars() }, { deep: true })

async function fetchTasks() {
  try {
    const res = await request.get('/examines', {
      params: { page: 1, per_page: 200 },
    })
    tasks.value = res.data?.data || []
    if (tasks.value.length > 0) {
      selectTask(tasks.value[0])
    }
  } catch (e) {
    console.error(e)
  }
}

async function selectTask(task) {
  currentTask.value = task
  loading.value = true
  try {
    const [vRes, sRes] = await Promise.all([
      request.get(`/statistics/examine/${task.id}/vote-summary`),
      request.get(`/statistics/examine/${task.id}/score-summary`),
    ])
    voteData.value = vRes.data || {}
    scoreData.value = sRes.data || {}
  } catch (e) {
    console.error(e)
    ElMessage.error('获取数据失败')
  } finally {
    loading.value = false
  }
}

function getTargetItemValue(row, itemIdx, field) {
  return row.items?.[itemIdx]?.[field] || ''
}

function getTargetOptionCount(row, itemIdx, optIdx) {
  const opt = row.items?.[itemIdx]?.options?.[optIdx]
  if (!opt) return ''
  return opt.a_count + opt.b_count
}

function getOptionACount(row, itemIdx, optIdx) {
  const opt = row.items?.[itemIdx]?.options?.[optIdx]
  return opt?.a_count ?? ''
}

function getOptionBCount(row, itemIdx, optIdx) {
  const opt = row.items?.[itemIdx]?.options?.[optIdx]
  return opt?.b_count ?? ''
}

function getScoreValue(row, idx) {
  return row.item_scores?.[idx]?.combined_score ?? '-'
}

async function handleExport() {
    if (!currentTask.value) return

    try {
      let url = ''
      let name = ''

      if (activeTab.value === 'vote') {
        url = `/statistics/examine/${currentTask.value.id}/by-unit/export`
        name = `得票汇总_${currentTask.value.examine_name}`
      } else {
        url = `/statistics/examine/${currentTask.value.id}/export`
        name = `得分汇总_${currentTask.value.examine_name}`
      }

      const response = await request.get(url, { responseType: 'blob' })
      const blob = response instanceof Blob ? response : new Blob([response])
      const link = document.createElement('a')
      link.href = URL.createObjectURL(blob)
      link.download = `${name}_${Date.now()}.xlsx`
      link.click()
      URL.revokeObjectURL(link.href)
      ElMessage.success('导出成功')
    } catch (e) {
      ElMessage.error('导出失败')
    }
  }

  async function handleBatchExport() {
    if (!hasSelectedTasks.value) return

    try {
      const res = await request.post('/statistics/batch-export', {
        task_ids: selectedTaskIds.value,
        type: activeTab.value
      }, { responseType: 'blob' })

      const blob = res instanceof Blob ? res : new Blob([res])
      const dateStr = new Date().toISOString().slice(0, 10)
      const link = document.createElement('a')
      link.href = URL.createObjectURL(blob)
      link.download = `批量导出_${dateStr}.zip`
      link.click()
      URL.revokeObjectURL(link.href)
      ElMessage.success('批量导出成功')
    } catch (e) {
      ElMessage.error('批量导出失败')
    }
  }
</script>

<style scoped>
.analysis-page {
  display: flex;
  height: calc(100vh - 40px);
  gap: 16px;
  padding: 20px;
  background: #f5f7fa;
}

.task-panel {
  width: 280px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  background: #fff;
  border-radius: 8px;
  padding: 16px;
  border: 1px solid #c0c4cc;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
}

.search-input {
  margin-bottom: 4px;
}

.select-all-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 2px;
  border-bottom: 1px solid #DCDFE6;
  margin-bottom: 8px;
}

.select-all-checkbox {
  font-size: 13px;
}

.select-all-checkbox :deep(.el-checkbox__inner) {
  border-color: #409eff;
  border-width: 1.5px;
}

.task-checkbox :deep(.el-checkbox__inner) {
  border-color: #409eff;
  border-width: 1.5px;
}

.selected-count {
  font-size: 12px;
  color: #909399;
}

.task-list {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 0;
}

.task-card-wrapper {
  display: flex;
  align-items: flex-start;
  gap: 4px;
  margin-bottom: 6px;
}

.task-checkbox {
  margin-top: 12px;
}

.task-checkbox :deep(.el-checkbox__label) {
  display: none;
}

.task-card {
  flex: 1;
  padding: 12px 14px;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s;
  border: 1px solid #D8DFE8;
  background: #fff;
}

.task-card:hover {
  border-color: #c6e2ff;
  background: #f0f9ff;
}

.task-card.active {
  background: #409eff;
  color: #fff;
  border-color: #409eff;
}

.task-name {
  font-size: 13px;
  font-weight: 500;
  line-height: 1.4;
  margin-bottom: 4px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.task-time {
  font-size: 11px;
  color: #8A96A6;
}

.main-content {
  flex: 1;
  background: #fff;
  border-radius: 8px;
  padding: 20px;
  border: 1px solid #c0c4cc;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.toolbar {
  display: flex;
  align-items: center;
  gap: 20px;
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid #DCDFE6;
}

.progress-text {
  font-size: 15px;
  color: #606266;
}

.progress-text strong {
  color: #f56c6c;
  font-size: 18px;
}

.data-tabs {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.table-wrapper {
  flex: 1;
  overflow-y: auto;
  overflow-x: auto;
  --el-table-border-color: #000000;
  --el-table-header-border-color: #000000;
  --el-table-row-border-color: #000000;
  border: 1.5px solid #000000;
}

.table-wrapper :deep(.el-table) {
  min-width: 100%;
  border: 1.5px solid #000000 !important;
  border-radius: 0 !important;
}

.table-wrapper :deep(.el-table .el-table__header th) {
  border-radius: 0 !important;
}

.table-wrapper :deep(.el-table th.el-table__cell) {
  background: #EFF3F8 !important;
  border-right: 1.5px solid #000000 !important;
  border-bottom: 1.5px solid #000000 !important;
}

.table-wrapper :deep(.el-table td.el-table__cell) {
  border-right: 1.5px solid #000000 !important;
  border-bottom: 1.5px solid #000000 !important;
}

.table-wrapper :deep(.el-table th.el-table__cell.is-last),
.table-wrapper :deep(.el-table td.el-table__cell.is-last) {
  border-right: none !important;
}

.table-wrapper :deep(.el-table--striped .el-table__row--striped td.el-table__cell) {
  background: #F0F3F7 !important;
}

.table-wrapper :deep(.el-table__body .el-table__row:hover > td.el-table__cell) {
  background: #E8F0FE !important;
}

.empty-hint {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>
