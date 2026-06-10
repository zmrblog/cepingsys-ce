<template>
  <el-card shadow="hover" class="edition-compare-card">
    <template #header>
      <div class="card-header">
        <span>
          <el-icon><Histogram /></el-icon>
          版本功能对比
        </span>
        <el-tag v-if="isCommunity" type="warning" size="small">社区版</el-tag>
        <el-tag v-else type="success" size="small">企业版</el-tag>
      </div>
    </template>

    <el-table :data="compareFeatures" size="small" stripe :show-header="true">
      <el-table-column prop="name" label="功能模块" width="160" />
      <el-table-column label="社区版" width="160" align="center">
        <template #default="{ row }">
          <template v-if="row.community === true">
            <span class="check">✅</span>
          </template>
          <template v-else-if="typeof row.community === 'string'">
            <span class="limited">⚠️ {{ row.community }}</span>
          </template>
          <template v-else>
            <span class="cross">❌</span>
          </template>
        </template>
      </el-table-column>
      <el-table-column label="企业版" width="160" align="center">
        <template #default="{ row }">
          <template v-if="row.enterprise === true">
            <span class="check">✅</span>
          </template>
          <template v-else-if="typeof row.enterprise === 'string'">
            <span class="limited">🔜 {{ row.enterprise }}</span>
          </template>
          <template v-else>
            <span class="cross">❌</span>
          </template>
        </template>
      </el-table-column>
    </el-table>

    <div v-if="isCommunity" class="upgrade-section">
      <p>需要更多功能？</p>
      <el-button type="primary" size="small" @click="$emit('showUpgrade')">
        查看企业版详情
      </el-button>
    </div>
  </el-card>
</template>

<script setup>
import { computed } from 'vue'
import { useEditionStore } from '@/stores/edition'

defineEmits(['showUpgrade'])

const editionStore = useEditionStore()
const isCommunity = computed(() => editionStore.isCommunity)

const compareFeatures = computed(() => {
  if (editionStore.features.length > 0) return editionStore.features
  // fallback defaults
  return [
    { name: '测评模板管理', community: true, enterprise: true },
    { name: '测评任务管理', community: true, enterprise: true },
    { name: '移动端H5答题', community: true, enterprise: true },
    { name: 'A/B类加权统计', community: true, enterprise: true },
    { name: '反向测评防刷', community: true, enterprise: true },
    { name: '数据归档', community: true, enterprise: true },
    { name: '用户管理', community: '单组织≤100人', enterprise: '无人数限制' },
    { name: 'Excel导出', community: false, enterprise: true },
    { name: '审计子系统', community: false, enterprise: true },
    { name: '批量删除', community: false, enterprise: true },
    { name: '批量Excel导出', community: false, enterprise: true },
    { name: 'AI智能分析', community: false, enterprise: '后期上线' },
    { name: '品牌定制', community: '默认水印', enterprise: '后期上线' },
  ]
})
</script>

<style scoped>
.edition-compare-card { margin-top: 20px; border-radius: 12px; }
.card-header {
  display: flex; justify-content: space-between; align-items: center;
}
.card-header > span {
  font-weight: 600; font-size: 15px; display: flex; align-items: center; gap: 8px;
}
.check { font-size: 16px; }
.cross { font-size: 16px; opacity: 0.5; }
.limited { font-size: 11px; color: #E6A23C; font-weight: 600; }
.upgrade-section {
  margin-top: 16px; padding-top: 16px; border-top: 1px solid #EBEEF5;
  display: flex; justify-content: space-between; align-items: center;
}
.upgrade-section p { margin: 0; font-size: 13px; color: #909399; }
</style>
