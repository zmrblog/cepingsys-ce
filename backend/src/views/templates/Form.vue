<template>
  <div class="template-form">
    <el-card>
      <template #header>
        <span>{{ isEdit ? '编辑模板' : '创建模板' }}</span>
      </template>

      <el-tabs v-model="activeTab" type="border-card">
        <!-- 标签页1：基本信息 -->
        <el-tab-pane label="基本信息" name="basic">
          <el-form ref="formRef" :model="form" :rules="rules" label-width="120px">
            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="模板名称" prop="template_name">
                  <el-input v-model="form.template_name" placeholder="请输入模板名称" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="模板类型" prop="template_type">
                  <el-radio-group v-model="form.template_type">
                    <el-radio value="leader">干部民主测评</el-radio>
                    <el-radio value="team">班子民主测评</el-radio>
                  </el-radio-group>
                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item label="模板说明">
              <el-input
                v-model="form.description"
                type="textarea"
                :rows="3"
                placeholder="请输入模板说明（可选）"
              />
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 标签页2：指标项管理 -->
        <el-tab-pane label="评分指标项设定" name="items">
          <div class="toolbar">
            <el-button type="primary" @click="openItemDialog()">+ 添加指标</el-button>
            <el-button type="success" :disabled="!selectedRows.length" @click="copySelected">复制选中</el-button>
            <el-button type="danger" :disabled="!selectedRows.length" @click="deleteSelected">删除选中</el-button>
            <el-button :disabled="!selectedRows.length" @click="moveUp">↑ 上移</el-button>
            <el-button :disabled="!selectedRows.length" @click="moveDown">↓ 下移</el-button>
          </div>

          <el-table
            ref="tableRef"
            :data="form.items"
            border
            stripe
            row-key="_uid"
            @selection-change="onSelectionChange"
            class="items-table"
          >
            <el-table-column type="selection" width="45" align="center" />

            <el-table-column label="题型" width="90" align="center">
              <template #default="{ row }">
                <el-tag size="small" :type="row.item_type === 'radio' ? '' : row.item_type === 'checkbox' ? 'success' : 'info'">
                  {{ row.item_type === 'radio' ? '单选' : row.item_type === 'checkbox' ? '多选' : '文本' }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column label="指标名称" min-width="160">
              <template #default="{ row }">
                <span class="item-title-cell">{{ row.item_title || '(未命名)' }}</span>
              </template>
            </el-table-column>

            <el-table-column label="简称" width="100" align="center">
              <template #default="{ row }">
                <span>{{ row.short_name || '-' }}</span>
              </template>
            </el-table-column>

            <el-table-column label="计分" width="70" align="center">
              <template #default="{ row }">
                <el-tag size="small" :type="row.is_scoring ? 'warning' : 'info'" effect="plain">
                  {{ row.is_scoring ? '是' : '否' }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column label="权重" width="70" align="center">
              <template #default="{ row }">
                <span>{{ row.weight }}</span>
              </template>
            </el-table-column>

            <el-table-column label="选项概览" min-width="220">
              <template #default="{ row }">
                <span v-if="row.item_type === 'textarea'" class="text-muted">文本题</span>
                <div v-else class="options-preview">
                  <el-tag
                    v-for="(opt, oi) in getOptionTexts(row)"
                    :key="oi"
                    size="small"
                    class="option-tag"
                  >{{ opt.letter }}:{{ opt.text }}</el-tag>
                </div>
              </template>
            </el-table-column>

            <el-table-column label="操作" width="100" align="center" fixed="right">
              <template #default="{ row, $index }">
                <el-button type="primary" link size="small" @click="openItemDialog(row, $index)">编辑</el-button>
                <el-button type="danger" link size="small" @click="removeItem($index)" :disabled="form.items.length <= 1">删</el-button>
              </template>
            </el-table-column>
          </el-table>

          <div class="table-footer" v-if="form.items.length === 0">
            <el-empty description="暂无指标项，点击上方「添加指标」按钮开始配置" />
          </div>
        </el-tab-pane>
      </el-tabs>

      <div class="form-actions">
        <el-button type="primary" size="large" :loading="submitLoading" @click="handleSubmit">
          {{ isEdit ? '更新模板' : '创建模板' }}
        </el-button>
        <el-button size="large" @click="$router.back()">取消</el-button>
      </div>
    </el-card>

    <!-- 添加/编辑指标弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogIsEdit ? '编辑指标' : '添加指标'"
      width="820px"
      :close-on-click-modal="false"
      destroy-on-close
      class="item-dialog"
    >
      <div class="dialog-body">
        <el-row :gutter="20">
          <el-col :span="16">
            <div class="dialog-field">
              <label>测评指标<span class="required">*</span>：</label>
              <el-input v-model="dialogForm.item_title" type="textarea" :autosize="{ minRows: 1, maxRows: 3 }" placeholder="请输入测评指标名称" />
            </div>
          </el-col>
          <el-col :span="8">
            <div class="dialog-field scoring-field">
              <label>是否计分：</label>
              <el-checkbox v-model="dialogForm.is_scoring">是否计分</el-checkbox>
            </div>
          </el-col>
        </el-row>

        <el-row :gutter="20">
          <el-col :span="12">
            <div class="dialog-field">
              <label>指标简称：</label>
              <el-input v-model="dialogForm.short_name" placeholder="可以为空" />
            </div>
          </el-col>
          <el-col :span="12">
            <div class="dialog-field">
              <label>指标类型：</label>
              <el-select v-model="dialogForm.item_type" placeholder="可以为空" style="width:100%" @change="onDialogTypeChange">
                <el-option label="单选题" value="radio" />
                <el-option label="多选题" value="checkbox" />
                <el-option label="文本题" value="textarea" />
              </el-select>
            </div>
          </el-col>
        </el-row>

        <el-row :gutter="20">
          <el-col :span="12">
            <div class="dialog-field inline-field">
              <label>权重：</label>
              <el-input-number v-model="dialogForm.weight" :min="1" :max="10" controls-position="right" style="width:140px" />
            </div>
          </el-col>
          <el-col :span="12" v-if="dialogForm.item_type !== 'textarea'">
            <div class="dialog-field inline-field">
              <label>选项数：</label>
              <el-input-number v-model="dialogOptionCount" :min="2" :max="10" controls-position="right" style="width:140px" @change="syncOptionCount" />
            </div>
          </el-col>
        </el-row>

        <div class="dialog-field" v-if="dialogForm.item_type !== 'textarea'">
          <label>指标说明：</label>
          <el-input v-model="dialogForm.item_description" type="textarea" :rows="2" placeholder="输入指标详细说明（可选）" maxlength="500" show-word-limit />
        </div>

        <!-- 选项配置表 -->
        <div class="options-config" v-if="dialogForm.item_type !== 'textarea'">
          <table class="options-table">
            <thead>
              <tr>
                <th class="col-letter">对应字符</th>
                <th class="col-text">含义</th>
                <th class="col-score">积分</th>
                <th class="col-category">选项类别</th>
                <th class="col-texteval">设置文字评价</th>
                <th class="col-help" width="30">
                  <el-tooltip content="勾选「设置文字评价」后，用户选择该选项时需填写具体事例说明">
                    <el-icon><QuestionFilled /></el-icon>
                  </el-tooltip>
                </th>
                <th class="col-delete" width="50">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(opt, idx) in dialogForm.options" :key="idx">
                <td class="col-letter"><span class="letter-badge">{{ opt.letter }}</span></td>
                <td class="col-text">
                  <el-input v-model="opt.text" size="small" placeholder="请输入内容" />
                </td>
                <td class="col-score">
                  <el-input-number v-model="opt.score" :min="0" :max="100" controls-position="right" size="small" style="width:90px" />
                </td>
                <td class="col-category">
                  <el-input v-model="opt.category" size="small" placeholder="请输入内容" />
                </td>
                <td class="col-texteval" align="center">
                  <el-checkbox v-model="opt.textEval" />
                </td>
                <td></td>
                <td class="col-delete" align="center">
                  <el-button type="danger" link size="small" @click="removeOptionRow(idx)" :disabled="dialogForm.options.length <= 2">删除</el-button>
                </td>
              </tr>
            </tbody>
          </table>
          <div class="add-option-row">
            <el-button type="primary" plain size="small" @click="addOptionRow">+ 添加选项</el-button>
            <span class="option-count-hint">当前共 {{ dialogForm.options.length }} 个选项（至少保留 2 个）</span>
          </div>
        </div>

        <!-- 多选约束 & 反向测评（在选项表下方） -->
        <el-row :gutter="20" v-if="dialogForm.item_type === 'checkbox'" style="margin-top:16px">
          <el-col :span="24">
            <div class="dialog-field inline-field">
              <label>选择约束：</label>
              <span>最少</span>
              <el-input-number v-model="dialogForm.min_select" :min="0" :max="dialogForm.options.length" size="small" style="width:80px" controls-position="right" />
              <span>项，最多</span>
              <el-input-number v-model="dialogForm.max_select" :min="0" :max="dialogForm.options.length" size="small" style="width:80px" controls-position="right" />
              <span>项</span>
            </div>
          </el-col>
        </el-row>

        <el-row :gutter="20" v-if="dialogForm.item_type === 'radio' || dialogForm.item_type === 'checkbox'" style="margin-top:12px">
          <el-col :span="24">
            <div class="dialog-field inline-field">
              <label>反向测评：</label>
              <el-switch v-model="dialogForm.is_reverse" active-text="启用" inactive-text="关闭" />
              <span v-if="dialogForm.is_reverse" class="reverse-hint" style="margin-left:12px;color:#909399;font-size:12px">
                （选择以下选项时需要填写具体事例）
              </span>
            </div>
            <div v-if="dialogForm.is_reverse" style="padding-left:72px;margin-top:6px">
              <el-checkbox-group v-model="dialogForm.reverse_options">
                <el-checkbox
                  v-for="(opt, idx) in dialogForm.options.filter(o => o.text.trim())"
                  :key="idx"
                  :label="opt.letter + ':' + opt.text"
                  :value="opt.letter + ':' + opt.text"
                >{{ opt.letter }}:{{ opt.text }}</el-checkbox>
              </el-checkbox-group>
              <el-checkbox v-model="dialogForm.required_example" style="margin-top:6px">强制要求填写事例</el-checkbox>
            </div>
          </el-col>
        </el-row>
      </div>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="confirmDialog" :loading="dialogSubmitting">
          {{ dialogIsEdit ? '保存修改' : '添加' }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import request from '@/api/request'
import { ElMessage, ElMessageBox } from 'element-plus'
import { QuestionFilled } from '@element-plus/icons-vue'

const route = useRoute()
const router = useRouter()

const formRef = ref(null)
const tableRef = ref(null)
const submitLoading = ref(false)
const activeTab = ref('items')
let uidCounter = 0
const selectedRows = ref([])

const templateId = computed(() => route.params.id)
const isEdit = computed(() => !!templateId.value)

const hasOptions = computed(() => {
  return form.value.items.some(item => item.item_type === 'radio' || item.item_type === 'checkbox')
})

const form = ref({
  template_name: '',
  template_type: 'leader',
  description: '',
  items: [],
})

const rules = {
  template_name: [{ required: true, message: '请输入模板名称', trigger: 'blur' }],
  template_type: [{ required: true, message: '请选择模板类型', trigger: 'change' }],
}

function createOptionObj(letter, text = '', score = null) {
  const scoreMap = { A: 100, B: 80, C: 60, D: 40, E: 20, F: 10, G: 5, H: 0, I: 0, J: 0 }
  return {
    letter,
    text: text || '',
    score: score ?? (scoreMap[letter] ?? (100 - (letter.charCodeAt(0) - 65) * 20)),
    category: '',
    textEval: false,
  }
}

function createDefaultOptions(count = 4) {
  const letters = 'ABCDEFGHIJ'
  const defaults = { A: '好', B: '较好', C: '一般', D: '差', E: '', F: '', G: '', H: '', I: '', J: '' }
  const arr = []
  for (let i = 0; i < count; i++) {
    arr.push(createOptionObj(letters[i], defaults[letters[i]]))
  }
  return arr
}

function createNewItem() {
  return {
    _uid: ++uidCounter,
    item_title: '',
    item_description: '',
    short_name: '',
    item_type: 'radio',
    options: createDefaultOptions(4),
    option_count: 4,
    min_select: null,
    max_select: null,
    is_reverse: false,
    reverse_options: [],
    required_example: false,
    weight: 1,
    is_scoring: true,
  }
}

onMounted(async () => {
  if (isEdit.value) {
    await fetchTemplate()
  }
})

async function fetchTemplate() {
  try {
    const res = await request.get(`/templates/${templateId.value}`)
    
    form.value = {
      template_name: res.data.template_name,
      template_type: res.data.template_type,
      description: res.data.description || '',
      items: (res.data.items || []).map(item => normalizeItem(item)),
    }

    if (form.value.items.length === 0) {
      form.value.items.push(createNewItem())
    }
  } catch (error) {
    console.error('获取模板详情失败:', error)
  }
}

function normalizeItem(item) {
  let options = item.options
  if (!options || !Array.isArray(options)) {
    options = []
  }
  if (options.length > 0 && typeof options[0] === 'string') {
    options = options.map((text, idx) => createOptionObj(String.fromCharCode(65 + idx), text))
  } else if (options.length > 0 && typeof options[0] === 'object') {
    options = options.map((o, idx) => ({
      letter: o.letter || String.fromCharCode(65 + idx),
      text: o.text || (typeof o === 'string' ? o : ''),
      score: o.score ?? null,
      category: o.category || '',
      textEval: !!o.textEval,
    }))
  }
  while (options.length < 2) {
    options.push(createOptionObj(String.fromCharCode(65 + options.length)))
  }

  return {
    ...item,
    _uid: ++uidCounter,
    options,
    option_count: options.length,
    short_name: item.short_name || '',
    is_scoring: item.is_scoring !== undefined ? !!item.is_scoring : true,
    weight: item.weight ?? 1,
    reverse_options: item.reverse_options || [],
  }
}

function getOptionTexts(row) {
  if (!row.options || !row.options.length) return []
  return row.options.filter(o => o && o.text).slice(0, 4)
}

const dialogVisible = ref(false)
const dialogIsEdit = ref(false)
const dialogEditingIndex = ref(-1)
const dialogSubmitting = ref(false)
const dialogOptionCount = ref(4)

const dialogForm = reactive({
  item_title: '',
  item_description: '',
  short_name: '',
  item_type: 'radio',
  options: [],
  min_select: null,
  max_select: null,
  is_reverse: false,
  reverse_options: [],
  required_example: false,
  weight: 1,
  is_scoring: true,
})

function resetDialogForm() {
  dialogForm.item_title = ''
  dialogForm.item_description = ''
  dialogForm.short_name = ''
  dialogForm.item_type = 'radio'
  dialogForm.min_select = null
  dialogForm.max_select = null
  dialogForm.is_reverse = false
  dialogForm.reverse_options = []
  dialogForm.required_example = false
  dialogForm.weight = 1
  dialogForm.is_scoring = true
  dialogOptionCount.value = 4
  dialogForm.options = createDefaultOptions(4)
}

function openItemDialog(existingRow, index) {
  if (existingRow && index !== undefined) {
    dialogIsEdit.value = true
    dialogEditingIndex.value = index
    dialogForm.item_title = existingRow.item_title || ''
    dialogForm.item_description = existingRow.item_description || ''
    dialogForm.short_name = existingRow.short_name || ''
    dialogForm.item_type = existingRow.item_type || 'radio'
    dialogForm.weight = existingRow.weight ?? 1
    dialogForm.is_scoring = existingRow.is_scoring !== undefined ? !!existingRow.is_scoring : true
    dialogForm.min_select = existingRow.min_select ?? null
    dialogForm.max_select = existingRow.max_select ?? null
    dialogForm.is_reverse = !!existingRow.is_reverse
    dialogForm.reverse_options = [...(existingRow.reverse_options || [])]
    dialogForm.required_example = !!existingRow.required_example

    const opts = existingRow.options || []
    dialogOptionCount.value = Math.max(opts.length, 2)
    dialogForm.options = JSON.parse(JSON.stringify(opts))
    while (dialogForm.options.length < dialogOptionCount.value) {
      dialogForm.options.push(createOptionObj(String.fromCharCode(65 + dialogForm.options.length)))
    }
  } else {
    dialogIsEdit.value = false
    dialogEditingIndex.value = -1
    resetDialogForm()
  }
  dialogVisible.value = true
}

function syncOptionCount(val) {
  val = Number(val) || 2
  const currentLen = dialogForm.options.length
  if (val > currentLen) {
    for (let i = currentLen; i < val; i++) {
      dialogForm.options.push(createOptionObj(String.fromCharCode(65 + i)))
    }
  } else if (val < currentLen) {
    dialogForm.options.splice(val)
  }
}

function addOptionRow() {
  const nextLetter = String.fromCharCode(65 + dialogForm.options.length)
  dialogForm.options.push(createOptionObj(nextLetter))
  dialogOptionCount.value = dialogForm.options.length
}

function removeOptionRow(idx) {
  if (dialogForm.options.length <= 2) {
    ElMessage.warning('至少保留2个选项')
    return
  }
  dialogForm.options.splice(idx, 1)
  for (let i = 0; i < dialogForm.options.length; i++) {
    dialogForm.options[i].letter = String.fromCharCode(65 + i)
  }
  dialogOptionCount.value = dialogForm.options.length
}

function onDialogTypeChange(val) {
  if (val === 'textarea') {
    dialogForm.options = []
    dialogForm.min_select = null
    dialogForm.max_select = null
    dialogForm.is_reverse = false
    dialogForm.reverse_options = []
  } else if (!dialogForm.options.length) {
    dialogOptionCount.value = 4
    dialogForm.options = createDefaultOptions(4)
  }
}

function confirmDialog() {
  if (!dialogForm.item_title || !dialogForm.item_title.trim()) {
    ElMessage.warning('请输入测评指标名称')
    return
  }

  if (dialogForm.item_type !== 'textarea') {
    const validOpts = dialogForm.options.filter(o => o && o.text && o.text.trim())
    if (validOpts.length < 2) {
      ElMessage.warning('至少需要填写2个有效选项的含义')
      return
    }
  }

  const itemData = {
    item_title: dialogForm.item_title.trim(),
    item_description: dialogForm.item_description.trim(),
    short_name: dialogForm.short_name.trim(),
    item_type: dialogForm.item_type,
    options: JSON.parse(JSON.stringify(dialogForm.options)),
    option_count: dialogForm.options.length,
    min_select: dialogForm.min_select,
    max_select: dialogForm.max_select,
    is_reverse: dialogForm.is_reverse,
    reverse_options: [...dialogForm.reverse_options],
    required_example: dialogForm.required_example,
    weight: dialogForm.weight,
    is_scoring: dialogForm.is_scoring,
  }

  if (dialogIsEdit.value && dialogEditingIndex.value >= 0) {
    itemData._uid = form.value.items[dialogEditingIndex.value]._uid
    form.value.items[dialogEditingIndex.value] = itemData
    ElMessage.success('指标已更新')
  } else {
    itemData._uid = ++uidCounter
    form.value.items.push(itemData)
    ElMessage.success('指标已添加')
  }

  dialogVisible.value = false
}

function removeItem(index) {
  if (form.value.items.length <= 1) {
    ElMessage.warning('至少保留一个指标项')
    return
  }
  form.value.items.splice(index, 1)
}

function onSelectionChange(rows) {
  selectedRows.value = rows
}

function copySelected() {
  if (!selectedRows.value.length) return
  const indices = selectedRows.value.map(row => form.value.items.findIndex(i => i._uid === row._uid)).filter(i => i !== -1).sort((a, b) => a - b)
  const newItems = indices.map(idx => ({
    ...JSON.parse(JSON.stringify(form.value.items[idx])),
    _uid: ++uidCounter,
    item_title: form.value.items[idx].item_title + ' (副本)',
  }))
  const lastIdx = Math.max(...indices)
  form.value.items.splice(lastIdx + 1, 0, ...newItems)
  ElMessage.success(`已复制 ${newItems.length} 个指标项`)
}

async function deleteSelected() {
  if (!selectedRows.value.length) return
  try {
    await ElMessageBox.confirm(`确定要删除选中的 ${selectedRows.value.length} 个指标项吗？`, '确认删除', { type: 'warning' })
    const uidsToDelete = new Set(selectedRows.value.map(r => r._uid))
    form.value.items = form.value.items.filter(item => !uidsToDelete.has(item._uid))
    if (form.value.items.length === 0) {
      form.value.items.push(createNewItem())
    }
    selectedRows.value = []
    ElMessage.success('已删除选中项')
  } catch {}
}

function moveUp() {
  if (!selectedRows.value.length) return
  const uids = new Set(selectedRows.value.map(r => r._uid))
  const items = form.value.items
  for (let i = 1; i < items.length; i++) {
    if (uids.has(items[i]._uid) && !uids.has(items[i - 1]._uid)) {
      ;[items[i - 1], items[i]] = [items[i], items[i - 1]]
    }
  }
}

function moveDown() {
  if (!selectedRows.value.length) return
  const uids = new Set(selectedRows.value.map(r => r._uid))
  const items = form.value.items
  for (let i = items.length - 2; i >= 0; i--) {
    if (uids.has(items[i]._uid) && !uids.has(items[i + 1]._uid)) {
      ;[items[i], items[i + 1]] = [items[i + 1], items[i]]
    }
  }
}

async function handleSubmit() {
  activeTab.value = 'basic'
  await nextTick()

  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    activeTab.value = 'basic'
    return
  }

  for (let i = 0; i < form.value.items.length; i++) {
    const item = form.value.items[i]
    if (!item.item_title || !item.item_title.trim()) {
      ElMessage.warning(`第 ${i + 1} 个指标项的名称不能为空`)
      activeTab.value = 'items'
      return
    }
    if ((item.item_type === 'radio' || item.item_type === 'checkbox')) {
      const validOpts = (item.options || []).filter(o => o && o.text && o.text.trim())
      if (validOpts.length < 2) {
        ElMessage.warning(`第 ${i + 1} 个指标项「${item.item_title}」至少需要2个有效选项`)
        activeTab.value = 'items'
        return
      }
    }
  }

  submitLoading.value = true

  try {
    const submitData = {
      template_name: form.value.template_name,
      template_type: form.value.template_type,
      description: form.value.description,
      items: form.value.items.map(({ _uid, option_count, ...rest }) => rest),
    }

    if (isEdit.value) {
      await request.put(`/templates/${templateId.value}`, submitData)
      ElMessage.success('更新成功')
    } else {
      await request.post('/templates', submitData)
      ElMessage.success('创建成功')
    }

    router.push('/templates')
  } catch (error) {
    console.error('提交失败:', error)
  } finally {
    submitLoading.value = false
  }
}
</script>

<style scoped>
.template-form .toolbar {
  margin-bottom: 12px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.template-form .items-table {
  width: 100%;
}

.template-form .item-title-cell {
  font-weight: 500;
  color: #303133;
}

.template-form .options-preview {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.template-form .option-tag {
  font-size: 11px;
}

.template-form .table-footer {
  padding: 20px 0;
}

.template-form .text-muted {
  color: #808894;
}

.template-form .form-actions {
  margin-top: 24px;
  text-align: center;
}

/* ===== 弹窗样式 ===== */
.item-dialog .dialog-body {
  padding: 0 10px;
}

.item-dialog .dialog-field {
  margin-bottom: 14px;
}

.item-dialog .dialog-field > label {
  display: block;
  font-size: 13px;
  color: #606266;
  margin-bottom: 6px;
  font-weight: 500;
}

.item-dialog .dialog-field .required {
  color: #f56c6c;
  margin-left: 2px;
}

.item-dialog .scoring-field {
  padding-top: 28px;
}

.item-dialog .scoring-field > label {
  display: inline;
  font-size: 14px;
  color: #606266;
  margin-right: 8px;
}

.item-dialog .inline-field {
  display: flex;
  align-items: center;
}

.item-dialog .inline-field > label {
  display: inline;
  font-size: 13px;
  color: #606266;
  margin-right: 8px;
  white-space: nowrap;
  font-weight: 500;
}

.item-dialog .inline-field span {
  font-size: 13px;
  color: #606266;
  margin: 0 4px;
}

/* 选项配置表格 */
.item-dialog .options-config {
  margin-top: 16px;
  border: 1px solid #DCDFE6;
  border-radius: 4px;
  overflow: hidden;
}

.item-dialog .options-table {
  width: 100%;
  border-collapse: collapse;
}

.item-dialog .options-table th {
  background: #EFF3F8;
  color: #606266;
  font-size: 13px;
  font-weight: 600;
  padding: 10px 12px;
  border-bottom: 1px solid #DCDFE6;
  text-align: center;
}

.item-dialog .options-table td {
  padding: 10px 8px;
  border-bottom: 1px solid #E8ECF1;
  vertical-align: middle;
}

.item-dialog .options-table tbody tr:last-child td {
  border-bottom: none;
}

.item-dialog .options-table tbody tr:hover {
  background: #F0F3F7;
}

.item-dialog .col-letter {
  width: 70px;
  text-align: center;
}

.item-dialog .letter-badge {
  display: inline-block;
  width: 28px;
  height: 28px;
  line-height: 28px;
  background: #ecf5ff;
  color: #409eff;
  border-radius: 50%;
  font-weight: 600;
  font-size: 13px;
}

.item-dialog .col-text {
  width: 200px;
}

.item-dialog .col-score {
  width: 110px;
  text-align: center;
}

.item-dialog .col-category {
  width: 160px;
}

.item-dialog .col-texteval {
  width: 90px;
  text-align: center;
}

.item-dialog .col-help {
  text-align: center;
  color: #c0c4cc;
  cursor: help;
}

.item-dialog .col-delete {
  text-align: center;
}

.item-dialog .add-option-row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 10px 0 4px;
  border-top: 1px solid #E8E8E8;
}

.item-dialog .option-count-hint {
  font-size: 12px;
  color: #909399;
}
</style>
