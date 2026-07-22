const request = require('../../utils/request');
const util = require('../../utils/util');

Page({
  data: {
    orderId: '',
    orderInfo: {},
    timelineList: []
  },

  onLoad(options) {
    const { orderId } = options;
    this.setData({ orderId });
    this.loadOrderDetail();
  },

  onShow() {
    if (this.data.orderId) {
      this.loadOrderDetail();
    }
  },

  loadOrderDetail() {
    request.get('/api/v1/order/detail', {
      order_id: this.data.orderId
    }).then((res) => {
      const status = res.status;
      const orderInfo = {
        orderId: res.order_id,
        status: status,
        statusText: util.getOrderStatusText(status),
        statusColor: util.getOrderStatusColor(status),
        statusDesc: this.getStatusDesc(status),
        gameName: res.game_name || '',
        serviceName: res.service_name || '',
        rank: res.rank || '',
        amount: util.fenToYuan(res.amount),
        createTime: util.formatTime(res.create_time, 'YYYY-MM-DD HH:mm'),
        remark: res.remark || '',
        playerAvatar: res.player_avatar || '',
        playerName: res.player_name || '',
        playerRating: res.player_rating || 0,
        userAvatar: res.user_avatar || '',
        userName: res.user_name || '',
        canCancel: status === 0 || status === 1,
        canAppeal: status === 3 || status === 4
      };

      this.setData({
        orderInfo: orderInfo,
        timelineList: this.buildTimeline(res)
      });
    }).catch((err) => {
      console.error('加载订单详情失败:', err);
    });
  },

  getStatusDesc(status) {
    const descMap = {
      0: '等待打手接单',
      1: '打手已接单，准备开始服务',
      2: '服务进行中',
      3: '等待您确认完成',
      4: '订单已完成',
      5: '订单已取消',
      6: '申诉处理中'
    };
    return descMap[status] || '';
  },

  buildTimeline(res) {
    const status = res.status;
    const items = [
      { step: 1, title: '下单成功', time: util.formatTime(res.create_time, 'MM-DD HH:mm'), active: true, done: true },
      { step: 2, title: '打手接单', time: res.accept_time ? util.formatTime(res.accept_time, 'MM-DD HH:mm') : '', active: status >= 1, done: status >= 1 },
      { step: 3, title: '服务进行中', time: res.start_time ? util.formatTime(res.start_time, 'MM-DD HH:mm') : '', active: status >= 2, done: status >= 2 },
      { step: 4, title: '服务完成', time: res.finish_time ? util.formatTime(res.finish_time, 'MM-DD HH:mm') : '', active: status >= 4, done: status >= 4 }
    ];

    if (status === 5) {
      items.push({ step: 5, title: '订单已取消', time: res.cancel_time ? util.formatTime(res.cancel_time, 'MM-DD HH:mm') : '', active: true, done: true });
    } else if (status === 6) {
      items.push({ step: 5, title: '申诉中', time: res.appeal_time ? util.formatTime(res.appeal_time, 'MM-DD HH:mm') : '', active: true, done: false });
    }

    items[items.length - 1].last = true;
    return items;
  },

  onChatWithPlayer() {
    const { orderInfo } = this.data;
    wx.navigateTo({
      url: '/chat/room/room?conversationId=' + orderInfo.orderId + '&targetName=' + encodeURIComponent(orderInfo.playerName)
    });
  },

  onConfirmComplete() {
    wx.showModal({
      title: '确认完成',
      content: '确认打手已完成服务？确认后款项将结算给打手。',
      confirmText: '确认完成',
      success: (res) => {
        if (res.confirm) {
          this.confirmComplete();
        }
      }
    });
  },

  confirmComplete() {
    request.post('/api/v1/order/complete', {
      order_id: this.data.orderId
    }).then(() => {
      wx.showToast({
        title: '已完成',
        icon: 'success',
        duration: 2000
      });
      this.loadOrderDetail();
    }).catch((err) => {
      console.error('确认完成失败:', err);
    });
  },

  onCancelOrder() {
    wx.showModal({
      title: '取消订单',
      content: '确认取消该订单？',
      confirmText: '确认取消',
      confirmColor: '#e94560',
      success: (res) => {
        if (res.confirm) {
          this.cancelOrder();
        }
      }
    });
  },

  cancelOrder() {
    request.post('/api/v1/order/cancel', {
      order_id: this.data.orderId
    }).then(() => {
      wx.showToast({
        title: '已取消',
        icon: 'success',
        duration: 2000
      });
      this.loadOrderDetail();
    }).catch((err) => {
      console.error('取消订单失败:', err);
    });
  },

  onAppeal() {
    wx.navigateTo({
      url: '/pages/appeal-submit/appeal-submit?orderId=' + this.data.orderId
    });
  },

  onGoEvaluate() {
    wx.navigateTo({
      url: '/order-flow/evaluate/evaluate?orderId=' + this.data.orderId
    });
  },

  onGoReward() {
    wx.navigateTo({
      url: '/order-flow/reward/reward?orderId=' + this.data.orderId
    });
  }
});