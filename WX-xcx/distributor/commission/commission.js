const request = require('../../utils/request');
const util = require('../../utils/util');

Page({
  data: {
    totalCommission: '0.00',
    pendingCount: 0,
    settledCount: 0,
    firstOrderCount: 0,
    commissionList: [],
    statusFilter: ['全部', '待结算', '已结算'],
    statusIndex: 0,
    page: 1,
    pageSize: 20,
    hasMore: true,
    loading: true,
    loadingMore: false
  },

  onLoad() {
    this.loadCommissionSummary();
    this.loadCommissionList();
  },

  async loadCommissionSummary() {
    try {
      const res = await request.get('/distributor/commission/summary');
      this.setData({
        totalCommission: util.fenToYuan(res.totalCommission || 0),
        pendingCount: res.pendingCount || 0,
        settledCount: res.settledCount || 0,
        firstOrderCount: res.firstOrderCount || 0
      });
    } catch (err) {
      // 忽略
    }
  },

  async loadCommissionList() {
    this.setData({ loading: true });
    try {
      const res = await request.get('/distributor/commission/list', {
        page: this.data.page,
        pageSize: this.data.pageSize,
        status: this.data.statusIndex === 0 ? '' : this.data.statusIndex
      });

      const list = (res.list || []).map(item => this.formatCommissionItem(item));
      this.setData({
        commissionList: list,
        hasMore: res.hasMore !== false,
        loading: false
      });
    } catch (err) {
      this.setData({ loading: false });
    }
  },

  async onReachBottom() {
    if (!this.data.hasMore || this.data.loadingMore) return;
    this.setData({ loadingMore: true });
    try {
      const nextPage = this.data.page + 1;
      const res = await request.get('/distributor/commission/list', {
        page: nextPage,
        pageSize: this.data.pageSize,
        status: this.data.statusIndex === 0 ? '' : this.data.statusIndex
      });

      const list = (res.list || []).map(item => this.formatCommissionItem(item));
      this.setData({
        commissionList: [...this.data.commissionList, ...list],
        page: nextPage,
        hasMore: res.hasMore !== false,
        loadingMore: false
      });
    } catch (err) {
      this.setData({ loadingMore: false });
    }
  },

  formatCommissionItem(item) {
    const rateMap = { 1: '5%', 2: '2%' };
    return {
      ...item,
      sourceAvatar: item.sourceAvatar || '/assets/images/default-avatar.png',
      sourceName: util.maskName(item.sourceName || ''),
      orderNo: item.orderNo || '',
      amountText: util.fenToYuan(item.amount || 0),
      rateText: `佣金${rateMap[item.level] || '--'}`,
      timeText: util.formatTime(item.createTime, 'MM-DD HH:mm'),
      statusText: item.status === 1 ? '已结算' : '待结算',
      isFirstOrder: item.isFirstOrder || false
    };
  },

  /* ========== 状态筛选 ========== */
  onStatusChange(e) {
    const index = parseInt(e.detail.value);
    this.setData({
      statusIndex: index,
      page: 1,
      commissionList: [],
      hasMore: true
    });
    this.loadCommissionList();
  }
});