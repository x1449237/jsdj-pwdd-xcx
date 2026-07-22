import { defineStore } from 'pinia'
import { getToken, setToken, removeToken } from '@/utils/auth'
import { login as loginApi, getAdminInfo } from '@/api/auth'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: getToken() || '',
    adminInfo: null,
    isInitialized: false
  }),

  getters: {
    isLoggedIn: (state) => !!state.token,
    username: (state) => state.adminInfo?.username || '',
    avatar: (state) => state.adminInfo?.avatar || '',
    roles: (state) => state.adminInfo?.roles || [],
    permissions: (state) => state.adminInfo?.permissions || []
  },

  actions: {
    async login(loginForm) {
      const res = await loginApi(loginForm)
      const { token, needInit } = res.data
      setToken(token)
      this.token = token
      if (needInit) {
        return { needInit: true }
      }
      await this.getInfo()
      return { needInit: false }
    },

    async getInfo() {
      const res = await getAdminInfo()
      this.adminInfo = res.data
      this.isInitialized = true
    },

    async logout() {
      this.token = ''
      this.adminInfo = null
      this.isInitialized = false
      removeToken()
    },

    hasPermission(perm) {
      if (!this.permissions || this.permissions.length === 0) return false
      return this.permissions.includes(perm)
    },

    resetState() {
      this.token = ''
      this.adminInfo = null
      this.isInitialized = false
    }
  }
})