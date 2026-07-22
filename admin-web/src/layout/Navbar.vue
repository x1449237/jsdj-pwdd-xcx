<template>
  <div class="navbar">
    <div class="navbar-left">
      <el-icon class="hamburger" @click="appStore.toggleSidebar">
        <Fold v-if="!appStore.isCollapsed" />
        <Expand v-else />
      </el-icon>
      <el-breadcrumb separator="/">
        <el-breadcrumb-item v-for="item in breadcrumbs" :key="item.path" :to="item.path">
          {{ item.title }}
        </el-breadcrumb-item>
      </el-breadcrumb>
    </div>
    <div class="navbar-right">
      <el-dropdown trigger="click" @command="handleCommand">
        <div class="user-info">
          <el-avatar :size="32" :src="authStore.avatar" />
          <span class="username">{{ authStore.username }}</span>
          <el-icon><ArrowDown /></el-icon>
        </div>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item command="changePassword">
              <el-icon><Lock /></el-icon>
              <span>修改密码</span>
            </el-dropdown-item>
            <el-dropdown-item command="logout" divided>
              <el-icon><SwitchButton /></el-icon>
              <span>退出登录</span>
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>
  </div>
</template>

<script>
import { useAppStore } from '@/stores/app'
import { useAuthStore } from '@/stores/auth'

export default {
  name: 'Navbar',
  data() {
    return {
      appStore: useAppStore(),
      authStore: useAuthStore()
    }
  },
  computed: {
    breadcrumbs() {
      const matched = this.$route.matched.filter((item) => item.meta?.title)
      return matched.map((item) => ({
        path: item.path,
        title: item.meta.title
      }))
    }
  },
  methods: {
    async handleCommand(command) {
      if (command === 'logout') {
        await this.authStore.logout()
        this.$router.push('/login')
      } else if (command === 'changePassword') {
        this.$router.push('/init')
      }
    }
  }
}
</script>

<style lang="scss" scoped>
.navbar {
  height: 50px;
  background: #fff;
  box-shadow: 0 1px 4px rgba(0, 21, 41, 0.08);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  flex-shrink: 0;
}

.navbar-left {
  display: flex;
  align-items: center;
  gap: 16px;
}

.hamburger {
  font-size: 20px;
  cursor: pointer;
  transition: color 0.3s;

  &:hover {
    color: #409eff;
  }
}

.navbar-right {
  display: flex;
  align-items: center;
}

.user-info {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 4px;

  &:hover {
    background-color: #f5f7fa;
  }

  .username {
    font-size: 14px;
    color: #333;
  }
}
</style>