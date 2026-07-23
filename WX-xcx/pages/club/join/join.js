const request = require('../../../utils/request');

Page({
  data: {
    clubName: '',
    clubType: 'green_v',  // 默认个人级
    clubTypeIndex: 1,
    clubTypeOptions: ['企业级俱乐部', '个人级俱乐部'],
    clubTypeValues: ['blue_v', 'green_v'],
    description: '',
    submitting: false,
    clubJoinOpen: true
  },

  onLoad() {
    this.checkSwitch();
  },

  async checkSwitch() {
    try {
      const res = await request.get('/api/v1/club/check_switch');
      const isOpen = res.data?.club_join_open === true;
      if (!isOpen) {
        wx.showModal({
          title: '提示',
          content: '俱乐部入驻功能暂未开放',
          showCancel: false,
          success: () => wx.navigateBack()
        });
      }
      this.setData({ clubJoinOpen: isOpen });
    } catch (e) {
      wx.showToast({ title: '网络异常', icon: 'none' });
    }
  },

  onClubTypeChange(e) {
    const index = e.detail.value;
    this.setData({
      clubTypeIndex: index,
      clubType: this.data.clubTypeValues[index]
    });
  },

  async onSubmit() {
    const { clubName, clubType, clubJoinOpen } = this.data;

    if (!clubJoinOpen) {
      wx.showToast({ title: '俱乐部入驻功能暂未开放', icon: 'none' });
      return;
    }

    if (!clubName.trim()) {
      wx.showToast({ title: '请输入俱乐部名称', icon: 'none' });
      return;
    }

    if (clubName.trim().length < 2) {
      wx.showToast({ title: '俱乐部名称至少2个字符', icon: 'none' });
      return;
    }

    this.setData({ submitting: true });

    try {
      await request.post('/api/v1/club/submit', {
        club_name: clubName.trim(),
        club_type: clubType,
        description: this.data.description
      });

      wx.showToast({ title: '入驻申请已提交', icon: 'success' });
      setTimeout(() => {
        wx.navigateBack();
      }, 1500);
    } catch (e) {
      wx.showToast({ title: e.message || '提交失败', icon: 'none' });
    } finally {
      this.setData({ submitting: false });
    }
  }
});