<template>
  <div class="examine-form">
    <el-page-header @back="$router.push('/examines')" content="测评任务" />

    <!-- ====== 创建模式：三步走 ====== -->
    <template v-if="!isEdit">
      <el-steps :active="currentStep" align-center class="mt-4 mb-4" finish-status="success" process-status="process">
        <el-step v-for="(step, idx) in steps" :key="idx" :title="step.title" />
      </el-steps>

      <!-- Step 1: 填写测评信息 -->
      <el-card v-show="currentStep === 0">
        <template #header>
          <span>填写测评信息</span>
          <el-tag type="info" size="small">Step 1 / 3</el-tag>
        </template>

        <el-form ref="formRef" :model="form" :rules="rules" label-width="120px">
          <el-form-item label="任务名称" prop="examine_name">
            <el-input v-model="form.examine_name" placeholder="请输入任务名称" />
          </el-form-item>

          <el-form-item label="考核周期" prop="periodType" required>
            <div class="period-combo">
              <el-input-number v-model="form.periodYear" :min="2000" :max="2099" style="width: 130px" placeholder="年份" />
              <el-select v-model="form.periodType" placeholder="周期类型" style="width: 140px; margin-left: 8px">
                <el-option label="年度" value="year" />
                <el-option v-for="(label, idx) in quarterLabels" :key="idx" :label="label" :value="label" />
              </el-select>
            </div>
          </el-form-item>

          <el-form-item label="测评类型" prop="examine_type">
            <el-select v-model="form.examine_type" placeholder="请选择测评类型" @change="onExamineTypeChange">
              <el-option label="干部民主测评" value="leader" />
              <el-option label="班子民主测评" value="team" />
            </el-select>
          </el-form-item>

          <el-form-item label="所属部门" prop="unit_id">
            <el-select v-model="form.unit_id" placeholder="请选择部门" :loading="unitLoading" filterable @change="onUnitChange">
              <el-option v-for="u in units" :key="u.id" :label="u.unit_name" :value="u.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="选用模板" prop="template_id">
            <el-select v-model="form.template_id" placeholder="请先选择测评类型" :loading="templateLoading" :disabled="!form.examine_type">
              <el-option v-for="t in templates" :key="t.id" :label="t.template_name" :value="t.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="开始时间" prop="start_time">
            <el-date-picker v-model="form.start_time" type="datetime" placeholder="选择开始时间" />
          </el-form-item>

          <el-form-item label="结束时间" prop="end_time">
            <el-date-picker v-model="form.end_time" type="datetime" placeholder="选择结束时间" />
          </el-form-item>
        </el-form>

        <div class="step-actions">
          <el-button type="primary" @click="nextStep">下一步 →</el-button>
          <el-button @click="$router.push('/examines')">取消</el-button>
        </div>
      </el-card>

      <!-- Step 2: 添加被测评对象 -->
      <el-card v-show="currentStep === 1">
        <template #header>
          <span>添加被测评对象</span>
          <el-tag type="primary" size="small">Step 2 / 3</el-tag>
        </template>

        <div class="tab-toolbar">
          <span class="tab-hint">被测评的人 / 班子（如：张三局长、公安局班子）</span>
          <div class="tab-actions">
            <el-button type="danger" size="small" @click="handleBatchRemoveTargets" :disabled="selectedTargetIds.length === 0">
              批量删除（{{ selectedTargetIds.length }}）
            </el-button>
            <el-button type="primary" size="small" @click="showAddTargetDialog = true">+ 添加</el-button>
            <el-button type="success" size="small" @click="openBatchTargetDialog">📋 批量粘贴</el-button>
            <el-upload :auto-upload="false" :show-file-list="false" accept=".xlsx,.xls" :on-change="handleTargetExcelUpload">
              <el-button type="warning" size="small">📤 导入Excel</el-button>
            </el-upload>
            <el-button type="info" size="small" plain @click="downloadTargetTemplate">📥 下载模板</el-button>
          </div>
        </div>

        <el-table ref="targetTableRef" :data="targets" style="width: 100%" v-loading="targetsLoading" border stripe empty-text="暂无测评对象，请点击上方按钮添加" size="small" @selection-change="onTargetSelectionChange">
          <el-table-column type="selection" width="45" />
          <el-table-column prop="target_name" label="姓名/名称" width="150" />
          <el-table-column prop="target_type" label="类型" width="90">
            <template #default="{ row }">
              <el-tag :type="row.target_type === 'leader' ? '' : 'success'" size="small">{{ row.target_type === 'leader' ? '干部' : '班子' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="position" label="职务" width="130" />
          <el-table-column prop="unit_name" label="单位" min-width="130" />
          <el-table-column label="操作" width="70" fixed="right">
            <template #default="{ row }">
              <el-button type="danger" link size="small" @click="handleRemoveTarget(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <div class="step-actions">
          <el-button @click="prevStep">← 上一步</el-button>
          <el-button type="primary" @click="nextStep">下一步 →</el-button>
        </div>
      </el-card>

      <!-- Step 3: 添加参评人员 -->
      <el-card v-show="currentStep === 2">
        <template #header>
          <span>添加参评人员</span>
          <el-tag type="success" size="small">Step 3 / 3</el-tag>
        </template>

        <div class="tab-toolbar">
          <span class="tab-hint">参与打分评价的用户</span>
          <div class="tab-actions">
            <el-button type="danger" size="small" @click="handleBatchRemove" :disabled="selectedUserIds.length === 0">
              批量移除（{{ selectedUserIds.length }}）
            </el-button>
            <el-button type="primary" size="small" @click="showAddUserDialog = true">
              + 添加用户
            </el-button>
          </div>
        </div>

        <el-table ref="userTableRef" :data="assignedUsers" style="width: 100%" v-loading="usersLoading" border stripe empty-text="暂无参评人员，请点击上方按钮添加" size="small" @selection-change="onUserSelectionChange">
          <el-table-column type="selection" width="45" />
          <el-table-column prop="name" label="姓名" width="90" />
          <el-table-column prop="phone" label="手机号" width="130" />
          <el-table-column prop="position" label="职务" width="120" />
          <el-table-column prop="unit_name" label="所属单位" min-width="130" />
          <el-table-column label="来源" width="80">
            <template #default="{ row }">
              <el-tag :type="row.source === 'admin' ? '' : 'success'" size="small">{{ row.source === 'registered' ? '已注册' : '后台' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="status" label="状态" width="80">
            <template #default="{ row }">
              <el-tag :type="statusMap[row.status]?.type || 'info'" size="small">{{ statusMap[row.status]?.label || row.status }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="60" fixed="right">
            <template #default="{ row }">
              <el-button type="danger" link size="small" @click="handleRemoveUser(row)">移除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <div class="step-actions">
          <el-button @click="prevStep">← 上一步</el-button>
          <el-button type="primary" @click="handleSubmit" :loading="submitting">✓ 创建任务</el-button>
        </div>
      </el-card>
    </template>

    <!-- ====== 编辑模式：原有单页布局不变 ====== -->
    <template v-else>
      <el-card class="mt-4">
        <template #header>
          <div class="card-header">
            <span>编辑测评任务</span>
            <el-tag v-if="isEdit && form.status" :type="form.status === 'active' ? '' : form.status === 'finished' ? 'success' : 'info'" size="small">
              {{ form.status === 'active' ? '进行中' : form.status === 'finished' ? '已结束' : '草稿' }}
            </el-tag>
          </div>
        </template>

        <el-form ref="formRef" :model="form" :rules="rules" label-width="120px">
          <el-form-item label="任务名称" prop="examine_name">
            <el-input v-model="form.examine_name" placeholder="请输入任务名称" :disabled="!canEditCoreInfo" />
          </el-form-item>

          <el-form-item label="考核周期" prop="periodType" required>
            <div class="period-combo">
              <el-input-number v-model="form.periodYear" :min="2000" :max="2099" style="width: 130px" placeholder="年份" />
              <el-select v-model="form.periodType" placeholder="周期类型" style="width: 140px; margin-left: 8px">
                <el-option label="年度" value="year" />
                <el-option v-for="(label, idx) in quarterLabels" :key="idx" :label="label" :value="label" />
              </el-select>
            </div>
          </el-form-item>

          <el-form-item label="测评类型" prop="examine_type">
            <el-select v-model="form.examine_type" placeholder="请选择测评类型" @change="onExamineTypeChange" :disabled="!canEditCoreInfo">
              <el-option label="干部民主测评" value="leader" />
              <el-option label="班子民主测评" value="team" />
            </el-select>
          </el-form-item>

          <el-form-item label="所属部门" prop="unit_id">
            <el-select v-model="form.unit_id" placeholder="请选择部门" :loading="unitLoading" filterable @change="onUnitChange" :disabled="!canEditCoreInfo">
              <el-option v-for="u in units" :key="u.id" :label="u.unit_name" :value="u.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="选用模板" prop="template_id">
            <el-select v-model="form.template_id" placeholder="请先选择测评类型" :loading="templateLoading" :disabled="!canEditCoreInfo || !form.examine_type">
              <el-option v-for="t in templates" :key="t.id" :label="t.template_name" :value="t.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="开始时间" prop="start_time">
            <el-date-picker v-model="form.start_time" type="datetime" placeholder="选择开始时间" :disabled="!canEditTime" />
          </el-form-item>

          <el-form-item label="结束时间" prop="end_time">
            <el-date-picker v-model="form.end_time" type="datetime" placeholder="选择结束时间" :disabled="!canEditTime" />
          </el-form-item>

          <el-form-item>
            <el-button v-if="canEditCoreInfo" type="primary" @click="handleSubmit" :loading="submitting">
              更新
            </el-button>
            <template v-else-if="isEdit && form.status === 'active'">
              <el-button type="warning" @click="handleUpdateTime" :loading="submitting">
                保存时间设置
              </el-button>
              <span class="active-hint">任务进行中，可修改测评时间和参评人员</span>
            </template>
            <el-button v-else-if="isEdit && form.status === 'finished'" type="info" disabled>
              任务已结束
            </el-button>
            <el-button @click="$router.push('/examines')">返回列表</el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <!-- 测评配置区域（编辑模式） -->
      <el-card class="mt-4">
        <template #header>
          <div class="card-header">
            <span>测评配置</span>
          </div>
        </template>

        <el-tabs v-model="activeConfigTab" type="border-card">
          <!-- Tab 1: 测评对象 -->
          <el-tab-pane name="targets">
            <template #label>
              <span>📋 测评对象 <el-badge :value="targets.length" :max="99" type="primary" /></span>
            </template>

            <div class="tab-toolbar">
              <span class="tab-hint">被测评的人 / 班子（如：张三局长、公安局班子）</span>
              <div class="tab-actions">
                <el-button type="danger" size="small" @click="handleBatchRemoveTargets" :disabled="selectedTargetIds.length === 0 || canEditUsers === false">
                  批量删除（{{ selectedTargetIds.length }}）
                </el-button>
                <el-button type="primary" size="small" @click="showAddTargetDialog = true" :disabled="canEditUsers === false">+ 添加</el-button>
                <el-button type="success" size="small" @click="openBatchTargetDialog" :disabled="canEditUsers === false">📋 批量粘贴</el-button>
                <el-upload :auto-upload="false" :show-file-list="false" accept=".xlsx,.xls" :on-change="handleTargetExcelUpload">
                  <el-button type="warning" size="small" :disabled="canEditUsers === false">📤 导入Excel</el-button>
                </el-upload>
                <el-button type="info" size="small" plain @click="downloadTargetTemplate">📥 下载模板</el-button>
              </div>
            </div>

            <el-table ref="targetTableRef" :data="targets" style="width: 100%" v-loading="targetsLoading" border stripe empty-text="暂无测评对象，请点击上方按钮添加" size="small" @selection-change="onTargetSelectionChange">
              <el-table-column type="selection" width="45" />
              <el-table-column prop="target_name" label="姓名/名称" width="150" />
              <el-table-column prop="target_type" label="类型" width="90">
                <template #default="{ row }">
                  <el-tag :type="row.target_type === 'leader' ? '' : 'success'" size="small">{{ row.target_type === 'leader' ? '干部' : '班子' }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="position" label="职务" width="130" />
              <el-table-column prop="unit_name" label="单位" min-width="130" />
              <el-table-column label="操作" width="70" fixed="right">
                <template #default="{ row }">
                  <el-button type="danger" link size="small" :disabled="canEditUsers === false" @click="handleRemoveTarget(row)">删除</el-button>
                </template>
              </el-table-column>
            </el-table>
          </el-tab-pane>

          <!-- Tab 2: 参与测评用户 -->
          <el-tab-pane name="users">
            <template #label>
              <span>👥 参评人员 <el-badge :value="assignedUsers.length" :max="99" type="success" /></span>
            </template>

            <div class="tab-toolbar">
              <span class="tab-hint">参与打分评价的用户</span>
              <div class="tab-actions">
                <el-button type="danger" size="small" @click="handleBatchRemove" :disabled="selectedUserIds.length === 0 || canEditUsers === false">
                  批量移除（{{ selectedUserIds.length }}）
                </el-button>
                <el-button type="primary" size="small" @click="showAddUserDialog = true" :disabled="canEditUsers === false">
                  + 添加用户
                </el-button>
              </div>
            </div>

            <el-table ref="userTableRef" :data="assignedUsers" style="width: 100%" v-loading="usersLoading" border stripe empty-text="暂无参评人员，请点击上方按钮添加" size="small" @selection-change="onUserSelectionChange">
              <el-table-column type="selection" width="45" />
              <el-table-column prop="name" label="姓名" width="90" />
              <el-table-column prop="phone" label="手机号" width="130" />
              <el-table-column prop="position" label="职务" width="120" />
              <el-table-column prop="unit_name" label="所属单位" min-width="130" />
              <el-table-column label="来源" width="80">
                <template #default="{ row }">
                  <el-tag :type="row.source === 'admin' ? '' : 'success'" size="small">{{ row.source === 'registered' ? '已注册' : '后台' }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="status" label="状态" width="80">
                <template #default="{ row }">
                  <el-tag :type="statusMap[row.status]?.type || 'info'" size="small">{{ statusMap[row.status]?.label || row.status }}</el-tag>
                </template>
              </el-table-column>
              <el-table-column label="操作" width="60" fixed="right">
                <template #default="{ row }">
                  <el-button type="danger" link size="small" :disabled="canEditUsers === false" @click="handleRemoveUser(row)">移除</el-button>
                </template>
              </el-table-column>
            </el-table>
          </el-tab-pane>
        </el-tabs>
      </el-card>
    </template>

    <!-- 添加测评对象对话框 -->
    <el-dialog v-model="showAddTargetDialog" title="添加测评对象" width="600px" destroy-on-close>
      <el-form ref="targetFormRef" :model="targetForm" :rules="targetRules" label-width="100px">
        <el-form-item label="对象类型" prop="target_type">
          <el-radio-group v-model="targetForm.target_type">
            <el-radio value="leader">干部</el-radio>
            <el-radio value="team">班子</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="姓名/名称" prop="target_name">
          <el-input v-model="targetForm.target_name" placeholder="请输入姓名或班子名称" />
        </el-form-item>
        <el-form-item v-if="targetForm.target_type === 'leader'" label="职务" prop="position">
          <el-input v-model="targetForm.position" placeholder="请输入职务（干部必填）" />
        </el-form-item>
        <el-form-item label="单位名称" prop="unit_name">
          <el-input v-model="targetForm.unit_name" placeholder="请输入单位名称（可选）" />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="showAddTargetDialog = false">取消</el-button>
        <el-button type="primary" :loading="savingTargets" @click="handleSaveTarget">确定添加</el-button>
      </template>
    </el-dialog>

    <!-- 添加用户对话框 -->
    <el-dialog
      v-model="showAddUserDialog"
      title="添加参评用户"
      width="750px"
      destroy-on-close
    >
      <div class="mb-3 dialog-toolbar">
        <el-select
          v-model="addUserUnitFilter"
          placeholder="选择部门筛选"
          clearable
          style="width: 200px"
          @change="fetchAvailableUsers"
        >
          <el-option
            v-for="u in allUnits"
            :key="u.id"
            :label="u.unit_name"
            :value="u.id"
          />
        </el-select>
        <el-input
          v-model="userSearchKeyword"
          placeholder="搜索姓名或手机号..."
          clearable
          style="width: 260px; margin-left: 12px"
          prefix-icon="Search"
        />
        <span class="ml-2 text-muted">
          共 {{ availableUsers.length }} 人，可选 {{ selectableCount }} 人
        </span>
      </div>

      <el-table
        ref="addUserTableRef"
        :data="filteredAvailableUsers"
        style="width: 100%"
        v-loading="availableUsersLoading"
        max-height="400"
        border
        stripe
        empty-text="该部门下没有可添加的用户"
        @selection-change="onAddUserSelectionChange"
        :row-class-name="addUserRowClassName"
      >
        <el-table-column type="selection" width="50" :selectable="(row) => !row.selected" />
        <el-table-column prop="name" label="姓名" width="100" />
        <el-table-column prop="phone" label="手机号" width="140" />
        <el-table-column prop="position" label="职务" min-width="120" />
        <el-table-column prop="unit_name" label="所属单位" width="120">
          <template #default="{ row }">
            {{ getUnitName(row.unit_id) }}
          </template>
        </el-table-column>
        <el-table-column prop="user_type" label="类型" width="70">
          <template #default="{ row }">
            <el-tag size="small">{{ row.user_type === 'A' ? 'A类' : 'B类' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="来源" width="80">
          <template #default="{ row }">
            <el-tag :type="row.source === 'admin' ? '' : 'success'" size="small">
              {{ row.source === 'registered' ? '已注册' : '后台' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag v-if="row.selected" type="info" size="small">已添加</el-tag>
            <span v-else class="text-muted">可添加</span>
          </template>
        </el-table-column>
      </el-table>

      <template #footer>
        <div class="dialog-footer">
          <span class="text-muted mr-2">已选 {{ selectedAddUserIds.length }} 人</span>
          <el-button @click="showAddUserDialog = false">取消</el-button>
          <el-button type="primary" @click="handleAddUsers" :disabled="selectedAddUserIds.length === 0" :loading="addingUsers">
            确定添加 ({{ selectedAddUserIds.length }})
          </el-button>
        </div>
      </template>
    </el-dialog>
    <UpgradeDialog ref="upgradeDialog" />
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElLoading, ElMessageBox } from 'element-plus'
import dayjs from 'dayjs'
import request from '@/api/request'
import { useEditionStore } from '@/stores/edition'
import UpgradeDialog from '@/components/UpgradeDialog.vue'

const route = useRoute()
const router = useRouter()
const editionStore = useEditionStore()
const upgradeDialog = ref(null)
const formRef = ref()
const submitting = ref(false)
const templateLoading = ref(false)
const unitLoading = ref(false)

const isEdit = !!route.params.id
const examineId = route.params.id

// 用户管理相关状态
const showAddUserDialog = ref(false)
const usersLoading = ref(false)
const availableUsersLoading = ref(false)
const addingUsers = ref(false)
const removingUsers = ref(false)
const userSearchKeyword = ref('')
const selectedAddUserIds = ref([])
const selectedUserIds = ref([])
const addUserTableRef = ref()
const userTableRef = ref()
const addUserUnitFilter = ref(null)

const assignedUsers = ref([])
const availableUsers = ref([])
const allUnits = ref([])

// 测评对象管理相关状态
const activeConfigTab = ref('targets')
const targets = ref([])
const selectedTargetIds = ref([])
const targetsLoading = ref(false)
const savingTargets = ref(false)
const showAddTargetDialog = ref(false)
const targetTableRef = ref()
const targetFormRef = ref()
const targetForm = reactive({
  target_type: 'leader',
  target_name: '',
  position: '',
  unit_name: '',
})
const targetRules = {
  target_type: [{ required: true, message: '请选择对象类型', trigger: 'change' }],
  target_name: [{ required: true, message: '请输入姓名或名称', trigger: 'blur' }],
}

// 批量粘贴相关（企业版功能，CE 仅弹升级提示）

// Excel 上传相关（企业版功能，CE 仅弹升级提示）

const canEditUsers = computed(() => {
  return true
})

// 核心信息（名称、类型、部门、模板）：激活后始终锁定
const canEditCoreInfo = computed(() => {
  return !isEdit || form.status !== 'active'
})

// 时间信息（开始/结束时间）：激活后仍可修改
const canEditTime = computed(() => {
  return true
})

// 三步走相关
const currentStep = ref(0)
const steps = [
  { title: '填写信息' },
  { title: '添加对象' },
  { title: '添加用户' },
]

const nextStep = async () => {
  if (currentStep.value === 0) {
    try {
      await formRef.value.validate()
    } catch {
      ElMessage.warning('请先完善测评基本信息')
      return
    }
  }
  currentStep.value++
}

const prevStep = () => {
  if (currentStep.value > 0) currentStep.value--
}

const selectableCount = computed(() => {
  return filteredAvailableUsers.value.filter(u => !u.selected).length
})

const statusMap = {
  pending: { label: '待测评', type: 'warning' },
  in_progress: { label: '测评中', type: 'primary' },
  completed: { label: '已完成', type: 'success' },
}

const filteredAvailableUsers = computed(() => {
  const keyword = userSearchKeyword.value.trim().toLowerCase()
  let list = availableUsers.value
  if (!keyword) return list
  return list.filter(u =>
    (u.name && u.name.toLowerCase().includes(keyword)) ||
    (u.phone && u.phone.includes(keyword))
  )
})

const getUnitName = (unitId) => {
  if (!unitId) return ''
  const unit = allUnits.value.find(u => u.id === unitId)
  return unit ? unit.unit_name : ''
}

const addUserRowClassName = ({ row }) => {
  return row.selected ? 'user-row-selected' : ''
}

const form = reactive({
  examine_name: '',
  period: '',
  periodYear: null,
  periodType: '',
  examine_type: '',
  unit_id: null,
  template_id: null,
  start_time: null,
  end_time: null,
  weight_mode: 'equal',
  weight_a: 1.0,
  weight_b: 1.0,
  status: ''
})

const templates = ref([])
const units = ref([])

const rules = {
  examine_name: [{ required: true, message: '请输入任务名称', trigger: 'blur' }],
  periodType: [{ required: true, message: '请选择考核周期', trigger: 'change' }],
  examine_type: [{ required: true, message: '请选择测评类型', trigger: 'change' }],
  unit_id: [{ required: true, message: '请选择所属部门', trigger: 'change' }],
  template_id: [{ required: true, message: '请选择模板', trigger: 'change' }],
  start_time: [{ required: true, message: '请选择开始时间', trigger: 'change' }],
  end_time: [{ required: true, message: '请选择结束时间', trigger: 'change' }]
}

const quarterLabels = ['第一季度', '第二季度', '第三季度', '第四季度']

const initPeriodDefaults = () => {
  const now = dayjs()
  if (!form.periodYear) form.periodYear = now.year()
  if (!form.periodType) {
    form.periodType = now.month() === 0 ? 'year' : quarterLabels[Math.floor(now.month() / 3)]
  }
}

watch(() => [form.periodYear, form.periodType], ([year, type]) => {
  if (year && type) {
    form.period = type === 'year' ? `${year}年度` : `${year}年${type}`
  }
})

const parsePeriod = (period) => {
  if (!period) return
  const match = period.match(/^(\d{4})年(.+)$/)
  if (match) {
    form.periodYear = parseInt(match[1])
    form.periodType = match[2] === '度' ? 'year' : match[2]
  } else if (/^\d{4}年度$/.test(period)) {
    form.periodYear = parseInt(period)
    form.periodType = 'year'
  }
}

const fetchTemplates = async (type) => {
  templateLoading.value = true
  try {
    const params = type ? { type } : {}
    const res = await request.get('/templates', { params })
    templates.value = res.data.data || []
  } catch (error) {
    console.error('fetchTemplates error:', error)
  } finally {
    templateLoading.value = false
  }
}

const fetchUnits = async () => {
  unitLoading.value = true
  try {
    const res = await request.get('/units', { params: { per_page: 999 } })
    units.value = res.data.data?.data || res.data.data || []
  } catch (error) {
    console.error('fetchUnits error:', error)
  } finally {
    unitLoading.value = false
  }
}

const fetchExamine = async (id) => {
  const loadingInstance = ElLoading.service({ text: '加载中...' })
  try {
    const res = await request.get(`/examines/${id}`)
    const data = res.data || res
    
    if (!data || !data.examine_name) {
      throw new Error('获取的数据无效')
    }
    
    form.examine_name = data.examine_name || ''
    parsePeriod(data.period || '')
    form.examine_type = data.template_type || ''
    form.unit_id = data.unit_id ? Number(data.unit_id) : null
    form.template_id = data.template_id ? Number(data.template_id) : null
    form.start_time = data.start_time ? new Date(data.start_time) : null
    form.end_time = data.end_time ? new Date(data.end_time) : null
    form.weight_mode = data.weight_mode || 'equal'
    form.weight_a = data.weight_a ? Number(data.weight_a) : 1.0
    form.weight_b = data.weight_b ? Number(data.weight_b) : 1.0
    form.status = data.status || ''
    
    if (data.template_type) {
      await fetchTemplates(data.template_type)
    }
    
    ElMessage.success('数据加载成功')
  } catch (error) {
    console.error('fetchExamine error:', error)
    ElMessage.error('加载数据失败: ' + (error.message || '未知错误'))
  } finally {
    loadingInstance.close()
  }
}

const onExamineTypeChange = (val) => {
  form.template_id = null
  if (val) {
    fetchTemplates(val)
  } else {
    templates.value = []
  }
}

const onUnitChange = () => {
  if (isEdit && form.unit_id) {
    fetchAssignedUsers()
  }
}

const handleSubmit = async () => {
  if (!formRef.value) return

  try {
    await formRef.value.validate()
  } catch { return }

  submitting.value = true
  try {
    if (isEdit) {
      await request.put(`/examines/${examineId}`, {
        examine_name: form.examine_name,
        period: form.period,
        examine_type: form.examine_type,
        unit_id: form.unit_id,
        template_id: form.template_id,
        start_time: form.start_time ? dayjs(form.start_time).format('YYYY-MM-DD HH:mm:ss') : null,
        end_time: form.end_time ? dayjs(form.end_time).format('YYYY-MM-DD HH:mm:ss') : null,
        weight_mode: form.weight_mode,
        weight_a: Number(form.weight_a),
        weight_b: Number(form.weight_b),
      })

      ElMessage.success('更新成功')

      try {
        const detail = await request.get(`/examines/${examineId}`)
        if (detail.data?.status === 'draft' && targets.value.length > 0 && assignedUsers.value.length > 0) {
          await request.post(`/examines/${examineId}/activate`)
          ElMessage.success('任务已自动激活，用户可以开始答题')
        }
      } catch {}

      router.push('/examines')

    } else {
      let newId = null

      if (newId) {
        await request.put(`/examines/${newId}`, {
          examine_name: form.examine_name || '（未命名测评任务）',
          period: form.period,
          examine_type: form.examine_type || 'leader',
          unit_id: form.unit_id,
          template_id: form.template_id,
          start_time: form.start_time ? dayjs(form.start_time).format('YYYY-MM-DD HH:mm:ss') : null,
          end_time: form.end_time ? dayjs(form.end_time).format('YYYY-MM-DD HH:mm:ss') : null,
          weight_mode: form.weight_mode,
          weight_a: Number(form.weight_a),
          weight_b: Number(form.weight_b),
        })
      } else {
        const res = await request.post('/examines', {
          examine_name: form.examine_name,
          period: form.period,
          examine_type: form.examine_type,
          unit_id: form.unit_id,
          template_id: form.template_id,
          start_time: form.start_time ? dayjs(form.start_time).format('YYYY-MM-DD HH:mm:ss') : null,
          end_time: form.end_time ? dayjs(form.end_time).format('YYYY-MM-DD HH:mm:ss') : null,
          weight_mode: form.weight_mode,
          weight_a: Number(form.weight_a),
          weight_b: Number(form.weight_b),
        })

        newId = res.data.id || res.data.data?.id
      }

      if (targets.value.length === 0 && assignedUsers.value.length === 0) {
        ElMessage({
          message: '任务已创建成功，但尚未添加测评对象和参评人员，建议进入编辑页补充配置',
          type: 'warning',
          duration: 4000,
          showClose: true,
        })
        router.push('/examines')
        return
      }

      if (targets.value.length > 0) {
        await request.post(`/examines/${newId}/targets`, {
          targets: targets.value.map(t => ({
            target_type: t.target_type,
            target_name: t.target_name || t.target_name,
            position: t.position,
            unit_name: t.unit_name,
          }))
        })
      }

      if (assignedUsers.value.length > 0) {
        for (const user of assignedUsers.value) {
          await request.post(`/examines/${newId}/users/add`, { user_id: user.id })
        }
      }

      if (targets.value.length > 0 && assignedUsers.value.length > 0) {
        try {
          await request.post(`/examines/${newId}/activate`)
          ElMessage.success(`创建成功并已自动激活（${targets.value.length}个对象，${assignedUsers.value.length}人参评），用户可以开始答题`)
        } catch (actErr) {
          const actMsg = actErr?.response?.data?.message || '自动激活失败'
          ElMessage.warning(`任务已保存（草稿），${actMsg}。可在列表页手动激活`)
        }
      } else {
        const warnings = []
        if (targets.value.length === 0) warnings.push('测评对象')
        if (assignedUsers.value.length === 0) warnings.push('参评人员')
        ElMessage({
          message: `任务已创建成功${warnings.length ? `，但未配置：${warnings.join('、')}` : ''}。请进入编辑页补充配置后激活`,
          type: warnings.length > 0 ? 'warning' : 'success',
          duration: 4000,
          showClose: true,
        })
      }
      router.push('/examines')
    }

  } catch (error) {
    console.error('submit error:', error)
    ElMessage.error(error?.response?.data?.message || '操作失败')
  } finally {
    submitting.value = false
  }
}

const handleUpdateTime = async () => {
  if (!examineId) {
    ElMessage.error('任务ID缺失，无法保存')
    return
  }
  if (!form.start_time || !form.end_time) {
    ElMessage.warning('请设置完整的测评时间')
    return
  }
  const startTime = new Date(form.start_time)
  const endTime = new Date(form.end_time)
  if (isNaN(startTime.getTime()) || isNaN(endTime.getTime())) {
    ElMessage.error('时间格式无效')
    return
  }
  if (startTime >= endTime) {
    ElMessage.error('开始时间必须早于结束时间')
    return
  }
  submitting.value = true
  try {
    const res = await request.put(`/examines/${examineId}`, {
      start_time: dayjs(form.start_time).format('YYYY-MM-DD HH:mm:ss'),
      end_time: dayjs(form.end_time).format('YYYY-MM-DD HH:mm:ss'),
    })
    ElMessage.success('测评时间已更新')
  } catch (error) {
    console.error('update time error:', error)
    const msg = error?.response?.data?.message || error?.message || '保存失败，请重试'
    ElMessage.error(msg)
  } finally {
    submitting.value = false
  }
}

// ====== 测评对象管理相关方法 ======

const fetchTargets = async () => {
  if (!isEdit || !examineId) return
  targetsLoading.value = true
  try {
    const res = await request.get(`/examines/${examineId}`)
    const data = res.data.data || res.data
    targets.value = data.targets || []
  } catch (error) {
    console.error('fetchTargets error:', error)
  } finally {
    targetsLoading.value = false
  }
}

const handleSaveTarget = async () => {
  if (!targetFormRef.value) return

  try {
    await targetFormRef.value.validate()
  } catch { return }

  const newTarget = {
    target_type: targetForm.target_type,
    target_name: targetForm.target_name,
    position: targetForm.position,
    unit_name: targetForm.unit_name,
  }

  if (!isEdit) {
    targets.value.push(newTarget)
    ElMessage.success('测评对象添加成功')
    showAddTargetDialog.value = false
    targetForm.target_name = ''
    targetForm.position = ''
    targetForm.unit_name = ''
    return
  }

  savingTargets.value = true
  try {
    const newTargets = [...targets.value, newTarget]
    await request.post(`/examines/${examineId}/targets/batch`, { targets: newTargets })
    ElMessage.success('测评对象添加成功')
    showAddTargetDialog.value = false
    targetForm.target_name = ''
    targetForm.position = ''
    targetForm.unit_name = ''
    await fetchTargets()
  } catch (error) {
    console.error('save target error:', error)
    ElMessage.error(error?.response?.data?.message || '保存失败')
  } finally {
    savingTargets.value = false
  }
}

const handleRemoveTarget = async (row) => {
  try {
    await ElMessageBox.confirm(
      `确定要删除测评对象「${row.target_name}」吗？`,
      '确认删除',
      { confirmButtonText: '确定删除', cancelButtonText: '取消', type: 'warning' }
    )
  } catch { return }

  if (!isEdit) {
    targets.value = targets.value.filter(t => t !== row && t.id !== row.id)
    ElMessage.success(`已删除测评对象「${row.target_name}」`)
    return
  }

  const updatedTargets = targets.value.filter(t => t.id !== row.id)

  savingTargets.value = true
  try {
    await request.post(`/examines/${examineId}/targets/batch`, { targets: updatedTargets })
    ElMessage.success(`已删除测评对象「${row.target_name}」`)
    await fetchTargets()
  } catch (error) {
    console.error('remove target error:', error)
    ElMessage.error('删除失败')
  } finally {
    savingTargets.value = false
  }
}

const onTargetSelectionChange = (rows) => {
  selectedTargetIds.value = rows.map(r => r.id || r._idx)
}

const handleBatchRemoveTargets = async () => {
  upgradeDialog.value?.open()
}

// ====== 批量粘贴相关方法 ======

function openBatchTargetDialog() {
  upgradeDialog.value?.open()
}

// ====== Excel 上传相关方法 ======

const downloadTargetTemplate = async () => {
  upgradeDialog.value?.open()
}

const handleTargetExcelUpload = async () => {
  upgradeDialog.value?.open()
}

// ====== 用户管理相关方法 ======

const fetchAssignedUsers = async () => {
  if (!isEdit || !examineId) return
  usersLoading.value = true
  try {
    const res = await request.get(`/examines/${examineId}/users`)
    const data = res.data.data || res.data
    assignedUsers.value = data.data || data || []
  } catch (error) {
    console.error('fetchAssignedUsers error:', error)
  } finally {
    usersLoading.value = false
  }
}

const fetchAvailableUsers = async () => {
  availableUsersLoading.value = true
  try {
    if (isEdit && examineId) {
      const params = {}
      if (addUserUnitFilter.value) {
        params.unit_id = addUserUnitFilter.value
      }
      const res = await request.get(`/examines/${examineId}/available-users`, { params })
      const data = res.data.data || res.data
      availableUsers.value = data.users || []
      allUnits.value = data.all_units || units.value
      if (!addUserUnitFilter.value && data.current_unit_id) {
        addUserUnitFilter.value = data.current_unit_id
      }
    } else {
      const params = { per_page: 9999 }
      if (addUserUnitFilter.value) {
        params.unit_id = addUserUnitFilter.value
      }
      const res = await request.get('/users', { params })
      availableUsers.value = res.data?.data || []
      if (allUnits.value.length === 0) {
        allUnits.value = units.value.length > 0 ? units.value : []
      }
    }
  } catch (error) {
    console.error('fetchAvailableUsers error:', error)
  } finally {
    availableUsersLoading.value = false
  }
}

const filterAvailableUsers = () => {
  // computed 已处理
}

const onAddUserSelectionChange = (selection) => {
  selectedAddUserIds.value = selection.map(s => s.id)
}

const onUserSelectionChange = (selection) => {
  selectedUserIds.value = selection.map(s => s.user_id)
}

const handleBatchRemove = async () => {
  if (selectedUserIds.value.length === 0) return

  const selectedNames = assignedUsers.value
    .filter(u => selectedUserIds.value.includes(u.user_id))
    .map(u => u.name)

  try {
    await ElMessageBox.confirm(
      `确定要移除以下 ${selectedUserIds.value.length} 位用户吗？\n${selectedNames.join('、')}`,
      '批量移除确认',
      { confirmButtonText: '确定移除', cancelButtonText: '取消', type: 'warning' }
    )
  } catch { return }

  if (!isEdit) {
    const idSet = new Set(selectedUserIds.value)
    assignedUsers.value = assignedUsers.value.filter(u => !idSet.has(u.user_id))
    ElMessage.success(`成功移除 ${selectedUserIds.value.length} 位用户`)
    selectedUserIds.value = []
    return
  }

  removingUsers.value = true
  let successCount = 0
  let failCount = 0

  for (const userId of selectedUserIds.value) {
    try {
      await request.delete(`/examines/${examineId}/users/${userId}`)
      successCount++
    } catch (e) {
      failCount++
    }
  }

  removingUsers.value = false

  if (successCount > 0) ElMessage.success(`成功移除 ${successCount} 位用户`)
  if (failCount > 0) ElMessage.warning(`${failCount} 位用户移除失败`)

  selectedUserIds.value = []
  await Promise.all([fetchAssignedUsers(), fetchAvailableUsers()])
}

const handleAddUsers = async () => {
  if (selectedAddUserIds.value.length === 0) return

  if (!isEdit) {
    const usersToAdd = availableUsers.value.filter(u => selectedAddUserIds.value.includes(u.id))
    const existingIds = new Set(assignedUsers.value.map(u => u.user_id || u.id))
    let added = 0
    for (const u of usersToAdd) {
      if (!existingIds.has(u.id)) {
        assignedUsers.value.push({ ...u })
        existingIds.add(u.id)
        added++
      }
    }
    showAddUserDialog.value = false
    selectedAddUserIds.value = []
    ElMessage.success(`成功添加 ${added} 位用户`)
    return
  }

  addingUsers.value = true
  let successCount = 0
  let failCount = 0

  for (const userId of selectedAddUserIds.value) {
    try {
      await request.post(`/examines/${examineId}/users/add`, { user_id: userId })
      successCount++
    } catch (e) {
      failCount++
    }
  }

  addingUsers.value = false
  showAddUserDialog.value = false
  selectedAddUserIds.value = []

  if (successCount > 0) ElMessage.success(`成功添加 ${successCount} 位用户`)
  if (failCount > 0) ElMessage.warning(`${failCount} 位用户添加失败`)

  await Promise.all([fetchAssignedUsers(), fetchAvailableUsers()])
}

const handleRemoveUser = async (row) => {
  try {
    await ElMessageBox.confirm(
      `确定要移除用户「${row.name}」吗？`,
      '确认移除',
      { confirmButtonText: '确定移除', cancelButtonText: '取消', type: 'warning' }
    )
  } catch { return }

  if (!isEdit) {
    assignedUsers.value = assignedUsers.value.filter(u => (u.user_id || u.id) !== (row.user_id || row.id))
    ElMessage.success(`已移除用户「${row.name}」`)
    return
  }

  try {
    await request.delete(`/examines/${examineId}/users/${row.user_id}`)
    ElMessage.success(`已移除用户「${row.name}」`)
    await Promise.all([fetchAssignedUsers(), fetchAvailableUsers()])
  } catch (error) {
    console.error('removeUser error:', error)
    ElMessage.error('移除失败')
  }
}

onMounted(async () => {
  await fetchUnits()

  if (isEdit && examineId) {
    await fetchExamine(examineId)
    await Promise.all([fetchTargets(), fetchAssignedUsers()])
  } else {
    initPeriodDefaults()
  }
})

watch(showAddUserDialog, (val) => {
  if (val) {
    userSearchKeyword.value = ''
    selectedAddUserIds.value = []
    addUserUnitFilter.value = form.unit_id || null
    if (!isEdit && allUnits.value.length === 0) {
      allUnits.value = units.value.length > 0 ? [...units.value] : []
    }
    fetchAvailableUsers()
  }
})
</script>

<style scoped>
.examine-form {
  padding: 20px;
}
.mt-4 {
  margin-top: 16px;
}
.mb-3 {
  margin-bottom: 12px;
}
.ml-2 {
  margin-left: 8px;
}
.mr-2 {
  margin-right: 8px;
}
.text-muted {
  color: #808894;
  font-size: 13px;
}
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.header-actions {
  display: flex;
  align-items: center;
}
.active-hint {
  margin-left: 12px;
  font-size: 13px;
  color: #e6a23c;
}
.user-count {
  color: #409eff;
  font-weight: bold;
  font-size: 14px;
  margin-right: 12px;
}
.dialog-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
}
.dialog-toolbar {
  display: flex;
  align-items: center;
}
.user-row-selected {
  background-color: #ECF5FF !important;
}

.tab-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.tab-hint {
  color: #808894;
  font-size: 13px;
}

.tab-actions {
  display: flex;
  gap: 8px;
}

.step-actions {
  display: flex;
  justify-content: center;
  gap: 12px;
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid #DCDFE6;
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
