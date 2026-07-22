const request = require('../../utils/request');
const util = require('../../utils/util');

Page({
  data: {
    serviceInfo: {},
    playerInfo: {},
    totalAmount: '0.00',
    isMinor: false,
    minorLimit: 200,
    remark: '',
    paying: false,
    playerId: '',
    serviceId: ''
  },

  onLoad(options) {
    const { playerId, serviceId } = options;
    this.setData({ playerId, serviceId });
    this.loadOrderInfo(playerId, serviceId);
    this.checkUserAge();
  },

  loadOrderInfo(playerId, serviceId) {
    request.get('/api/v1/order/preview', {
      player_id: playerId,
      service_id: serviceId
    }).then((res) => {
      this.setData({
        serviceInfo: {
          gameName: res.game_name || '',
          rank: res.rank || '',
          serviceName: res.service_name || '',
          duration: res.duration || 1,
          price: util.fenToYuan(res.price)
        },
        playerInfo: {
          avatar: res.player_avatar || '',
          nickname: res.player_nickname || '',
          rating: res.player_rating || 0,
          orderCount: res.player_order_count || 0,
          tags: res.player_tags || []
        },
        totalAmount: util.fenToYuan(res.total_amount)
      });
    }).catch((err) => {
      console.error('加载订单信息失败:', err);
      wx.showToast({ title: '加载失败', icon: 'none' });
    });
  },

  checkUserAge() {
    request.get('/api/v1/user/profile').then((res) => {
      if (res.is_minor) {
        this.setData({
          isMinor: true,
          minorLimit: res.minor_limit || 200
        });
      }
    }).catch(() => {});
  },

  onRemarkInput(e) {
    this.setData({ remark: e.detail.value });
  },

  onGuardianVerify() {
    wx.navigateTo({
      url: '/pages/guardian-verify/guardian-verify?amount=' + this.data.totalAmount
    });
  },

  onOpenESign() {
    wx.navigateTo({
      url: '/pages/e-sign/e-sign?playerId=' + this.data.playerId + '&serviceId=' + this.data.serviceId
    });
  },

  onPay() {
    const { isMinor, totalAmount, minorLimit, playerId, serviceId, remark } = this.data;

    if (isMinor && parseFloat(totalAmount) > minorLimit) {
      wx.showModal({
        title: '未成年人消费限额',
        content: '您的订单金额超出未成年人消费限额，需要监护人验证后才能继续支付。',
        confirmText: '去验证',
        cancelText: '取消',
        success: (res) => {
          if (res.confirm) {
            this.onGuardianVerify();
          }
        }
      });
      return;
    }

    this.setData({ paying: true });

    request.post('/api/v1/order/create', {
      player_id: playerId,
      service_id: serviceId,
      remark: remark.trim()
    }).then((res) => {
      const orderId = res.order_id;

      this.requestPayment(res.pay_info).then(() => {
        wx.showToast({
          title: '支付成功',
          icon: 'success',
          duration: 2000
        });

        setTimeout(() => {
          wx.redirectTo({
            url: '/order-flow/detail/detail?orderId=' + orderId
          });
        }, 2000);
      }).catch((err) => {
        if (err.errMsg && err.errMsg.indexOf('cancel') === -1) {
          wx.showToast({ title: '支付失败', icon: 'none' });
        }
        this.setData({ paying: false });
      });
    }).catch((err) => {
      console.error('创建订单失败:', err);
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