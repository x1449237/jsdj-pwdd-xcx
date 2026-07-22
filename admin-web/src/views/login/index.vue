<template>
  <div class="login-container">
    <div class="login-box">
      <div class="login-header">
        <h2 class="login-title">超级管理后台</h2>
        <p class="login-subtitle">Super Admin Console</p>
      </div>

      <div class="login-mode-switch" v-if="showWebAuthn">
        <el-tabs v-model="loginMode" class="login-tabs">
          <el-tab-pane label="账号密码登录" name="password">
            <el-form ref="loginFormRef" :model="loginForm" :rules="rules" label-width="0" size="large">
              <el-form-item prop="username">
                <el-input
                  v-model="loginForm.username"
                  placeholder="请输入用户名"
                  :prefix-icon="User"
                  @keyup.enter="focusPassword"
                />
              </el-form-item>
              <el-form-item prop="password">
                <el-input
                  ref="passwordRef"
                  v-model="loginForm.password"
                  type="password"
                  placeholder="请输入密码"
                  show-password
                  :prefix-icon="Lock"
                  @keyup.enter="handleLogin"
                />
              </el-form-item>
              <el-form-item>
                <el-button
                  type="primary"
                  :loading="loading"
                  class="login-btn"
                  @click="handleLogin"
                >
                  登 录
                </el-button>
              </el-form-item>
              <div class="login-extra">
                <el-link type="primary" :underline="false" @click="handleForgotPassword">
                  忘记密码？
                </el-link>
              </div>
            </el-form>
          </el-tab-pane>
          <el-tab-pane label="扫码登录" name="webauthn">
            <div class="qrcode-section">
              <div class="qrcode-box" v-loading="qrLoading">
                <div v-if="qrError" class="qrcode-error">
                  <el-icon :size="48"><WarningFilled /></el-icon>
                  <p>{{ qrError }}</p>
                  <el-button type="primary" size="small" @click="fetchQRCode">重新获取</el-button>
                </div>
                <div v-else-if="qrCodeUrl" class="qrcode-image">
                  <img :src="qrCodeUrl" alt="扫码登录" />
                  <p class="qrcode-tip">请使用 WebAuthn 设备扫码登录</p>
                </div>
                <el-empty v-else description="正在生成二维码..." />
              </div>
            </div>
          </el-tab-pane>
        </el-tabs>
      </div>

      <div v-else>
        <el-form ref="loginFormRef" :model="loginForm" :rules="rules" label-width="0" size="large">
          <el-form-item prop="username">
            <el-input
              v-model="loginForm.username"
              placeholder="请输入用户名"
              :prefix-icon="User"
              @keyup.enter="focusPassword"
            />
          </el-form-item>
          <el-form-item prop="password">
            <el-input
              ref="passwordRef"
              v-model="loginForm.password"
              type="password"
              placeholder="请输入密码"
              show-password
              :prefix-icon="Lock"
              @keyup.enter="handleLogin"
            />
          </el-form-item>
          <el-form-item>
            <el-button
              type="primary"
              :loading="loading"
              class="login-btn"
              @click="handleLogin"
            >
              登 录
            </el-button>
          </el-form-item>
          <div class="login-extra">
            <el-link type="primary" :underline="false" @click="handleForgotPassword">
              忘记密码？
            </el-link>
          </div>
        </el-form>
      </div>
    </div>
  </div>
</template>

<script>
import { useAuthStore } from '@/stores/auth'
import { User, Lock, WarningFilled } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'

export default {
  name: 'Login',
  data() {
    return {
      User,
      Lock,
      WarningFilled,
      loginForm: {
        username: '',
        password: ''
      },
      rules: {
        username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
        password: [{ required: true, message: '请输入密码', trigger: 'blur' }]
      },
      loading: false,
      loginMode: 'password',
      showWebAuthn: false,
      qrCodeUrl: '',
      qrLoading: false,
      qrError: '',
      qrPollTimer: null
    }
  },
  mounted() {
    // 检查 URL 参数是否启用 WebAuthn
    if (this.$route.query.webauthn === '1') {
      this.showWebAuthn = true
      this.fetchQRCode()
    }
  },
  beforeUnmount() {
    if (this.qrPollTimer) {
      clearInterval(this.qrPollTimer)
    }
  },
  methods: {
    focusPassword() {
      this.$refs.passwordRef?.focus()
    },
    async handleLogin() {
      const valid = await this.$refs.loginFormRef.validate().catch(() => false)
      if (!valid) return
      this.loading = true
      try {
        const authStore = useAuthStore()
        const { needInit } = await authStore.login(this.loginForm)
        if (needInit) {
          this.$router.push('/init')
        } else {
          const redirect = this.$route.query.redirect || '/dashboard'
          this.$router.push(redirect)
        }
      } catch (err) {
        console.error(err)
      } finally {
        this.loading = false
      }
    },
    handleForgotPassword() {
      ElMessage.info('请联系超级管理员重置密码')
    },
    async fetchQRCode() {
      this.qrLoading = true
      this.qrError = ''
      try {
        // 模拟获取 WebAuthn 扫码二维码
        this.qrCodeUrl = 'data:image/svg+xml;base64,' + btoa(
          '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect width="200" height="200" fill="#fff"/><text x="100" y="100" text-anchor="middle" dominant-baseline="middle" font-size="14" fill="#999">WebAuthn QR</text></svg>'
        )
        this.startQRPolling()
      } catch (err) {
        this.qrError = '获取二维码失败，请重试'
        console.error('获取二维码失败:', err)
      } finally {
        this.qrLoading = false
      }
    },
    startQRPolling() {
      if (this.qrPollTimer) {
        clearInterval(this.qrPollTimer)
      }
      this.qrPollTimer = setInterval(async () => {
        try {
          // 轮询扫码状态
          const authStore = useAuthStore()
          await authStore.login({ qrToken: 'mock-qr-token' })
          clearInterval(this.qrPollTimer)
          this.$router.push('/dashboard')
        } catch (err) {
          // 扫码未完成，继续轮询
        }
      }, 3000)
    }
  }
}
</script>

<style lang="scss" scoped>
.login-container {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
  padding: 20px;
}

.login-box {
  width: 420px;
  max-width: 100%;
  background: #fff;
  border-radius: 12px;
  padding: 40px;
  box-shadow: 0 8px 40px rgba(0, 0, 0, 0.2);
}

.login-header {
  text-align: center;
  margin-bottom: 32px;
}

.login-title {
  font-size: 24px;
  font-weight: 700;
  color: #303133;
  margin: 0 0 8px 0;
}

.login-subtitle {
  font-size: 13px;
  color: #909399;
  margin: 0;
}

.login-tabs {
  :deep(.el-tabs__header) {
    margin-bottom: 24px;
  }
}

.login-btn {
  width: 100%;
}

.login-extra {
  text-align: right;
  margin-top: -8px;
}

.qrcode-section {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.qrcode-box {
  width: 200px;
  height: 200px;
  border: 1px solid #ebeef5;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.qrcode-image {
  text-align: center;

  img {
    width: 180px;
    height: 180px;
  }
}

.qrcode-tip {
  font-size: 12px;
  color: #909399;
  margin-top: 8px;
}

.qrcode-error {
  text-align: center;
  color: #909399;

  p {
    margin: 8px 0;
    font-size: 13px;
  }
}

@media screen and (max-width: 480px) {
  .login-box {
    padding: 24px 20px;
  }
}
</style>