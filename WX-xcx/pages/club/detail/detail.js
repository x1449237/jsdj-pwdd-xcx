const request = require('../../../utils/request');

Page({
  data: {
    clubId: 0,
    club: null,
    loading: true
  },

  onLoad(options) {
    const id = parseInt(options.id) || 0;
    this.setData({ clubId: id });
    this.loadDetail();
  },

  async loadDetail() {
    try {
      const res = await request.get('/api/v1/club/detail', {
        id: this.data.clubId
      });
      this.setData({ club: res.data, loading: false });
    } catch (e) {
      wx.showToast({ title: '加载失败', icon: 'none' });
      this.setData({ loading: false });
    }
  }
});