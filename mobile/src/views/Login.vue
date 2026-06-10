<template>
  <div class="login-page">
    <div class="login-container" :class="{ 'register-mode': isRegister, 'reset-mode': isReset }">
      <div class="login-header">
        <h1>年度考核测评系统</h1>
        <p v-if="!isReset">{{ isRegister ? '用户注册' : '用户登录' }}</p>
        <p v-else>找回密码</p>
      </div>

      <!-- 登录表单 -->
      <van-form @submit="handleLogin" v-if="!isRegister && !isReset">
        <van-cell-group inset>
          <van-field
            v-model="loginForm.phone"
            label="手机号"
            placeholder="请输入手机号"
            type="tel"
            maxlength="11"
            :rules="[{ required: true, message: '请输入手机号' }]"
          />
          <van-field
            v-model="loginForm.password"
            label="密码"
            placeholder="请输入密码"
            type="password"
            :rules="[{ required: true, message: '请输入密码' }]"
          />
        </van-cell-group>

        <div class="login-actions">
          <van-button
            round
            block
            type="primary"
            native-type="submit"
            :loading="loading"
            loading-text="登录中..."
          >
            登录
          </van-button>

          <div class="switch-mode">
            <span @click="isRegister = true">还没有账号？立即注册</span>
            <span @click="isReset = true">忘记密码？</span>
          </div>
        </div>
      </van-form>

      <!-- 注册表单 -->
      <van-form @submit="handleRegister" v-if="isRegister && !isReset">
        <van-cell-group inset>
          <van-field
            v-model="registerForm.real_name"
            label="真实姓名"
            placeholder="请输入真实姓名"
            :rules="[{ required: true, message: '请输入真实姓名' }]"
          />
          <van-field
            v-model="registerForm.phone"
            label="手机号"
            placeholder="请输入手机号"
            type="tel"
            maxlength="11"
            :rules="[
              { required: true, message: '请输入手机号' },
              { pattern: /^1[3-9]\d{9}$/, message: '手机号格式不正确' }
            ]"
          />
          <van-field
            v-model="registerForm.password"
            label="设置密码"
            placeholder="请设置登录密码(6位以上)"
            type="password"
            :rules="[
              { required: true, message: '请设置密码' },
              { pattern: /.{6,}/, message: '密码至少6位' }
            ]"
          />
          <van-field
            v-model="registerForm.password_confirm"
            label="确认密码"
            placeholder="再次输入密码"
            type="password"
            :rules="[
              { required: true, message: '请确认密码' },
              {
                validator: (val) => val === registerForm.password,
                message: '两次密码不一致'
              }
            ]"
          />

          <van-field name="security_question" label="安全问题">
            <template #input>
              <select
                v-model.number="registerForm.security_question"
                :class="{ 'placeholder-style': !registerForm.security_question }"
                style="width:100%;padding:8px;border:1px solid #eee;border-radius:4px;color:#323233;"
              >
                <option value="">请选择一个安全问题</option>
                <option v-for="(q, id) in securityQuestions" :key="id" :value="Number(id)" style="color:#000">{{ q }}</option>
              </select>
            </template>
          </van-field>

          <van-field
            v-model="registerForm.security_answer"
            label="问题答案"
            type="textarea"
            rows="2"
            autosize
            placeholder="请输入答案"
            :rules="[{ required: true, message: '请输入答案' }]"
          />
        </van-cell-group>

        <div class="login-actions">
          <van-button
            round
            block
            type="primary"
            native-type="submit"
            :loading="loading"
            loading-text="注册中..."
          >
            注册
          </van-button>

          <div class="switch-mode">
            <span @click="isRegister = false; isReset = false">已有账号？返回登录</span>
          </div>
        </div>
      </van-form>

      <!-- 找回密码表单 -->
      <van-form @submit="handleResetPassword" v-if="isReset">
        <van-cell-group inset>
          <van-field
            v-model="resetForm.phone"
            label="手机号"
            placeholder="注册时使用的手机号"
            type="tel"
            maxlength="11"
            :rules="[
              { required: true, message: '请输入手机号' },
              { pattern: /^1[3-9]\d{9}$/, message: '手机号格式不正确' }
            ]"
          />
          <van-field
            v-model="resetForm.real_name"
            label="真实姓名"
            placeholder="注册时填写的真实姓名"
            :rules="[{ required: true, message: '请输入真实姓名' }]"
          />
          <van-field name="security_question" label="安全问题">
            <template #input>
              <select
                v-model.number="resetForm.security_question"
                style="width:100%;padding:8px;border:1px solid #eee;border-radius:4px;color:#323233;"
              >
                <option value="" disabled selected>请选择您注册时设置的安全问题</option>
                <option v-for="(q, id) in securityQuestions" :key="id" :value="Number(id)" style="color:#000">{{ q }}</option>
              </select>
            </template>
          </van-field>
          <van-field
            v-model="resetForm.security_answer"
            label="问题答案"
            placeholder="请输入该问题的答案"
            :rules="[{ required: true, message: '请输入答案' }]"
          />
          <van-field
            v-model="resetForm.password"
            label="新密码"
            placeholder="请设置新密码(6位以上)"
            type="password"
            :rules="[
              { required: true, message: '请设置新密码' },
              { pattern: /.{6,}/, message: '至少6位' }
            ]"
          />
          <van-field
            v-model="resetForm.password_confirm"
            label="确认新密码"
            placeholder="再次输入新密码"
            type="password"
            :rules="[
              { required: true, message: '请确认新密码' },
              { validator: (val) => val === resetForm.password, message: '两次密码不一致' }
            ]"
          />
        </van-cell-group>

        <div class="login-actions">
          <van-button
            round
            block
            type="primary"
            native-type="submit"
            :loading="loading"
            loading-text="提交中..."
          >
            确认重置
          </van-button>

          <div class="switch-mode">
            <span @click="isReset = false">返回登录</span>
          </div>
        </div>
      </van-form>
    </div>

  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { showToast, showSuccessToast } from 'vant'
import request from '@/api/request'

const router = useRouter()
const route = useRoute()
const loading = ref(false)
const isRegister = ref(false)
const isReset = ref(false)
const securityQuestions = ref({})

const loginForm = reactive({
  phone: '',
  password: '',
})

const registerForm = reactive({
  real_name: '',
  phone: '',
  password: '',
  password_confirm: '',
  security_question: '',
  security_answer: ''
})

const resetForm = reactive({
  phone: '',
  real_name: '',
  security_question: '',
  security_answer: '',
  password: '',
  password_confirm: ''
})

onMounted(async () => {
  if (route.query.mode === 'reset') {
    isReset.value = true
  }
  await fetchSecurityQuestions()
})

async function fetchSecurityQuestions() {
  try {
    const res = await request.get('/auth/security-questions')
    securityQuestions.value = res.data?.questions || {}
  } catch {}
}

function getDeviceFingerprint() {
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')
  ctx.textBaseline = 'top'
  ctx.font = '14px Arial'
  ctx.fillText('fingerprint', 2, 2)
  const canvasHash = canvas.toDataURL().slice(-50)

  const screenInfo = `${screen.width}x${screen.height}x${screen.colorDepth}`
  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone
  const language = navigator.language
  const platform = navigator.platform
  const userAgent = navigator.userAgent.substring(0, 100)

  const fingerprint = btoa(`${canvasHash}|${screenInfo}|${timezone}|${language}|${platform}|${userAgent}`)
  return fingerprint.slice(0, 64)
}

async function handleLogin() {
  if (!loginForm.phone || !loginForm.password) return

  loading.value = true

  try {
    const res = await request.post('/auth/login', {
      phone: loginForm.phone,
      password: loginForm.password,
      device_fingerprint: getDeviceFingerprint()
    })

    const data = res.data

    localStorage.setItem('userInfo', JSON.stringify(data.user))
    localStorage.setItem('token', data.token)

    showSuccessToast('登录成功')
    router.push('/home')
  } catch (error) {
    console.error('登录失败:', error)
    showToast(error.response?.data?.message || '登录失败，请检查账号密码')
  } finally {
    loading.value = false
  }
}

async function handleRegister() {
  if (!registerForm.security_question) {
    showToast('请选择安全问题')
    return
  }

  loading.value = true

  try {
    await request.post('/auth/register', {
      real_name: registerForm.real_name,
      phone: registerForm.phone,
      password: registerForm.password,
      device_fingerprint: getDeviceFingerprint(),
      security_question: registerForm.security_question,
      security_answer: registerForm.security_answer
    })

    showSuccessToast('注册成功，请登录')

    loginForm.phone = registerForm.phone
    loginForm.password = registerForm.password

    isRegister.value = false

    Object.assign(registerForm, {
      real_name: '', phone: '', password: '', password_confirm: '',
      security_question: '', security_answer: ''
    })
  } catch (error) {
    console.error('注册失败:', error)
    showToast(error.response?.data?.message || '注册失败')
  } finally {
    loading.value = false
  }
}

async function handleResetPassword() {
  if (resetForm.password !== resetForm.password_confirm) {
    showToast('两次密码不一致')
    return
  }

  if (!resetForm.security_question) {
    showToast('请选择安全问题')
    return
  }

  loading.value = true

  try {
    await request.post('/auth/reset-password', {
      phone: resetForm.phone,
      real_name: resetForm.real_name,
      security_question: resetForm.security_question,
      security_answer: resetForm.security_answer,
      password: resetForm.password
    })

    showSuccessToast('密码重置成功，请使用新密码登录')

    loginForm.phone = resetForm.phone
    loginForm.password = resetForm.password

    isReset.value = false

    Object.assign(resetForm, {
      phone: '', real_name: '', security_question: '',
      security_answer: '', password: '', password_confirm: ''
    })
  } catch (error) {
    console.error('重置密码失败:', error)
    showToast(error.response?.data?.message || '重置失败')
  } finally {
    loading.value = false
  }
}
</script>

<style lang="scss" scoped>
.login-page {
  min-height: 100vh;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.login-container {
  width: 100%;
  max-width: 420px;
  background: white;
  border-radius: 16px;
  padding: 40px 24px;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);

  &.register-mode,
  &.reset-mode {
    max-width: 460px;
  }
}

.login-header {
  text-align: center;
  margin-bottom: 32px;

  h1 {
    font-size: 22px;
    color: #333;
    margin-bottom: 8px;
    font-weight: bold;
  }

  p {
    color: #999;
    font-size: 14px;
  }
}

.login-actions {
  margin-top: 24px;

  .switch-mode {
    display: flex;
    justify-content: space-between;
    margin-top: 16px;
    padding: 0 4px;
  }

  span {
    color: #667eea;
    font-size: 13px;
    cursor: pointer;

    &:active {
      opacity: 0.7;
    }
  }
}


.placeholder-style {
  color: #c8c9cc;
}

// select 下拉选项文字颜色
select {
  color: #323233;
  option {
    color: #323233;
    &:checked {
      color: #323233;
    }
    &[disabled] {
      color: #c8c9cc;
    }
  }
}
</style>
