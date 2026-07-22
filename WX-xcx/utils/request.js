const auth = require('./auth');

const baseURL = 'https://api.example.com';

let requestCount = 0;

const generateTraceId = () => {
  const timestamp = Date.now().toString(36);
  const random = Math.random().toString(36).substring(2, 10);
  const seq = (++requestCount).toString(36).padStart(4, '0');
  return `${timestamp}${random}${seq}`;
};

const request = (options) => {
  return new Promise((resolve, reject) => {
    const token = auth.getToken();
    const traceId = generateTraceId();

    const header = {
      'Content-Type': 'application/json',
      'X-Trace-Id': traceId,
      ...options.header
    };

    if (token) {
      header['Authorization'] = `Bearer ${token}`;
    }

    wx.request({
      url: `${baseURL}${options.url}`,
      method: options.method || 'GET',
      data: options.data || {},
      header: header,
      timeout: options.timeout || 15000,
      success(res) {
        const { statusCode, data } = res;

        if (statusCode === 200) {
          if (data.code === 0) {
            resolve(data.data);
          } else if (data.code === 401) {
            auth.removeToken();
            const app = getApp();
            app.globalData.isLogin = false;
            app.globalData.token = null;
            wx.reLaunch({
              url: '/pages/login/login'
            });
            reject(data);
          } else {
            wx.showToast({
              title: data.message || '请求失败',
              icon: 'none',
              duration: 2000
            });
            reject(data);
          }
        } else if (statusCode === 401) {
          auth.removeToken();
          const app = getApp();
          app.globalData.isLogin = false;
          app.globalData.token = null;
          wx.reLaunch({
            url: '/pages/login/login'
          });
          reject({ code: 401, message: '登录已过期' });
        } else if (statusCode === 500) {
          wx.showToast({
            title: '服务器繁忙，请稍后重试',
            icon: 'none',
            duration: 2000
          });
          reject({ code: 500, message: '服务器错误' });
        } else {
          wx.showToast({
            title: `请求失败(${statusCode})`,
            icon: 'none',
            duration: 2000
          });
          reject({ code: statusCode, message: '请求失败' });
        }
      },
      fail(err) {
        wx.showToast({
          title: '网络异常，请检查网络',
          icon: 'none',
          duration: 2000
        });
        reject(err);
      }
    });
  });
};

const get = (url, data = {}, options = {}) => {
  return request({ url, data, method: 'GET', ...options });
};

const post = (url, data = {}, options = {}) => {
  return request({ url, data, method: 'POST', ...options });
};

const put = (url, data = {}, options = {}) => {
  return request({ url, data, method: 'PUT', ...options });
};

const del = (url, data = {}, options = {}) => {
  return request({ url, data, method: 'DELETE', ...options });
};

module.exports = {
  request,
  get,
  post,
  put,
  del,
  baseURL
};