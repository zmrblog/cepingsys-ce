<template>
  <div class="import-data-page">
    <el-card>
      <template #header>
        <span>导入单位及用户</span>
      </template>

      <el-alert
        title="导入说明"
        type="info"
        :closable="false"
        show-icon
        class="import-tips"
      >
        <template #default>
          <p>1. 请先下载模板文件，按格式填写数据</p>
          <p>2. Excel表头必须包含：序号 | 单位名称 | 姓名 | 手机号 | 职务 | 类型</p>
          <p>3. 单位名称不存在时会自动创建新单位</p>
          <p>4. 手机号为必填项，重复手机号将自动跳过或更新已有记录</p>
          <p>5. 类型只能填A（班子成员）或B（一般干部）</p>
          <p>6. 支持xlsx/xls格式，单次最多导入1000条数据</p>
        </template>
      </el-alert>

      <div class="action-bar">
        <el-button type="primary" @click="downloadTemplateClick">
          <el-icon><Download /></el-icon>
          下载导入模板
        </el-button>
      </div>

      <el-upload
        ref="uploadRef"
        class="upload-area"
        drag
        action="#"
        :auto-upload="false"
        :limit="1"
        accept=".xlsx,.xls"
        :on-change="handleFileChange"
        :on-remove="handleFileRemove"
      >
        <el-icon class="el-icon--upload"><UploadFilled /></el-icon>
        <div class="el-upload__text">
          将文件拖到此处，或<em>点击上传</em>
        </div>
        <template #tip>
          <div class="el-upload__tip">
            只能上传 xlsx/xls 文件，且不超过 10MB
          </div>
        </template>
      </el-upload>

      <div class="submit-area">
        <el-button
          type="success"
          size="large"
          :loading="importLoading"
          @click="handleImportClick"
          :disabled="!importFile"
        >
          开始导入</el-button>
      </div>
    </el-card>

    <el-dialog
      v-model="resultDialogVisible"
      title="导入结果"
      width="500px"
    >
      <div class="result-summary">
        <el-result
          :icon="resultData.error_count > 0 ? 'warning' : 'success'"
          :title="resultData.error_count > 0 ? `导入完成，${resultData.error_count} 条失败` : `导入成功，共 ${resultData.success_count} 条`"
        />
      </div>
      <div v-if="resultData.errors && resultData.errors.length > 0" class="error-list">
        <h4>错误详情（前20条）：</h4>
        <ul>
          <li v-for="(err, idx) in resultData.errors" :key="idx">{{ err }}</li>
        </ul>
      </div>
      <template #footer>
        <el-button type="primary" @click="resultDialogVisible = false">确定</el-button>
      </template>
    </el-dialog>

  <UpgradeDialog ref="upgradeDialog" />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import request from '@/api/request'
import { useEditionStore } from '@/stores/edition'
import UpgradeDialog from '@/components/UpgradeDialog.vue'
import { ElMessage } from 'element-plus'
import { Download, UploadFilled } from '@element-plus/icons-vue'

const editionStore = useEditionStore()
const upgradeDialog = ref(null)
const uploadRef = ref(null)
const importLoading = ref(false)
const importFile = ref(null)
const resultDialogVisible = ref(false)
const resultData = ref({
  success_count: 0,
  error_count: 0,
  errors: [],
})

async function downloadTemplateClick() {
  if (editionStore.isCommunity) { upgradeDialog.value?.open(); return }
  downloadTemplate()
}

function handleImportClick() {
  if (editionStore.isCommunity) { upgradeDialog.value?.open(); return }
  handleImport()
}

async function downloadTemplate() {
  try {
    const res = await request.get('/import/download-template', {
      responseType: 'blob',
    })

    const blob = res instanceof Blob ? res : new Blob([res], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = '单位及用户导入模板.xlsx'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(url)

    ElMessage.success('模板下载成功')
  } catch (error) {
    console.error('下载模板失败:', error)
    ElMessage.error('下载模板失败')
  }
}

function handleFileChange(file) {
  if (file.raw.size > 10 * 1024 * 1024) {
    ElMessage.error('文件大小不能超过10MB')
    return
  }

  const allowedTypes = [
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-excel',
  ]

  if (!allowedTypes.includes(file.raw.type)) {
    ElMessage.error('只支持Excel文件格式（xlsx/xls）')
    return
  }

  importFile.value = file.raw
}

function handleFileRemove() {
  importFile.value = null
}

async function handleImport() {
  if (!importFile.value) {
    ElMessage.warning('请先选择文件')
    return
  }

  importLoading.value = true

  try {
    const formData = new FormData()
    formData.append('file', importFile.value)

    const res = await request.post('/import/users-units', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    resultData.value = {
      success_count: res.data?.success_count || 0,
      error_count: res.data?.error_count || 0,
      errors: res.data?.errors || [],
    }
    resultDialogVisible.value = true

    if (importFile.value) {
      importFile.value = null
      uploadRef.value?.clearFiles()
    }
  } catch (error) {
    console.error('导入失败:', error)
    ElMessage.error(error.response?.data?.message || '导入失败，请检查文件格式')
  } finally {
    importLoading.value = false
  }
}
</script>

<style lang="scss" scoped>
.import-data-page {
  .import-tips {
    margin-bottom: 24px;

    p {
      margin: 5px 0;
      font-size: 13px;
      color: #606266;
    }
  }

  .action-bar {
    margin-bottom: 20px;
  }

  .upload-area {
    margin-bottom: 24px;

    :deep(.el-upload-dragger) {
      padding: 40px;
    }
  }

  .submit-area {
    text-align: center;
    margin-top: 30px;

    .el-button {
      min-width: 200px;
    }
  }

  .error-list {
    margin-top: 16px;
    max-height: 300px;
    overflow-y: auto;
    background: #fef0f0;
    border-radius: 4px;
    padding: 12px 16px;

    h4 {
      color: #f56c6c;
      font-size: 14px;
      margin-bottom: 8px;
    }

    ul {
      list-style: none;
      padding: 0;
      margin: 0;

      li {
        font-size: 13px;
        color: #606266;
        line-height: 1.8;
        border-bottom: 1px dashed #fbc4c4;

        &:last-child {
          border-bottom: none;
        }
      }
    }
  }
}
</style>
