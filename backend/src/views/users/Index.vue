<template>
  <div class="users-page">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>用户管理</span>
          <div class="header-actions">
            <el-button type="danger" @click="batchDeleteClick" :disabled="selectedIds.length === 0" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
              批量删除（{{ selectedIds.length }}）</el-button>
            <el-radio-group v-model="batchDeleteMode" size="small" v-if="userStore.isSuperAdmin && selectedIds.length > 0" style="margin-left: 8px;">
              <el-radio value="normal">常规</el-radio>
              <el-radio value="force" style="color: #F56C6C;">强制</el-radio>
            </el-radio-group>
            <el-button type="primary" @click="handleAdd" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
              <el-icon><Plus /></el-icon>
              新增用户
            </el-button>
          </div>
        </div>
      </template>

      <div class="search-bar">
        <el-input
          v-model="keyword"
          placeholder="姓名/手机号"
          style="width: 250px; margin-right: 10px;"
          @keyup.enter="fetchData"
        >
          <template #prefix>
            <el-icon><Search /></el-icon>
          </template>
        </el-input>

        <el-select v-model="unitId" placeholder="选择单位" clearable style="width: 200px;">
          <el-option
            v-for="unit in units"
            :key="unit.id"
            :label="unit.unit_name"
            :value="unit.id"
          />
        </el-select>

        <el-select v-model="sourceFilter" placeholder="用户来源" clearable style="width: 150px;">
          <el-option label="后台添加" value="admin" />
          <el-option label="已注册" value="registered" />
        </el-select>

        <el-button type="primary" @click="fetchData">搜索</el-button>
      </div>

      <el-table ref="tableRef" :data="tableData" stripe v-loading="loading" @selection-change="onSelectionChange">
        <el-table-column type="selection" width="50" v-if="!editionStore.isCommunity" />
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="name" label="姓名" width="120" />
        <el-table-column prop="phone" label="手机号" width="130" />
        <el-table-column prop="position" label="职务" min-width="150" />
        <el-table-column prop="unit_name" label="单位" width="150" />
        <el-table-column prop="user_type" label="类型" width="80">
          <template #default="{ row }">
            <el-tag :type="row.user_type === 'A' ? '' : 'success'" size="small">
              {{ row.user_type }}类</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="source" label="来源" width="100">
          <template #default="{ row }">
            <el-tag :type="row.source === 'admin' ? '' : 'success'" size="small">
              {{ row.source === 'admin' ? '后台添加' : '已注册' }}
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
        <el-table-column label="操作" width="200" fixed="right" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
          <template #default="{ row }">
            <el-button type="primary" link @click="handleEdit(row)">编辑</el-button>
            <el-dropdown trigger="click" @command="(cmd) => handleDeleteCommand(cmd, row)">
              <el-button type="danger" link>删除
                <el-icon class="el-icon--right"><ArrowDown /></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="normal">删除</el-dropdown-item>
                  <el-dropdown-item command="force" v-if="userStore.isSuperAdmin" style="color: #F56C6C;">强制删除</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
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

    <!-- 用户表单对话框 -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="560px"
      @close="resetForm"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
        <el-form-item label="单位" prop="unit_id">
          <el-select v-model="form.unit_id" placeholder="请选择单位" style="width: 100%;">
            <el-option
              v-for="unit in units"
              :key="unit.id"
              :label="unit.unit_name"
              :value="unit.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="姓名" prop="name">
          <el-input v-model="form.name" placeholder="请输入姓名" />
        </el-form-item>
        <el-form-item label="手机号" prop="phone">
          <el-input v-model="form.phone" placeholder="请输入手机号" />
        </el-form-item>
        <el-form-item label="职务" prop="position">
          <el-input v-model="form.position" placeholder="请输入职务" />
        </el-form-item>
        <el-form-item label="用户类型" prop="user_type">
          <el-radio-group v-model="form.user_type">
            <el-radio value="A">A类</el-radio>
            <el-radio value="B">B类</el-radio>
          </el-radio-group>
        </el-form-item>

        <!-- 密码区域（仅编辑时显示） -->
        <template v-if="form.id">
          <el-divider content-position="left">密码管理</el-divider>

          <el-form-item label="重置密码">
            <div class="reset-password-area">
              <div class="password-status-info" :class="{ 'no-pwd': !form.has_password }">
                <span>当前状态：{{ form.has_password ? '已设置密码' : '未设置密码' }}</span>
              </div>
              <el-input
                v-model="newPassword"
                type="password"
                placeholder="请输入新密码（至少6位）"
                style="width: 100%; margin: 10px 0;"
              />
              <el-button
                type="warning"
                size="small"
                :loading="resetPwdLoading"
                :disabled="!newPassword || newPassword.length < 6"
                @click="handleResetPassword"
              >
                确认重置
              </el-button>
            </div>
          </el-form-item>
        </template>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitLoading" @click="handleSubmit">确定</el-button>
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
import { Plus, Search, ArrowDown } from '@element-plus/icons-vue'

const userStore = useUserStore()
const editionStore = useEditionStore()
const upgradeDialog = ref(null)

const loading = ref(false)
const submitLoading = ref(false)
const resetPwdLoading = ref(false)
const tableData = ref([])
const units = ref([])
const keyword = ref('')
const unitId = ref(null)
const sourceFilter = ref('admin')
const selectedIds = ref([])
const tableRef = ref(null)
const batchDeleteMode = ref('normal')

const dialogVisible = ref(false)
const dialogTitle = ref('新增用户')
const formRef = ref(null)
const newPassword = ref('')

const pagination = ref({
  page: 1,
  perPage: 20,
  total: 0,
})

const form = ref({
  id: null,
  unit_id: '',
  name: '',
  phone: '',
  position: '',
  user_type: 'A',
})

const rules = {
  unit_id: [{ required: true, message: '请选择单位', trigger: 'change' }],
  name: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
  phone: [
    { required: true, message: '请输入手机号码', trigger: 'blur' },
    { pattern: /^1[3-9]\d{9}$/, message: '手机号格式不正确', trigger: 'blur' },
  ],
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
    }

    if (keyword.value) params.keyword = keyword.value
    if (unitId.value) params.unit_id = unitId.value
    if (sourceFilter.value) params.source = sourceFilter.value

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

function handleAdd() {
  dialogTitle.value = '新增用户'
  form.value = { id: null, unit_id: '', name: '', phone: '', position: '', user_type: 'A' }
  dialogVisible.value = true
}

async function handleEdit(row) {
  dialogTitle.value = '编辑用户'
  form.value = { ...row, has_password: false }
  newPassword.value = ''

  try {
    const res = await request.get(`/users/${row.id}`)
    if (res.code === 200 && res.data) {
      const userData = res.data
      form.value = {
        ...form.value,
        has_password: !!userData.password_hash,
      }
    }
  } catch (error) {
    console.error('获取用户详情失败:', error)
  }

  dialogVisible.value = true
}

async function handleDeleteCommand(command, row) {
  if (command === 'force') {
    await handleForceDelete(row)
  } else {
    await handleDelete(row)
  }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`确定要删除用户"${row.name}"吗？`, '提示', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    })

    await request.delete(`/users/${row.id}`)
    ElMessage.success('删除成功')
    fetchData()
  } catch (error) {
    if (error !== 'cancel') console.error('删除失败:', error)
  }
}

async function handleForceDelete(row) {
  try {
    const { value: confirmName } = await ElMessageBox.prompt(
      `⚠️ 危险操作\n\n用户「${row.name}」存在测评记录，强制删除将同时清除其所有答题数据和参评记录。\n此操作不可恢复，请输入用户名「${row.name}」以确认：`,
      '强制删除确认',
      {
        confirmButtonText: '确认强制删除',
        cancelButtonText: '取消',
        type: 'error',
        inputPlaceholder: `请输入：${row.name}`,
        inputPattern: new RegExp(`^${row.name}$`),
        inputErrorMessage: '用户名不匹配，请重新输入',
        confirmButtonClass: 'el-button--danger',
      }
    )

    if (!confirmName) return

    const res = await request.post(`/users/${row.id}/force-delete`)
    const data = res.data || {}
    ElMessage.success(`已强制删除用户「${data.deleted_user || row.name}」，同时清理了 ${data.answers_deleted || 0} 条答题记录和 ${data.examine_users_deleted || 0} 条参评记录`)
    fetchData()
  } catch (error) {
    if (error !== 'cancel') console.error('强制删除失败:', error)
  }
}

function onSelectionChange(selection) {
  selectedIds.value = selection.map(s => s.id)
}

function batchDeleteClick() {
  if (editionStore.isCommunity) { upgradeDialog.value?.open(); return }
  handleBatchDelete()
}

async function handleBatchDelete() {
  if (selectedIds.value.length === 0) return

  const selectedNames = tableData.value
    .filter(u => selectedIds.value.includes(u.id))
    .map(u => u.name)

  const isForce = batchDeleteMode.value === 'force'
  const modeLabel = isForce ? '【强制】' : ''
  const modeWarning = isForce ? '\n\n⚠️ 强制模式将级联清除所有关联的答题数据和参评记录！' : ''

  try {
    await ElMessageBox.confirm(
      `确定要${modeLabel}删除以下 ${selectedIds.value.length} 位用户吗？\n${selectedNames.join('、')}${modeWarning}`,
      `${modeLabel}批量删除确认`,
      { confirmButtonText: '确定删除', cancelButtonText: '取消', type: isForce ? 'error' : 'warning' }
    )
  } catch {
    return
  }

  let successCount = 0
  let failCount = 0

  for (const id of selectedIds.value) {
    try {
      if (isForce) {
        await request.post(`/users/${id}/force-delete`)
      } else {
        await request.delete(`/users/${id}`)
      }
      successCount++
    } catch (e) {
      failCount++
    }
  }

  if (successCount > 0) {
    ElMessage.success(`${modeLabel}成功删除 ${successCount} 位用户`)
  }
  if (failCount > 0) {
    ElMessage.warning(`${failCount} 位用户删除失败`)
  }

  selectedIds.value = []
  fetchData()
}

async function handleSubmit() {
  if (!formRef.value) return

  try {
    await formRef.value.validate()
  } catch (error) {
    return
  }

  submitLoading.value = true

  try {
    if (form.value.id) {
      await request.put(`/users/${form.value.id}`, form.value)
      ElMessage.success('更新成功')
    } else {
      await request.post('/users', form.value)
      ElMessage.success('创建成功')
    }

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
      {
        confirmButtonText: '确定重置',
        cancelButtonText: '取消',
        type: 'warning',
      }
    )

    resetPwdLoading.value = true
    const res = await request.put(`/users/${form.value.id}/reset-password`, {
      new_password: newPassword.value,
    })

    if (res.code === 200) {
      ElMessage.success(`密码已重置为：${res.data?.new_password || newPassword.value}`)
      form.value.password_mask = '••••••••'
      form.value.has_password = true
      newPassword.value = ''
    }
  } catch (error) {
    if (error !== 'cancel') {
      console.error('重置密码失败:', error)
    }
  } finally {
    resetPwdLoading.value = false
  }
}
</script>

<style lang="scss" scoped>
.users-page {
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
  }

  .reset-password-area {
    width: 100%;

    .password-status-info {
      font-size: 13px;
      color: var(--color-success);
      margin-bottom: 8px;
      padding: 6px 10px;
      background-color: rgba(46, 139, 87, 0.06);
      border-radius: var(--radius-sm);
      border-left: 3px solid var(--color-success);

      &.no-pwd {
        color: var(--color-danger);
        background-color: rgba(194, 59, 34, 0.05);
        border-left-color: var(--color-danger);
      }
    }
  }
}

/* 蓝色复选框 */
:deep(.el-checkbox__inner) {
  border: 2px solid var(--color-primary);
}
:deep(.el-checkbox__input.is-checked .el-checkbox__inner) {
  background-color: var(--color-primary);
  border-color: var(--color-primary);
}
</style>
