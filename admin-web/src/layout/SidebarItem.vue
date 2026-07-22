<template>
  <div>
    <template v-if="item.children && item.children.length > 0 && !item.meta?.hidden">
      <el-sub-menu :index="resolvePath(basePath)">
        <template #title>
          <el-icon v-if="item.meta?.icon">
            <component :is="item.meta.icon" />
          </el-icon>
          <span>{{ item.meta?.title }}</span>
        </template>
        <SidebarItem
          v-for="child in item.children.filter((c) => !c.meta?.hidden)"
          :key="child.path"
          :item="child"
          :base-path="resolvePath(child.path)"
        />
      </el-sub-menu>
    </template>
    <template v-else>
      <el-menu-item :index="resolvePath(basePath)">
        <el-icon v-if="item.meta?.icon">
          <component :is="item.meta.icon" />
        </el-icon>
        <template #title>{{ item.meta?.title }}</template>
      </el-menu-item>
    </template>
  </div>
</template>

<script>
export default {
  name: 'SidebarItem',
  props: {
    item: {
      type: Object,
      required: true
    },
    basePath: {
      type: String,
      default: ''
    }
  },
  methods: {
    resolvePath(routePath) {
      if (routePath.startsWith('/')) {
        return routePath
      }
      return '/' + routePath
    }
  }
}
</script>