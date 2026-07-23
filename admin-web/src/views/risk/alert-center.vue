<template>
  <div class="page-container">
    <div class="page-header">
      <span class="page-title">AI风险预警中心</span>
    </div>

    <el-row :gutter="20" class="stat-row">
      <el-col :xs="12" :sm="12" :md="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-card-inner">
            <div class="stat-icon pending">
              <el-icon :size="28"><Warning /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.pending_count || 0 }}</div>
              <div class="stat-label">待处理</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="12" :md="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-card-inner">
            <div class="stat-icon high">
              <el-icon :size="28"><CircleClose /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value high-value">{{ stats.high_count || 0 }}</div>
              <div class="stat-label">高风险</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="12" :md="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-card-inner">
            <div class="stat-icon medium">
              <el-icon :size="28"><InfoFilled /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value medium-value">{{ stats.medium_count || 0 }}</div>
              <div class="stat-label">中风险</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="12" :md="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-card-inner">
            <div class="stat-icon handled">
              <el-icon :size="28"><CircleCheck /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.handled_count || 0 }}</div>
              <div class="stat-label">已处理</div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="hover" class="filter-card" style="margin-top: 20px">
      <el-form :inline="true" :model="filters" @submit.prevent>
        <el-form-item label="预警类型">
          <el-select
            v-model="filters.alert_type"
            placeholder="全部类型"
            clearable
            style="width: 160px"
          >
            <el-option label="高频退款" value="high_refund_rate" />
            <el-option label="同IP注册" value="same_ip_regist" />
            <el-option label="大额提现" value="large_withdraw" />
            <el-option label="深夜下单" value="midnight_order" />
            <el-option label="高频下单" value="frequency_order" />
          </el-select>
        </el-form-item>
        <el-form-item label="风险等级">
          <el-select
            v-model="filters.risk_level"
            placeholder="全部等级"
            clearable
            style="width: 120px"
          >
            <el-option label="低风险" value="low" />
            <el-option label="中风险" value="medium" />
            <el-option label="高风险" value="high" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select
            v-model="filters.status"
            placeholder="全部状态"
            clearable
            style="width: 120px"
          >
            <el-option label="待处理" value="pending" />
            <el-option label="处理中" value="processing" />
            <el-option label="已处理" value="handled" />
            <el-option label="已忽略" value="ignored" />
          </el-select>
        </el-form-item>
        <el-form-item label="用户ID">
          <el-input
            v-model="filters.user_id"
            placeholder="用户ID"
            clearable
            style="width: 120px"
          />
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
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="hover" style="margin-top: 20px">
      <template #header>
        <div class="card-header">
          <span>预警列表</span>
          <div>
            <el-button
              type="warning"
              :icon="Lock"
              :disabled="selectedRows.length === 0"
              @click="handleBatchBan"
            >
              批量封禁
            </el-button>
            <el-button
              type="success"
              :icon="Check"
              :disabled="selectedRows.length === 0"
              @click="handleBatchHandle"
            >
              批量处理
            </el-button>
          </div>
        </div>
      </template>

      <el-table
        :data="tableData"
        v-loading="loading"
        stripe
        @selection-change="handleSelectionChange"
        style="width: 100%"
      >
        <el-table-column type="selection" width="55" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="预警类型" width="120">
          <template #default="{ row }">
            <el-tag size="small">{{ getAlertTypeLabel(row.alert_type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="风险等级" width="100" align="center">
          <template #default="{ row }">
            <el-tag
              :type="getRiskLevelType(row.risk_level)"
              size="small"
              effect="dark"
            >
              {{ getRiskLevelLabel(row.risk_level) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="user_id" label="用户ID" width="100" />
        <el-table-column prop="description" label="描述" min-width="200" show-overflow-tooltip />
        <el-table-column label="状态" width="100" align="center">
          <template #default="{ row }">
            <el-tag :type="getStatusType(row.status)" size="small">
              {{ getStatusLabel(row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="handler_id" label="处理人" width="100" />
        <el-table-column prop="create_time" label="创建时间" width="180" />
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="handleDetail(row)">
              详情
            </el-button>
            <el-button
              v-if="row.status === 'pending'"
              type="success"
              link
              size="small"
              @click="handleSingleHandle(row)"
            >
              处理
            </el-button>
            <el-button
              v-if="row.status === 'pending'"
              type="warning"
              link
              size="small"
              @click="handleBan(row)"
            >
              封禁
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

    <el-dialog v-model="detailVisible" title="预警详情" width="600px">
      <div v-if="currentDetail" class="detail-content">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="预警ID">{{ currentDetail.id }}</el-descriptions-item>
          <el-descriptions-item label="类型">{{ getAlertTypeLabel(currentDetail.alert_type) }}</el-descriptions-item>
          <el-descriptions-item label="风险等级">
            <el-tag :type="getRiskLevelType(currentDetail.risk_level)" size="small" effect="dark">
              {{ getRiskLevelLabel(currentDetail.risk_level) }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="用户ID">{{ currentDetail.user_id }}</el-descriptions-item>
          <el-descriptions-item label="描述" :span="2">{{ currentDetail.description }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <el-tag :type="getStatusType(currentDetail.status)" size="small">
              {{ getStatusLabel(currentDetail.status) }}
            </el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="处理人">{{ currentDetail.handler_id || '-' }}</el-descriptions-item>
          <el-descriptions-item label="创建时间">{{ currentDetail.create_time }}</el-descriptions-item>
          <el-descriptions-item label="处理时间">{{ currentDetail.handle_time || '-' }}</el-descriptions-item>
          <el-descriptions-item label="数据详情" :span="2">
            <pre class="data-json">{{ formatJson(currentDetail.data_json) }}</pre>
          </el-descriptions-item>
        </el-descriptions>
      </div>
      <template #footer>
        <el-button v-if="currentDetail && currentDetail.status === 'pending'" @click="handleSingleHandle(currentDetail)">
          处理
        </el-button>
        <el-button v-if="currentDetail && currentDetail.status === 'pending'" type="warning" @click="handleBan(currentDetail)">
          封禁用户
        </el-button>
        <el-button @click="detailVisible = false">关闭</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="handleVisible" title="处理预警" width="400px">
      <el-form :model="handleForm" label-width="80px">
        <el-form-item label="处理结果">
          <el-radio-group v-model="handleForm.action">
            <el-radio value="handle">已处理</el-radio>
            <el-radio value="ignore">忽略</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="处理备注">
          <el-input
            v-model="handleForm.remark"
            type="textarea"
            :rows="3"
            placeholder="请输入处理备注"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="handleVisible = false">取消</el-button>
        <el-button type="primary" @click="confirmHandle">确认</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="banVisible" title="封禁用户" width="400px">
      <el-form :model="banForm" label-width="80px">
        <el-form-item label="封禁原因">
          <el-input
            v-model="banForm.reason"
            type="textarea"
            :rows="3"
            placeholder="请输入封禁原因"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="banVisible = false">取消</el-button>
        <el-button type="warning" @click="confirmBan">确认封禁</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script>
import request from '@/utils/request'
import {
  Search,
  Refresh,
  Warning,
  CircleClose,
  InfoFilled,
  CircleCheck,
  Lock,
  Check
} from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'

export default {
  name: 'RiskAlert',
  data() {
    return {
      Search,
      Refresh,
      Warning,
      CircleClose,
      InfoFilled,
      CircleCheck,
      Lock,
      Check,
      loading: false,
      dateRange: [],
      filters: {
        alert_type: '',
        risk_level: '',
        status: '',
        user_id: ''
      },
      stats: {},
      tableData: [],
      selectedRows: [],
      pagination: {
        page: 1,
        limit: 20,
        total: 0
      },
      detailVisible: false,
      currentDetail: null,
      handleVisible: false,
      handleForm: {
        id: 0,
        action: 'handle',
        remark: ''
      },
      banVisible: false,
      banForm: {
        userIds: [],
        reason: ''
      }
    }
  },
  mounted() {
    this.fetchStats()
    this.fetchList()
  },
  methods: {
    async fetchStats() {
      try {
        const res = await request.get('/risk_alert/stats')
        this.stats = res.data || {}
      } catch (err) {
        console.error('获取统计失败:', err)
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
        const res = await request.get('/risk_alert/list', { params })
        this.tableData = res.data?.list || []
        this.pagination.total = res.data?.total || 0
      } catch (err) {
        console.error('获取预警列表失败:', err)
        ElMessage.error('获取预警列表失败')
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
        alert_type: '',
        risk_level: '',
        status: '',
        user_id: ''
      }
      this.dateRange = []
      this.pagination.page = 1
      this.fetchList()
    },
    handleSelectionChange(rows) {
      this.selectedRows = rows
    },
    handleDetail(row) {
      this.currentDetail = row
      this.detailVisible = true
    },
    handleSingleHandle(row) {
      this.handleForm.id = row.id
      this.handleForm.action = 'handle'
      this.handleForm.remark = ''
      this.handleVisible = true
    },
    async confirmHandle() {
      try {
        await request.post('/risk_alert/handle', {
          id: this.handleForm.id,
          action: this.handleForm.action,
          remark: this.handleForm.remark
        })
        ElMessage.success('处理成功')
        this.handleVisible = false
        this.detailVisible = false
        this.fetchList()
        this.fetchStats()
      } catch (err) {
        console.error('处理失败:', err)
        ElMessage.error('处理失败')
      }
    },
    async handleBatchHandle() {
      if (this.selectedRows.length === 0) return
      try {
        await ElMessageBox.confirm(
          `确定批量处理选中的 ${this.selectedRows.length} 条预警吗？`,
          '提示',
          { type: 'warning' }
        )
        const ids = this.selectedRows.map(r => r.id).join(',')
        await request.post('/risk_alert/batch_handle', {
          alert_ids: ids,
          action: 'handle'
        })
        ElMessage.success('批量处理成功')
        this.fetchList()
        this.fetchStats()
      } catch (err) {
        if (err !== 'cancel') {
          console.error('批量处理失败:', err)
          ElMessage.error('批量处理失败')
        }
      }
    },
    handleBan(row) {
      this.banForm.userIds = [row.user_id]
      this.banForm.reason = ''
      this.banVisible = true
    },
    async handleBatchBan() {
      if (this.selectedRows.length === 0) return
      const userIds = [...new Set(this.selectedRows.map(r => r.user_id).filter(Boolean))]
      if (userIds.length === 0) {
        ElMessage.warning('请选择有用户的预警记录')
        return
      }
      this.banForm.userIds = userIds
      this.banForm.reason = ''
      this.banVisible = true
    },
    async confirmBan() {
      try {
        await ElMessageBox.confirm(
          `确定封禁选中的 ${this.banForm.userIds.length} 个用户吗？`,
          '警告',
          { type: 'error' }
        )
        await request.post('/risk_alert/batch_ban', {
          user_ids: this.banForm.userIds.join(','),
          reason: this.banForm.reason
        })
        ElMessage.success('封禁成功')
        this.banVisible = false
        this.detailVisible = false
        this.fetchList()
        this.fetchStats()
      } catch (err) {
        if (err !== 'cancel') {
          console.error('封禁失败:', err)
          ElMessage.error('封禁失败')
        }
      }
    },
    getAlertTypeLabel(type) {
      const map = {
        high_refund_rate: '高频退款',
        same_ip_regist: '同IP注册',
        large_withdraw: '大额提现',
        midnight_order: '深夜下单',
        frequency_order: '高频下单'
      }
      return map[type] || type
    },
    getRiskLevelLabel(level) {
      const map = { low: '低风险', medium: '中风险', high: '高风险' }
      return map[level] || level
    },
    getRiskLevelType(level) {
      const map = { low: 'success', medium: 'warning', high: 'danger' }
      return map[level] || 'info'
    },
    getStatusLabel(status) {
      const map = {
        pending: '待处理',
        processing: '处理中',
        handled: '已处理',
        ignored: '已忽略'
      }
      return map[status] || status
    },
    getStatusType(status) {
      const map = {
        pending: 'warning',
        processing: 'primary',
        handled: 'success',
        ignored: 'info'
      }
      return map[status] || 'info'
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
.stat-row {
  margin-bottom: 0;
}

.stat-card {
  .stat-card-inner {
    display: flex;
    align-items: center;
    gap: 16px;
  }

  .stat-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: #409eff;

    &.pending {
      background-color: #fff7e6;
      color: #e6a23c;
    }

    &.high {
      background-color: #fff1f0;
      color: #f56c6c;
    }

    &.medium {
      background-color: #fdf6ec;
      color: #e6a23c;
    }

    &.handled {
      background-color: #f0f9eb;
      color: #67c23a;
    }
  }

  .stat-info {
    flex: 1;
    min-width: 0;
  }

  .stat-value {
    font-size: 28px;
    font-weight: 700;
    color: #303133;
    line-height: 1.2;
  }

  .stat-label {
    margin-top: 4px;
    font-size: 13px;
    color: #909399;
  }

  .high-value {
    color: #f56c6c;
  }

  .medium-value {
    color: #e6a23c;
  }
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

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
  .data-json {
    max-height: 200px;
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
