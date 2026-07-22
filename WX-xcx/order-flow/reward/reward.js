const request = require('../../utils/request');
const util = require('../../utils/util');

Page({
  data: {
    orderId: '',
    playerInfo: {},
    presetAmounts: [5, 10, 18, 28, 50, 88],
    quickAmounts: [6.66, 8.88, 18.88, 66.66],
    selectedAmount: 0,
    customAmount: '',
    isCustomAmount: false,
    message: '',
    paying: false
  },

  onLoad(options) {
    const { orderId } = options;
    this.setData({ orderId });
    this.loadPlayerInfo();
  },

  loadPlayerInfo() {
    request.get('/api/v1/order/reward-info', {
      order_id: this.data.orderId
    }).then((res) => {
      this.setData({
        playerInfo: {
          avatar: res.player_avatar || '',
          nickname: res.player_nickname || ''
        }
      });
    }).catch(() => {});
  },

  onSelectAmount(e) {
    const amount = parseFloat(e.currentTarget.dataset.amount);
    this.setData({
      selectedAmount: amount,
      customAmount: '',
      isCustomAmount: false
    });
  },

  onCustomAmountInput(e) {
    const value = e.detail.value;
    this.setData({
      customAmount: value,
      selectedAmount: 0,
      isCustomAmount: !!value
    });
  },

  onCustomFocus() {
    this.setData({
      isCustomAmount: true,
      selectedAmount: 0
    });
  },

  onMessageInput(e) {
    this.setData({ message: e.detail.value });
  },

  onReward() {
    const { selectedAmount, customAmount, isCustomAmount, message, orderId } = this.data;
    let amount = selectedAmount;

    if (isCustomAmount) {
      amount = parseFloat(customAmount);
      if (!amount || amount <= 0) {
        wx.showToast({ title: '请输入有效的打赏金额', icon: 'none' });
        return;
      }
      if (amount > 200) {
        wx.showToast({ title: '单笔打赏不能超过¥200', icon: 'none' });
        return;
      }
    }

    if (!amount || amount <= 0) {
      wx.showToast({ title: '请选择打赏金额', icon: 'none' });
      return;
    }

    this.setData({ paying: true });

    request.post('/api/v1/order/reward', {
      order_id: orderId,
      amount: util.yuanToFen(amount),
      message: message.trim()
    }).then((res) => {
      this.requestPayment(res.pay_info).then(() => {
        wx.showToast({
          title: '打赏成功',
          icon: 'success',
          duration: 2000
        });
        setTimeout(() => {
          wx.navigateBack();
        }, 2000);
      }).catch((err) => {
        if (err.errMsg && err.errMsg.indexOf('cancel') === -1) {
          wx.showToast({ title: '支付失败', icon: 'none' });
        }
        this.setData({ paying: false });
      });
    }).catch((err) => {
      console.error('打赏失败:', err);
      this.setData({ paying: false });
    });
  },

  requestPayment(payInfo) {
    return new Promise((resolve, reject) => {
      wx.requestPayment({
        timeStamp: payInfo.timeStamp,
        nonceStr: payInfo.nonceStr,
        package: payInfo.package,
        signType: payInfo.signType || 'MD5',
        paySign: payInfo.paySign,
        success: resolve,
        fail: reject
      });
    });
  }
});