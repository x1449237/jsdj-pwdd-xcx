Component({
  properties: {
    badgeType: {
      type: String,
      value: 'golden_v' // golden_v | blue_v | green_v
    }
  },

  data: {
    badgeClass: '',
    badgeLabel: 'V'
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

      switch (type) {
        case 'golden_v':
          badgeClass = 'badge-golden';
          break;
        case 'blue_v':
          badgeClass = 'badge-blue';
          break;
        case 'green_v':
          badgeClass = 'badge-green';
          break;
        default:
          badgeClass = 'badge-golden';
      }

      this.setData({ badgeClass });
    }
  }
});