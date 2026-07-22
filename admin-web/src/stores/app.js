import { defineStore } from 'pinia'

export const useAppStore = defineStore('app', {
  state: () => ({
    sidebarCollapsed: false,
    breadcrumbs: []
  }),

  getters: {
    isCollapsed: (state) => state.sidebarCollapsed
  },

  actions: {
    toggleSidebar() {
      this.sidebarCollapsed = !this.sidebarCollapsed
    },

    setBreadcrumbs(list) {
      this.breadcrumbs = list
    }
  }
})