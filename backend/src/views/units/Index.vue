<template>
  <div class="units-page">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>单位管理</span>
          <div class="header-actions">
            <el-button type="danger" :disabled="!selectedIds.length" @click="batchDeleteClick" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
              批量删除（{{ selectedIds.length }}）</el-button>
            <el-button type="success" @click="batchAddClick" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
              <el-icon><DocumentCopy /></el-icon>
              批量新增
            </el-button>
            <el-button type="primary" @click="handleAdd" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
              <el-icon><Plus /></el-icon>
              新增单位
            </el-button>
          </div>
        </div>
      </template>

      <el-table ref="tableRef" :data="tableData" stripe v-loading="loading" @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="50" v-if="!editionStore.isCommunity" />
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="unit_name" label="单位名称" min-width="200" />
        <el-table-column prop="unit_code" label="单位编码" width="150" />
        <el-table-column prop="sort_order" label="排序" width="80" />
        <el-table-column label="操作" width="200" fixed="right" v-if="userStore.isTemplateAdmin || userStore.isAdmin">
          <template #default="{ row }">
            <el-button type="primary" link @click="handleEdit(row)">编辑</el-button>
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

    <!-- 单个新增/编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="500px"
      @close="resetForm"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
        <el-form-item label="单位名称" prop="unit_name">
          <el-input v-model="form.unit_name" placeholder="请输入单位名称" />
        </el-form-item>
        <el-form-item label="单位编码" prop="unit_code">
          <el-input v-model="form.unit_code" placeholder="请输入单位编码" />
        </el-form-item>
        <el-form-item label="排序序号" prop="sort_order">
          <el-input-number v-model="form.sort_order" :min="0" :max="9999" />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitLoading" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>

    <!-- 批量新增单位弹窗 -->
    <el-dialog
      v-model="batchDialogVisible"
      title="批量新增单位"
      width="560px"
      @close="resetBatchForm"
    >
      <div class="batch-dialog-body">
        <el-alert
          title="提示"
          type="info"
          :closable="false"
          show-icon
          style="margin-bottom:16px"
        >每行一个单位名称，可使用制表符、英文逗号或分号分隔</el-alert>
        <el-form ref="batchFormRef" :model="batchForm" :rules="batchRules" label-width="0">
          <el-form-item prop="names">
            <textarea
              v-model="batchForm.names"
              class="batch-textarea"
              rows="10"
              placeholder="例如：&#10;北京市&#10;上海市&#10;广州市"
              maxlength="5000"
            />
            <div class="textarea-footer">
              <span class="char-count">{{ batchForm.names.length }} / 5000</span>
            </div>
          </el-form-item>
        </el-form>

        <div class="batch-preview" v-if="parsedNames.length > 0">
          <div class="preview-title">
            预览（共 {{ parsedNames.length }} 个）
            <el-tag size="small" type="success">{{ parsedNames.length - skippedCount }} 个将新增</el-tag>
            <el-tag size="small" type="warning" v-if="skippedCount > 0">{{ skippedCount }} 个已存在将跳过</el-tag>
          </div>
          <div class="preview-list">
            <span
              v-for="(name, idx) in parsedNames"
              :key="idx"
              class="preview-tag"
              :class="{ 'is-skipped': skippedSet.has(name) }"
            >{{ name }}</span>
          </div>
        </div>
      </div>

      <template #footer>
        <el-button @click="batchDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="batchSubmitting" @click="handleBatchSubmit" :disabled="!parsedNames.length || parsedNames.length === skippedCount">
          确认批量新增{{ parsedNames.length - skippedCount > 0 ? `（${parsedNames.length - skippedCount} 个）` : '' }}
        </el-button>
      </template>
    </el-dialog></div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useUserStore } from '@/stores/user'
import { Plus, DocumentCopy } from '@element-plus/icons-vue'
import request from '@/api/request'
import { useEditionStore } from '@/stores/edition'
import { ElMessage, ElMessageBox } from 'element-plus'

const userStore = useUserStore()
const editionStore = useEditionStore()

const loading = ref(false)
const submitLoading = ref(false)
const tableData = ref([])
const dialogVisible = ref(false)
const dialogTitle = ref('新增单位')
const formRef = ref(null)
const tableRef = ref(null)

const pagination = ref({
  page: 1,
  perPage: 20,
  total: 0,
})

const form = ref({
  id: null,
  unit_name: '',
  unit_code: '',
  sort_order: 0,
})

const rules = {
  unit_name: [
    { required: true, message: '请输入单位名称', trigger: 'blur' },
  ],
}

onMounted(() => {
  fetchData()
})

async function fetchData() {
  loading.value = true
  try {
    const res = await request.get('/units', {
      params: {
        page: pagination.value.page,
        per_page: pagination.value.perPage,
      },
    })

    tableData.value = res.data?.data || []
    pagination.value.total = res.data?.pagination?.total || 0
  } catch (error) {
    console.error('获取数据失败:', error)
  } finally {
    loading.value = false
  }
}

function handleAdd() {
  dialogTitle.value = '新增单位'
  form.value = { id: null, unit_name: '', unit_code: '', sort_order: 0 }
  dialogVisible.value = true
}

function handleEdit(row) {
  dialogTitle.value = '编辑单位'
  form.value = { ...row }
  dialogVisible.value = true
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`确定要删除单位"${row.unit_name}"吗？`, '提示', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    })

    await request.delete(`/units/${row.id}`)
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
    if (form.value.id) {
      await request.put(`/units/${form.value.id}`, form.value)
      ElMessage.success('更新成功')
    } else {
      await request.post('/units', form.value)
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

// ===== 复选框选择 =====
const selectedIds = ref([])
const selectedRows = ref([])

function handleSelectionChange(rows) {
  selectedRows.value = rows
  selectedIds.value = rows.map(r => r.id)
}

// ===== 批量删除 =====
function batchDeleteClick() { handleBatchDelete() }

function batchAddClick() { handleBatchAdd() }

async function handleBatchDelete() {
  if (!selectedIds.value.length) return

  const names = selectedRows.value.map(r => r.unit_name).join('、')
  try {
    await ElMessageBox.confirm(
      `确定要删除选中的 ${selectedIds.value.length} 个单位吗？（${names}）`,
      '批量删除确认',
      {
        confirmButtonText: '确定删除',
        cancelButtonText: '取消',
        type: 'warning',
        confirmButtonClass: 'el-button--danger',
      }
    )

    await request.post('/units/batch-delete', { ids: selectedIds.value })
    ElMessage.success(`成功删除 ${selectedIds.value.length} 个单位`)
    selectedIds.value = []
    selectedRows.value = []
    fetchData()
  } catch (error) {
    if (error !== 'cancel') {
      console.error('批量删除失败:', error)
    }
  }
}

// ===== 批量新增 =====
const batchDialogVisible = ref(false)
const batchSubmitting = ref(false)
const batchFormRef = ref(null)

const batchForm = reactive({
  names: '',
})

const batchRules = {
  names: [{ required: true, message: '请输入单位名称', trigger: 'blur' }],
}

const parsedNames = computed(() => {
  let text = batchForm.names
  text = text.replace(/[\t]+/g, '')
  return text
    .split(/[\s,;，；、]+/)
    .map(s => s.trim())
    .filter(s => s !== '' && s.length <= 100)
})

const skippedSet = computed(() => new Set(
  tableData.value.map(u => u.unit_name).filter(n => parsedNames.value.includes(n))
))

const skippedCount = computed(() => skippedSet.value.size)

function handleBatchAdd() {
  resetBatchForm()
  batchDialogVisible.value = true
}

function resetBatchForm() {
  batchForm.names = ''
  batchFormRef.value?.resetFields()
}

async function handleBatchSubmit() {
  if (!batchFormRef.value) return
  try {
    await batchFormRef.value.validate()
  } catch { return }

  batchSubmitting.value = true
  try {
    const res = await request.post('/units/batch', { names: batchForm.names })
    const data = res.data ?? {}
    let msg = ''
    if (data.created > 0 && data.skipped > 0) {
      msg = `成功新增 ${data.created} 个单位，跳过 ${data.skipped} 个已存在单位`
    } else if (data.created > 0) {
      msg = `成功新增 ${data.created} 个单位`
    } else {
      msg = `所有单位均已存在，无需新增`
    }

    ElMessage.success(msg)
    batchDialogVisible.value = false
    fetchData()
  } catch (error) {
    console.error('批量新增失败:', error)
  } finally {
    batchSubmitting.value = false
  }
}
</script>

<style lang="scss" scoped>
.units-page {
  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .header-actions {
    display: flex;
    gap: 8px;
  }

  :deep(.el-table) {
    .el-checkbox__input.is-checked .el-checkbox__inner {
      background-color: #409eff;
      border-color: #409eff;
    }
    .el-checkbox__inner {
      background-color: #fff;
      border-color: #409eff;
      &:hover {
        border-color: #409eff;
      }
    }
  }

  .batch-textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #dcdfe6;
    border-radius: 4px;
    font-size: 14px;
    line-height: 1.6;
    resize: vertical;
    outline: none;
    transition: border-color 0.2s;
    box-sizing: border-box;

    &:focus {
      border-color: #409eff;
    }

    &::placeholder {
      color: #909399;
      font-size: 13px;
    }
  }

  .textarea-footer {
    text-align: right;
    margin-top: 4px;
    font-size: 12px;
    color: #909399;
  }
}

.batch-dialog-body {
  .batch-preview {
    margin-top: 12px;
    border: 1px solid #e4e7ed;
    border-radius: 4px;
    padding: 12px;

    .preview-title {
      font-size: 13px;
      color: #606266;
      font-weight: 600;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .preview-list {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }

    .preview-tag {
      display: inline-block;
      padding: 2px 8px;
      font-size: 12px;
      background: #ecf5ff;
      color: #409eff;
      border-radius: 3px;
      line-height: 22px;

      &.is-skipped {
        background: #EEECEF;
        color: #909399;
        text-decoration: line-through;
      }
    }
  }
}
</style>



