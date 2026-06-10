<template>
  <div class="admins-page">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>管理员管理</span>
          <el-button type="primary" @click="handleAdd">
            <el-icon><Plus /></el-icon>
            新增管理员
          </el-button>
        </div>
      </template>

      <div class="filter-bar">
        <el-input
          v-model="filters.keyword"
          placeholder="搜索用户名/姓名"
          clearable
          style="width: 250px"
          @clear="fetchData"
          @keyup.enter="fetchData"
        >
          <template #prefix>
            <el-icon><Search /></el-icon>
          </template>
        </el-input>

        <el-select v-model="filters.role" placeholder="角色筛选" clearable style="width: 150px; margin-left: 10px;" @change="fetchData">
          <el-option label="超级管理员" value="super" />
          <el-option label="模板管理员" value="template" />
          <el-option label="查看管理员" value="viewer" />
        </el-select>

        <el-button type="primary" @click="fetchData" style="margin-left: 10px;">
          <el-icon><Search /></el-icon>
          搜索
        </el-button>
      </div>

      <el-table :data="tableData" stripe v-loading="loading" style="width: 100%;">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="username" label="用户名" width="150" />
        <el-table-column prop="real_name" label="真实姓名" width="120" />
        <el-table-column label="角色" width="130">
          <template #default="{ row }">
            <el-tag :type="getRoleType(row.role)" effect="plain">
              {{ getRoleText(row.role) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'" size="small">
              {{ row.status === 1 ? '正常' : '禁用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="170">
          <template #default="{ row }">
            {{ formatDate(row.created_at) }}
          </template>
        </el-table-column>
        <el-table-column prop="updated_at" label="更新时间" width="170">
          <template #default="{ row }">
            {{ formatDate(row.updated_at) }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link @click="handleEdit(row)">编辑</el-button>
            <el-button type="warning" link @click="handleResetPassword(row)" v-if="row.id !== currentAdminId">重置密码</el-button>
            <el-button type="danger" link @click="handleDelete(row)" v-if="row.id !== currentAdminId">删除</el-button>
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

    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="550px"
      @close="resetForm"
      destroy-on-close
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
        <el-form-item label="用户名" prop="username">
          <el-input
            v-model="form.username"
            placeholder="请输入用户名（3-50位字母数字）"
            :disabled="isEdit"
          />
          <div class="form-tip">仅支持字母、数字和下划线</div>
        </el-form-item>

        <el-form-item label="密码" prop="password" v-if="!isEdit">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="请输入密码（至少6位）"
            show-password
          />
        </el-form-item>

        <el-form-item label="新密码" prop="password" v-if="isEdit">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="留空则不修改密码"
            show-password
          />
          <div class="form-tip">如需修改密码请填写，留空则保持原密码</div>
        </el-form-item>

        <el-form-item label="真实姓名" prop="real_name">
          <el-input v-model="form.real_name" placeholder="请输入真实姓名" />
        </el-form-item>

        <el-form-item label="角色" prop="role">
          <el-select v-model="form.role" placeholder="请选择角色" style="width: 100%;">
            <el-option label="超级管理员 (super)" value="super" />
            <el-option label="模板管理员 (template)" value="template" />
            <el-option label="查看管理员 (viewer)" value="viewer" />
          </el-select>
        </el-form-item>

        <el-form-item label="状态" prop="status" v-if="isEdit">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">正常</el-radio>
            <el-radio :value="0">禁用</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitLoading" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useUserStore } from '@/stores/user'
import request from '@/api/request'
import { ElMessage, ElMessageBox } from 'element-plus'

const userStore = useUserStore()
const loading = ref(false)
const submitLoading = ref(false)
const tableData = ref([])
const dialogVisible = ref(false)
const dialogTitle = ref('新增管理员')
const formRef = ref(null)

const currentAdminId = computed(() => userStore.adminInfo?.id || 0)

const isEdit = computed(() => !!form.value?.id)

const filters = ref({
  keyword: '',
  role: '',
})

const pagination = ref({
  page: 1,
  perPage: 20,
  total: 0,
})

const form = ref({
  id: null,
  username: '',
  password: '',
  real_name: '',
  role: 'viewer',
  status: 1,
})

const rules = {
  username: [
    { required: true, message: '请输入用户名', trigger: 'blur' },
    { min: 3, max: 50, message: '用户名长度应在3-50个字符之间', trigger: 'blur' },
    { pattern: /^[a-zA-Z0-9_]+$/, message: '用户名只能包含字母、数字和下划线', trigger: 'blur' },
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur', validator: (rule, value, callback) => {
      if (!isEdit.value && !value) {
        callback(new Error('请输入密码'))
      } else if (value && value.length < 6) {
        callback(new Error('密码长度不能少于6位'))
      } else {
        callback()
      }
    }},
  ],
  real_name: [
    { required: true, message: '请输入真实姓名', trigger: 'blur' },
  ],
  role: [
    { required: true, message: '请选择角色', trigger: 'change' },
  ],
}

onMounted(() => {
  fetchData()
})

async function fetchData() {
  loading.value = true
  try {
    const params = {
      page: pagination.value.page,
      per_page: pagination.value.perPage,
    }

    if (filters.value.keyword) {
      params.keyword = filters.value.keyword
    }

    if (filters.value.role) {
      params.role = filters.value.role
    }

    const res = await request.get('/admins', { params })

    tableData.value = res.data?.data || []
    pagination.value.total = res.data?.pagination?.total || 0
  } catch (error) {
    console.error('获取管理员列表失败:', error)
  } finally {
    loading.value = false
  }
}

function handleAdd() {
  dialogTitle.value = '新增管理员'
  form.value = {
    id: null,
    username: '',
    password: '',
    real_name: '',
    role: 'viewer',
    status: 1,
  }
  dialogVisible.value = true
}

function handleEdit(row) {
  dialogTitle.value = '编辑管理员'
  form.value = {
    id: row.id,
    username: row.username,
    password: '',
    real_name: row.real_name,
    role: row.role,
    status: row.status,
  }
  dialogVisible.value = true
}

async function handleResetPassword(row) {
  try {
    const { value } = await ElMessageBox.prompt(
      `请为管理员 "${row.real_name}" 设置新密码（至少6位）`,
      '重置密码',
      {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        inputPattern: /^.{6,}$/,
        inputErrorMessage: '密码长度不能少于6位',
        inputType: 'password',
      }
    )

    await request.put(`/admins/${row.id}`, { password: value })
    ElMessage.success('密码重置成功')
  } catch (error) {
    if (error !== 'cancel') {
      console.error('重置密码失败:', error)
    }
  }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(
      `确定要删除管理员"${row.real_name}"吗？此操作不可恢复！`,
      '删除确认',
      {
        confirmButtonText: '确定删除',
        cancelButtonText: '取消',
        type: 'warning',
        confirmButtonClass: 'el-button--danger',
      }
    )

    await request.delete(`/admins/${row.id}`)
    ElMessage.success('删除成功')
    fetchData()
  } catch (error) {
    if (error !== 'cancel') {
      console.error('删除失败:', error)
    }
  }
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
    const submitData = {
      username: form.value.username,
      real_name: form.value.real_name,
      role: form.value.role,
    }

    if (form.value.password) {
      submitData.password = form.value.password
    }

    if (isEdit.value) {
      submitData.status = form.value.status
      await request.put(`/admins/${form.value.id}`, submitData)
      ElMessage.success('更新成功')
    } else {
      await request.post('/admins', submitData)
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
}

function getRoleText(role) {
  const map = {
    super: '超级管理员',
    template: '模板管理员',
    viewer: '查看管理员',
  }
  return map[role] || role
}

function getRoleType(role) {
  const map = {
    super: 'danger',
    template: 'warning',
    viewer: 'info',
  }
  return map[role] || ''
}

function formatDate(dateStr) {
  if (!dateStr) return '-'
  const date = new Date(dateStr)
  return date.toLocaleString('zh-CN', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}
</script>

<style lang="scss" scoped>
.admins-page {
  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .filter-bar {
    display: flex;
    align-items: center;
    margin-bottom: 16px;
  }

  .form-tip {
    font-size: 12px;
    color: #909399;
    margin-top: 4px;
    line-height: 1.4;
  }
}
</style>
