Component({
  properties: {
    badgeType: {
      type: String,
      value: 'golden_v'
      // golden_v | blue_v | green_v
      // up_bronze | up_advanced | up_high | up_elite | up_master | up_supreme
    },
    badgeText: {
      type: String,
      value: ''
    }
  },

  data: {
    badgeClass: '',
    badgeLabel: 'V',
    isUpMaster: false
  },

  lifetimes: {
    attached() {
      this.updateBadgeStyle();
    }
  },

  observers: {
    'badgeType': function(newVal) {
      this.updateBadgeStyle();
    }
  },

  methods: {
    updateBadgeStyle() {
      const type = this.properties.badgeType;
      let badgeClass = '';
      let badgeLabel = 'V';
      let isUpMaster = false;

      // 检查是否为UP主类型
      if (type && type.startsWith('up_')) {
        isUpMaster = true;
        badgeLabel = 'UP';
        switch (type) {
          case 'up_bronze':   badgeClass = 'badge-up-bronze'; break;
          case 'up_advanced': badgeClass = 'badge-up-advanced'; break;
          case 'up_high':     badgeClass = 'badge-up-high'; break;
          case 'up_elite':    badgeClass = 'badge-up-elite'; break;
          case 'up_master':   badgeClass = 'badge-up-master'; break;
          case 'up_supreme':  badgeClass = 'badge-up-supreme'; break;
          default:            badgeClass = 'badge-up-bronze';
        }
      } else {
        switch (type) {
          case 'golden_v': badgeClass = 'badge-golden'; break;
          case 'blue_v':   badgeClass = 'badge-blue'; break;
          case 'green_v':  badgeClass = 'badge-green'; break;
          default:         badgeClass = 'badge-golden';
        }
      }

      this.setData({ badgeClass, badgeLabel, isUpMaster });
    }
  }
});