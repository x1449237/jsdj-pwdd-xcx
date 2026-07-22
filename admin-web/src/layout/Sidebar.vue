<template>
  <div class="sidebar-container" :class="{ collapsed: appStore.isCollapsed }">
    <div class="logo-container">
      <img src="@/assets/logo.svg" class="logo-img" alt="logo" />
      <span v-show="!appStore.isCollapsed" class="logo-title">超级管理后台</span>
    </div>
    <el-scrollbar>
      <el-menu
        :default-active="activeMenu"
        :collapse="appStore.isCollapsed"
        :collapse-transition="false"
        background-color="#304156"
        text-color="#bfcbd9"
        active-text-color="#409eff"
        :router="true"
        mode="vertical"
      >
        <SidebarItem
          v-for="route in menuRoutes"
          :key="route.path"
          :item="route"
          :base-path="route.path"
        />
      </el-menu>
    </el-scrollbar>
  </div>
</template>

<script>
import { useAppStore } from '@/stores/app'
import SidebarItem from './SidebarItem.vue'

export default {
  name: 'Sidebar',
  components: { SidebarItem },
  data() {
    return {
      appStore: useAppStore()
    }
  },
  computed: {
    menuRoutes() {
      return this.$router.options.routes
        .find((route) => route.path === '/')
        ?.children.filter((item) => !item.meta?.hidden) || []
    },
    activeMenu() {
      const { path } = this.$route
      return path
    }
  }
}
</script>

<style lang="scss" scoped>
.sidebar-container {
  position: fixed;
  left: 0;
  top: 0;
  bottom: 0;
  width: 220px;
  background-color: #304156;
  transition: width 0.3s ease;
  z-index: 100;
  overflow: hidden;

  &.collapsed {
    width: 64px;
  }
}

.logo-container {
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 16px;
  overflow: hidden;

  .logo-img {
    width: 32px;
    height: 32px;
    flex-shrink: 0;
  }

  .logo-title {
    margin-left: 10px;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    white-space: nowrap;
  }
}

.el-scrollbar {
  height: calc(100% - 60px);
}

.el-menu {
  border-right: none;
}
</style>