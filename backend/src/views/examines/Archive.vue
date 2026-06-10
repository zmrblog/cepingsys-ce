<template>
  <div class="archive-page">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>测评归档</span>
        </div>
      </template>

      <div v-loading="loading">
        <el-empty v-if="periods.length === 0" description="暂无测评任务数据" />

        <div v-for="group in periods" :key="group.period || 'unclassified'" class="archive-group">
          <div class="group-header">
            <div class="group-title">
              <h3>{{ group.label }}</h3>
              <el-tag v-if="group.type === 'year'" type="primary" size="small">年度考核</el-tag>
              <el-tag v-else-if="group.type === 'quarter'" type="success" size="small">季度考核</el-tag>
              <el-tag v-else type="warning" size="small">未分类</el-tag>
            </div>
            <div class="group-stats">
              <span>总任务：<b>{{ group.total_tasks }}</b></span>
              <span class="stat-finished">已完成：<b>{{ group.finished_tasks }}</b></span>
              <span class="stat-archived">已归档：<b>{{ group.archived_tasks }}</b></span>
              <span v-if="group.pending_tasks > 0" class="stat-pending">进行中：<b>{{ group.pending_tasks }}</b></span>
            </div>
          </div>

          <div class="group-status">
            <el-alert
              v-if="group.can_archive"
              title="该周期下所有任务已完成，可以进行归档"
              type="success"
              :closable="false"
              show-icon
            />
            <el-alert
              v-else-if="group.type === 'unclassified' && group.total_tasks > 0"
              title="未分类任务，建议先设定考核周期后再归档"
              type="warning"
              :closable="false"
              show-icon
            />
            <el-alert
              v-else-if="!group.all_completed && group.pending_tasks > 0"
              :title="`尚有 ${group.pending_tasks} 个任务未完成，不可归档`"
              type="warning"
              :closable="false"
              show-icon
            />
            <el-alert
              v-else-if="group.total_tasks === 0"
              title="该周期下暂无任务"
              type="info"
              :closable="false"
              show-icon
            />
            <el-alert
              v-else-if="group.finished_tasks === 0 && group.archived_tasks > 0"
              title="该周期下所有任务已归档"
              type="info"
              :closable="false"
              show-icon
            />
          </div>

          <div class="group-actions" v-if="group.total_tasks > 0">
            <el-button
              v-if="group.can_archive"
              type="primary"
              :icon="Select"
              @click="handleBatchArchive(group)"
            >
              一键归档（{{ group.finished_tasks }} 个任务）
            </el-button>
            <el-button
              v-if="group.archived_tasks > 0"
              type="warning"
              plain
              @click="handleBatchUnarchive(group)"
            >
              全部取消归档
            </el-button>
            <el-button
              v-if="group.type === 'unclassified' && group.total_tasks > 0"
              type="primary"
              plain
              @click="openSetPeriodDialog(group)"
            >
              设定考核周期
            </el-button>
          </div>

          <el-table
            v-if="group.tasks.length > 0"
            :data="group.tasks"
            stripe
            border
            size="small"
            @selection-change="(rows) => onSelectionChange(group.period, rows)"
          >
            <el-table-column type="selection" width="45" />
            <el-table-column prop="id" label="ID" width="60" />
            <el-table-column prop="examine_name" label="任务名称" min-width="180" />
            <el-table-column label="类型" width="100">
              <template #default="{ row }">
                {{ row.template_type === 'leader' ? '干部测评' : '班子测评' }}
              </template>
            </el-table-column>
            <el-table-column prop="unit_name" label="所属单位" width="150" />
            <el-table-column label="开展时间" width="220">
              <template #default="{ row }">
                {{ row.start_time }} ~ {{ row.end_time }}
              </template>
            </el-table-column>
            <el-table-column label="完成进度" width="140">
              <template #default="{ row }">
                <span>{{ row.completed_users }}/{{ row.total_users }}</span>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="90">
              <template #default="{ row }">
                <el-tag v-if="row.status === 'finished'" type="success" size="small">已完成</el-tag>
                <el-tag v-else-if="row.status === 'archived'" type="info" size="small">已归档</el-tag>
                <el-tag v-else-if="row.status === 'active'" size="small">进行中</el-tag>
                <el-tag v-else type="warning" size="small">草稿</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="120" fixed="right">
              <template #default="{ row }">
                <el-button
                  v-if="row.status === 'finished'"
                  type="primary"
                  link
                  size="small"
                  @click="handleArchive(row)"
                >
                  归档
                </el-button>
                <el-button
                  v-if="row.status === 'archived'"
                  type="warning"
                  link
                  size="small"
                  @click="handleUnarchive(row)"
                >
                  取消归档
                </el-button>
              </template>
            </el-table-column>
          </el-table>
        </div>
      </div>
    </el-card>

    <el-dialog v-model="setPeriodDialogVisible" title="设定考核周期" width="450px" destroy-on-close>
      <el-form :model="setPeriodForm" label-width="100px">
        <el-form-item label="当前未分类任务">
          <span>{{ setPeriodForm.taskCount }} 个任务</span>
        </el-form-item>
        <el-form-item label="考核周期">
          <div class="period-combo">
            <el-input-number v-model="setPeriodForm.periodYear" :min="2000" :max="2099" style="width: 120px" placeholder="年份" />
            <el-select v-model="setPeriodForm.periodType" placeholder="周期类型" style="width: 140px; margin-left: 8px">
              <el-option label="年度" value="year" />
              <el-option v-for="(label, idx) in quarterLabels" :key="idx" :label="label" :value="label" />
            </el-select>
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="setPeriodDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="settingPeriod" @click="handleSetPeriod">
          确认设定
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Select } from '@element-plus/icons-vue'
import dayjs from 'dayjs'
import request from '@/api/request'

const loading = ref(false)
const periods = ref([])
const selectedRows = reactive({})

const setPeriodDialogVisible = ref(false)
const settingPeriod = ref(false)
const setPeriodForm = reactive({
  periodYear: null,
  periodType: '',
  taskIds: [],
  taskCount: 0,
})

const quarterLabels = ['第一季度', '第二季度', '第三季度', '第四季度']

const onSelectionChange = (periodKey, rows) => {
  selectedRows[periodKey] = rows
}

const fetchOverview = async () => {
  loading.value = true
  try {
    const res = await request.get('/examines/archive-overview')
    periods.value = res.data.periods || []
  } catch (error) {
    console.error('fetchOverview error:', error)
    ElMessage.error('加载归档数据失败')
  } finally {
    loading.value = false
  }
}

const handleArchive = async (row) => {
  try {
    await ElMessageBox.confirm(`确认归档任务「${row.examine_name}」？`, '确认归档', {
      confirmButtonText: '确认归档',
      cancelButtonText: '取消',
      type: 'info',
    })
  } catch {
    return
  }

  try {
    await request.post(`/examines/${row.id}/archive`)
    ElMessage.success('归档成功')
    await fetchOverview()
  } catch (error) {
    ElMessage.error(error.response?.data?.message || '归档失败')
  }
}

const handleUnarchive = async (row) => {
  try {
    await ElMessageBox.confirm(`确认取消归档任务「${row.examine_name}」？`, '取消归档', {
      confirmButtonText: '确认取消归档',
      cancelButtonText: '取消',
      type: 'warning',
    })
  } catch {
    return
  }

  try {
    await request.post(`/examines/${row.id}/unarchive`)
    ElMessage.success('已取消归档')
    await fetchOverview()
  } catch (error) {
    ElMessage.error(error.response?.data?.message || '取消归档失败')
  }
}

const handleBatchArchive = async (group) => {
  const finishedTasks = group.tasks.filter((t) => t.status === 'finished')
  if (finishedTasks.length === 0) {
    ElMessage.warning('没有可以归档的任务')
    return
  }

  try {
    await ElMessageBox.confirm(
      `确认一键归档「${group.label}」下的 ${finishedTasks.length} 个已完成任务？`,
      '批量归档',
      {
        confirmButtonText: '确认归档',
        cancelButtonText: '取消',
        type: 'info',
      }
    )
  } catch {
    return
  }

  try {
    const res = await request.post('/examines/batch-archive', {
      period: group.period,
    })
    ElMessage.success(res.data?.message || '批量归档完成')
    await fetchOverview()
  } catch (error) {
    ElMessage.error(error.response?.data?.message || '批量归档失败')
  }
}

const handleBatchUnarchive = async (group) => {
  const ids = group.tasks.filter((t) => t.status === 'archived').map((t) => t.id)
  if (ids.length === 0) return

  try {
    await ElMessageBox.confirm(
      `确认取消归档「${group.label}」下的所有 ${ids.length} 个已归档任务？`,
      '批量取消归档',
      {
        confirmButtonText: '确认取消归档',
        cancelButtonText: '取消',
        type: 'warning',
      }
    )
  } catch {
    return
  }

  const failCount = ref(0)
  for (const id of ids) {
    try {
      await request.post(`/examines/${id}/unarchive`)
    } catch {
      failCount.value++
    }
  }

  if (failCount.value > 0) {
    ElMessage.warning(`取消归档完成，${ids.length - failCount.value} 个成功，${failCount.value} 个失败`)
  } else {
    ElMessage.success(`已全部取消归档（${ids.length} 个）`)
  }
  await fetchOverview()
}

const openSetPeriodDialog = (group) => {
  setPeriodForm.taskIds = group.tasks.map((t) => t.id)
  setPeriodForm.taskCount = group.tasks.length
  const now = dayjs()
  setPeriodForm.periodYear = now.year()
  setPeriodForm.periodType = now.month() === 0 ? 'year' : quarterLabels[Math.floor(now.month() / 3)]
  setPeriodDialogVisible.value = true
}

const handleSetPeriod = async () => {
  if (!setPeriodForm.periodYear || !setPeriodForm.periodType) {
    ElMessage.warning('请选择完整的考核周期')
    return
  }

  const period = setPeriodForm.periodType === 'year'
    ? `${setPeriodForm.periodYear}年度`
    : `${setPeriodForm.periodYear}年${setPeriodForm.periodType}`

  settingPeriod.value = true
  const updates = setPeriodForm.taskIds.map((id) =>
    request.put(`/examines/${id}`, { period })
  )

  try {
    await Promise.all(updates)
    ElMessage.success(`已为 ${setPeriodForm.taskCount} 个任务设定考核周期：${period}`)
    setPeriodDialogVisible.value = false
    await fetchOverview()
  } catch (error) {
    ElMessage.error('设定考核周期失败')
  } finally {
    settingPeriod.value = false
  }
}

onMounted(() => {
  fetchOverview()
})
</script>

<style scoped>
.archive-page {
  padding: 0;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.archive-group {
  margin-bottom: 24px;
  padding: 16px;
  border: 1px solid #D8DFE8;
  border-radius: 8px;
  background: #fff;
}

.archive-group:last-child {
  margin-bottom: 0;
}

.group-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.group-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.group-title h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
}

.group-stats {
  display: flex;
  gap: 20px;
  font-size: 14px;
  color: #606266;
}

.group-stats b {
  color: #303133;
}

.stat-finished {
  color: #67c23a;
}

.stat-archived {
  color: #788494;
}

.stat-pending {
  color: #e6a23c;
}

.group-status {
  margin-bottom: 12px;
}

.group-actions {
  margin-bottom: 12px;
  display: flex;
  gap: 8px;
}
</style>