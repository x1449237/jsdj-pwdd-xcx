<template>
  <div class="page-container">
    <div class="page-header">
      <span class="page-title">操作日志</span>
    </div>

    <el-card shadow="hover" class="filter-card">
      <el-form :inline="true" :model="filters" @submit.prevent>
        <el-form-item label="管理员">
          <el-input
            v-model="filters.admin_id"
            placeholder="管理员ID"
            clearable
            style="width: 140px"
          />
        </el-form-item>
        <el-form-item label="模块">
          <el-select
            v-model="filters.module"
            placeholder="全部模块"
            clearable
            style="width: 160px"
          >
            <el-option
              v-for="item in moduleList"
              :key="item"
              :label="item"
              :value="item"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="操作">
          <el-input
            v-model="filters.action"
            placeholder="操作动作"
            clearable
            style="width: 140px"
          />
        </el-form-item>
        <el-form-item label="IP">
          <el-input
            v-model="filters.ip"
            placeholder="IP地址"
            clearable
            style="width: 140px"
          />
        </el-form-item>
        <el-form-item label="结果">
          <el-select
            v-model="filters.result"
            placeholder="全部"
            clearable
            style="width: 100px"
          >
            <el-option label="成功" :value="1" />
            <el-option label="失败" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item label="时间范围">
          <el-date-picker
            v-model="dateRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            style="width: 260px"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :icon="Search" @click="handleSearch">查询</el-button>
          <el-button :icon="Refresh" @click="handleReset">重置</el-button>
          <el-button type="success" :icon="Download" @click="handleExport">导出CSV</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="hover" style="margin-top: 20px">
      <el-table
        :data="tableData"
        v-loading="loading"
        stripe
        style="width: 100%"
      >
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="admin_id" label="管理员ID" width="100" />
        <el-table-column prop="username" label="用户名" width="120" />
        <el-table-column prop="module" label="模块" width="140" />
        <el-table-column prop="action" label="操作" width="160" />
        <el-table-column prop="ip" label="IP地址" width="140" />
        <el-table-column prop="device" label="设备信息" min-width="180" show-overflow-tooltip />
        <el-table-column label="结果" width="80" align="center">
          <template #default="{ row }">
            <el-tag :type="row.result === 1 ? 'success' : 'danger'" size="small">
              {{ row.result === 1 ? '成功' : '失败' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" label="操作时间" width="180" />
        <el-table-column label="操作" width="100" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="handleDetail(row)">
              详情
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.limit"
          :total="pagination.total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          background
          @size-change="fetchList"
          @current-change="fetchList"
        />
      </div>
    </el-card>

    <el-dialog v-model="detailVisible" title="操作详情" width="600px">
      <div v-if="currentDetail" class="detail-content">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="日志ID">{{ currentDetail.id }}</el-descriptions-item>
          <el-descriptions-item label="管理员ID">{{ currentDetail.admin_id }}</el-descriptions-item>
          <el-descriptions-item label="用户名">{{ currentDetail.username }}</el-descriptions-item>
          <el-descriptions-item label="模块">{{ currentDetail.module }}</el-descriptions-item>
          <el-descriptions-item label="操作">{{ currentDetail.action }}</el-descriptions-item>
          <el-descriptions-item label="IP">{{ currentDetail.ip }}</el-descriptions-item>
          <el-descriptions-item label="设备" :span="2">{{ currentDetail.device }}</el-descriptions-item>
          <el-descriptions-item label="结果">
            <el-tag :type="currentDetail.result === 1 ? 'success' : 'danger'" size="small">
              {{ currentDetail.result === 1 ? '成功' : '失败' }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="操作时间">{{ currentDetail.create_time }}</el-descriptions-item>
          <el-descriptions-item label="请求参数" :span="2">
            <pre class="params-json">{{ formatJson(currentDetail.params_json) }}</pre>
          </el-descriptions-item>
        </el-descriptions>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import request from '@/utils/request'
import { Search, Refresh, Download } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'

export default {
  name: 'OperationLog',
  data() {
    return {
      Search,
      Refresh,
      Download,
      loading: false,
      moduleList: [],
      dateRange: [],
      filters: {
        admin_id: '',
        module: '',
        action: '',
        ip: '',
        result: ''
      },
      tableData: [],
      pagination: {
        page: 1,
        limit: 20,
        total: 0
      },
      detailVisible: false,
      currentDetail: null
    }
  },
  mounted() {
    this.fetchModules()
    this.fetchList()
  },
  methods: {
    async fetchModules() {
      try {
        const res = await request.get('/operation_log/modules')
        this.moduleList = res.data || []
      } catch (err) {
        console.error('获取模块列表失败:', err)
      }
    },
    async fetchList() {
      this.loading = true
      try {
        const params = {
          ...this.filters,
          page: this.pagination.page,
          limit: this.pagination.limit
        }
        if (this.dateRange && this.dateRange.length === 2) {
          params.start_date = this.dateRange[0]
          params.end_date = this.dateRange[1]
        }
        const res = await request.get('/operation_log/list', { params })
        this.tableData = res.data?.list || []
        this.pagination.total = res.data?.total || 0
      } catch (err) {
        console.error('获取操作日志失败:', err)
        ElMessage.error('获取操作日志失败')
      } finally {
        this.loading = false
      }
    },
    handleSearch() {
      this.pagination.page = 1
      this.fetchList()
    },
    handleReset() {
      this.filters = {
        admin_id: '',
        module: '',
        action: '',
        ip: '',
        result: ''
      }
      this.dateRange = []
      this.pagination.page = 1
      this.fetchList()
    },
    async handleExport() {
      try {
        const params = { ...this.filters }
        if (this.dateRange && this.dateRange.length === 2) {
          params.start_date = this.dateRange[0]
          params.end_date = this.dateRange[1]
        }
        const res = await request.get('/operation_log/export', {
          params,
          responseType: 'blob'
        })
        const url = window.URL.createObjectURL(new Blob([res.data]))
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', `operation_log_${Date.now()}.csv`)
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        window.URL.revokeObjectURL(url)
        ElMessage.success('导出成功')
      } catch (err) {
        console.error('导出失败:', err)
        ElMessage.error('导出失败')
      }
    },
    handleDetail(row) {
      this.currentDetail = row
      this.detailVisible = true
    },
    formatJson(json) {
      if (!json) return '-'
      try {
        if (typeof json === 'string') {
          return JSON.stringify(JSON.parse(json), null, 2)
        }
        return JSON.stringify(json, null, 2)
      } catch (e) {
        return json
      }
    }
  }
}
</script>

<style lang="scss" scoped>
.filter-card {
  :deep(.el-form-item) {
    margin-bottom: 0;
  }
}

.pagination-wrapper {
  display: flex;
  justify-content: flex-end;
  margin-top: 20px;
}

.detail-content {
  .params-json {
    max-height: 300px;
    overflow-y: auto;
    background: #f5f7fa;
    padding: 12px;
    border-radius: 4px;
    font-size: 12px;
    margin: 0;
    white-space: pre-wrap;
    word-break: break-all;
  }
}
</style>
