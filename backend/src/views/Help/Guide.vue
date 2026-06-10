<template>
  <div class="guide-page">
    <el-card>
      <template #header>
        <div class="card-header">
          <span class="title">📖 使用说明</span>
          <el-tag type="success" size="small">面向新手</el-tag>
        </div>
      </template>

      <div class="guide-intro">
        <p>本系统用于组织年度考核和季度考核的在线测评工作。跟着下面 6 个步骤操作，就能从零开始完成一次完整的测评。</p>
      </div>

      <el-collapse v-model="activeSteps" accordion>
        <el-collapse-item v-for="(step, idx) in steps" :key="idx" :name="idx">
          <template #title>
            <div class="step-title">
              <el-tag :type="step.tagType" size="small" round class="step-badge">{{ step.badge }}</el-tag>
              <span class="step-label">{{ step.title }}</span>
            </div>
          </template>

          <div class="step-body">
            <div class="step-entry">
              <el-icon><Promotion /></el-icon>
              <span>操作入口：<el-tag type="primary" size="small">{{ step.entry }}</el-tag></span>
            </div>

            <el-steps :active="step.steps.length" direction="vertical" class="step-list">
              <el-step
                v-for="(s, si) in step.steps"
                :key="si"
                :title="s.title"
                :description="s.desc"
              />
            </el-steps>

            <div v-if="step.tips" class="step-tips">
              <el-alert
                :title="'💡 ' + step.tips"
                type="info"
                :closable="false"
                show-icon
              />
            </div>
          </div>
        </el-collapse-item>
      </el-collapse>
    </el-card>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const activeSteps = ref(0)

const steps = [
  {
    badge: '第一步',
    tagType: '',
    title: '准备基础数据（先录入单位和人员）',
    entry: '参评信息管理 → 导入信息（或 部门管理、用户管理）',
    steps: [
      { title: '进入参评信息管理', desc: '点击左侧菜单「参评信息管理」→「导入信息」' },
      { title: '下载 Excel 模板', desc: '点击「下载模板」按钮，获取标准格式的导入文件' },
      { title: '填写数据', desc: '按模板格式填写单位和人员信息（不要修改表头）' },
      { title: '上传导入', desc: '选择填写好的 Excel 文件，点击「导入」即可自动创建' },
    ],
    tips: '也可以手动添加：先进入「部门管理」添加单位，再进入「用户管理」添加人员。人员需要填写姓名、手机号、职务等信息。',
  },
  {
    badge: '第二步',
    tagType: 'success',
    title: '创建测评模板（设计测评问卷）',
    entry: '测评任务管理 → 模板管理',
    steps: [
      { title: '进入模板管理', desc: '点击左侧菜单「测评任务管理」→「模板管理」' },
      { title: '点击创建模板', desc: '点击「创建模板」按钮' },
      { title: '选择模板类型', desc: '选择「干部民主测评」或「班子民主测评」' },
      { title: '添加指标项', desc: '添加测评题目，可选三种题型：\n• 单选：评价等级（优秀/良好/合格/不合格）\n• 多选：可勾选多项\n• 文本域：填写文字评价' },
      { title: '保存模板', desc: '设置完成后点击「保存」，模板创建完毕' },
    ],
    tips: '建议先设计好纸质测评表，再对照录入系统。模板可以复用，一次创建多次使用。',
  },
  {
    badge: '第三步',
    tagType: 'warning',
    title: '创建测评任务（发布一场测评）',
    entry: '测评任务管理 → 测评任务 → 创建任务',
    steps: [
      { title: '进入测评任务', desc: '点击「测评任务管理」→「测评任务」→「创建任务」' },
      { title: '填写基本信息', desc: '填写任务名称，选择考核周期（如"2026年度"），选择测评类型' },
      { title: '选择模板和单位', desc: '选择第二步创建的模板，以及本次测评针对的单位' },
      { title: '设置时间范围', desc: '设定测评开始和结束日期，到时间系统会自动控制答题开关' },
      { title: '设置权重（可选）', desc: '如需区分不同用户群体的评分权重，可选择「自定义权重」模式' },
      { title: '保存任务', desc: '点击「保存」，任务创建成功，此时状态为「草稿」' },
    ],
    tips: '创建后任务默认为"草稿"状态，此时可以编辑修改。确认无误后再激活。',
  },
  {
    badge: '第四步',
    tagType: 'primary',
    title: '分配参评人员（让谁来评、评谁）',
    entry: '测评任务管理 → 测评任务 → 选择任务 → 分配人员',
    steps: [
      { title: '进入任务详情', desc: '在测评任务列表中找到刚创建的任务，点击「编辑」或「分配人员」' },
      { title: '分配参评人员', desc: '选择哪些用户参与本次测评，可从单位中批量选择' },
      { title: '添加测评对象', desc: '添加本次要测评的干部或班子名单（被评人）' },
      { title: '确认分配', desc: '保存后，参评人员的手机端就会出现待办测评任务' },
    ],
    tips: '参评人员是"谁去打分"，测评对象是"打给谁的分"，两者不同。可以分别从用户列表和目标人群中添加。',
  },
  {
    badge: '第五步',
    tagType: 'danger',
    title: '执行测评（从开始到结束）',
    entry: '测评任务管理 → 测评任务 → 激活 / 归档',
    steps: [
      { title: '激活任务', desc: '确认数据无误后，在任务列表点击「激活」。\n激活后任务状态变为「进行中」，参评人员的手机端就能看到并开始答题' },
      { title: '等待答题', desc: '参评人员在手机上逐人逐题打分，可随时保存，一键提交' },
      { title: '监控进度', desc: '在工作台可查看各任务的完成进度' },
      { title: '结束任务', desc: '答题时间到或所有人完成后，点击「结束」按钮，任务状态变为「已结束」' },
      { title: '归档数据', desc: '当该考核周期下所有任务都结束后，进入「测评归档」页面，一键归档全部数据' },
    ],
    tips: '激活后任务不可编辑。如果参评人员丢失答题入口，可让他们重新登录手机端查看待办。',
  },
  {
    badge: '第六步',
    tagType: 'info',
    title: '查看结果（统计分析）',
    entry: '数据分析 → 数据分析',
    steps: [
      { title: '进入数据分析', desc: '点击左侧菜单「数据分析」' },
      { title: '选择任务', desc: '在列表中找到要查看的任务' },
      { title: '查看统计', desc: '可以查看整体统计、按单位统计、每个对象的得分详情' },
      { title: '导出 Excel', desc: '点击「导出」按钮，可将测评结果导出为 Excel 表格' },
    ],
    tips: '支持批量导出多个任务的统计数据，方便汇总报送。',
  },
]
</script>

<style lang="scss" scoped>
.guide-page {
  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;

    .title {
      font-size: 18px;
      font-weight: 600;
    }
  }

  .guide-intro {
    margin-bottom: 20px;
    padding: 12px 16px;
    background: #ecf5ff;
    border-radius: 8px;
    border-left: 4px solid #409eff;

    p {
      margin: 0;
      color: #303133;
      font-size: 14px;
      line-height: 1.6;
    }
  }

  .step-title {
    display: flex;
    align-items: center;
    gap: 10px;

    .step-badge {
      flex-shrink: 0;
    }

    .step-label {
      font-size: 15px;
      font-weight: 500;
    }
  }

  .step-body {
    padding: 8px 4px 12px 4px;
  }

  .step-entry {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 16px;
    padding: 8px 12px;
    background: #EFF3F8;
    border-radius: 6px;
    font-size: 13px;
    color: #606266;
  }

  .step-list {
    margin-bottom: 12px;
  }

  .step-tips {
    margin-top: 8px;
  }
}

:deep(.el-collapse-item__header) {
  padding: 4px 0;
}

:deep(.el-step__description) {
  white-space: pre-line;
  font-size: 13px !important;
  line-height: 1.5 !important;
}
</style>