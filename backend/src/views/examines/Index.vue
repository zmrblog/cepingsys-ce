<template>
  <div class="examines-page">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>测评任务</span>
          <div class="header-actions">
            <el-button type="danger" plain @click="batchDeleteClick" :disabled="selectedRows.length === 0">
              批量删除（{{ selectedRows.length }}）</el-button>
            <el-button type="primary" @click="$router.push('/examines/create')" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
              <el-icon><Plus /></el-icon>
              创建任务
            </el-button>
          </div>
        </div>
      </template>

      <el-table :data="tableData" stripe v-loading="loading" @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="50" />
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="examine_name" label="任务名称" min-width="200" />
        <el-table-column prop="template_type" label="类型" width="100">
          <template #default="{ row }">
            {{ row.template_type === 'leader' ? '干部测评' : '班子测评' }}
          </template>
        </el-table-column>
        <el-table-column label="时间范围" width="220">
          <template #default="{ row }">
            {{ row.start_time }} ~ {{ row.end_time }}
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="getStatusType(row.status)">
              {{ getStatusText(row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="进度" width="200">
          <template #default="{ row }">
            <div class="progress-info">
              <span>{{ row.completed_count }}/{{ row.users_count }}</span>
              <el-progress
                :percentage="row.users_count > 0 ? Math.round((row.completed_count / row.users_count) * 100) : 0"
                :stroke-width="6"
                style="flex: 1; margin-left: 10px;"
              />
            </div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="250" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link @click="$router.push(`/examines/${row.id}/edit`)" v-if="row.status === 'draft'">编辑</el-button>
            <el-button type="primary" link @click="$router.push(`/examines/${row.id}/edit`)" v-if="row.status === 'active'">管理</el-button>
            <el-button type="success" link @click="handleActivate(row)" v-if="row.status === 'draft'">激活</el-button>
            <el-button type="warning" link @click="handleFinish(row)" v-if="row.status === 'active'">结束</el-button>
            <el-button type="danger" link @click="handleDelete(row)" v-if="['draft', 'finished'].includes(row.status)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-model:current-page="pagination.page"
        v-model:page-size="pagination.perPage"
        :total="pagination.total"
        layout="total, prev, pager, next"
        style="margin-top: 20px; justify-content: flex-end;"
        @current-change="fetchData"
      />
    </el-card>
    <UpgradeDialog ref="upgradeDialog" />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useUserStore } from '@/stores/user'
import { useEditionStore } from '@/stores/edition'
import UpgradeDialog from '@/components/UpgradeDialog.vue'
import request from '@/api/request'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'

const userStore = useUserStore()
const editionStore = useEditionStore()
const upgradeDialog = ref(null)
const loading = ref(false)
const tableData = ref([])
const selectedRows = ref([])
const pagination = ref({ page: 1, perPage: 20, total: 0 })

onMounted(fetchData)

async function fetchData() {
  loading.value = true
  try {
    const res = await request.get('/examines', {
      params: { page: pagination.value.page, per_page: pagination.value.perPage },
    })
    tableData.value = res.data?.data || []
    pagination.value.total = res.data?.pagination?.total || 0
  } catch (error) {
    console.error('获取数据失败:', error)
  } finally {
    loading.value = false
  }
}

function getStatusType(status) {
  return { draft: 'info', active: '', finished: 'success', archived: 'warning' }[status] || 'info'
}

function getStatusText(status) {
  return { draft: '草稿', active: '进行中', finished: '已结束', archived: '已归档' }[status] || status
}

async function handleActivate(row) {
  try {
    await ElMessageBox.confirm(`确定要激活任务"${row.examine_name}"吗？激活后用户可以开始答题。`, '提示', {
      confirmButtonText: '确定激活',
      cancelButtonText: '取消',
      type: 'info',
    })

    await request.post(`/examines/${row.id}/activate`)
    ElMessage.success('任务已激活')
    fetchData()
  } catch (error) {
    if (error !== 'cancel') console.error('激活失败:', error)
  }
}

async function handleFinish(row) {
  try {
    await ElMessageBox.confirm(`确定要结束任务"${row.examine_name}"吗？结束后将无法再答题。`, '警告', {
      confirmButtonText: '确定结束',
      cancelButtonText: '取消',
      type: 'warning',
    })

    await request.post(`/examines/${row.id}/finish`)
    ElMessage.success('任务已结束')
    fetchData()
  } catch (error) {
    if (error !== 'cancel') console.error('结束失败:', error)
  }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`确定要删除任务"${row.examine_name}"吗？此操作不可恢复！`, '警告', {
      confirmButtonText: '确定删除',
      cancelButtonText: '取消',
      type: 'error',
    })

    await request.delete(`/examines/${row.id}`)
    ElMessage.success('删除成功')
    selectedRows.value = []
    fetchData()
  } catch (error) {
    if (error !== 'cancel') console.error('删除失败:', error)
  }
}

function handleSelectionChange(rows) {
  selectedRows.value = rows
}

function batchDeleteClick() {
  if (editionStore.isCommunity) { upgradeDialog.value?.open(); return }
  handleBatchDelete()
}

async function handleBatchDelete() {
  const ids = selectedRows.value.map((r) => r.id)
  if (ids.length === 0) return

  const activeCount = selectedRows.value.filter((r) => r.status === 'active').length

  try {
    let message = `确定要删除选中的 ${ids.length} 个任务吗？此操作不可恢复。`
    if (activeCount > 0) {
      message += `\n\n注意：其中 ${activeCount} 个进行中的任务将被跳过，不会被删除。`
    }
    await ElMessageBox.confirm(message, '批量删除', {
      confirmButtonText: '确定删除',
      cancelButtonText: '取消',
      type: 'error',
    })

    const res = await request.post('/examines/batch-delete', { ids })
    const result = res.data || {}
    ElMessage.success(`删除完成：${result.deleted || 0} 个成功` + (result.skipped > 0 ? `，${result.skipped} 个跳过` : ''))
    selectedRows.value = []
    fetchData()
  } catch (error) {
    if (error !== 'cancel') console.error('批量删除失败:', error)
  }
}
</script>

<style scoped>
.examines-page .card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.progress-info {
  display: flex;
  align-items: center;
  font-size: 12px;
}
</style>
