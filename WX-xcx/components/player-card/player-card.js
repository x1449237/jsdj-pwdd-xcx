Component({
  properties: {
    player: {
      type: Object,
      value: {
        id: 0,
        avatar: '/assets/images/default-avatar.png',
        nickname: '',
        gender: 0,
        is_online: false,
        rank: '',
        specialty: '',
        good_rate: 0,
        order_count: 0,
        price: 0
      }
    },
    extraClass: {
      type: String,
      value: ''
    }
  },

  methods: {
    onTap() {
      this.triggerEvent('tap', { player: this.properties.player });
    }
  }
});