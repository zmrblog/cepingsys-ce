<template>
  <div class="registered-users-page">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>注册用户管理</span>
          <div class="header-actions">
            <el-button type="danger" size="small" @click="batchDisableClick" :disabled="selectedIds.length === 0" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
              批量禁用（{{ selectedIds.length }}）</el-button>
            <el-button type="success" size="small" @click="batchEnableClick" :disabled="selectedIds.length === 0" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
              批量启用（{{ selectedIds.length }}）</el-button>
            <el-button type="danger" size="small" plain @click="batchForceDeleteClick" :disabled="selectedIds.length === 0" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
              批量删除（{{ selectedIds.length }}）</el-button>
          </div>
        </div>
      </template>

      <div class="search-bar">
        <el-input
          v-model="keyword"
          placeholder="请输入姓名/手机号"
          style="width: 250px; margin-right: 10px;"
          @keyup.enter="fetchData"
        >
          <template #prefix>
            <el-icon><Search /></el-icon>
          </template>
        </el-input>

        <el-select v-model="statusFilter" placeholder="状态筛选" style="width: 150px;">
          <el-option label="正常" :value="1" />
          <el-option label="禁用" :value="0" />
        </el-select>

        <el-button type="primary" @click="fetchData">搜索</el-button>
        <el-button @click="handleReset">重置</el-button>
      </div>

      <el-table ref="tableRef" :data="tableData" stripe v-loading="loading" @selection-change="onSelectionChange">
        <el-table-column type="selection" width="50" />
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="name" label="姓名" width="120" />
        <el-table-column prop="phone" label="手机号" width="130" />
        <el-table-column prop="position" label="职务" min-width="150" />
        <el-table-column prop="unit_name" label="单位" width="150" />
        <el-table-column label="密码" width="100">
          <template #default="{ row }">
            <el-tag :type="row.password_hash ? 'success' : 'warning'" size="small">
              {{ row.password_hash ? '已设置' : '未设置' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="安全问题" width="100">
          <template #default="{ row }">
            <el-tag :type="row.security_question ? 'success' : 'info'" size="small">
              {{ row.security_question ? '已设置' : '未设置' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'" size="small">
              {{ row.status === 1 ? '正常' : '禁用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="注册时间" width="170" />
        <el-table-column label="操作" width="260" fixed="right" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
          <template #default="{ row }">
            <el-button type="primary" link @click="handleView(row)">详情</el-button>
            <el-button type="primary" link @click="handleEdit(row)">编辑</el-button>
            <el-button :type="row.status === 1 ? 'warning' : 'success'" link @click="handleToggleStatus(row)">
              {{ row.status === 1 ? '禁用' : '启用' }}
            </el-button>
            <el-button type="danger" link @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-model:current-page="pagination.page"
        v-model:page-size="pagination.perPage"
        :total="pagination.total"
        :page-sizes="[10, 20, 50]"
        layout="total, sizes, prev, pager, next, jumper"
        style="margin-top: 20px; justify-content: flex-end;"
        @size-change="fetchData"
        @current-change="fetchData"
      />
    </el-card>

    <!-- 查看/编辑对话框 -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="600px"
      @close="resetForm"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px" :disabled="dialogMode === 'view'">
        <el-divider content-position="left">基本信息</el-divider>

        <el-form-item label="姓名" prop="name">
          <el-input v-model="form.name" placeholder="请输入姓名" />
        </el-form-item>
        <el-form-item label="手机号" prop="phone">
          <el-input v-model="form.phone" disabled />
        </el-form-item>
        <el-form-item label="职务" prop="position">
          <el-input v-model="form.position" placeholder="请输入职务" />
        </el-form-item>
        <el-form-item label="所属单位" prop="unit_id">
          <el-select v-model="form.unit_id" placeholder="请选择单位" style="width: 100%;" clearable>
            <el-option
              v-for="unit in units"
              :key="unit.id"
              :label="unit.unit_name"
              :value="unit.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="用户类型" prop="user_type">
          <el-radio-group v-model="form.user_type">
            <el-radio value="A">A类</el-radio>
            <el-radio value="B">B类</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-switch
            v-model="form.status"
            :active-value="1"
            :inactive-value="0"
            active-text="正常"
            inactive-text="禁用"
          />
        </el-form-item>

        <el-divider content-position="left">安全信息</el-divider>

        <div class="security-info">
          <div class="info-item" :class="{ active: form.has_password }">
            <el-icon><Lock /></el-icon>
            <span>登录密码：{{ form.has_password ? '已设置' : '未设置' }}</span>
          </div>
          <div class="info-item" :class="{ active: form.security_question }">
            <el-icon><QuestionFilled /></el-icon>
            <span>安全问题：{{ form.security_question || '未设置' }}</span>
          </div>
          <div class="info-item">
            <el-icon><Clock /></el-icon>
            <span>注册时间：{{ form.created_at || '-' }}</span>
          </div>
        </div>

        <!-- 密码重置区域（仅编辑模式显示） -->
        <template v-if="dialogMode === 'edit' && form.id">
          <el-divider content-position="left">密码管理</el-divider>
          <el-form-item label="重置密码">
            <div class="reset-password-area">
              <el-input
                v-model="newPassword"
                type="password"
                placeholder="请输入新密码"
                style="width: 100%; margin-bottom: 8px;"
              />
              <el-button
                type="warning"
                size="small"
                :loading="resetPwdLoading"
                :disabled="!newPassword || newPassword.length < 6"
                @click="handleResetPassword"
              >
                确认重置密码
              </el-button>
            </div>
          </el-form-item>
        </template>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">{{ dialogMode === 'view' ? '关闭' : '取消' }}</el-button>
        <el-button v-if="dialogMode === 'edit'" type="primary" :loading="submitLoading" @click="handleSubmit">保存修改</el-button>
      </template>
    </el-dialog>

  <UpgradeDialog ref="upgradeDialog" />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useUserStore } from '@/stores/user'
import request from '@/api/request'
import { useEditionStore } from '@/stores/edition'
import UpgradeDialog from '@/components/UpgradeDialog.vue'
import { ElMessage, ElMessageBox } from 'element-plus'

const userStore = useUserStore()
const editionStore = useEditionStore()
const upgradeDialog = ref(null)

const loading = ref(false)
const submitLoading = ref(false)
const resetPwdLoading = ref(false)
const tableData = ref([])
const units = ref([])
const keyword = ref('')
const statusFilter = ref(null)
const selectedIds = ref([])
const tableRef = ref(null)

const dialogVisible = ref(false)
const dialogTitle = ref('查看详情')
const dialogMode = ref('view')
const formRef = ref(null)
const newPassword = ref('')

const pagination = ref({
  page: 1,
  perPage: 20,
  total: 0,
})

const form = ref({
  id: null,
  name: '',
  phone: '',
  position: '',
  unit_id: '',
  user_type: 'A',
  status: 1,
  has_password: false,
  security_question: '',
  password_hash: '',
  created_at: '',
})

const rules = {
  name: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
}

onMounted(() => {
  fetchData()
  fetchUnits()
})

async function fetchData() {
  loading.value = true
  try {
    const params = {
      page: pagination.value.page,
      per_page: pagination.value.perPage,
      source: 'registered',
    }

    if (keyword.value) params.keyword = keyword.value
    if (statusFilter.value !== null) params.status = statusFilter.value

    const res = await request.get('/users', { params })
    tableData.value = res.data?.data || []
    pagination.value.total = res.data?.pagination?.total || 0
  } catch (error) {
    console.error('获取数据失败:', error)
  } finally {
    loading.value = false
  }
}

async function fetchUnits() {
  try {
    const res = await request.get('/units', { params: { per_page: 1000 } })
    units.value = res.data?.data || []
  } catch (error) {
    console.error('获取单位列表失败:', error)
  }
}

function handleReset() {
  keyword.value = ''
  statusFilter.value = null
  pagination.value.page = 1
  fetchData()
}

function handleView(row) {
  dialogMode.value = 'view'
  dialogTitle.value = '用户详情'
  loadUserData(row)
}

function handleEdit(row) {
  dialogMode.value = 'edit'
  dialogTitle.value = '编辑用户'
  loadUserData(row)
}

async function loadUserData(row) {
  try {
    const res = await request.get(`/users/${row.id}`)
    if (res.code === 200 && res.data) {
      const userData = res.data
      form.value = {
        id: userData.id,
        name: userData.name || '',
        phone: userData.phone || '',
        position: userData.position || '',
        unit_id: userData.unit_id || '',
        user_type: userData.user_type || 'A',
        status: userData.status ?? 1,
        has_password: !!userData.password_hash,
        security_question: userData.security_question || '',
        password_hash: userData.password_hash || '',
        created_at: userData.created_at || '',
      }
    }
  } catch (error) {
    console.error('获取用户详情失败:', error)
    form.value = {
      id: row.id,
      name: row.name || '',
      phone: row.phone || '',
      position: row.position || '',
      unit_id: row.unit_id || '',
      user_type: row.user_type || 'A',
      status: row.status ?? 1,
      has_password: !!row.password_hash,
      security_question: row.security_question || '',
      password_hash: row.password_hash || '',
      created_at: row.created_at || '',
    }
  }

  newPassword.value = ''
  dialogVisible.value = true
}

async function handleToggleStatus(row) {
  const action = row.status === 1 ? '禁用' : '启用'
  try {
    await ElMessageBox.confirm(
      `确定要${action}用户"${row.name}"的账户吗？${action === '禁用' ? '禁用后该用户将无法登录系统。' : ''}`,
      `${action}账户`,
      { confirmButtonText: `确定${action}`, cancelButtonText: '取消', type: 'warning' }
    )

    await request.put(`/users/${row.id}`, { status: row.status === 1 ? 0 : 1 })
    ElMessage.success(`已${action}用户"${row.name}"`)
    fetchData()
  } catch (error) {
    if (error !== 'cancel') console.error(`${action}失败:`, error)
  }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(
      `确定要删除注册用户"${row.name}"吗？删除后该用户将无法登录，且相关测评数据可能受影响。`,
      '删除确认',
      { confirmButtonText: '确定删除', cancelButtonText: '取消', type: 'warning' }
    )

    await request.delete(`/users/${row.id}`)
    ElMessage.success('删除成功')
    fetchData()
  } catch (error) {
    if (error !== 'cancel') console.error('删除失败:', error)
  }
}

function onSelectionChange(selection) {
  selectedIds.value = selection.map(s => s.id)
}

function batchDisableClick() {
  if (editionStore.isCommunity) { upgradeDialog.value?.open(); return }
  handleBatchDisable()
}

function batchEnableClick() {
  if (editionStore.isCommunity) { upgradeDialog.value?.open(); return }
  handleBatchEnable()
}

function batchForceDeleteClick() {
  if (editionStore.isCommunity) { upgradeDialog.value?.open(); return }
  handleBatchForceDelete()
}

async function handleBatchDisable() {
  if (selectedIds.value.length === 0) return
  await batchUpdateStatus(selectedIds.value, 0, '禁用')
}

async function handleBatchEnable() {
  if (selectedIds.value.length === 0) return
  await batchUpdateStatus(selectedIds.value, 1, '启用')
}

async function batchUpdateStatus(ids, status, actionLabel) {
  const names = tableData.value.filter(u => ids.includes(u.id)).map(u => u.name)
  try {
    await ElMessageBox.confirm(
      `确定要批量${actionLabel}以下 ${ids.length} 位用户吗？\n${names.join('、')}`,
      `批量${actionLabel}确认`,
      { confirmButtonText: `确定${actionLabel}`, cancelButtonText: '取消', type: 'warning' }
    )
  } catch {
    return
  }

  let successCount = 0
  let failCount = 0

  for (const id of ids) {
    try {
      await request.put(`/users/${id}`, { status })
      successCount++
    } catch (e) {
      failCount++
    }
  }

  if (successCount > 0) ElMessage.success(`成功${actionLabel} ${successCount} 位用户`)
  if (failCount > 0) ElMessage.warning(`${failCount} 位用户操作失败`)

  selectedIds.value = []
  fetchData()
}

async function handleBatchForceDelete() {
  if (selectedIds.value.length === 0) return

  const names = tableData.value.filter(u => selectedIds.value.includes(u.id)).map(u => u.name)
  try {
    await ElMessageBox.confirm(
      `确定要强制删除以下 ${selectedIds.value.length} 位用户吗？\n将同时删除其所有测评数据和答题记录！\n\n${names.join('、')}`,
      '批量强制删除确认',
      { confirmButtonText: '确定删除', cancelButtonText: '取消', type: 'error' }
    )
  } catch {
    return
  }

  try {
    const res = await request.post('/users/batch-force-delete', { ids: selectedIds.value })
    const d = res.data || {}
    if (d.success_count > 0) ElMessage.success(d.message || `成功删除 ${d.success_count} 位用户`)
    if (d.fail_count > 0) ElMessage.warning(`${d.fail_count} 位用户删除失败`)
    selectedIds.value = []
    fetchData()
  } catch (error) {
    console.error('批量删除失败:', error)
  }
}

async function handleSubmit() {
  if (!formRef.value) return

  try {
    await formRef.value.validate()
  } catch {
    return
  }

  submitLoading.value = true
  try {
    await request.put(`/users/${form.value.id}`, {
      name: form.value.name,
      position: form.value.position,
      unit_id: form.value.unit_id || null,
      user_type: form.value.user_type,
      status: form.value.status,
    })
    ElMessage.success('更新成功')
    dialogVisible.value = false
    fetchData()
  } catch (error) {
    console.error('提交失败:', error)
  } finally {
    submitLoading.value = false
  }
}

function resetForm() {
  formRef.value?.resetFields()
  newPassword.value = ''
}

async function handleResetPassword() {
  if (!newPassword.value || newPassword.value.length < 6) {
    ElMessage.warning('请输入至少 6 位的新密码')
    return
  }

  try {
    await ElMessageBox.confirm(
      `确定要将用户"${form.value.name}"的密码重置为：${newPassword.value}？`,
      '确认重置密码',
      { confirmButtonText: '确定重置', cancelButtonText: '取消', type: 'warning' }
    )

    resetPwdLoading.value = true
    const res = await request.put(`/users/${form.value.id}/reset-password`, {
      new_password: newPassword.value,
    })

    if (res.code === 200) {
      ElMessage.success(`密码已重置为：${res.data?.new_password || newPassword.value}`)
      form.value.has_password = true
      newPassword.value = ''
    }
  } catch (error) {
    if (error !== 'cancel') console.error('重置密码失败:', error)
  } finally {
    resetPwdLoading.value = false
  }
}
</script>

<style lang="scss" scoped>
.registered-users-page {
  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;

    .header-actions {
      display: flex;
      gap: 10px;
    }
  }

  .search-bar {
    display: flex;
    margin-bottom: 20px;
    gap: 10px;
    align-items: center;
  }

  .security-info {
    background: #F0F3F7;
    border-radius: 8px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;

    .info-item {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 14px;
      color: #808894;

      &.active {
        color: #67c23a;
      }

      .el-icon {
        font-size: 16px;
      }
    }
  }

  .reset-password-area {
    width: 100%;
  }
}

/* 蓝色复选框 */
:deep(.el-checkbox__inner) {
  border: 2px solid #409eff;
}
:deep(.el-checkbox__input.is-checked .el-checkbox__inner) {
  background-color: #409eff;
  border-color: #409eff;
}
</style>
