<template>
  <div class="page-container">
    <div class="page-header">
      <span class="page-title">活动运营</span>
    </div>

    <el-row :gutter="20" class="stat-row">
      <el-col :xs="12" :sm="12" :md="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-card-inner">
            <div class="stat-icon coupon">
              <el-icon :size="28"><Ticket /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.coupon_total || 0 }}</div>
              <div class="stat-label">优惠券模板总数</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="12" :md="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-card-inner">
            <div class="stat-icon active">
              <el-icon :size="28"><CircleCheck /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value success-value">{{ stats.coupon_active || 0 }}</div>
              <div class="stat-label">进行中活动</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="12" :md="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-card-inner">
            <div class="stat-icon lottery">
              <el-icon :size="28"><Present /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">0</div>
              <div class="stat-label">抽奖活动</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="12" :md="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-card-inner">
            <div class="stat-icon group">
              <el-icon :size="28"><UserFilled /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">0</div>
              <div class="stat-label">拼团活动</div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-tabs v-model="activeTab" type="card" style="margin-top: 20px">
      <el-tab-pane label="优惠券管理" name="coupon">
        <el-card shadow="hover">
          <template #header>
            <div class="card-header">
              <span>优惠券模板</span>
              <el-button type="primary" :icon="Plus" @click="handleCreateCoupon">
                新建优惠券
              </el-button>
            </div>
          </template>

          <el-form :inline="true" :model="couponFilters" @submit.prevent style="margin-bottom: 16px">
            <el-form-item label="类型">
              <el-select
                v-model="couponFilters.type"
                placeholder="全部类型"
                clearable
                style="width: 120px"
              >
                <el-option label="满减券" value="discount" />
                <el-option label="折扣券" value="percent" />
                <el-option label="免单券" value="free" />
              </el-select>
            </el-form-item>
            <el-form-item label="状态">
              <el-select
                v-model="couponFilters.status"
                placeholder="全部状态"
                clearable
                style="width: 120px"
              >
                <el-option label="启用" :value="1" />
                <el-option label="禁用" :value="0" />
              </el-select>
            </el-form-item>
            <el-form-item label="关键词">
              <el-input
                v-model="couponFilters.keyword"
                placeholder="名称搜索"
                clearable
                style="width: 160px"
              />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :icon="Search" @click="fetchCouponList">查询</el-button>
            </el-form-item>
          </el-form>

          <el-table
            :data="couponList"
            v-loading="couponLoading"
            stripe
            style="width: 100%"
          >
            <el-table-column prop="id" label="ID" width="70" />
            <el-table-column prop="name" label="名称" min-width="160" />
            <el-table-column label="类型" width="100">
              <template #default="{ row }">
                <el-tag size="small">{{ getCouponTypeLabel(row.type) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="优惠" width="120">
              <template #default="{ row }">
                <span v-if="row.discount_type === 'fixed'">
                  满¥{{ (row.min_amount / 100).toFixed(2) }}减¥{{ row.discount_value }}
                </span>
                <span v-else-if="row.discount_type === 'percent'">
                  {{ row.discount_value }}折
                </span>
                <span v-else>{{ row.discount_value }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="total_count" label="总数量" width="100" />
            <el-table-column label="有效期" width="180">
              <template #default="{ row }">
                <span v-if="row.valid_type === 'fixed'">
                  {{ row.start_time }} ~ {{ row.end_time }}
                </span>
                <span v-else>领取后{{ row.valid_days }}天有效</span>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="80" align="center">
              <template #default="{ row }">
                <el-switch
                  v-model="row.status"
                  :active-value="1"
                  :inactive-value="0"
                  @change="val => handleToggleCoupon(row, val)"
                />
              </template>
            </el-table-column>
            <el-table-column prop="create_time" label="创建时间" width="180" />
            <el-table-column label="操作" width="150" fixed="right">
              <template #default="{ row }">
                <el-button type="primary" link size="small" @click="handleEditCoupon(row)">
                  编辑
                </el-button>
                <el-button type="danger" link size="small" @click="handleDeleteCoupon(row)">
                  删除
                </el-button>
              </template>
            </el-table-column>
          </el-table>

          <div class="pagination-wrapper">
            <el-pagination
              v-model:current-page="couponPagination.page"
              v-model:page-size="couponPagination.limit"
              :total="couponPagination.total"
              :page-sizes="[10, 20, 50]"
              layout="total, sizes, prev, pager, next, jumper"
              background
              @size-change="fetchCouponList"
              @current-change="fetchCouponList"
            />
          </div>
        </el-card>
      </el-tab-pane>

      <el-tab-pane label="抽奖活动" name="lottery">
        <el-empty description="抽奖活动功能开发中" />
      </el-tab-pane>

      <el-tab-pane label="拼团活动" name="group">
        <el-empty description="拼团活动功能开发中" />
      </el-tab-pane>
    </el-tabs>

    <el-dialog
      v-model="couponDialogVisible"
      :title="isEditCoupon ? '编辑优惠券' : '新建优惠券'"
      width="600px"
    >
      <el-form :model="couponForm" :rules="couponRules" ref="couponFormRef" label-width="100px">
        <el-form-item label="优惠券名称" prop="name">
          <el-input v-model="couponForm.name" placeholder="请输入优惠券名称" maxlength="64" />
        </el-form-item>
        <el-form-item label="优惠券类型" prop="type">
          <el-select v-model="couponForm.type" style="width: 200px">
            <el-option label="满减券" value="discount" />
            <el-option label="折扣券" value="percent" />
            <el-option label="免单券" value="free" />
          </el-select>
        </el-form-item>
        <el-form-item label="优惠方式" prop="discount_type">
          <el-radio-group v-model="couponForm.discount_type">
            <el-radio value="fixed">固定金额</el-radio>
            <el-radio value="percent">折扣比例</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="优惠值" prop="discount_value">
          <el-input-number
            v-model="couponForm.discount_value"
            :min="0"
            :precision="couponForm.discount_type === 'percent' ? 1 : 2"
            style="width: 200px"
          />
          <span style="margin-left: 8px; color: #909399">
            {{ couponForm.discount_type === 'percent' ? '折' : '元' }}
          </span>
        </el-form-item>
        <el-form-item label="最低使用金额">
          <el-input-number v-model="couponForm.min_amount" :min="0" :precision="2" style="width: 200px" />
          <span style="margin-left: 8px; color: #909399">元</span>
        </el-form-item>
        <el-form-item label="发放总量">
          <el-input-number v-model="couponForm.total_count" :min="0" style="width: 200px" />
          <span style="margin-left: 8px; color: #909399">张，0表示不限量</span>
        </el-form-item>
        <el-form-item label="每人限领">
          <el-input-number v-model="couponForm.per_user_limit" :min="1" style="width: 200px" />
          <span style="margin-left: 8px; color: #909399">张</span>
        </el-form-item>
        <el-form-item label="有效期类型" prop="valid_type">
          <el-radio-group v-model="couponForm.valid_type">
            <el-radio value="fixed">固定时间</el-radio>
            <el-radio value="relative">领取后有效</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="couponForm.valid_type === 'fixed'" label="有效期" prop="start_time">
          <el-date-picker
            v-model="couponDateRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item v-if="couponForm.valid_type === 'relative'" label="有效天数" prop="valid_days">
          <el-input-number v-model="couponForm.valid_days" :min="1" :max="365" style="width: 200px" />
          <span style="margin-left: 8px; color: #909399">天</span>
        </el-form-item>
        <el-form-item label="使用说明">
          <el-input
            v-model="couponForm.description"
            type="textarea"
            :rows="3"
            placeholder="请输入使用说明"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="couponDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSaveCoupon">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script>
import request from '@/utils/request'
import {
  Ticket,
  CircleCheck,
  Present,
  UserFilled,
  Plus,
  Search
} from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'

export default {
  name: 'Activity',
  data() {
    return {
      Ticket,
      CircleCheck,
      Present,
      UserFilled,
      Plus,
      Search,
      activeTab: 'coupon',
      stats: {},
      couponLoading: false,
      couponList: [],
      couponFilters: {
        type: '',
        status: '',
        keyword: ''
      },
      couponPagination: {
        page: 1,
        limit: 10,
        total: 0
      },
      couponDialogVisible: false,
      isEditCoupon: false,
      couponFormRef: null,
      couponDateRange: [],
      couponForm: {
        id: 0,
        name: '',
        type: 'discount',
        discount_type: 'fixed',
        discount_value: 0,
        min_amount: 0,
        total_count: 0,
        per_user_limit: 1,
        valid_type: 'fixed',
        valid_days: 7,
        start_time: '',
        end_time: '',
        description: ''
      },
      couponRules: {
        name: [{ required: true, message: '请输入优惠券名称', trigger: 'blur' }],
        type: [{ required: true, message: '请选择优惠券类型', trigger: 'change' }]
      }
    }
  },
  mounted() {
    this.fetchStats()
    this.fetchCouponList()
  },
  methods: {
    async fetchStats() {
      try {
        const res = await request.get('/activity/index')
        this.stats = res.data || {}
      } catch (err) {
        console.error('获取活动统计失败:', err)
      }
    },
    async fetchCouponList() {
      this.couponLoading = true
      try {
        const params = {
          ...this.couponFilters,
          page: this.couponPagination.page,
          limit: this.couponPagination.limit
        }
        const res = await request.get('/activity/coupon/list', { params })
        this.couponList = res.data?.list || []
        this.couponPagination.total = res.data?.total || 0
      } catch (err) {
        console.error('获取优惠券列表失败:', err)
        ElMessage.error('获取优惠券列表失败')
      } finally {
        this.couponLoading = false
      }
    },
    getCouponTypeLabel(type) {
      const map = { discount: '满减券', percent: '折扣券', free: '免单券' }
      return map[type] || type
    },
    handleCreateCoupon() {
      this.isEditCoupon = false
      this.couponForm = {
        id: 0,
        name: '',
        type: 'discount',
        discount_type: 'fixed',
        discount_value: 0,
        min_amount: 0,
        total_count: 0,
        per_user_limit: 1,
        valid_type: 'fixed',
        valid_days: 7,
        start_time: '',
        end_time: '',
        description: ''
      }
      this.couponDateRange = []
      this.couponDialogVisible = true
    },
    handleEditCoupon(row) {
      this.isEditCoupon = true
      this.couponForm = { ...row }
      this.couponForm.min_amount = row.min_amount ? row.min_amount / 100 : 0
      if (row.valid_type === 'fixed') {
        this.couponDateRange = [row.start_time, row.end_time]
      }
      this.couponDialogVisible = true
    },
    async handleSaveCoupon() {
      try {
        const data = { ...this.couponForm }
        if (this.couponForm.valid_type === 'fixed' && this.couponDateRange.length === 2) {
          data.start_time = this.couponDateRange[0]
          data.end_time = this.couponDateRange[1]
        }
        if (this.isEditCoupon) {
          await request.post('/activity/coupon/update', data)
          ElMessage.success('更新成功')
        } else {
          await request.post('/activity/coupon/create', data)
          ElMessage.success('创建成功')
        }
        this.couponDialogVisible = false
        this.fetchCouponList()
        this.fetchStats()
      } catch (err) {
        console.error('保存失败:', err)
        ElMessage.error('保存失败')
      }
    },
    async handleToggleCoupon(row, val) {
      try {
        await request.post('/activity/coupon/toggle', {
          id: row.id,
          status: val
        })
        ElMessage.success(val === 1 ? '已启用' : '已禁用')
      } catch (err) {
        console.error('切换状态失败:', err)
        row.status = val === 1 ? 0 : 1
        ElMessage.error('操作失败')
      }
    },
    async handleDeleteCoupon(row) {
      try {
        await ElMessageBox.confirm(
          `确定删除优惠券「${row.name}」吗？`,
          '确认删除',
          { type: 'warning' }
        )
        await request.delete(`/activity/coupon/delete?id=${row.id}`)
        ElMessage.success('删除成功')
        this.fetchCouponList()
        this.fetchStats()
      } catch (err) {
        if (err !== 'cancel') {
          console.error('删除失败:', err)
          ElMessage.error('删除失败')
        }
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

    &.coupon {
      background-color: #ecf5ff;
      color: #409eff;
    }

    &.active {
      background-color: #f0f9eb;
      color: #67c23a;
    }

    &.lottery {
      background-color: #fdf6ec;
      color: #e6a23c;
    }

    &.group {
      background-color: #fef0f0;
      color: #f56c6c;
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

  .success-value {
    color: #67c23a;
  }
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.pagination-wrapper {
  display: flex;
  justify-content: flex-end;
  margin-top: 20px;
}
</style>
