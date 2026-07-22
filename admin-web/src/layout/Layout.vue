<template>
  <div class="layout-container">
    <Sidebar />
    <div class="main-container" :class="{ collapsed: appStore.isCollapsed }">
      <Navbar />
      <div class="app-main">
        <router-view />
      </div>
    </div>
  </div>
</template>

<script>
import Sidebar from './Sidebar.vue'
import Navbar from './Navbar.vue'
import { useAppStore } from '@/stores/app'

export default {
  name: 'Layout',
  components: { Sidebar, Navbar },
  data() {
    return {
      appStore: useAppStore()
    }
  }
}
</script>

<style lang="scss" scoped>
.layout-container {
  display: flex;
  height: 100vh;
  overflow: hidden;
}

.main-container {
  flex: 1;
  display: flex;
  flex-direction: column;
  margin-left: 220px;
  transition: margin-left 0.3s ease;
  overflow: hidden;

  &.collapsed {
    margin-left: 64px;
  }
}

.app-main {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
  background-color: #f0f2f5;
}
</style>