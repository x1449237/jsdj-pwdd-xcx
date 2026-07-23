<template>
  <div class="bigscreen-container">
    <div class="bigscreen-header">
      <div class="header-left">
        <el-button :icon="FullScreen" @click="toggleFullscreen" circle size="large">
        </el-button>
      </div>
      <div class="header-center">
        <h1 class="bigscreen-title">数据可视化大屏</h1>
        <p class="bigscreen-time">{{ currentTime }}</p>
      </div>
      <div class="header-right">
        <span class="refresh-text" @click="fetchData">
          <el-icon :class="{ 'is-loading': loading }"><Refresh /></el-icon>
          刷新
        </span>
      </div>
    </div>

    <div class="bigscreen-body">
      <el-row :gutter="20" class="stat-row">
        <el-col :span="6">
          <div class="stat-card">
            <div class="stat-icon online">
              <el-icon :size="32"><User /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ realtimeData.online_count || 0 }}</div>
              <div class="stat-label">实时在线</div>
            </div>
          </div>
        </el-col>
        <el-col :span="6">
          <div class="stat-card">
            <div class="stat-icon order">
              <el-icon :size="32"><Document /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ realtimeData.today_orders || 0 }}</div>
              <div class="stat-label">今日订单</div>
            </div>
          </div>
        </el-col>
        <el-col :span="6">
          <div class="stat-card">
            <div class="stat-icon money">
              <el-icon :size="32"><Money /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">¥{{ realtimeData.today_revenue || '0.00' }}</div>
              <div class="stat-label">今日营收</div>
            </div>
          </div>
        </el-col>
        <el-col :span="6">
          <div class="stat-card">
            <div class="stat-icon risk" :class="{ 'alert-pulse': (realtimeData.pending_risk || 0) > 0 }">
              <el-icon :size="32"><Warning /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value risk-value">{{ realtimeData.pending_risk || 0 }}</div>
              <div class="stat-label">风险工单</div>
            </div>
          </div>
        </el-col>
      </el-row>

      <el-row :gutter="20" class="chart-row">
        <el-col :span="12">
          <div class="chart-card">
            <div class="chart-header">
              <span>24小时订单趋势</span>
              <span class="chart-sub">峰值: {{ realtimeData.peak_orders || 0 }}单/小时</span>
            </div>
            <div class="chart-body">
              <div class="line-chart">
                <div
                  v-for="(item, index) in orderTrend"
                  :key="index"
                  class="line-bar"
                  :title="item.hour + '时: ' + item.order_count + '单'"
                >
                  <div
                    class="bar"
                    :style="{
                      height: maxOrderCount > 0 ? (item.order_count / maxOrderCount * 100) + '%' : '0%'
                    }"
                  ></div>
                  <span class="bar-label">{{ item.hour }}</span>
                </div>
              </div>
            </div>
          </div>
        </el-col>
        <el-col :span="12">
          <div class="chart-card">
            <div class="chart-header">
              <span>资金流水</span>
              <span class="chart-sub">24小时</span>
            </div>
            <div class="chart-body">
              <div class="line-chart">
                <div
                  v-for="(item, index) in fundFlow"
                  :key="index"
                  class="fund-bar"
                >
                  <div class="bar-group">
                    <div
                      class="bar income"
                      :style="{
                        height: maxFundAmount > 0 ? (item.income / maxFundAmount * 100) + '%' : '0%'
                      }"
                      :title="'收入: ¥' + item.income"
                    ></div>
                    <div
                      class="bar expense"
                      :style="{
                        height: maxFundAmount > 0 ? (item.expense / maxFundAmount * 100) + '%' : '0%'
                      }"
                      :title="'支出: ¥' + item.expense"
                    ></div>
                  </div>
                  <span class="bar-label">{{ item.time }}</span>
                </div>
              </div>
              <div class="chart-legend">
                <span><i class="legend-dot income"></i>收入</span>
                <span><i class="legend-dot expense"></i>支出</span>
              </div>
            </div>
          </div>
        </el-col>
      </el-row>

      <el-row :gutter="20" class="bottom-row">
        <el-col :span="8">
          <div class="chart-card">
            <div class="chart-header">
              <span>用户统计</span>
            </div>
            <div class="chart-body user-stats">
              <div class="user-stat-item">
                <span class="stat-num primary">{{ realtimeData.total_users || 0 }}</span>
                <span class="stat-text">总用户数</span>
              </div>
              <div class="user-stat-item">
                <span class="stat-num success">{{ realtimeData.today_new_users || 0 }}</span>
                <span class="stat-text">今日新增</span>
              </div>
              <div class="user-stat-item">
                <span class="stat-num warning">{{ realtimeData.hour_orders || 0 }}</span>
                <span class="stat-text">近1小时订单</span>
              </div>
            </div>
          </div>
        </el-col>
        <el-col :span="8">
          <div class="chart-card">
            <div class="chart-header">
              <span>风险等级分布</span>
              <span class="chart-sub">近7天</span>
            </div>
            <div class="chart-body risk-stats">
              <div class="risk-stat-item high">
                <div class="risk-level">高风险</div>
                <div class="risk-bar">
                  <div
                    class="risk-bar-inner"
                    :style="{ width: totalRiskCount > 0 ? (highRiskCount / totalRiskCount * 100) + '%' : '0%' }"
                  ></div>
                </div>
                <div class="risk-count">{{ highRiskCount }}</div>
              </div>
              <div class="risk-stat-item medium">
                <div class="risk-level">中风险</div>
                <div class="risk-bar">
                  <div
                    class="risk-bar-inner"
                    :style="{ width: totalRiskCount > 0 ? (mediumRiskCount / totalRiskCount * 100) + '%' : '0%' }"
                  ></div>
                </div>
                <div class="risk-count">{{ mediumRiskCount }}</div>
              </div>
              <div class="risk-stat-item low">
                <div class="risk-level">低风险</div>
                <div class="risk-bar">
                  <div
                    class="risk-bar-inner"
                    :style="{ width: totalRiskCount > 0 ? (lowRiskCount / totalRiskCount * 100) + '%' : '0%' }"
                  ></div>
                </div>
                <div class="risk-count">{{ lowRiskCount }}</div>
              </div>
            </div>
          </div>
        </el-col>
        <el-col :span="8">
          <div class="chart-card">
            <div class="chart-header">
              <span>今日提现</span>
            </div>
            <div class="chart-body withdraw-stats">
              <div class="withdraw-amount">
                <span class="amount-symbol">¥</span>
                <span class="amount-value">{{ realtimeData.today_withdraw || '0.00' }}</span>
              </div>
              <div class="withdraw-label">今日提现总额</div>
              <div class="withdraw-tip">
                <el-icon><InfoFilled /></el-icon>
                实时更新中
              </div>
            </div>
          </div>
        </el-col>
      </el-row>
    </div>
  </div>
</template>

<script>
import request from '@/utils/request'
import {
  FullScreen,
  Refresh,
  User,
  Document,
  Money,
  Warning,
  InfoFilled
} from '@element-plus/icons-vue'

export default {
  name: 'Bigscreen',
  data() {
    return {
      FullScreen,
      Refresh,
      User,
      Document,
      Money,
      Warning,
      InfoFilled,
      loading: false,
      currentTime: '',
      timer: null,
      refreshTimer: null,
      realtimeData: {},
      orderTrend: [],
      fundFlow: []
    }
  },
  computed: {
    maxOrderCount() {
      if (this.orderTrend.length === 0) return 1
      const max = Math.max(...this.orderTrend.map(item => item.order_count))
      return max || 1
    },
    maxFundAmount() {
      if (this.fundFlow.length === 0) return 1
      const max = Math.max(
        ...this.fundFlow.map(item => Math.max(parseFloat(item.income), parseFloat(item.expense)))
      )
      return max || 1
    },
    highRiskCount() {
      if (!this.riskTrend || this.riskTrend.length === 0) return 0
      return this.riskTrend.reduce((sum, item) => sum + (item.high || 0), 0)
    },
    mediumRiskCount() {
      if (!this.riskTrend || this.riskTrend.length === 0) return 0
      return this.riskTrend.reduce((sum, item) => sum + (item.medium || 0), 0)
    },
    lowRiskCount() {
      if (!this.riskTrend || this.riskTrend.length === 0) return 0
      return this.riskTrend.reduce((sum, item) => sum + (item.low || 0), 0)
    },
    totalRiskCount() {
      return this.highRiskCount + this.mediumRiskCount + this.lowRiskCount
    },
    riskTrend() {
      return []
    }
  },
  mounted() {
    this.updateTime()
    this.timer = setInterval(() => {
      this.updateTime()
    }, 1000)
    this.fetchData()
    this.refreshTimer = setInterval(() => {
      this.fetchData()
    }, 30000)
  },
  beforeUnmount() {
    if (this.timer) {
      clearInterval(this.timer)
    }
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer)
    }
  },
  methods: {
    updateTime() {
      const now = new Date()
      this.currentTime = now.toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
      })
    },
    async fetchData() {
      this.loading = true
      try {
        const [realtimeRes, trendRes, fundRes] = await Promise.all([
          request.get('/data_dashboard/realtime').catch(() => ({ data: {} })),
          request.get('/data_dashboard/order_trend').catch(() => ({ data: [] })),
          request.get('/data_dashboard/fund_flow').catch(() => ({ data: [] }))
        ])
        this.realtimeData = realtimeRes.data || {}
        this.orderTrend = trendRes.data || []
        this.fundFlow = fundRes.data || []
      } catch (err) {
        console.error('获取大屏数据失败:', err)
      } finally {
        this.loading = false
      }
    },
    toggleFullscreen() {
      if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen()
      } else {
        document.exitFullscreen()
      }
    }
  }
}
</script>

<style lang="scss" scoped>
.bigscreen-container {
  min-height: 100vh;
  background: linear-gradient(135deg, #0c1929 0%, #1a2a4a 50%, #0c1929 100%);
  color: #fff;
  padding: 20px;
  box-sizing: border-box;
}

.bigscreen-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 20px;
  margin-bottom: 20px;

  .header-left,
  .header-right {
    width: 120px;
  }

  .header-center {
    text-align: center;
    flex: 1;
  }

  .bigscreen-title {
    font-size: 32px;
    font-weight: 700;
    margin: 0;
    background: linear-gradient(90deg, #4fc3f7, #29b6f6, #0288d1);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    letter-spacing: 4px;
  }

  .bigscreen-time {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.6);
    margin: 8px 0 0 0;
  }

  .refresh-text {
    cursor: pointer;
    font-size: 14px;
    color: rgba(255, 255, 255, 0.7);
    display: flex;
    align-items: center;
    gap: 6px;
    justify-content: flex-end;

    &:hover {
      color: #4fc3f7;
    }

    .is-loading {
      animation: spin 1s linear infinite;
    }
  }
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.bigscreen-body {
  max-width: 1920px;
  margin: 0 auto;
}

.stat-row {
  margin-bottom: 20px;
}

.stat-card {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(79, 195, 247, 0.2);
  border-radius: 12px;
  padding: 24px;
  display: flex;
  align-items: center;
  gap: 16px;
  backdrop-filter: blur(10px);

  .stat-icon {
    width: 64px;
    height: 64px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;

    &.online {
      background: linear-gradient(135deg, rgba(76, 175, 80, 0.3), rgba(76, 175, 80, 0.1));
      color: #4caf50;
    }

    &.order {
      background: linear-gradient(135deg, rgba(33, 150, 243, 0.3), rgba(33, 150, 243, 0.1));
      color: #2196f3;
    }

    &.money {
      background: linear-gradient(135deg, rgba(255, 193, 7, 0.3), rgba(255, 193, 7, 0.1));
      color: #ffc107;
    }

    &.risk {
      background: linear-gradient(135deg, rgba(244, 67, 54, 0.3), rgba(244, 67, 54, 0.1));
      color: #f44336;
    }

    &.alert-pulse {
      animation: riskPulse 2s ease-in-out infinite;
    }
  }

  .stat-info {
    flex: 1;
    min-width: 0;
  }

  .stat-value {
    font-size: 36px;
    font-weight: 700;
    line-height: 1.2;
    font-family: 'DIN Alternate', 'Arial', sans-serif;
  }

  .stat-label {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.6);
    margin-top: 6px;
  }

  .risk-value {
    color: #f44336;
  }
}

@keyframes riskPulse {
  0%, 100% {
    box-shadow: 0 0 0 0 rgba(244, 67, 54, 0.4);
  }
  50% {
    box-shadow: 0 0 0 12px rgba(244, 67, 54, 0);
  }
}

.chart-row {
  margin-bottom: 20px;
}

.chart-card {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(79, 195, 247, 0.2);
  border-radius: 12px;
  backdrop-filter: blur(10px);

  .chart-header {
    padding: 16px 20px;
    border-bottom: 1px solid rgba(79, 195, 247, 0.1);
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 16px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.9);
  }

  .chart-sub {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.5);
    font-weight: normal;
  }

  .chart-body {
    padding: 20px;
    height: 280px;
  }
}

.line-chart {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  height: 100%;
  padding: 0 4px;
  border-bottom: 1px solid rgba(79, 195, 247, 0.1);
}

.line-bar {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  height: 100%;
  justify-content: flex-end;

  .bar {
    width: 60%;
    max-width: 20px;
    min-height: 2px;
    background: linear-gradient(to top, rgba(79, 195, 247, 0.8), rgba(79, 195, 247, 0.3));
    border-radius: 3px 3px 0 0;
    transition: height 0.6s ease;
  }

  .bar-label {
    font-size: 10px;
    color: rgba(255, 255, 255, 0.4);
    margin-top: 6px;
    white-space: nowrap;
  }
}

.fund-bar {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  height: 100%;
  justify-content: flex-end;

  .bar-group {
    display: flex;
    align-items: flex-end;
    gap: 2px;
    height: 80%;
  }

  .bar {
    width: 10px;
    min-height: 2px;
    border-radius: 2px 2px 0 0;
    transition: height 0.6s ease;

    &.income {
      background: linear-gradient(to top, rgba(76, 175, 80, 0.9), rgba(76, 175, 80, 0.3));
    }

    &.expense {
      background: linear-gradient(to top, rgba(244, 67, 54, 0.9), rgba(244, 67, 54, 0.3));
    }
  }

  .bar-label {
    font-size: 10px;
    color: rgba(255, 255, 255, 0.4);
    margin-top: 6px;
    white-space: nowrap;
  }
}

.chart-legend {
  display: flex;
  justify-content: center;
  gap: 24px;
  margin-top: 12px;
  font-size: 12px;
  color: rgba(255, 255, 255, 0.6);

  .legend-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 6px;
    vertical-align: middle;

    &.income {
      background-color: #4caf50;
    }

    &.expense {
      background-color: #f44336;
    }
  }
}

.bottom-row {
  margin-bottom: 0;
}

.user-stats {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 20px;
  height: 100%;

  .user-stat-item {
    text-align: center;
  }

  .stat-num {
    font-size: 32px;
    font-weight: 700;
    font-family: 'DIN Alternate', 'Arial', sans-serif;
    display: block;

    &.primary {
      color: #4fc3f7;
    }

    &.success {
      color: #4caf50;
    }

    &.warning {
      color: #ffc107;
    }
  }

  .stat-text {
    font-size: 13px;
    color: rgba(255, 255, 255, 0.6);
    margin-top: 4px;
    display: block;
  }
}

.risk-stats {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 16px;
  height: 100%;

  .risk-stat-item {
    display: flex;
    align-items: center;
    gap: 12px;

    .risk-level {
      width: 60px;
      font-size: 13px;
      flex-shrink: 0;
    }

    .risk-bar {
      flex: 1;
      height: 12px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 6px;
      overflow: hidden;
    }

    .risk-bar-inner {
      height: 100%;
      border-radius: 6px;
      transition: width 0.6s ease;
    }

    &.high {
      .risk-level { color: #f44336; }
      .risk-bar-inner { background: linear-gradient(90deg, #f44336, #ff5722); }
    }

    &.medium {
      .risk-level { color: #ff9800; }
      .risk-bar-inner { background: linear-gradient(90deg, #ff9800, #ffc107); }
    }

    &.low {
      .risk-level { color: #4caf50; }
      .risk-bar-inner { background: linear-gradient(90deg, #4caf50, #8bc34a); }
    }

    .risk-count {
      width: 40px;
      text-align: right;
      font-size: 14px;
      font-weight: 600;
    }
  }
}

.withdraw-stats {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  text-align: center;

  .withdraw-amount {
    display: flex;
    align-items: baseline;
    gap: 4px;

    .amount-symbol {
      font-size: 24px;
      color: #ffc107;
    }

    .amount-value {
      font-size: 40px;
      font-weight: 700;
      color: #ffc107;
      font-family: 'DIN Alternate', 'Arial', sans-serif;
    }
  }

  .withdraw-label {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.6);
    margin-top: 8px;
  }

  .withdraw-tip {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.4);
    margin-top: 12px;
    display: flex;
    align-items: center;
    gap: 4px;
  }
}

@media screen and (max-width: 1200px) {
  .stat-value {
    font-size: 28px !important;
  }

  .bigscreen-title {
    font-size: 24px !important;
  }
}
</style>
