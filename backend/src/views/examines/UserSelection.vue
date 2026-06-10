<template>
  <div class="user-selection">
    <el-page-header @back="$router.push('/examines')" content="分配参评人员" />
    
    <el-card class="mt-4">
      <template #header>
        <div class="card-header">
          <span>测评任务：{{ examineName }}</span>
          <el-tag v-if="!saved" type="warning">待分配</el-tag>
          <el-tag v-else type="success">已分配</el-tag>
        </div>
      </template>

      <div class="mb-3">
        <span class="label">所属部门：</span>
        <strong>{{ currentUnitName }}</strong>
        <el-button type="primary" link class="ml-2" @click="changeUnit" v-if="units.length">
          切换部门
        </el-button>
      </div>

      <el-select
        v-if="showUnitSelect"
        v-model="selectedUnitId"
        placeholder="选择部门查看用户"
        @change="onUnitChange"
        class="mb-3"
        style="width: 300px"
        filterable
      >
        <el-option v-for="u in units" :key="u.id" :label="u.unit_name" :value="u.id" />
      </el-select>
      
      <el-alert
        v-if="alertMessage"
        :title="alertMessage"
        :type="alertType"
        show-icon
        class="mb-3"
        closable
        @close="alertMessage = ''"
      />
      
      <el-table
        :data="users"
        style="width: 100%"
        v-loading="loading"
        @selection-change="onSelectionChange"
        ref="tableRef"
        max-height="500"
        border
        stripe
      >
        <el-table-column type="selection" width="55" />
        <el-table-column prop="name" label="姓名" width="120" />
        <el-table-column prop="position" label="职务" width="180" />
        <el-table-column prop="phone" label="手机号" width="160" />
        <el-table-column prop="user_type" label="用户类型" width="100">
          <template #default="{ row }">
            <el-tag :type="row.user_type === 'A' ? '' : 'warning'" size="small">
              {{ row.user_type === 'A' ? 'A类' : 'B类' }}
            </el-tag>
          </template>
        </el-table-column>
      </el-table>

      <div class="mt-3 flex-between">
        <div>
          <el-button type="primary" @click="selectAll" :disabled="!users.length">全选</el-button>
          <el-button @click="clearSelection" :disabled="!selectedIds.length">取消全选</el-button>
        </div>
        <div class="text-muted">
          已选择 <strong>{{ selectedIds.length }}</strong> / {{ users.length }} 人
        </div>
      </div>

      <div class="mt-4">
        <el-button type="primary" @click="handleSave" :loading="saving" :disabled="!selectedIds.length">
          保存分配
        </el-button>
        <el-button @click="$router.push('/examines')">返回列表</el-button>
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import request from '@/api/request'

const route = useRoute()
const router = useRouter()
const tableRef = ref()
const loading = ref(false)
const saving = ref(false)
const saved = ref(false)

const examineName = ref('')
const currentUnitName = ref('')
const showUnitSelect = ref(false)
const selectedUnitId = ref(null)
const units = ref([])
const users = ref([])
const selectedIds = ref([])

const alertMessage = ref('')
const alertType = ref('success')

const examineId = route.params.id

const fetchExamine = async () => {
  try {
    const res = await request.get(`/examines/${examineId}`)
    const data = res.data.data || res.data
    examineName.value = data.examine_name
    currentUnitName.value = data.unit_name || ''
    selectedUnitId.value = data.unit_id
  } catch (error) {
    console.error(error)
    ElMessage.error('获取任务信息失败')
  }
}

const fetchUnits = async () => {
  try {
    const res = await request.get('/units', { params: { per_page: 999 } })
    units.value = res.data.data?.data || res.data.data || []
  } catch (error) {
    console.error(error)
  }
}

const fetchUsers = async () => {
  loading.value = true
  try {
    const res = await request.get(`/examines/${examineId}/available-users`)
    const data = res.data.data || res.data
    users.value = data.users || []
    if (data.unit) {
      currentUnitName.value = data.unit.unit_name
      selectedUnitId.value = data.unit.id
    }
    showUnitSelect.value = false

    const preSelected = users.value.filter(u => u.selected).map(u => u.id)
    selectedIds.value = preSelected
    if (preSelected.length) {
      saved.value = true
    }
  } catch (error) {
    console.error(error)
    ElMessage.error('获取用户列表失败')
  } finally {
    loading.value = false
  }
}

const onSelectionChange = (selection) => {
  selectedIds.value = selection.map(s => s.id)
}

const selectAll = () => {
  tableRef.value?.toggleAllSelection()
}

const clearSelection = () => {
  tableRef.value?.clearSelection()
}

const onUnitChange = (unitId) => {
  selectedUnitId.value = unitId
  showUnitSelect.value = false

  loading.value = true
  request.get('/users', { params: { unit_id: unitId, per_page: 999 } })
    .then(res => {
      const raw = res.data.data?.data || res.data.data || []
      users.value = raw.map(u => ({
        id: u.id,
        name: u.name,
        phone: u.phone,
        position: u.position,
        user_type: u.user_type,
      }))
      selectedIds.value = []
      saved.value = false
    })
    .catch(() => ElMessage.error('获取用户列表失败'))
    .finally(() => loading.value = false)

  const unit = units.value.find(u => u.id === unitId)
  if (unit) currentUnitName.value = unit.unit_name
}

const changeUnit = () => {
  showUnitSelect.value = !showUnitSelect.value
}

const handleSave = async () => {
  if (!selectedIds.value.length) {
    ElMessage.warning('请至少选择一位参评人员')
    return
  }

  saving.value = true
  try {
    await request.post(`/examines/${examineId}/users`, {
      user_ids: selectedIds.value
    })
    ElMessage.success(`已分配 ${selectedIds.value.length} 位参评人员`)
    saved.value = true
    alertMessage.value = `成功分配 ${selectedIds.value.length} 位参评人员`
    alertType.value = 'success'
  } catch (error) {
    console.error(error)
    ElMessage.error(error.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchExamine()
  fetchUnits()
  fetchUsers()
})
</script>

<style scoped>
.user-selection {
  padding: 20px;
}
.mt-4 {
  margin-top: 16px;
}
.mt-3 {
  margin-top: 12px;
}
.mb-3 {
  margin-bottom: 12px;
}
.ml-2 {
  margin-left: 8px;
}
.flex-between {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.text-muted {
  color: #808894;
  font-size: 14px;
}
.text-muted strong {
  color: #409eff;
  font-size: 18px;
}
.label {
  color: #606266;
}
.card-header {
  display: flex;
  align-items: center;
  gap: 12px;
}
</style>
