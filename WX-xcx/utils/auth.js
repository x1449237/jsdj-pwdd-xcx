const TOKEN_KEY = 'auth_token';
const USER_INFO_KEY = 'user_info';
const AGREEMENT_KEY = 'agreement_accepted';

const getToken = () => {
  try {
    return wx.getStorageSync(TOKEN_KEY) || '';
  } catch (e) {
    return '';
  }
};

const setToken = (token) => {
  try {
    wx.setStorageSync(TOKEN_KEY, token);
  } catch (e) {
    console.error('保存token失败:', e);
  }
};

const removeToken = () => {
  try {
    wx.removeStorageSync(TOKEN_KEY);
  } catch (e) {
    console.error('移除token失败:', e);
  }
};

const getStoredUserInfo = () => {
  try {
    return wx.getStorageSync(USER_INFO_KEY) || null;
  } catch (e) {
    return null;
  }
};

const setStoredUserInfo = (userInfo) => {
  try {
    wx.setStorageSync(USER_INFO_KEY, userInfo);
  } catch (e) {
    console.error('保存用户信息失败:', e);
  }
};

const isLogin = () => {
  const token = getToken();
  return !!token;
};

const isAgreementAccepted = () => {
  try {
    return wx.getStorageSync(AGREEMENT_KEY) || false;
  } catch (e) {
    return false;
  }
};

const acceptAgreement = () => {
  try {
    wx.setStorageSync(AGREEMENT_KEY, true);
  } catch (e) {
    console.error('保存协议同意状态失败:', e);
  }
};

const wxLogin = () => {
  return new Promise((resolve, reject) => {
    wx.login({
      success(res) {
        if (res.code) {
          resolve(res.code);
        } else {
          reject(new Error('登录失败'));
        }
      },
      fail(err) {
        reject(err);
      }
    });
  });
};

const getPhoneNumber = (e) => {
  return new Promise((resolve, reject) => {
    if (e.detail.errMsg === 'getPhoneNumber:ok') {
      resolve(e.detail);
    } else {
      reject(new Error('获取手机号失败'));
    }
  });
};

module.exports = {
  getToken,
  setToken,
  removeToken,
  getStoredUserInfo,
  setStoredUserInfo,
  isLogin,
  isAgreementAccepted,
  acceptAgreement,
  wxLogin,
  getPhoneNumber
};