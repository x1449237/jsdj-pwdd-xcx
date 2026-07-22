const request = require('../../utils/request');
const util = require('../../utils/util');

Page({
  data: {
    inviteCode: '',
    totalCommission: '0.00',
    monthCommission: '0.00',
    level1Count: 0,
    level2Count: 0
  },

  onLoad() {
    this.loadDistributorInfo();
  },

  onShow() {
    this.loadDistributorInfo();
  },

  async loadDistributorInfo() {
    try {
      const res = await request.get('/distributor/info');
      this.setData({
        inviteCode: res.inviteCode || '',
        totalCommission: util.fenToYuan(res.totalCommission || 0),
        monthCommission: util.fenToYuan(res.monthCommission || 0),
        level1Count: res.level1Count || 0,
        level2Count: res.level2Count || 0
      });
    } catch (err) {
      // 忽略错误
    }
  },

  /* ========== 复制邀请码 ========== */
  copyInviteCode() {
    if (!this.data.inviteCode) {
      wx.showToast({ title: '邀请码获取失败', icon: 'none' });
      return;
    }
    wx.setClipboardData({
      data: this.data.inviteCode,
      success: () => {
        wx.showToast({ title: '邀请码已复制', icon: 'success' });
      }
    });
  },

  /* ========== 快速入口 ========== */
  goSubordinates() {
    wx.navigateTo({
      url: '/distributor/subordinates/subordinates'
    });
  },

  goCommission() {
    wx.navigateTo({
      url: '/distributor/commission/commission'
    });
  }
});