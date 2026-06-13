<template>
  <div class="feature-page">
    <el-card>
      <template #header>
        <div class="card-header">
          <span class="title">功能对比</span>
          <el-tag type="warning" size="small">企业版 vs 社区版</el-tag>
        </div>
      </template>

      <div class="feature-intro">
        <p>以下列出企业版与社区版的核心功能差异，帮助您了解不同版本的能力范围。标记 <el-icon color="#67C23A" size="16"><Check /></el-icon> 表示支持，<el-icon color="#F56C6C" size="16"><Close /></el-icon> 表示不支持。</p>
      </div>

      <el-table :data="sortedFeatures" stripe style="width: 100%" border>
        <el-table-column prop="category" label="功能模块" width="140" />

        <el-table-column prop="name" label="功能项" min-width="180" />

        <el-table-column label="社区版" width="130" align="center">
          <template #default="{ row }">
            <template v-if="typeof row.community === 'boolean'">
              <el-icon v-if="row.community" color="#67C23A" size="20"><Check /></el-icon>
              <el-icon v-else color="#F56C6C" size="20"><Close /></el-icon>
            </template>
            <span v-else class="cell-text">{{ row.community }}</span>
          </template>
        </el-table-column>

        <el-table-column label="企业版" width="130" align="center">
          <template #default="{ row }">
            <template v-if="typeof row.enterprise === 'boolean'">
              <el-icon v-if="row.enterprise" color="#67C23A" size="20"><Check /></el-icon>
              <el-icon v-else color="#C0C4CC" size="20"><Close /></el-icon>
            </template>
            <span v-else class="cell-text">{{ row.enterprise }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="remark" label="说明" min-width="220" />
      </el-table>

      <el-divider />

      <el-alert
        title="如需从社区版升级至企业版，请联系管理员获取企业版授权。"
        type="warning"
        :closable="false"
        show-icon
      />
    </el-card>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Check, Close } from '@element-plus/icons-vue'

function isDifferent(row) {
  return row.community !== row.enterprise
}

const sortedFeatures = computed(() => {
  const copy = [...features]
  copy.sort((a, b) => {
    const aDiff = isDifferent(a) ? 0 : 1
    const bDiff = isDifferent(b) ? 0 : 1
    return aDiff - bDiff
  })
  return copy
})

const features = [
  // ===== 测评管理 =====
  { category: '测评管理', name: '测评模板管理', community: true, enterprise: true, remark: '创建/编辑/复制测评问卷模板（两类：干部/班子）' },
  { category: '测评管理', name: '测评任务管理', community: true, enterprise: true, remark: '创建/发布/状态流转：草稿→进行中→已结束→已归档' },
  { category: '测评管理', name: '多题型支持（含反向测评）', community: true, enterprise: true, remark: '单选/多选/文本，支持反向测评及事例填写' },
  { category: '测评管理', name: '测评对象管理', community: true, enterprise: true, remark: '管理被测评对象（班子/干部类型）' },
  { category: '测评管理', name: '参评人员分配', community: true, enterprise: true, remark: '按部门筛选/批量分配/单个添加移除' },
  { category: '测评管理', name: '测评归档', community: true, enterprise: true, remark: '按考核周期归档，归档概览与查询' },
  { category: '测评管理', name: '数据导入（Excel）', community: false, enterprise: true, remark: '下载模板+批量导入用户和单位' },
  { category: '测评管理', name: '批量删除任务', community: false, enterprise: true, remark: '一次性删除多个测评任务' },

  // ===== 参评管理 =====
  { category: '参评管理', name: '单位/部门管理', community: true, enterprise: true, remark: '多级部门组织管理' },
  { category: '参评管理', name: '用户管理', community: '≤50人', enterprise: '无限制', remark: '管理参评人员信息' },
  { category: '参评管理', name: '注册用户管理', community: true, enterprise: true, remark: '管理手机端注册用户（启用/禁用/删除）' },
  { category: '参评管理', name: '部门及用户信息总览', community: true, enterprise: true, remark: '树形结构展示组织与人员' },
  { category: '参评管理', name: '批量新增单位', community: false, enterprise: true, remark: '一次创建多个单位' },
  { category: '参评管理', name: '批量删除（单位/用户）', community: false, enterprise: true, remark: '批量删除单位、用户' },
  { category: '参评管理', name: '强制删除用户', community: false, enterprise: true, remark: '有测评记录时强制级联删除' },

  // ===== 数据分析 =====
  { category: '数据分析', name: '测评结果统计', community: true, enterprise: true, remark: '完成率、各对象答题情况统计' },
  { category: '数据分析', name: '按单位统计', community: true, enterprise: true, remark: '按单位分组统计测评结果' },
  { category: '数据分析', name: '投票汇总/得分排名', community: true, enterprise: true, remark: '投票汇总、得分汇总与排名，含A/B类加权' },
  { category: '数据分析', name: '自定义评分权重', community: true, enterprise: true, remark: '区分不同角色（A/B类）评分权重' },
  { category: '数据分析', name: 'Excel导出', community: false, enterprise: true, remark: '单任务/按单位/按对象多维度导出' },
  { category: '数据分析', name: '批量Excel导出', community: false, enterprise: true, remark: '多任务打包批量导出' },

  // ===== 系统管理 =====
  { category: '系统管理', name: '多管理员角色', community: true, enterprise: true, remark: '超级管理员/模板管理员/查看管理员三级权限' },
  { category: '系统管理', name: '管理员管理', community: true, enterprise: true, remark: '创建/编辑管理员账号' },
  { category: '系统管理', name: '系统配置管理', community: true, enterprise: true, remark: '系统参数动态配置' },
  { category: '系统管理', name: 'IP过滤/安全防护', community: false, enterprise: true, remark: '国外IP拦截、登录频率限制' },
  { category: '系统管理', name: '操作日志查看', community: false, enterprise: true, remark: '管理员操作全轨迹记录与查询' },
  { category: '系统管理', name: '审计子系统', community: false, enterprise: true, remark: '独立审计入口，数据查看/导出/打印' },

  // ===== 移动端 =====
  { category: '移动端', name: '手机端H5测评', community: true, enterprise: true, remark: '微信/浏览器答题，逐题保存+提交' },

  // ===== 其他 =====
  { category: '其他', name: '安装向导', community: true, enterprise: true, remark: '环境检查/数据库配置/管理员创建' },
  { category: '其他', name: '使用说明', community: true, enterprise: true, remark: '系统使用帮助文档' },
]
</script>

<style lang="scss" scoped>
.feature-page {
  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;

    .title {
      font-size: 18px;
      font-weight: 600;
    }
  }

  .feature-intro {
    margin-bottom: 20px;
    padding: 12px 16px;
    background: #fdf6ec;
    border-radius: 8px;
    border-left: 4px solid #e6a23c;

    p {
      margin: 0;
      color: #303133;
      font-size: 14px;
      line-height: 1.6;

      .el-icon {
        vertical-align: middle;
      }
    }
  }

  .cell-text {
    font-size: 13px;
    color: #606266;
  }
}

:deep(.el-table) {
  th.el-table__cell {
    background-color: #f5f7fa;
    font-weight: 600;
  }
}
</style>