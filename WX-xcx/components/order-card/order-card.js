Component({
  properties: {
    order: {
      type: Object,
      value: {
        order_no: '',
        game_name: '',
        game_icon: '',
        service_type: '',
        rank: '',
        user_avatar: '',
        user_nickname: '',
        user_level: '',
        user_rate: 0,
        amount: 0,
        duration: 0,
        create_time: '',
        status: 'pending'
      },
      observer: function (newVal) {
        this.updateStatusInfo(newVal);
      }
    },
    showActions: {
      type: Boolean,
      value: true
    },
    extraClass: {
      type: String,
      value: ''
    }
  },

  data: {
    orderStatusText: '',
    statusTagClass: ''
  },

  lifetimes: {
    attached() {
      this.updateStatusInfo(this.properties.order);
    }
  },

  methods: {
    updateStatusInfo(order) {
      if (!order) return;
      const statusMap = {
        'pending': { text: '待支付', tagClass: 'tag-pending' },
        'doing': { text: '进行中', tagClass: 'tag-doing' },
        'done': { text: '已完成', tagClass: 'tag-done' },
        'cancel': { text: '已取消', tagClass: 'tag-cancel' },
        'appeal': { text: '申诉中', tagClass: 'tag-appeal' }
      };
      const info = statusMap[order.status] || { text: '未知', tagClass: '' };
      this.setData({
        orderStatusText: info.text,
        statusTagClass: info.tagClass
      });
    },

    onTap() {
      this.triggerEvent('tap', { order: this.properties.order });
    },

    onCancel() {
      this.triggerEvent('cancel', { order: this.properties.order });
    },

    onPay() {
      this.triggerEvent('pay', { order: this.properties.order });
    },

    onContact() {
      this.triggerEvent('contact', { order: this.properties.order });
    },

    onEvaluate() {
      this.triggerEvent('evaluate', { order: this.properties.order });
    },

    onViewAppeal() {
      this.triggerEvent('viewappeal', { order: this.properties.order });
    }
  }
});