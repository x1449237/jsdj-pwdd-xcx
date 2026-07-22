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
        <!-- 分组菜单 -->
        <template v-for="group in menuGroups" :key="group.key">
          <el-sub-menu :index="group.key">
            <template #title>
              <el-icon v-if="group.icon">
                <component :is="group.icon" />
              </el-icon>
              <span>{{ group.title }}</span>
            </template>
            <el-menu-item
              v-for="route in group.routes"
              :key="route.path"
              :index="'/' + route.path"
            >
              <el-icon v-if="route.meta?.icon">
                <component :is="route.meta.icon" />
              </el-icon>
              <template #title>{{ route.meta?.title }}</template>
            </el-menu-item>
          </el-sub-menu>
        </template>
        <!-- 未分组菜单 -->
        <SidebarItem
          v-for="route in ungroupedRoutes"
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

const menuGroupConfig = [
  {
    key: 'platform',
    title: '平台管理',
    icon: 'Platform'
  },
  {
    key: 'chat',
    title: '聊天管理',
    icon: 'ChatDotRound'
  },
  {
    key: 'security',
    title: '安全中心',
    icon: 'Lock'
  }
]

export default {
  name: 'Sidebar',
  components: { SidebarItem },
  data() {
    return {
      appStore: useAppStore()
    }
  },
  computed: {
    allRoutes() {
      return this.$router.options.routes
        .find((route) => route.path === '/')
        ?.children.filter((item) => !item.meta?.hidden) || []
    },
    menuGroups() {
      return menuGroupConfig.map((group) => {
        const routes = this.allRoutes.filter(
          (route) => route.meta?.group === group.key
        )
        if (routes.length === 0) return null
        return { ...group, routes }
      }).filter(Boolean)
    },
    ungroupedRoutes() {
      const groupedPaths = new Set()
      this.menuGroups.forEach((group) => {
        group.routes.forEach((route) => groupedPaths.add(route.path))
      })
      return this.allRoutes.filter((route) => !groupedPaths.has(route.path))
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