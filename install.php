<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

$basePath = detectBasePath(__DIR__);
$installedFile = $basePath . '/api/storage/.installed';
$lockFile = __DIR__ . '/install.lock';
$envFile = $basePath . '/api/.env';
$forceReinstall = isset($_GET['reinstall']) && $_GET['reinstall'] === '1';
$isInstalled = !$forceReinstall && (file_exists($installedFile) || file_exists($lockFile));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(handleAction($basePath), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($isInstalled) {
    if (file_exists($installedFile)) {
        $info = json_decode(file_get_contents($installedFile), true) ?: [];
    } else {
        $info = [];
    }
    $installedEdition = htmlspecialchars(($info['edition'] ?? '') ?: (file_exists($basePath . '/api/app/routes-enterprise.php') ? '企业版' : '社区版'));
    $version = htmlspecialchars($info['version'] ?? 'v2026.05.28-1'); ?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>已安装</title>
<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f0f4f8;color:#2d3748;margin:0}
.box{background:#fff;padding:48px;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);text-align:center;max-width:420px}
h2{color:#38a169;margin-bottom:12px}p{color:#718096;line-height:1.6}code{background:#edf2f7;padding:2px 8px;border-radius:4px;font-size:13px}</style></head>
<body><div class="box"><h2>&#x2705; 系统已安装</h2>
<p>版本: <?php echo $version; ?> · <?php echo $installedEdition; ?></p>
<p>如需重新安装，请点击下方按钮：</p>
<p style="margin-top:12px"><a href="?reinstall=1" style="display:inline-block;padding:10px 24px;background:#e53e3e;color:#fff;border-radius:8px;text-decoration:none;font-size:14px">&#x1F504; 重新安装</a></p>
<p style="margin-top:8px;font-size:12px;color:#a0aec0">或手动删除 <code>install.lock</code> / <code>api/.env</code> 后刷新</p>
<p style="margin-top:16px"><a href="/" style="color:#1B5E9B">&rarr; 进入系统</a></p>
</div></body></html>
<?php exit;
}

$edition = file_exists($basePath . '/api/app/routes-enterprise.php') ? '企业版' : '社区版';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>安装向导 - 考核测评系统社区版</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif;background:#f0f4f8;color:#2d3748;line-height:1.6}
.container{max-width:720px;margin:40px auto;padding:0 20px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);padding:36px 40px}
.header{text-align:center;margin-bottom:32px}
.header h1{font-size:24px;font-weight:600;color:#1B5E9B;margin-bottom:6px}
.header p{color:#718096;font-size:14px}
.steps{display:flex;justify-content:center;gap:8px;margin-bottom:32px}
.step-dot{min-width:56px;height:32px;border-radius:16px;padding:0 8px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;white-space:nowrap;transition:.3s}
.step-dot.active{background:#1B5E9B;color:#fff}
.step-dot.done{background:#48bb78;color:#fff}
.step-dot.pending{background:#e2e8f0;color:#a0aec0}
.step-line{width:40px;height:2px;background:#e2e8f0;align-self:center;margin-top:-14px;transition:.3s}
.step-line.done{background:#48bb78}
.form-group{margin-bottom:20px}
.form-group label{display:block;font-size:14px;font-weight:500;color:#4a5568;margin-bottom:6px}
.form-group label .required{color:#e53e3e;margin-left:2px}
.form-group input,.form-group select{width:100%;padding:10px 14px;border:1px solid #cbd5e0;border-radius:8px;font-size:14px;color:#2d3748;background:#f7fafc;transition:border-color .2s}
.form-group input:focus,.form-group select:focus{outline:none;border-color:#1B5E9B;background:#fff;box-shadow:0 0 0 3px rgba(27,94,155,.1)}
.form-group .hint{font-size:12px;color:#a0aec0;margin-top:4px}
.form-row{display:flex;gap:16px}
.form-row .form-group{flex:1}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 24px;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;border:none;transition:.2s;gap:6px}
.btn-primary{background:#1B5E9B;color:#fff}.btn-primary:hover{background:#154c7d}.btn-primary:disabled{background:#a0aec0;cursor:not-allowed}
.btn-secondary{background:#edf2f7;color:#4a5568;border:1px solid #cbd5e0}.btn-secondary:hover{background:#e2e8f0}
.btn-success{background:#48bb78;color:#fff}.btn-success:hover{background:#38a169}
.btn-sm{padding:7px 16px;font-size:13px}
.actions{display:flex;justify-content:space-between;margin-top:28px;padding-top:20px;border-top:1px solid #e2e8f0}
.env-table{width:100%;border-collapse:collapse;margin-top:12px}
.env-table th,.env-table td{padding:10px 14px;text-align:left;font-size:13px;border-bottom:1px solid #edf2f7}
.env-table th{color:#718096;font-weight:500;width:30%}
.env-table .pass{color:#38a169;font-weight:600}
.env-table .fail{color:#e53e3e;font-weight:600}
.env-table .msg{color:#e53e3e;font-size:12px}
.welcome-icon{font-size:56px;margin-bottom:12px}
.feature-list{list-style:none;display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:20px 0}
.feature-list li{display:flex;align-items:center;gap:8px;font-size:14px;color:#4a5568}
.feature-list li::before{content:'\2713';color:#48bb78;font-weight:bold}
.progress-steps{margin-top:16px}
.progress-step{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #edf2f7}
.progress-step:last-child{border-bottom:none}
.ps-icon{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.ps-icon.ok{background:#c6f6d5;color:#22543d}
.ps-icon.fail{background:#fed7d7;color:#742a2a}
.ps-icon.running{background:#fefcbf;color:#744210;animation:pulse 1s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
.ps-text{font-size:14px}
.ps-text .action{font-weight:500;color:#2d3748}
.ps-text .error{color:#e53e3e;font-size:12px;margin-top:2px}
.success-box{text-align:center;padding:32px 0}
.success-box .big-icon{font-size:64px;margin-bottom:16px}
.success-box h2{font-size:22px;color:#22543d;margin-bottom:8px}
.success-box p{color:#4a5568;font-size:14px;margin-bottom:6px}
.nginx-hint{background:#ebf8ff;border:1px solid #90cdf4;border-radius:8px;padding:16px;margin-top:20px;text-align:left}
.nginx-hint h4{color:#2b6cb0;font-size:14px;margin-bottom:8px}
.nginx-hint pre{background:#fff;padding:12px;border-radius:6px;font-size:12px;color:#2d3748;overflow-x:auto;white-space:pre-wrap;word-break:break-all}
.php-mode-info{background:#faf5ff;border:1px solid #d6bcfa;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#553c9a}
.php-mode-info strong{color:#6b46c1}
.test-result{margin-top:6px;padding:8px 12px;border-radius:6px;font-size:13px;display:none}
.test-result.ok{background:#c6f6d5;color:#22543d;display:block}
.test-result.err{background:#fed7d7;color:#742a2a;display:block}
.hidden{display:none!important}
.step-view{display:none}
.step-view.active{display:block}
.edition-badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600}
.edition-community{background:#e6fffa;color:#234e52}
.edition-enterprise{background:#faf5ff;color:#44337a}
.section-card{background:#f7fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px 18px;margin-bottom:16px}
.section-card h4{font-size:14px;font-weight:600;color:#2d3748;margin-bottom:14px;display:flex;align-items:center;gap:6px}
.section-card h4 .badge{font-size:10px;padding:1px 8px;border-radius:10px;font-weight:500}
.section-card.db-section{border-left:3px solid #48bb78}
.section-card.db-section h4{color:#276749}
.section-card.server-section{border-left:3px solid #1B5E9B}
.section-card.server-section h4{color:#1B5E9B}
.section-card .hint{font-size:12px;color:#a0aec0;margin-top:4px}
.test-status.idle{background:#f7fafc;color:#a0aec0;border:1px dashed #e2e8f0}
.test-status.success{background:#c6f6d5;color:#276749;border:1px solid #9ae6b4}
.test-status.error{background:#fed7d7;color:#c53030;border:1px solid #feb2b2}
</style>
</head>
<body>
<div class="container">
<div class="card">
<div class="header">
<h1>考核测评系统社区版 <span class="edition-badge edition-<?php echo strtolower($edition) ?>"><?php echo htmlspecialchars($edition) ?></span></h1>
<p>v2026.05.28-1</p>
</div>

<div class="steps" id="stepDots"></div>

<div class="step-view active" id="view-0">
<div style="text-align:center;padding:20px 0"><div class="welcome-icon">&#x1F4CB;</div>
<h2 style="font-size:20px;color:#1B5E9B;margin-bottom:8px">欢迎使用测评系统安装向导</h2>
<p style="color:#718096;margin-bottom:24px">本向导将引导您完成系统初始化配置，预计需要 2-3 分钟</p>
<ul class="feature-list">
<li>环境自动检测</li><li>数据库一键建表</li><li>Nginx配置自动生成</li>
<li>管理员账号创建</li><li>.env 配置文件写入</li><li>安全参数自动生成</li>
</ul></div>
<div class="actions" style="justify-content:center;border:none">
<button class="btn btn-primary" onclick="goStep(1)">开始安装 &rarr;</button>
</div></div>

<div class="step-view" id="view-1">
<h3 style="font-size:17px;margin-bottom:4px">环境检测</h3><p class="hint" style="margin-bottom:16px">正在检测服务器运行环境是否满足要求...</p>
<div id="envResult"></div>
<div id="envLoading" style="text-align:center;padding:24px"><span style="font-size:18px">&#8987;</span>&nbsp; 正在检测...</div>
<div class="actions">
<button class="btn btn-secondary" onclick="goStep(0)">&larr; 上一步</button>
<button class="btn btn-primary" onclick="goStep(2)" id="btnNext1" disabled>下一步 &rarr;</button>
</div></div>

<div class="step-view" id="view-2">
<h3 style="font-size:17px;margin-bottom:4px">数据库与系统配置</h3><p class="hint" style="margin-bottom:16px">请填写以下配置信息，系统将自动完成初始化</p>
<div class="php-mode-info" id="phpModeInfo"></div>

<div class="section-card db-section">
<h4>&#x1F5C4; 数据库连接 <span class="badge" style="background:#c6f6d5;color:#22543d">MySQL</span></h4>
<div class="form-row"><div class="form-group"><label>主机 <span class="required">*</span></label><input type="text" id="db_host" value="127.0.0.1"></div>
<div class="form-group"><label>端口 <span class="required">*</span></label><input type="text" id="db_port" value="3306"></div></div>
<div class="form-row"><div class="form-group"><label>用户名 <span class="required">*</span></label><input type="text" id="db_username" value="root"></div>
<div class="form-group"><label>密码 <span class="required">*</span></label><input type="password" id="db_password" placeholder="MySQL 密码"></div></div>
<div class="form-group"><label>数据库名称 <span class="required">*</span></label><input type="text" id="db_database" value="examine_system"><div class="hint">如果不存在将自动创建</div></div>
</div>

<div class="section-card">
<h4>&#x1F310; 系统访问地址</h4>
<div class="form-group"><label>应用地址 (APP_URL) <span class="required">*</span></label><input type="text" id="app_url" value=""><div class="hint">用户通过浏览器访问本系统的完整地址</div><div id="appUrlError" style="color:#e53e3e;font-size:12px;margin-top:4px;display:none"></div></div>
</div>

<div class="section-card server-section">
<h4>&#x1F3D7; Web 服务器配置</h4>
<div class="form-group"><label>域名</label><input type="text" id="domain" value=""><div class="hint">如 cp.zmr 或 192.168.1.100</div></div>
<div class="form-row"><div class="form-group"><label>后台端口 <span class="required">*</span></label><input type="text" id="admin_port" value="2001"><div id="adminPortError" style="color:#e53e3e;font-size:12px;margin-top:4px;display:none"></div></div>
<div class="form-group"><label>移动端端口 <span class="required">*</span></label><input type="text" id="mobile_port" value="2002"><div id="mobilePortError" style="color:#e53e3e;font-size:12px;margin-top:4px;display:none"></div></div></div>
<div class="form-group"><label>PHP FastCGI 地址 <span class="required">*</span></label>
<div style="display:flex;gap:8px;align-items:flex-start">
<div style="flex:1">
<input type="text" id="php_pass" value="" placeholder="如 127.0.0.1:9000" style="width:100%;padding:10px 14px;border:1px solid #cbd5e0;border-radius:8px;font-size:14px;color:#2d3748;background:#f7fafc">
<div class="hint" id="phpPassHint">系统已自动检测，如不正确请手动修改</div>
</div>
<button type="button" class="btn btn-primary btn-sm" onclick="testFastcgi()" id="btnTestFcgi" style="flex-shrink:0;margin-top:4px;height:42px">&#x1F50D; 测试连接</button>
</div>
<div id="phpPassAutoDetect" style="color:#38a169;font-size:12px;margin-top:4px;display:none"></div>
<div id="phpPassError" style="color:#e53e3e;font-size:12px;margin-top:4px;display:none"></div>
<div id="fcgiStatus" class="test-status idle" style="margin-top:8px;padding:8px 12px;border-radius:6px;font-size:13px;background:#f7fafc;color:#a0aec0;border:1px dashed #e2e8f0">点击「测试连接」按钮检测 PHP 是否可连接</div>
</div>

<div class="actions"><button class="btn btn-secondary" onclick="goStep(1)">&larr; 上一步</button><button class="btn btn-primary" onclick="goStep(3)" id="btnNext2" disabled>下一步 &rarr;</button></div></div></div>

<div class="step-view" id="view-3">
<h3 style="font-size:17px;margin-bottom:4px">管理员账号设置</h3><p class="hint" style="margin-bottom:16px">创建系统超级管理员账号</p>
<div class="form-group"><label>管理员用户名 <span class="required">*</span></label><input type="text" id="admin_name" value="admin"></div>
<div class="form-group"><label>管理员密码 <span class="required">*</span></label><input type="password" id="admin_password" placeholder="至少 6 位字符"><div class="hint">密码将被 bcrypt 加密存储（cost=12）</div></div>
<div class="form-group"><label>确认密码 <span class="required">*</span></label><input type="password" id="admin_password_confirm" placeholder="再次输入密码"></div>
<div class="actions"><button class="btn btn-secondary" onclick="goStep(2)">&larr; 上一步</button><button class="btn-success btn-block" onclick="runInstall()" id="btnInstall" style="margin-left:16px;flex:1">&#x2713; 开始安装</button></div></div>

<div class="step-view" id="view-4">
<div id="installWaiting" style="text-align:center;padding:40px 0">
<div style="font-size:48px;margin-bottom:12px">&#x2699;</div>
<h3 style="color:#2d3748;font-size:18px;margin-bottom:12px">正在初始化配置，请耐心等待...</h3>
<p style="color:#718096;font-size:14px">系统正在执行安装操作，预计需要 1-2 分钟</p>
<p style="color:#a0aec0;font-size:13px;margin-top:8px">期间请勿关闭浏览器或刷新页面</p>
</div>
<div class="success-box" id="installSuccess" style="display:none"><div class="big-icon">&#x2705;</div><h2>安装完成！</h2><p>系统已成功初始化，可以开始使用了</p></div>
<div id="installFailed" style="display:none;text-align:center;padding:24px 0"><div style="font-size:48px;margin-bottom:12px">&#x274C;</div><h2 style="color:#c53030;font-size:20px">安装失败</h2><p id="failReason" style="color:#e53e3e;margin-top:8px"></p></div>
<div class="progress-steps" id="progressSteps" style="margin-top:20px"></div>
<div class="nginx-hint" id="nginxHint" style="display:none">
<h4>&#x1F4DD; 下一步：配置 Nginx</h4>
<p style="font-size:13px;color:#4a5568;margin-bottom:8px">系统已自动生成以下文件：</p>
<ul style="font-size:13px;color:#2d3748;margin-left:20px;margin-bottom:12px">
<li><strong style="color:#2b6cb0">generated.conf</strong> - Nginx 配置文件</li>
<li><strong style="color:#2b6cb0">宝塔配置指南.txt</strong> - 详细操作指南（包含宝塔面板完整步骤）</li>
</ul>
<p style="font-size:13px;color:#4a5568;margin-bottom:8px"><strong>快速配置方法（推荐宝塔面板）：</strong></p>
<ol style="font-size:13px;color:#2d3748;margin-left:20px;margin-bottom:12px">
<li>登录宝塔面板 → 网站 → 添加站点（后台端口：<span id="nginxAdminPort">2001</span>），根目录指向 <code>backend/dist</code></li>
<li>再次添加站点（移动端端口：<span id="nginxMobilePort">2002</span>），根目录指向 <code>mobile/dist</code></li>
<li>分别在两个站点的【设置】→【配置文件】中，分别复制 generated.conf 中对应的 server 块</li>
<li>宝塔面板 → 软件商店 → Nginx → 设置 → 服务 → 重载配置</li>
</ol>
<p style="font-size:12px;color:#718096;margin-top:8px">或直接查看 <strong>宝塔配置指南.txt</strong> 获取完整图文步骤！</p>
</div>
<div class="actions" style="justify-content:center;border:none;margin-top:20px">
<a class="btn btn-primary" href="/" id="gotoHome" style="display:none">&#x1F3E0; 进入系统</a>
<button class="btn btn-secondary" onclick="location.reload()" id="btnRetry" style="display:none">&#x1F504; 重新安装</button></div></div>
</div></div>

<script>
const API_URL = location.pathname.replace(/\/install\.php.*$/, '') + '/install.php';
let currentStep = 0;
const totalSteps = 4;
let fastcgiTestPassed = false;
let lastFastcgiTestValue = '';

function renderSteps(){
    const stepLabels=['\u7B2C\u4E00\u6B65','\u7B2C\u4E8C\u6B65','\u7B2C\u4E09\u6B65','\u7B2C\u56DB\u6B65','\u7B2C\u4E94\u6B65'];
    const c=document.getElementById('stepDots');c.innerHTML='';
    for(let i=0;i<=totalSteps;i++){
        const dot=document.createElement('div');
        dot.className='step-dot '+(i<currentStep?'done':i===currentStep?'active':'pending');
        dot.textContent=stepLabels[i];
        if(i<totalSteps){const line=document.createElement('div');line.className='step-line '+(i<currentStep?'done':'');c.appendChild(dot);c.appendChild(line);}else c.appendChild(dot);
    }
}

function goStep(n){
    document.querySelectorAll('.step-view').forEach(v=>v.classList.remove('active'));
    document.getElementById('view-'+n).classList.add('active');
    currentStep=n;renderSteps();
    if(n===1)checkEnv();if(n===2){loadPhpInfo();initConfigForm();}
}

const forbiddenPorts = new Set([21,22,25,53,80,110,143,443,465,587,993,995,3306,33060,6379,11211]);

function validateConfig(){
    let allOk = true;

    // 验证 APP_URL
    const appUrl = document.getElementById('app_url').value.trim();
    const appUrlErr = document.getElementById('appUrlError');
    if(!appUrl){
        appUrlErr.textContent = '请填写应用地址';
        appUrlErr.style.display = 'block';
        allOk = false;
    }else if(!/^https?:\/\/.+/.test(appUrl)){
        appUrlErr.textContent = '应用地址必须以 http:// 或 https:// 开头';
        appUrlErr.style.display = 'block';
        allOk = false;
    }else{
        appUrlErr.style.display = 'none';
    }

    // 验证后台端口
    const adminPort = parseInt(document.getElementById('admin_port').value);
    const adminPortErr = document.getElementById('adminPortError');
    if(isNaN(adminPort)){
        adminPortErr.textContent = '端口必须是数字';
        adminPortErr.style.display = 'block';
        allOk = false;
    }else if(adminPort < 1024 || adminPort > 65535){
        adminPortErr.textContent = '端口必须在 1024-65535 之间';
        adminPortErr.style.display = 'block';
        allOk = false;
    }else if(forbiddenPorts.has(adminPort)){
        adminPortErr.textContent = '该端口（' + adminPort + '）是常用端口，请更换其他端口';
        adminPortErr.style.display = 'block';
        allOk = false;
    }else{
        adminPortErr.style.display = 'none';
    }

    // 验证移动端端口
    const mobilePort = parseInt(document.getElementById('mobile_port').value);
    const mobilePortErr = document.getElementById('mobilePortError');
    if(isNaN(mobilePort)){
        mobilePortErr.textContent = '端口必须是数字';
        mobilePortErr.style.display = 'block';
        allOk = false;
    }else if(mobilePort < 1024 || mobilePort > 65535){
        mobilePortErr.textContent = '端口必须在 1024-65535 之间';
        mobilePortErr.style.display = 'block';
        allOk = false;
    }else if(forbiddenPorts.has(mobilePort)){
        mobilePortErr.textContent = '该端口（' + mobilePort + '）是常用端口，请更换其他端口';
        mobilePortErr.style.display = 'block';
        allOk = false;
    }else{
        mobilePortErr.style.display = 'none';
    }

    // 验证两个端口不能相同
    if(!isNaN(adminPort) && !isNaN(mobilePort) && adminPort === mobilePort){
        adminPortErr.textContent = '后台端口和移动端端口不能相同';
        adminPortErr.style.display = 'block';
        allOk = false;
    }

    // 检查端口与 fastcgi_pass 端口冲突
    const phpPassVal = document.getElementById('php_pass').value.trim();
    const phpPortMatch = phpPassVal.match(/:(\d+)$/);
    if(phpPortMatch && !isNaN(adminPort) && adminPort === parseInt(phpPortMatch[1])){
        adminPortErr.textContent = '后台端口不能与 fastcgi_pass 端口(' + phpPortMatch[1] + ')相同';
        adminPortErr.style.display = 'block';
        allOk = false;
    }
    if(phpPortMatch && !isNaN(mobilePort) && mobilePort === parseInt(phpPortMatch[1])){
        mobilePortErr.textContent = '移动端端口不能与 fastcgi_pass 端口(' + phpPortMatch[1] + ')相同';
        mobilePortErr.style.display = 'block';
        allOk = false;
    }

    // 验证 fastcgi_pass
    const phpPass = document.getElementById('php_pass').value.trim();
    const phpPassErr = document.getElementById('phpPassError');
    if(!phpPass){
        phpPassErr.textContent = '请填写 fastcgi_pass 地址';
        phpPassErr.style.display = 'block';
        allOk = false;
    }else if(!/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}:\d+|unix:\/.+)$/.test(phpPass)){
        phpPassErr.textContent = '格式错误，应为 IP:端口（如 127.0.0.1:9000）或 unix:/path/to/sock';
        phpPassErr.style.display = 'block';
        allOk = false;
    }else if(!fastcgiTestPassed || lastFastcgiTestValue !== phpPass){
        phpPassErr.textContent = '请先点击“测试连接”，确认 PHP FastCGI 地址可用后再继续';
        phpPassErr.style.display = 'block';
        allOk = false;
    }else{
        phpPassErr.style.display = 'none';
    }

    document.getElementById('btnNext2').disabled = !allOk;
    return allOk;
}

function initConfigForm(){
    // 自动填充当前访问地址
    const protocol = window.location.protocol;
    const host = window.location.hostname;
    const port = window.location.port;
    let appUrlDefault = protocol + '//' + host;
    if(port && port !== '80' && port !== '443'){
        appUrlDefault += ':' + port;
    }
    document.getElementById('app_url').value = appUrlDefault;
    document.getElementById('domain').value = host;

    // 添加输入事件监听
    ['app_url','admin_port','mobile_port','php_pass'].forEach(id=>{
        document.getElementById(id).addEventListener('input', ()=>{
            if(id === 'php_pass'){
                fastcgiTestPassed = false;
                lastFastcgiTestValue = '';
                const statusEl = document.getElementById('fcgiStatus');
                statusEl.className = 'test-status idle';
                statusEl.textContent = 'FastCGI 地址已修改，请重新测试连接';
            }
            validateConfig();
        });
    });

    validateConfig();
}

async function apiCall(action,data){
    const fd=new FormData();
    fd.append('action',action);
    if(data) Object.keys(data).forEach(k=>fd.append(k,data[k]));
    try{
        const r=await fetch(API_URL,{method:'POST',body:fd});
        if(!r.ok){return{success:false,message:'HTTP '+r.status+': '+r.statusText};}
        const txt=await r.text();
        try{return JSON.parse(txt);}catch(e){return{success:false,message:'响应格式错误: '+txt.substring(0,120)};}
    }catch(e){return{success:false,message:'网络请求失败: '+e.message};}
}

async function checkEnv(){
    const el=document.getElementById('envResult'),ld=document.getElementById('envLoading');
    ld.style.display='block';el.innerHTML='';
    const res=await apiCall('check-env');ld.style.display='none';
    if(!res||!res.items){return el.innerHTML='<p style="color:#e53e3e;text-align:center;padding:20px">无法获取环境信息'+(res&&res.message?'<br>'+res.message:'')+'</p>';}
    let html='<table class="env-table"><tr><th>检测项</th><th>当前值</th><th>要求</th><th>状态</th></tr>';
    res.items.forEach(item=>{
        html+=`<tr><td>${esc(item.name)}</td><td>${esc(String(item.current))}</td><td>${esc(item.required)}</td>`;
        html+=item.pass?'<td class="pass">&#x2713; 通过</td>':`<td class="fail">&#x2717; 未通过</td>`;
        if(item.msg)html+=`<tr><td colspan="4" class="msg">${esc(item.msg)}</td></tr>`;
    });html+='</table>';el.innerHTML=html;
    document.getElementById('btnNext1').disabled=!res.all_pass;
}

async function loadPhpInfo(){
    const ppEl=document.getElementById('php_pass');
    const res=await apiCall('php-info');
    if(!res||!res.detected_mode){
        const errMsg=res&&res.message?res.message:'未知错误';
        document.getElementById('phpModeInfo').innerHTML='<strong style="color:#c53030">自动检测失败</strong><br><span style="font-size:12px;color:#718096">错误: '+esc(errMsg)+'，请手动填写 FastCGI 地址</span>';
        if(!ppEl.value){ppEl.value='127.0.0.1:9000';ppEl.dataset.auto='1';}
        validateConfig();
        return;
    }
    const m=res.detected_mode;
    document.getElementById('phpModeInfo').innerHTML=`<strong>检测到 PHP 模式:</strong> ${esc(m.label)}<br><span style="font-size:12px">${esc(m.hint)}</span>`;
    if(!ppEl.value || ppEl.dataset.auto==='1'){
        ppEl.value=m.default_pass;
        ppEl.dataset.auto='1';
    }
    document.getElementById('phpPassHint').textContent=m.hint;
    if(m.auto_detected && m.auto_detected!==m.default_pass){
        document.getElementById('phpPassAutoDetect').textContent='\u2705 自动检测到: '+m.auto_detected;
        document.getElementById('phpPassAutoDetect').style.display='block';
    }else if(m.auto_detected){
        document.getElementById('phpPassAutoDetect').textContent='\u2705 已自动检测PHP监听地址';
        document.getElementById('phpPassAutoDetect').style.display='block';
    }
    validateConfig();
}

async function testFastcgi(){
    const btn=document.getElementById('btnTestFcgi');const statusEl=document.getElementById('fcgiStatus');
    btn.disabled=true;btn.textContent='测试中...';
    statusEl.className='test-status idle';statusEl.textContent='正在连接...';
    const phpPass=document.getElementById('php_pass').value.trim();
    if(!phpPass){statusEl.className='test-status error';statusEl.textContent='请先填写 FastCGI 地址';btn.disabled=false;btn.textContent='测试连接';fastcgiTestPassed=false;validateConfig();return;}
    const res=await apiCall('test-fastcgi',{php_pass:phpPass});
    btn.disabled=false;btn.textContent='测试连接';
    if(!res){statusEl.className='test-status error';statusEl.textContent='请求失败';fastcgiTestPassed=false;validateConfig();return;}
    if(res.success){
        fastcgiTestPassed=true;
        lastFastcgiTestValue=phpPass;
        statusEl.className='test-status success';
        statusEl.textContent='连接成功：'+(res.message || 'PHP FastCGI 可连接');
    }else{
        fastcgiTestPassed=false;
        lastFastcgiTestValue='';
        statusEl.className='test-status error';
        statusEl.textContent=res.message || '连接失败，请检查 PHP 服务是否启动、端口是否正确';
    }
    validateConfig();
}

async function testDb(){
    const el=document.getElementById('dbTestResult');
    el.style.display='none';el.className='test-result';el.textContent='';
    try{
        const res=await apiCall('test-db',{db_host:document.getElementById('db_host').value,db_port:document.getElementById('db_port').value,db_username:document.getElementById('db_username').value,db_password:document.getElementById('db_password').value,db_database:document.getElementById('db_database').value});
        if(!res||typeof res.success==='undefined'){el.textContent='\u274C 网络请求失败: '+(res&&res.message?res.message:'无响应');el.className='test-result err';return;}
        if(res.success){el.textContent='\u2705 '+res.message;el.className='test-result ok';}else{el.textContent='\u274C '+res.message;el.className='test-result err';}
    }catch(e){el.textContent='\u274C JS错误: '+e.message;el.className='test-result err';}
}

async function runInstall(){
    try{
        const pwd=document.getElementById('admin_password').value,pwd2=document.getElementById('admin_password_confirm').value;
        if(pwd.length<6){alert('密码至少需要6位');return;}
        if(pwd!==pwd2){alert('两次输入的密码不一致');return;}
        const data={db_host:document.getElementById('db_host').value,db_port:document.getElementById('db_port').value,db_username:document.getElementById('db_username').value,db_password:document.getElementById('db_password').value,db_database:document.getElementById('db_database').value,app_url:document.getElementById('app_url').value,domain:document.getElementById('domain').value,admin_port:document.getElementById('admin_port').value,mobile_port:document.getElementById('mobile_port').value,php_pass:document.getElementById('php_pass').value,admin_name:document.getElementById('admin_name').value,admin_password:pwd};
        document.getElementById('btnInstall').disabled=true;document.getElementById('btnInstall').textContent='正在安装...';
        document.getElementById('installWaiting').style.display='block';
        document.getElementById('installSuccess').style.display='none';
        document.getElementById('installFailed').style.display='none';
        document.getElementById('nginxHint').style.display='none';
        document.getElementById('gotoHome').style.display='none';
        document.getElementById('btnRetry').style.display='none';
        goStep(4);
        showProgress(data);
    }catch(e){
        alert('安装出错: ' + e.message);
        document.getElementById('installWaiting').style.display='none';
        document.getElementById('installFailed').style.display='block';
        document.getElementById('failReason').textContent = 'JS错误: ' + e.message;
        document.getElementById('btnRetry').style.display='inline-flex';
    }
}

async function showProgress(data){
    try{
        const res=await apiCall('run',data),container=document.getElementById('progressSteps');
        document.getElementById('installWaiting').style.display='none';
        let html='';
        if(res&&res.steps){res.steps.forEach(s=>{
            const iconCls=s.status==='ok'?'ok':s.status==='fail'?'fail':'running';
            const icon=s.status==='ok'?'\u2713':s.status==='fail'?'\u2717':'...';
            html+=`<div class="progress-step"><div class="ps-icon ${iconCls}">${icon}</div><div class="ps-text"><div class="action">${esc(s.action)}</div>`;if(s.error)html+=`<div class="error">${esc(s.error)}</div>`;html+='</div></div>';
        });}else{html='<div class="progress-step"><div class="ps-icon fail">&#x2717;</div><div class="ps-text"><div class="action">请求失败</div><div class="error">'+(res&&res.message?esc(res.message):'服务器无响应')+'</div></div></div>';}
        container.innerHTML=html;
        if(res&&res.success){
            document.getElementById('installSuccess').style.display='block';document.getElementById('installFailed').style.display='none';
            document.getElementById('gotoHome').style.display='inline-flex';document.getElementById('nginxHint').style.display='block';
            document.getElementById('nginxAdminPort').textContent = data.admin_port;
            document.getElementById('nginxMobilePort').textContent = data.mobile_port;
        }else{
            document.getElementById('installSuccess').style.display='none';document.getElementById('installFailed').style.display='block';
            document.getElementById('btnRetry').style.display='inline-flex';
            let reason='';if(res&&res.steps){const f=res.steps.find(s=>s.status==='fail');if(f&&f.error)reason=f.error;}
            if(!reason)reason=res&&res.message?res.message:'未知错误，请查看服务器日志';
            document.getElementById('failReason').textContent=reason;
        }
    }catch(e){
        document.getElementById('installWaiting').style.display='none';
        document.getElementById('installFailed').style.display='block';
        document.getElementById('failReason').textContent = 'JS错误: ' + e.message;
        document.getElementById('btnRetry').style.display='inline-flex';
    }
}

function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
renderSteps();
</script>
</body>
</html>
<?php

function detectBasePath(string $currentDir): string
{
    $markers = ['api/public/index.php', 'database/init.sql', 'composer.json'];
    $dir = $currentDir;
    for ($i = 0; $i < 6; $i++) {
        foreach ($markers as $m) {
            if (file_exists($dir . '/' . $m)) return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return $currentDir;
}

function handleAction(string $basePath): array
{
    $action = $_POST['action'] ?? '';
    try {
        return match ($action) {
            'check-env' => checkEnv($basePath),
            'php-info' => getPhpInfo(),
            'test-db' => testDbConnection(),
            'test-fastcgi' => testFastcgiConnection(),
            'run' => runInstall($basePath),
            default => ['code' => 0, 'message' => '未知操作'],
        };
    } catch (\Throwable $e) {
        return ['code' => 0, 'message' => '服务器错误: '.$e->getMessage(), 'success' => false];
    }
}

function checkEnv(string $basePath): array
{
    $results = [];

    $results[] = [
        'name' => 'PHP 版本', 'current' => PHP_VERSION, 'required' => '>= 8.1',
        'pass' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'msg' => version_compare(PHP_VERSION, '8.1.0', '>=') ? '' : "需要 8.1.0+，当前 " . PHP_VERSION,
    ];

    foreach (['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json', 'curl', 'gd', 'dom', 'fileinfo'] as $ext) {
        $loaded = extension_loaded($ext);
        $results[] = [
            'name' => "扩展: {$ext}", 'current' => $loaded ? '已加载' : '未加载',
            'required' => '必须', 'pass' => $loaded,
            'msg' => $loaded ? '' : "请在 php.ini 中启用 extension={$ext}",
        ];
    }

    foreach (['storage/', 'uploads/', 'logs/'] as $dir) {
        $fullPath = $basePath . '/' . $dir;
        if (!is_dir($fullPath)) @mkdir($fullPath, 0755, true);
        $writable = is_writable($fullPath);
        $results[] = [
            'name' => "权限: {$dir}", 'current' => $writable ? '可写' : '不可写',
            'required' => '可写', 'pass' => $writable,
            'msg' => $writable ? '' : "chmod 777 {$dir}",
        ];
    }

    $mmdbExists = file_exists($basePath . '/data/Country-without-asn.mmdb');
    $results[] = [
        'name' => 'GeoIP数据库', 'current' => $mmdbExists ? '存在' : '不存在',
        'required' => '可选', 'pass' => true, 'msg' => '',
    ];

    return ['all_pass' => !in_array(false, array_column($results, 'pass')), 'items' => $results];
}

function getPhpInfo(): array
{
    $sapi = strtolower(PHP_SAPI);
    try {
        $detected = detectPhpFastcgiPort();
    } catch (\Throwable $e) {
        $detected = '127.0.0.1:9000';
    }

    if (str_contains($sapi, 'fpm')) {
        return ['detected_mode'=>['mode'=>'fpm','label'=>'PHP-FPM (Linux标准)','default_pass'=>$detected,'hint'=>'已自动检测PHP-FPM监听地址，如不正确请手动修改','auto_detected'=>$detected]];
    }
    if (str_contains($sapi, 'cgi')) {
        return ['detected_mode'=>['mode'=>'cgi','label'=>'PHP-CGI (Windows)','default_pass'=>$detected,'hint'=>'已自动检测PHP-CGI监听地址，如不正确请手动修改','auto_detected'=>$detected]];
    }
    return ['detected_mode'=>['mode'=>'unknown','label'=>'未知','default_pass'=>$detected,'hint'=>'已尝试自动检测，如不正确请手动修改','auto_detected'=>$detected]];
}

function detectPhpFastcgiPort(): string
{
    $default = '127.0.0.1:9000';
    $configuredCandidates = [];
    $oldEr = error_reporting(0);

    $iniPath = @php_ini_loaded_file();
    if ($iniPath && @file_exists($iniPath)) {
        $phpDir = dirname($iniPath);
        $checkList = [];
        $fpmConfs = [
            $phpDir.'/php-fpm.conf',
            $phpDir.'/etc/php-fpm.conf',
            $phpDir.'/etc/php-fpm.d/www.conf',
            $phpDir.'/etc/php-fpm.d/www.conf.default',
            $phpDir.'/php-fpm.d/www.conf',
            $phpDir.'/php-fpm.d/www.conf.default',
        ];
        foreach ($fpmConfs as $cf) {
            if (@file_exists($cf)) $checkList[] = $cf;
        }
        foreach ([$phpDir.'/etc/php-fpm.d',$phpDir.'/php-fpm.d'] as $poolDir) {
            $files = @glob($poolDir.'/*.conf');
            if ($files) foreach ($files as $cf) $checkList[] = $cf;
        }
        foreach ($checkList as $cf) {
            $port = @parseFpmListen($cf);
            if ($port && @verifyFcgiPort($port)) { error_reporting($oldEr); return $port; }
            if ($port) $configuredCandidates[] = $port;
        }
    }

    foreach (['D:/BtSoft','C:/BtSoft','/www/server'] as $bt) {
        if (!@is_dir($bt)) continue;
        $phpDirs = @glob($bt.'/php/*', GLOB_ONLYDIR);
        if (!$phpDirs) continue;
        usort($phpDirs, fn($a,$b)=>version_compare(basename($b),basename($a)));
        foreach ($phpDirs as $pd) {
            $fpmConfs = [
                $pd.'/etc/php-fpm.conf',
                $pd.'/etc/php-fpm.d/www.conf',
                $pd.'/etc/php-fpm.d/www.conf.default',
                $pd.'/php-fpm.d/www.conf',
                $pd.'/php-fpm.d/www.conf.default',
            ];
            foreach ($fpmConfs as $cf) {
                if (!@file_exists($cf)) continue;
                $port = @parseFpmListen($cf);
                if ($port && @verifyFcgiPort($port)) { error_reporting($oldEr); return $port; }
                if ($port) $configuredCandidates[] = $port;
            }
            foreach ([$pd.'/etc/php-fpm.d',$pd.'/php-fpm.d'] as $poolDir) {
                $files = @glob($poolDir.'/*.conf');
                if ($files) foreach ($files as $cf) {
                    $port = @parseFpmListen($cf);
                    if ($port && @verifyFcgiPort($port)) { error_reporting($oldEr); return $port; }
                    if ($port) $configuredCandidates[] = $port;
                }
            }
        }
        $nginxDir = $bt.'/nginx/conf/php';
        if (@is_dir($nginxDir)) {
            $files = @glob($nginxDir.'/*.conf');
            if ($files) foreach ($files as $cf) {
                $content = @file_get_contents($cf);
                if ($content && preg_match('/fastcgi_pass\s+([^;]+);/', $content, $m)) {
                    $val = trim($m[1]);
                    if (preg_match('/^[\d.]+:\d+$/', $val) || str_starts_with($val,'unix:')) {
                        if (@verifyFcgiPort($val)) { error_reporting($oldEr); return $val; }
                        $configuredCandidates[] = $val;
                    }
                }
            }
        }
        foreach ([$bt.'/nginx/conf/enable-php-*.conf'] as $globPattern) {
            $files = @glob($globPattern);
            if ($files) foreach ($files as $cf) {
                $content = @file_get_contents($cf);
                if ($content && preg_match('/fastcgi_pass\s+([^;]+);/', $content, $m)) {
                    $val = trim($m[1]);
                    if (preg_match('/^[\d.]+:\d+$/', $val) || str_starts_with($val,'unix:')) {
                        if (@verifyFcgiPort($val)) { error_reporting($oldEr); return $val; }
                        $configuredCandidates[] = $val;
                    }
                }
            }
        }
        foreach ([$bt.'/panel/vhost/nginx',$bt.'/nginx/conf/vhost'] as $vhostDir) {
            if (!@is_dir($vhostDir)) continue;
            $files = @glob($vhostDir.'/*.conf');
            if ($files) foreach ($files as $cf) {
                $content = @file_get_contents($cf);
                if ($content && preg_match('/fastcgi_pass\s+([^;]+);/', $content, $m)) {
                    $val = trim($m[1]);
                    if (preg_match('/^[\d.]+:\d+$/', $val) || str_starts_with($val,'unix:')) {
                        if (@verifyFcgiPort($val)) { error_reporting($oldEr); return $val; }
                        $configuredCandidates[] = $val;
                    }
                }
            }
        }
    }

    foreach (['D:/phpEnv','C:/phpEnv'] as $pe) {
        if (!@is_dir($pe)) continue;
        $phpDirs = @glob($pe.'/php/*', GLOB_ONLYDIR);
        if (!$phpDirs) continue;
        foreach ($phpDirs as $pd) {
            foreach ([$pd.'/php-fpm.conf',$pd.'/etc/php-fpm.conf'] as $cf) {
                if (!@file_exists($cf)) continue;
                $port = @parseFpmListen($cf);
                if ($port && @verifyFcgiPort($port)) { error_reporting($oldEr); return $port; }
                if ($port) $configuredCandidates[] = $port;
            }
        }
    }

    $linuxConfs = ['/etc/php-fpm.conf','/etc/php-fpm.d/www.conf'];
    foreach ($linuxConfs as $cf) {
        if (!@file_exists($cf)) continue;
        $port = @parseFpmListen($cf);
        if ($port && @verifyFcgiPort($port)) { error_reporting($oldEr); return $port; }
        if ($port) $configuredCandidates[] = $port;
    }
    foreach (['/etc/php/*/fpm/php-fpm.conf','/etc/php/*/fpm/pool.d/www.conf'] as $pattern) {
        $files = @glob($pattern);
        if ($files) foreach ($files as $cf) {
            $port = @parseFpmListen($cf);
            if ($port && @verifyFcgiPort($port)) { error_reporting($oldEr); return $port; }
            if ($port) $configuredCandidates[] = $port;
        }
    }

    $socketPaths = [];
    $phpVer = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    $socketPaths[] = '/tmp/php-cgi-'.$phpVer.'.sock';
    $socketPaths[] = '/tmp/php-cgi-'.PHP_MAJOR_VERSION.PHP_MINOR_VERSION.'.sock';
    $socketPaths[] = '/run/php/php'.$phpVer.'-fpm.sock';
    $socketPaths[] = '/run/php/php-fpm.sock';
    $socketPaths[] = '/var/run/php'.$phpVer.'-fpm.sock';
    $socketPaths[] = '/var/run/php-fpm.sock';
    $socketPaths[] = '/var/run/php/php'.$phpVer.'-fpm.sock';
    foreach ($socketPaths as $sp) {
        if (@file_exists($sp)) { error_reporting($oldEr); return 'unix:'.$sp; }
    }

    $scanPorts = [9000,9010,9011,9012,20081,20082,20083,9001,9002,9003,9004];
    foreach ($scanPorts as $p) {
        $fp = @fsockopen('127.0.0.1', $p, $errno, $errstr, 0.5);
        if ($fp) { fclose($fp); error_reporting($oldEr); return "127.0.0.1:{$p}"; }
    }

    $configuredCandidates = array_values(array_unique(array_filter($configuredCandidates)));
    if ($configuredCandidates) { error_reporting($oldEr); return $configuredCandidates[0]; }

    error_reporting($oldEr);
    return $default;
}

function verifyFcgiPort(string $addr): bool
{
    if (str_starts_with($addr, 'unix:')) {
        $sock = substr($addr, 5);
        return @file_exists($sock);
    }
    if (!preg_match('/^([\d.]+):(\d+)$/', $addr, $m)) return false;
    $fp = @fsockopen($m[1], (int)$m[2], $errno, $errstr, 1);
    if ($fp) { fclose($fp); return true; }
    return false;
}

function parseFpmListen(string $confFile): ?string
{
    $content = @file_get_contents($confFile);
    if (!$content) return null;
    if (preg_match('/^\s*listen\s*=\s*(.+)$/m', $content, $m)) {
        $val = trim($m[1]);
        if (str_starts_with($val, 'unix:')) return $val;
        if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}):(\d+)$/', $val, $pm)) return $pm[1].':'.$pm[2];
        if (ctype_digit($val)) return '127.0.0.1:'.$val;
        if (preg_match('/^\/[^\s]+\.(sock|socket)$/', $val)) return 'unix:'.$val;
    }
    $dir = dirname($confFile);
    if (preg_match('/^\s*include\s*=\s*(.+)$/m', $content, $m)) {
        $incPattern = trim($m[1]);
        if (!str_contains($incPattern, '/') && !str_contains($incPattern, '\\')) $incPattern = $dir.'/'.$incPattern;
        foreach (glob($incPattern) as $inc) {
            $port = parseFpmListen($inc);
            if ($port) return $port;
        }
    }
    return null;
}

function testDbConnection(): array
{
    try {
        $dsn = "mysql:host={$_POST['db_host']};port={$_POST['db_port']};charset=utf8mb4";
        $pdo = new \PDO($dsn, $_POST['db_username'], $_POST['db_password'], [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_TIMEOUT=>5]);
        $dbName = trim($_POST['db_database']);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci");
        return ['success'=>true,'message'=>'连接成功，数据库已就绪'];
    } catch (\PDOException $e) {
        $msg = match(true) {
            str_contains($e->getMessage(),'Connection refused') => '无法连接MySQL，检查主机和端口',
            str_contains($e->getMessage(),'Access denied') => '用户名或密码错误',
            default => '错误: '.$e->getMessage(),
        };
        return ['success'=>false,'message'=>$msg];
    }
}

function testFastcgiConnection(): array
{
    $addr = trim($_POST['php_pass'] ?? '');
    if (!$addr) return ['success'=>false,'message'=>'请填写 FastCGI 地址'];

    if (str_starts_with($addr, 'unix:')) {
        $sock = substr($addr, 5);
        if (@file_exists($sock)) return ['success'=>true,'message'=>'Unix Socket 可用: '.$sock];
        return ['success'=>false,'message'=>'Unix Socket 文件不存在: '.$sock.'。请在服务器上执行 ls -la '.$sock.' 确认'];
    }

    if (!preg_match('/^([\d.]+):(\d+)$/', $addr, $m)) return ['success'=>false,'message'=>'地址格式错误，应为 IP:端口（如 127.0.0.1:9000）或 unix:/path/to/sock'];

    $host=$m[1]; $port=(int)$m[2];
    $fp = @fsockopen($host, $port, $errno, $errstr, 3);
    if ($fp) {
        fclose($fp);
        return ['success'=>true,'message'=>"端口 {$host}:{$port} 可连接，PHP 服务正在运行"];
    }
    return ['success'=>false,'message'=>"无法连接 {$host}:{$port} — {$errstr}（错误码:{$errno}）。请检查 PHP 服务是否启动，端口是否正确"];
}

function runInstall(string $basePath): array
{
    $steps = []; $pdo = null;

    $fcgiCheck = testFastcgiConnection();
    if (!$fcgiCheck['success']) {
        return ['success'=>false,'steps'=>[['action'=>'验证 PHP FastCGI 地址','status'=>'fail','error'=>$fcgiCheck['message']]]];
    }

    try {
        $pdo = new \PDO("mysql:host={$_POST['db_host']};port={$_POST['db_port']};charset=utf8mb4", $_POST['db_username'],$_POST['db_password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>true]);
        $dbName = trim($_POST['db_database']);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");
        $steps[] = ['action'=>'验证 PHP FastCGI 地址','status'=>'ok'];
        $steps[] = ['action'=>'创建数据库','status'=>'ok'];
    } catch (\Throwable $e) { $steps[]=['action'=>'创建数据库','status'=>'fail','error'=>$e->getMessage()]; return ['success'=>false,'steps'=>$steps]; }

    try {
        $sqlFile = $basePath.'/api/database/init.sql';
        if (!file_exists($sqlFile)) throw new \RuntimeException('init.sql 不存在');
        $sql = str_replace('`examine_system`', '`'.$dbName.'`', str_replace('\'examine_system\'', '\''.$dbName.'\'', file_get_contents($sqlFile)));
        $sql = preg_replace('/\s*INSERT\s+INTO/i', 'INSERT IGNORE INTO', $sql);
        $sql = preg_replace('!/\*.*?\*/!s', '', $sql);
        $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
        $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)), fn($s)=>$s!==''&&$s!==';'&&!preg_match('/^\s*SELECT\s/i',$s));
        foreach ($statements as $stmt) { try { $pdo->exec($stmt); } catch (\Throwable $ex) { if (!str_contains($ex->getMessage(),'already exists')&&!str_contains($ex->getMessage(),'Duplicate')) throw $ex; } }
        $steps[] = ['action'=>'导入表结构','status'=>'ok'];
    } catch (\Throwable $e) { $steps[]=['action'=>'导入表结构','status'=>'fail','error'=>$e->getMessage()]; return ['success'=>false,'steps'=>$steps]; }

    try {
        file_put_contents($basePath.'/api/.env',"APP_ENV=production\nAPP_DEBUG=false\nAPP_URL=".($_POST['app_url']??'http://localhost')."\n"
            ."DB_HOST={$_POST['db_host']}\nDB_PORT={$_POST['db_port']}\nDB_DATABASE={$dbName}\n"
            ."DB_USERNAME={$_POST['db_username']}\nDB_PASSWORD={$_POST['db_password']}\n"
            ."JWT_SECRET=".bin2hex(random_bytes(24))."\nAUDIT_JWT_SECRET=".bin2hex(random_bytes(24))."\n"
            ."JWT_EXPIRE_HOURS=24\nIP_FILTER_ENABLED=true\n");
        $steps[] = ['action'=>'写入配置文件(.env)','status'=>'ok'];
    } catch (\Throwable $e) { $steps[]=['action'=>'写入配置文件','status'=>'fail','error'=>$e->getMessage()]; return ['success'=>false,'steps'=>$steps]; }

    try { generateNginxConfig($basePath, $_POST); generateNginxGuide($basePath, $_POST); $steps[] = ['action'=>'生成Nginx配置','status'=>'ok']; }
    catch (\Throwable $e) { $steps[]=['action'=>'生成Nginx配置','status'=>'fail','error'=>$e->getMessage()]; return ['success'=>false,'steps'=>$steps]; }

    try {
        $name = trim($_POST['admin_name']); $pwd = trim($_POST['admin_password']);
        if (strlen($pwd)<6) throw new \RuntimeException('密码至少6位');
        $pdo->exec("DELETE FROM admins WHERE username=".$pdo->quote($name));
        $pdo->prepare("INSERT INTO admins(username,password_hash,role,status,created_at) VALUES(?,?,?,?,NOW())")
            ->execute([$name,password_hash($pwd,PASSWORD_BCRYPT,['cost'=>12]),'super',1]);
        $steps[] = ['action'=>'创建管理员账号','status'=>'ok'];
    } catch (\Throwable $e) { $steps[]=['action'=>'创建管理员账号','status'=>'fail','error'=>$e->getMessage()]; return ['success'=>false,'steps'=>$steps]; }

    try {
        @mkdir($basePath.'/api/storage',0755,true);
        $edition = file_exists($basePath . '/api/app/routes-enterprise.php') ? '企业版' : '社区版';
        file_put_contents($basePath.'/api/storage/.installed',json_encode(['installed_at'=>date('c'),'version'=>'v2026.05.28-1','edition'=>$edition,'domain'=>$_POST['domain']??'','admin_port'=>(int)($_POST['admin_port']??2001),'mobile_port'=>(int)($_POST['mobile_port']??2002)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        $steps[] = ['action'=>'安装锁定','status'=>'ok'];
    } catch (\Throwable $e) { $steps[]=['action'=>'安装锁定','status'=>'fail','error'=>$e->getMessage()]; return ['success'=>false,'steps'=>$steps]; }

    try {
        $self = __FILE__;
        $lockFile = dirname($self).'/install.lock';
        if (file_exists($self) && $self !== $lockFile) {
            @rename($self, $lockFile);
        }
    } catch (\Throwable $e) {}

    return ['success'=>true,'steps'=>$steps];
}

function generateNginxConfig(string $basePath, array $input): void
{
    $root = str_replace('\\','/',realpath($basePath)); $domain = trim($input['domain']??'localhost');
    $ap=(int)($input['admin_port']??2001); $mp=(int)($input['mobile_port']??2002); $pp=trim($input['php_pass']??'127.0.0.1:9000');
    $conf = "# ============================================================\n"
        ."# 考核测评系统社区版 - Nginx 参考配置\n"
        ."# 生成时间: ".date('Y-m-d H:i:s')."\n"
       ."# 使用方法: 将以下内容复制到宝塔面板的网站配置中\n"
        ."# ============================================================\n\n"
        ."# ---------- 后台管理 (:{$ap}) ----------\n"
        ."server {\n    listen {$ap};\n    server_name {$domain};\n    root {$root}/backend/dist;\n    index index.html;\n\n"
        ."    add_header X-Frame-Options SAMEORIGIN always;\n    add_header X-Content-Type-Options nosniff always;\n    server_tokens off;\n\n"
        ."    # 所有 /api/ 请求直接交给 PHP 处理（Slim 框架路由）\n"
        ."    location /api/ {\n        fastcgi_pass {$pp};\n        fastcgi_index index.php;\n        fastcgi_param SCRIPT_FILENAME {$root}/api/public/index.php;\n        fastcgi_param REQUEST_URI \$request_uri;\n\n"
        ."        set \$real_script_name \$fastcgi_script_name;\n        if (\$fastcgi_script_name ~ \"^(.+?\\.php)(/.+)\$\") {\n            set \$real_script_name \$1;\n            set \$path_info \$2;\n        }\n        fastcgi_param SCRIPT_NAME \$real_script_name;\n        fastcgi_param PATH_INFO \$path_info;\n\n"
        ."        include fastcgi_params;\n        fastcgi_read_timeout 3600s;\n    }\n\n"
        ."    # 前端静态资源 (Vue SPA)\n"
        ."    location / {\n        try_files \$uri \$uri/ /index.html;\n    }\n}\n\n"
        ."# ---------- 移动端H5 (:{$mp}) ----------\n"
        ."server {\n    listen {$mp};\n    server_name {$domain};\n    root {$root}/mobile/dist;\n    index index.html;\n    server_tokens off;\n\n"
        ."    # API 代理到后台端口（共用同一个 API 入口）\n"
        ."    location /api/ {\n        proxy_pass http://127.0.0.1:{$ap}/api/;\n"
        ."        proxy_set_header Host \$host;\n        proxy_set_header X-Real-IP \$remote_addr;\n"
        ."        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n        proxy_read_timeout 120s;\n    }\n\n"
        ."    # 前端静态资源 (Vue SPA)\n"
        ."    location / {\n        try_files \$uri \$uri/ /index.html;\n    }\n}\n";
    $result = file_put_contents($basePath.'/generated.conf',$conf);
    if ($result === false) throw new \RuntimeException('无法写入 generated.conf，请检查项目根目录权限');
}

function generateNginxGuide(string $basePath, array $input): void
{
    $root = str_replace('\\','/',realpath($basePath));
    $domain = trim($input['domain']??'localhost');
    $ap=(int)($input['admin_port']??2001);
    $mp=(int)($input['mobile_port']??2002);
    $genTime = date('Y-m-d H:i:s');
    $guide = <<<GUIDE
================================================================================
                        考核测评系统社区版 - 宝塔面板配置指南
================================================================================
生成时间: {$genTime}
项目根目录: {$root}
后台端口: {$ap}
移动端端口: {$mp}
域名: {$domain}

================================================================================
方法一：推荐 - 在宝塔面板添加两个新站点（最稳定）
================================================================================

步骤 1：创建后台管理站点
  1) 登录宝塔面板，进入【网站】
  2) 点击【添加站点】
     域名: {$domain}（或填 IP 地址）
     端口: {$ap}
     根目录: {$root}/backend/dist
     PHP版本: 纯静态
  3) 点【提交】
  4) 找到刚添加的站点，点【设置】→【配置文件】
  5) 将 generated.conf 中 listen {$ap} 的 server 块完整复制进去，替换原有内容
  6) 点【保存】

步骤 2：创建移动端站点
  1) 再次点【添加站点】
     域名: {$domain}（或填 IP 地址）
     端口: {$mp}
     根目录: {$root}/mobile/dist
     PHP版本: 纯静态
  2) 点【提交】
  3) 找到刚添加的站点，点【设置】→【配置文件】
  4) 将 generated.conf 中 listen {$mp} 的 server 块完整复制进去
  5) 点【保存】

步骤 3：重启或重载 Nginx
  1) 宝塔面板左侧菜单【软件商店】
  2) 找到 Nginx，点【设置】
  3) 点【服务】→【重载配置】或【重启】
  4) 或在服务器终端执行: nginx -t && nginx -s reload

================================================================================
重要提示（必读）：
================================================================================
  1) 如果已经有旧版本的 Nginx 配置，请用新生成的 generated.conf 完全替换！
  2) 后台端口（{$ap}）的站点，/api/ 开头的请求必须由 PHP 处理，不能只匹配 .php 结尾
  3) 如果出现"请求方法不允许"（405）或"404"，请检查 Nginx 配置是否正确替换

================================================================================
访问地址
================================================================================

后台管理: http://{$domain}:{$ap}
移动端: http://{$domain}:{$mp}

管理员账号: 安装时填写的用户名（默认 admin）
管理员密码: 安装时填写的密码

================================================================================
常见问题
================================================================================

1. 请求方法不允许（405）
   - 原因：Nginx 没把 /api/ 请求交给 PHP 处理
   - 解决：请确保使用了最新生成的 generated.conf，特别是 location /api/ 的部分

2. 502 Bad Gateway
   - 检查 PHP-CGI / PHP-FPM 是否运行
   - 检查 fastcgi_pass 地址是否正确（generated.conf 里的配置）
   - 检查 phpEnv/phpstudy 是否启动 PHP

3. 404 Not Found
   - 检查根目录路径是否正确
   - 检查 location 块里的 alias / root 是否指向正确路径

4. 静态资源加载失败
   - 检查 nginx 配置里的 root 或 alias 是否正确
   - 检查 backend/dist 和 mobile/dist 目录是否存在

================================================================================
如需重新安装
================================================================================

删除以下文件后刷新页面:
  - install.lock（项目根目录）
  - api/.env
  - api/storage/.installed

或访问: http://{$domain}/install.php?reinstall=1
GUIDE;
    file_put_contents($basePath.'/宝塔配置指南.txt',$guide);
}
