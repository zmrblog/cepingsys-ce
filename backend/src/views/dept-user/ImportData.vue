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

        <el-button type="success" size="large" @click="handleImportClick">
          开始导入
        </el-button>
      </div>
    </el-card>

  <UpgradeDialog ref="upgradeDialog" />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useEditionStore } from '@/stores/edition'
import UpgradeDialog from '@/components/UpgradeDialog.vue'
import { Download } from '@element-plus/icons-vue'

const editionStore = useEditionStore()
const upgradeDialog = ref(null)

function downloadTemplateClick() {
  upgradeDialog.value?.open()
}

function handleImportClick() {
  upgradeDialog.value?.open()
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
