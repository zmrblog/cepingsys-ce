var API_BASE = '/api/audit'
var token = localStorage.getItem('audit_token')
var currentUser = localStorage.getItem('audit_user')
var allRecords = []

var activeFilters = {}
var filterDropdown = document.getElementById('filterDropdown')
var currentFilterCol = null
var filterCheckboxes = {}

var loginPage = document.getElementById('loginPage')
var queryPage = document.getElementById('queryPage')
var loginBtn = document.getElementById('loginBtn')
var loginError = document.getElementById('loginError')
var usernameInput = document.getElementById('username')
var passwordInput = document.getElementById('password')
var logoutBtn = document.getElementById('logoutBtn')
var unitSelect = document.getElementById('unitSelect')
var queryBtn = document.getElementById('queryBtn')
var clearBtn = document.getElementById('clearBtn')
var exportBtn = document.getElementById('exportBtn')
var resultSection = document.getElementById('resultSection')
var resultBody = document.getElementById('resultBody')
var resultMeta = document.getElementById('resultMeta')
var noData = document.getElementById('noData')
var resultTable = document.getElementById('resultTable')
var currentUserSpan = document.getElementById('currentUser')

var accountsBody = document.getElementById('accountsBody')
var noAccounts = document.getElementById('noAccounts')
var addAccountBtn = document.getElementById('addAccountBtn')

var accountModal = document.getElementById('accountModal')
var modalTitle = document.getElementById('modalTitle')
var modalClose = document.getElementById('modalClose')
var modalCancel = document.getElementById('modalCancel')
var modalSave = document.getElementById('modalSave')
var modalError = document.getElementById('modalError')
var editUserId = document.getElementById('editUserId')
var editUsername = document.getElementById('editUsername')
var editPassword = document.getElementById('editPassword')
var editRealName = document.getElementById('editRealName')
var pwdRequired = document.getElementById('pwdRequired')

var confirmDialog = document.getElementById('confirmDialog')
var confirmMsg = document.getElementById('confirmMsg')
var confirmCancel = document.getElementById('confirmCancel')
var confirmOk = document.getElementById('confirmOk')

var pendingConfirm = null

if (token) { showQueryPage() }

loginBtn.addEventListener('click', doLogin)
logoutBtn.addEventListener('click', doLogout)
queryBtn.addEventListener('click', doQuery)
clearBtn.addEventListener('click', clearAll)
exportBtn.addEventListener('click', doExport)

usernameInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') passwordInput.focus() })
passwordInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') doLogin() })

addAccountBtn.addEventListener('click', openAddModal)
modalClose.addEventListener('click', closeModal)
modalCancel.addEventListener('click', closeModal)
modalSave.addEventListener('click', saveAccount)
confirmCancel.addEventListener('click', closeConfirm)
confirmOk.addEventListener('click', function () { if (pendingConfirm) { pendingConfirm(); pendingConfirm = null } closeConfirm() })

document.querySelectorAll('.tab').forEach(function (t) {
    t.addEventListener('click', function () {
        document.querySelectorAll('.tab').forEach(function (x) { x.classList.remove('active') })
        t.classList.add('active')
        document.querySelectorAll('.tab-content').forEach(function (x) { x.classList.remove('active') })
        document.getElementById('tab' + (t.dataset.tab === 'query' ? 'Query' : 'Accounts')).classList.add('active')
        if (t.dataset.tab === 'accounts') loadAccounts()
    })
})

document.querySelectorAll('.filterable').forEach(function (th) {
    th.addEventListener('click', function (e) { openFilterDropdown(th, e) })
})

document.addEventListener('click', function (e) {
    if (filterDropdown.style.display !== 'none' && !filterDropdown.contains(e.target) && !e.target.closest('.filterable')) {
        closeFilterDropdown()
    }
})

var filterSearch = filterDropdown.querySelector('.filter-search')
filterSearch.addEventListener('input', function () { renderFilterList(currentFilterCol) })

filterDropdown.querySelector('[data-action="selectAll"]').addEventListener('click', function () {
    Object.keys(filterCheckboxes).forEach(function (k) { filterCheckboxes[k].checked = true })
})
filterDropdown.querySelector('[data-action="deselectAll"]').addEventListener('click', function () {
    Object.keys(filterCheckboxes).forEach(function (k) { filterCheckboxes[k].checked = false })
})
filterDropdown.querySelector('[data-action="ok"]').addEventListener('click', function () {
    applyCurrentFilter()
})
filterDropdown.querySelector('[data-action="reset"]').addEventListener('click', function () {
    resetCurrentFilter()
})

function doLogin() {
    var u = usernameInput.value.trim()
    var p = passwordInput.value
    if (!u) { showLoginError('请输入账号'); return }
    if (!p) { showLoginError('请输入密码'); return }
    loginBtn.disabled = true
    loginBtn.textContent = '登录中...'
    hideLoginError()
    fetch(API_BASE + '/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: u, password: p })
    }).then(function (r) { return r.json() }).then(function (d) {
        if (d.code === 200 && d.data && d.data.token) {
            token = d.data.token
            currentUser = d.data.real_name
            localStorage.setItem('audit_token', token)
            localStorage.setItem('audit_user', currentUser)
            showQueryPage()
        } else { showLoginError(d.message || '登录失败') }
    }).catch(function () { showLoginError('网络错误') })
    .finally(function () { loginBtn.disabled = false; loginBtn.textContent = '登 录' })
}

function doLogout() {
    localStorage.removeItem('audit_token')
    localStorage.removeItem('audit_user')
    token = null; currentUser = null; allRecords = []
    activeFilters = {}
    usernameInput.value = ''; passwordInput.value = ''
    hideLoginError()
    queryPage.style.display = 'none'; loginPage.style.display = 'block'
    resultSection.style.display = 'none'
}

function showQueryPage() {
    loginPage.style.display = 'none'; queryPage.style.display = 'block'
    currentUserSpan.textContent = currentUser || ''
    loadUnits()
}

function loadUnits() {
    fetch(API_BASE + '/units', { headers: { 'Authorization': 'Bearer ' + token } })
    .then(function (r) { if (r.status === 401) { doLogout(); return Promise.reject() } return r.json() })
    .then(function (d) {
        if (d.code === 200 && d.data && d.data.list) {
            unitSelect.innerHTML = '<option value="">全部单位</option>'
            d.data.list.forEach(function (x) {
                var o = document.createElement('option'); o.value = x.id; o.textContent = x.unit_name; unitSelect.appendChild(o)
            })
        }
    }).catch(function () {})
}

function clearAll() {
    unitSelect.value = ''
    allRecords = []
    activeFilters = {}
    updateFilterHeaderStyles()
    resultSection.style.display = 'none'
}

function doQuery() {
    var uid = unitSelect.value
    queryBtn.disabled = true; queryBtn.textContent = '查询中...'
    var url = API_BASE + '/data'
    if (uid) url += '?unit_id=' + uid
    fetch(url, { headers: { 'Authorization': 'Bearer ' + token } })
    .then(function (r) { if (r.status === 401) { doLogout(); return Promise.reject() } return r.json() })
    .then(function (d) {
        if (d.code === 200 && d.data && d.data.records) {
            allRecords = d.data.records
            applyAllFilters()
        }
    }).catch(function () {})
    .finally(function () { queryBtn.disabled = false; queryBtn.textContent = '查询' })
}

function openFilterDropdown(th, ev) {
    currentFilterCol = th.dataset.col
    var rect = th.getBoundingClientRect()
    filterDropdown.style.display = 'block'
    filterDropdown.style.top = (rect.bottom + 4) + 'px'
    filterDropdown.style.left = Math.max(8, rect.left) + 'px'
    filterSearch.value = ''
    renderFilterList(th)
    ev.stopPropagation()
}

function renderFilterList(th) {
    var col = currentFilterCol
    var search = filterSearch.value.trim().toLowerCase()
    var values = []
    allRecords.forEach(function (r) {
        var v = formatCellValue(r, col)
        if (v !== '' && values.indexOf(v) === -1) values.push(v)
    })
    values.sort(function (a, b) { return a.localeCompare(b, 'zh') })

    if (search) {
        values = values.filter(function (v) { return v.toLowerCase().indexOf(search) !== -1 })
    }

    filterCheckboxes = {}
    var selectedSet = activeFilters[col] || []
    var listDiv = filterDropdown.querySelector('.filter-list')
    listDiv.innerHTML = ''

    values.forEach(function (v) {
        var div = document.createElement('div')
        div.className = 'filter-item'
        var cb = document.createElement('input')
        cb.type = 'checkbox'
        cb.checked = selectedSet.indexOf(v) !== -1
        cb.addEventListener('click', function (e) { e.stopPropagation() })
        var label = document.createElement('label')
        label.textContent = v
        div.appendChild(cb)
        div.appendChild(label)
        div.addEventListener('click', function (e) {
            if (e.target.tagName !== 'INPUT') {
                cb.checked = !cb.checked
            }
        })
        listDiv.appendChild(div)
        filterCheckboxes[v] = cb
    })

    if (values.length === 0) {
        listDiv.innerHTML = '<div class="filter-item" style="color:#a0aec0;cursor:default;">无匹配结果</div>'
    }
}

function applyCurrentFilter() {
    var selected = []
    Object.keys(filterCheckboxes).forEach(function (k) {
        if (filterCheckboxes[k].checked) selected.push(k)
    })
    if (selected.length === 0) {
        delete activeFilters[currentFilterCol]
    } else {
        activeFilters[currentFilterCol] = selected
    }
    closeFilterDropdown()
    applyAllFilters()
}

function resetCurrentFilter() {
    delete activeFilters[currentFilterCol]
    closeFilterDropdown()
    applyAllFilters()
}

function closeFilterDropdown() {
    filterDropdown.style.display = 'none'
    currentFilterCol = null
    filterCheckboxes = {}
}

function applyAllFilters() {
    var cols = Object.keys(activeFilters)
    var filtered = allRecords
    if (cols.length > 0) {
        filtered = allRecords.filter(function (row) {
            return cols.every(function (col) {
                var vals = activeFilters[col]
                var cellVal = formatCellValue(row, col)
                return vals.indexOf(cellVal) !== -1
            })
        })
    }
    updateFilterHeaderStyles()
    renderResults(filtered)
}

function formatCellValue(row, col) {
    if (col === 'user_phone') return maskPhone(row[col] || '')
    if (col === 'user_type') return row[col] === 'A' ? 'A类' : (row[col] === 'B' ? 'B类' : (row[col] || ''))
    if (col === 'target_type') return row[col] === 'team' ? '班子' : (row[col] === 'leader' ? '干部' : (row[col] || ''))
    if (col === 'item_type') return row[col] === 'radio' ? '单选' : (row[col] === 'checkbox' ? '多选' : (row[col] === 'textarea' ? '文本' : (row[col] || '')))
    if (col === 'answer_value') return renderAnswerText(row[col])
    return String(row[col] || '')
}

function renderAnswerText(v) {
    if (!v) return ''
    try { var a = JSON.parse(v); if (Array.isArray(a)) return a.join('、') } catch (e) {}
    return String(v)
}

function updateFilterHeaderStyles() {
    document.querySelectorAll('.filterable').forEach(function (th) {
        var col = th.dataset.col
        if (activeFilters[col] && activeFilters[col].length > 0) {
            th.classList.add('active-filter')
        } else {
            th.classList.remove('active-filter')
        }
    })
}

function renderResults(records) {
    resultSection.style.display = 'block'
    resultBody.innerHTML = ''
    if (!records.length) { noData.style.display = 'block'; resultTable.style.display = 'none'; resultMeta.textContent = ''; return }
    noData.style.display = 'none'; resultTable.style.display = ''; resultMeta.textContent = '共 ' + records.length + ' 条'

    records.forEach(function (row, i) {
        var tr = document.createElement('tr')
        tr.innerHTML =
            '<td class="col-seq">' + (i + 1) + '</td>' +
            '<td>' + esc(row.examine_name) + '</td>' +
            '<td>' + esc(row.user_name) + '</td>' +
            '<td>' + maskPhone(row.user_phone) + '</td>' +
            '<td>' + renderUserType(row.user_type) + '</td>' +
            '<td>' + esc(row.target_name) + '</td>' +
            '<td>' + renderTargetType(row.target_type) + '</td>' +
            '<td>' + esc(row.item_title) + '</td>' +
            '<td>' + renderItemType(row.item_type) + '</td>' +
            '<td>' + renderAnswer(row.answer_value) + '</td>' +
            '<td>' + esc(row.example_text) + '</td>' +
            '<td>' + esc(row.answered_at) + '</td>'
        resultBody.appendChild(tr)
    })
}

function doExport() {
    if (!allRecords.length) return

    var cols = Object.keys(activeFilters)
    var filtered = allRecords
    if (cols.length > 0) {
        filtered = allRecords.filter(function (row) {
            return cols.every(function (col) {
                var vals = activeFilters[col]
                var cellVal = formatCellValue(row, col)
                return vals.indexOf(cellVal) !== -1
            })
        })
    }

    if (!filtered.length) { alert('当前筛选结果为空，无法导出'); return }

    exportBtn.disabled = true
    exportBtn.textContent = '导出中...'

    fetch(API_BASE + '/export', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify({ records: filtered })
    }).then(function (r) {
        if (r.status === 401) { doLogout(); return Promise.reject() }
        if (!r.ok) return r.json().then(function (d) { throw new Error(d.message || '导出失败') })
        return r.blob()
    }).then(function (blob) {
        var url = window.URL.createObjectURL(blob)
        var a = document.createElement('a')
        a.href = url
        a.download = '审计查询结果_' + formatDate() + '.xlsx'
        document.body.appendChild(a)
        a.click()
        document.body.removeChild(a)
        window.URL.revokeObjectURL(url)
    }).catch(function (e) { alert(e.message || '导出失败，请重试') })
    .finally(function () { exportBtn.disabled = false; exportBtn.textContent = '导出 Excel' })
}

function formatDate() {
    var d = new Date()
    var y = d.getFullYear()
    var m = ('0' + (d.getMonth() + 1)).slice(-2)
    var day = ('0' + d.getDate()).slice(-2)
    var h = ('0' + d.getHours()).slice(-2)
    var min = ('0' + d.getMinutes()).slice(-2)
    var s = ('0' + d.getSeconds()).slice(-2)
    return y + m + day + '_' + h + min + s
}

function loadAccounts() {
    fetch(API_BASE + '/users', { headers: { 'Authorization': 'Bearer ' + token } })
    .then(function (r) { if (r.status === 401) { doLogout(); return Promise.reject() } return r.json() })
    .then(function (d) { if (d.code === 200 && d.data) renderAccounts(d.data.list) })
    .catch(function () {})
}

function renderAccounts(list) {
    accountsBody.innerHTML = ''
    if (!list.length) { noAccounts.style.display = 'block'; return }
    noAccounts.style.display = 'none'

    list.forEach(function (u) {
        var tr = document.createElement('tr')
        var toggleClass = u.is_active ? 'on' : 'off'
        var toggleText = u.is_active ? '启用' : '禁用'
        tr.innerHTML =
            '<td>' + u.id + '</td>' +
            '<td>' + esc(u.username) + '</td>' +
            '<td>' + esc(u.real_name) + '</td>' +
            '<td>' + (u.is_active ? '<span class="badge-on">正常</span>' : '<span class="badge-off">已禁用</span>') + '</td>' +
            '<td>' + esc(u.last_login_at) + '</td>' +
            '<td>' + esc(u.created_at) + '</td>' +
            '<td>' +
                '<button class="btn-edit-sm" data-id="' + u.id + '" data-action="edit">编辑</button>' +
                '<button class="btn-toggle-sm ' + toggleClass + '" data-id="' + u.id + '" data-action="toggle">' + toggleText + '</button>' +
                '<button class="btn-danger-sm" data-id="' + u.id + '" data-action="delete">删除</button>' +
            '</td>'
        accountsBody.appendChild(tr)
    })

    accountsBody.querySelectorAll('[data-action="edit"]').forEach(function (btn) {
        btn.addEventListener('click', function () { openEditModal(btn.dataset.id, list) })
    })
    accountsBody.querySelectorAll('[data-action="toggle"]').forEach(function (btn) {
        btn.addEventListener('click', function () { toggleAccount(btn.dataset.id) })
    })
    accountsBody.querySelectorAll('[data-action="delete"]').forEach(function (btn) {
        btn.addEventListener('click', function () { deleteAccount(btn.dataset.id) })
    })
}

function openAddModal() {
    modalTitle.textContent = '新增审计账号'; editUserId.value = ''
    editUsername.value = ''; editPassword.value = ''; editRealName.value = ''
    editUsername.disabled = false; pwdRequired.style.display = 'inline'; hideModalError()
    accountModal.style.display = 'flex'
}

function openEditModal(id, list) {
    var u = list.find(function (x) { return x.id == id })
    if (!u) return
    modalTitle.textContent = '编辑审计账号'; editUserId.value = u.id
    editUsername.value = u.username; editPassword.value = ''; editRealName.value = u.real_name || ''
    editUsername.disabled = true; pwdRequired.style.display = 'none'; hideModalError()
    accountModal.style.display = 'flex'
}

function closeModal() { accountModal.style.display = 'none' }

function saveAccount() {
    var id = editUserId.value
    var username = editUsername.value.trim()
    var password = editPassword.value
    var realName = editRealName.value.trim()
    var isEdit = !!id

    if (!isEdit && !username) { showModalError('请输入用户名'); return }
    if (!isEdit && !password) { showModalError('请输入密码'); return }
    if (!isEdit && password.length < 6) { showModalError('密码至少6位'); return }
    if (isEdit && password && password.length < 6) { showModalError('密码至少6位'); return }

    modalSave.disabled = true; modalSave.textContent = '保存中...'; hideModalError()

    var url, method, body = {}
    if (isEdit) {
        url = API_BASE + '/users/' + id; method = 'PUT'
        body.real_name = realName
        if (password) body.password = password
    } else {
        url = API_BASE + '/users'; method = 'POST'
        body.username = username; body.password = password; body.real_name = realName
    }

    fetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
        body: JSON.stringify(body)
    }).then(function (r) { return r.json() }).then(function (d) {
        if (d.code === 200) { closeModal(); loadAccounts() }
        else { showModalError(d.message || '操作失败') }
    }).catch(function () { showModalError('网络错误') })
    .finally(function () { modalSave.disabled = false; modalSave.textContent = '保存' })
}

function toggleAccount(id) {
    fetch(API_BASE + '/users/' + id + '/toggle', {
        method: 'PUT',
        headers: { 'Authorization': 'Bearer ' + token }
    }).then(function (r) { return r.json() }).then(function (d) {
        if (d.code === 200) loadAccounts()
    }).catch(function () {})
}

function deleteAccount(id) {
    confirmMsg.textContent = '确定要删除该审计账号吗？此操作不可撤销。'
    pendingConfirm = function () {
        fetch(API_BASE + '/users/' + id, {
            method: 'DELETE',
            headers: { 'Authorization': 'Bearer ' + token }
        }).then(function (r) { return r.json() }).then(function (d) {
            if (d.code === 200) loadAccounts()
        }).catch(function () {})
    }
    confirmDialog.style.display = 'flex'
}

function closeConfirm() { confirmDialog.style.display = 'none'; pendingConfirm = null }

function maskPhone(phone) { if (!phone) return '—'; return phone.replace(/(\d{3})\d{4}(\d{4})/, '$1****$2') }
function renderUserType(t) { if (t === 'A') return '<span class="tag tag-a">A类</span>'; if (t === 'B') return '<span class="tag tag-b">B类</span>'; return esc(t) }
function renderTargetType(t) { if (t === 'team') return '班子'; if (t === 'leader') return '干部'; return esc(t) }
function renderItemType(t) { if (t === 'radio') return '<span class="type-tag">单选</span>'; if (t === 'checkbox') return '<span class="type-tag">多选</span>'; if (t === 'textarea') return '<span class="type-tag">文本</span>'; return esc(t) }
function renderAnswer(v) { if (!v) return '—'; try { var a = JSON.parse(v); if (Array.isArray(a)) return esc(a.join('、')) } catch (e) {} return esc(v) }
function esc(s) { if (!s) return '—'; var d = document.createElement('div'); d.textContent = s; return d.innerHTML }

function showLoginError(m) { loginError.textContent = m; loginError.style.display = 'block' }
function hideLoginError() { loginError.style.display = 'none' }
function showModalError(m) { modalError.textContent = m; modalError.style.display = 'block' }
function hideModalError() { modalError.style.display = 'none' }