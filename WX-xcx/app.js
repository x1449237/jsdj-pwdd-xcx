const auth = require('./utils/auth');
const websocket = require('./utils/websocket');

App({
  globalData: {
    userInfo: null,
    token: null,
    isLogin: false,
    systemInfo: null,
    baseURL: 'https://api.example.com',
    wsConnected: false
  },

  onLaunch(options) {
    const that = this;
    this.getSystemInfo();
    this.checkUpdate();

    const token = auth.getToken();
    if (token) {
      this.globalData.token = token;
      this.globalData.isLogin = true;
      websocket.connect();
    }
  },

  onShow(options) {
    if (this.globalData.isLogin && !this.globalData.wsConnected) {
      websocket.connect();
    }
  },

  onHide() {
    websocket.close();
  },

  getSystemInfo() {
    try {
      const systemInfo = wx.getSystemInfoSync();
      this.globalData.systemInfo = systemInfo;
      this.globalData.statusBarHeight = systemInfo.statusBarHeight;
      this.globalData.navBarHeight = systemInfo.platform === 'ios' ? 44 : 48;
      this.globalData.safeAreaBottom = systemInfo.screenHeight - systemInfo.safeArea.bottom;
    } catch (e) {
      console.error('获取系统信息失败:', e);
    }
  },

  checkUpdate() {
    if (wx.canIUse('getUpdateManager')) {
      const updateManager = wx.getUpdateManager();
      updateManager.onCheckForUpdate(function (res) {
        if (res.hasUpdate) {
          updateManager.onUpdateReady(function () {
            wx.showModal({
              title: '更新提示',
              content: '新版本已经准备好，是否重启应用？',
              success: function (res) {
                if (res.confirm) {
                  updateManager.applyUpdate();
                }
              }
            });
          });
          updateManager.onUpdateFailed(function () {
            wx.showModal({
              title: '更新提示',
              content: '新版本下载失败，请检查网络',
              showCancel: false
            });
          });
        }
      });
    }
  },

  setLoginState(token, userInfo) {
    this.globalData.token = token;
    this.globalData.userInfo = userInfo;
    this.globalData.isLogin = true;
    auth.setToken(token);
    websocket.connect();
  },

  logout() {
    this.globalData.token = null;
    this.globalData.userInfo = null;
    this.globalData.isLogin = false;
    auth.removeToken();
    websocket.close();
    wx.reLaunch({
      url: '/pages/login/login'
    });
  }
});