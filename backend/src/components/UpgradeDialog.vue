<template>
  <el-dialog
    v-model="visible"
    title="升级到企业版"
    width="680px"
    :close-on-click-modal="false"
    destroy-on-close
  >
    <div class="upgrade-dialog">
      <p class="upgrade-tip">
        当前使用 <strong>社区版</strong>，以下功能需升级：
      </p>
      <el-table :data="enterpriseFeatures" size="small" stripe border>
        <el-table-column prop="name" label="功能" width="160" />
        <el-table-column label="社区版" width="120" align="center">
          <template #default>
            <el-tag type="danger" size="small">不支持</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="企业版" width="120" align="center">
          <template #default>
            <el-tag type="success" size="small">支持</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="desc" label="说明" show-overflow-tooltip />
      </el-table>
      <div class="upgrade-contact">
        <p>如需升级企业版，请联系：</p>
        <p class="contact-info">联系作者获取商业授权</p>
      </div>
    </div>
    <template #footer>
      <el-button @click="visible = false">我知道了</el-button>
      <el-button type="primary" @click="visible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>

<script setup>
import { ref, defineExpose } from 'vue'

const visible = ref(false)

const enterpriseFeatures = [
  { name: 'Excel导出', desc: '单任务 / 按单位 / 按对象多维度导出' },
  { name: '批量Excel导出', desc: '多任务打包批量导出' },
  { name: '数据导入（Excel）', desc: '下载模板 + 批量导入用户/单位' },
  { name: '批量删除', desc: '批量删除单位、用户、测评任务' },
  { name: '强制删除用户', desc: '有测评记录时强制级联删除' },
  { name: '批量新增单位', desc: '一次创建多个单位' },
  { name: '用户管理（无限制）', desc: '解除社区版 50 人上限' },
  { name: '操作日志查看', desc: '管理员操作全轨迹记录与查询' },
  { name: 'IP过滤/安全防护', desc: '国外IP拦截、登录频率限制' },
  { name: '审计子系统', desc: '独立审计入口，数据查看/导出/打印' },
]

function open() {
  visible.value = true
}

defineExpose({ open })
</script>

<style scoped>
.upgrade-dialog { padding: 0 10px; }
.upgrade-tip { margin-bottom: 16px; font-size: 14px; color: #606266; }
.upgrade-contact { margin-top: 16px; padding: 12px; background: #F5F7FA; border-radius: 8px; }
.upgrade-contact p { margin: 0; font-size: 13px; color: #606266; }
.contact-info { margin-top: 6px !important; font-weight: 600; color: #409EFF !important; }
</style>