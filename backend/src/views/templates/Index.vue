<template>
  <div class="templates-page">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>模板管理</span>
          <el-button type="primary" @click="$router.push('/templates/create')" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
            <el-icon><Plus /></el-icon>
            创建模板
          </el-button>
        </div>
      </template>

      <div class="filter-bar">
        <el-radio-group v-model="filterType" @change="fetchData" size="small">
          <el-radio-button value="">全部</el-radio-button>
          <el-radio-button value="leader">干部测评</el-radio-button>
          <el-radio-button value="team">班子测评</el-radio-button>
        </el-radio-group>
      </div>

      <el-table :data="tableData" stripe v-loading="loading">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="template_name" label="模板名称" min-width="200" />
        <el-table-column prop="template_type" label="类型" width="120">
          <template #default="{ row }">
            <el-tag :type="row.template_type === 'leader' ? '' : 'success'">
              {{ row.template_type === 'leader' ? '干部测评' : '班子测评' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="creator_name" label="创建人" width="100" />
        <el-table-column prop="is_default" label="默认" width="80">
          <template #default="{ row }">
            <el-tag v-if="row.is_default" type="warning" size="small">是</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="250" fixed="right" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
          <template #default="{ row }">
            <el-button type="primary" link @click="$router.push(`/templates/${row.id}/edit`)">编辑</el-button>
            <el-button type="success" link @click="handleDuplicate(row)">复制</el-button>
            <el-button type="danger" link @click="handleDelete(row)">删除</el-button>
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
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useUserStore } from '@/stores/user'
import request from '@/api/request'
import { ElMessage, ElMessageBox } from 'element-plus'

const userStore = useUserStore()
const loading = ref(false)
const tableData = ref([])
const filterType = ref('')
const pagination = ref({ page: 1, perPage: 20, total: 0 })

onMounted(fetchData)

async function fetchData() {
  loading.value = true
  try {
    const params = { page: pagination.value.page, per_page: pagination.value.perPage }
    if (filterType.value) params.type = filterType.value
    
    const res = await request.get('/templates', { params })
    tableData.value = res.data?.data || []
    pagination.value.total = res.data?.pagination?.total || 0
  } catch (error) {
    console.error('获取数据失败:', error)
  } finally {
    loading.value = false
  }
}

async function handleDuplicate(row) {
  try {
    await ElMessageBox.confirm(`确定要复制模板"${row.template_name}"吗？`, '提示', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'info',
    })

    await request.post(`/templates/${row.id}/duplicate`)
    ElMessage.success('复制成功')
    fetchData()
  } catch (error) {
    if (error !== 'cancel') console.error('复制失败:', error)
  }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`确定要删除模板"${row.template_name}"吗？此操作不可恢复！`, '警告', {
      confirmButtonText: '确定删除',
      cancelButtonText: '取消',
      type: 'error',
    })

    await request.delete(`/templates/${row.id}`)
    ElMessage.success('删除成功')
    fetchData()
  } catch (error) {
    if (error !== 'cancel') console.error('删除失败:', error)
  }
}
</script>

<style scoped>
.templates-page .card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.templates-page .filter-bar {
  margin-bottom: 15px;
}
</style>