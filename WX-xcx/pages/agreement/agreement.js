Page({
  data: {
    currentTab: 'service' // service | privacy
  },

  onLoad(options) {
    if (options && options.type) {
      this.setData({ currentTab: options.type });
    }
  },

  onSwitchTab(e) {
    const tab = e.currentTarget.dataset.tab;
    if (tab !== this.data.currentTab) {
      this.setData({ currentTab: tab });
    }
  }
});