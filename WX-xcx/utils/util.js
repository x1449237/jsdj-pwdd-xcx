const formatTime = (date, fmt = 'YYYY-MM-DD HH:mm:ss') => {
  if (!date) return '';
  if (typeof date === 'string' || typeof date === 'number') {
    date = new Date(date);
  }

  const o = {
    'Y+': date.getFullYear(),
    'M+': date.getMonth() + 1,
    'D+': date.getDate(),
    'H+': date.getHours(),
    'm+': date.getMinutes(),
    's+': date.getSeconds()
  };

  for (let k in o) {
    const reg = new RegExp('(' + k + ')');
    if (reg.test(fmt)) {
      const str = o[k].toString();
      fmt = fmt.replace(reg, (match) => {
        if (match.length === 1) return str;
        return ('00' + str).slice(-match.length);
      });
    }
  }

  return fmt;
};

const formatRelativeTime = (timestamp) => {
  if (!timestamp) return '';
  const now = Date.now();
  const diff = now - (typeof timestamp === 'number' ? timestamp : new Date(timestamp).getTime());

  const minute = 60 * 1000;
  const hour = 60 * minute;
  const day = 24 * hour;

  if (diff < minute) {
    return '刚刚';
  } else if (diff < hour) {
    return Math.floor(diff / minute) + '分钟前';
  } else if (diff < day) {
    return Math.floor(diff / hour) + '小时前';
  } else if (diff < 7 * day) {
    return Math.floor(diff / day) + '天前';
  } else {
    return formatTime(timestamp, 'MM-DD HH:mm');
  }
};

const formatMoney = (fen) => {
  if (fen === null || fen === undefined) return '0.00';
  const yuan = (fen / 100).toFixed(2);
  return yuan.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
};

const fenToYuan = (fen) => {
  if (fen === null || fen === undefined) return 0;
  return (fen / 100).toFixed(2);
};

const yuanToFen = (yuan) => {
  if (yuan === null || yuan === undefined) return 0;
  return Math.round(parseFloat(yuan) * 100);
};

const maskPhone = (phone) => {
  if (!phone) return '';
  return phone.replace(/^(\d{3})\d{4}(\d{4})$/, '$1****$2');
};

const maskIdCard = (idCard) => {
  if (!idCard) return '';
  if (idCard.length === 18) {
    return idCard.replace(/^(\d{4})\d{10}(\d{4})$/, '$1**********$2');
  }
  return idCard.replace(/^(\d{3})\d{9}(\d{3})$/, '$1*********$2');
};

const maskName = (name) => {
  if (!name) return '';
  if (name.length === 1) return name;
  if (name.length === 2) return name[0] + '*';
  return name[0] + '*'.repeat(name.length - 2) + name[name.length - 1];
};

const debounce = (fn, delay = 300) => {
  let timer = null;
  return function (...args) {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
      fn.apply(this, args);
      timer = null;
    }, delay);
  };
};

const throttle = (fn, delay = 300) => {
  let lastTime = 0;
  return function (...args) {
    const now = Date.now();
    if (now - lastTime >= delay) {
      lastTime = now;
      fn.apply(this, args);
    }
  };
};

const sleep = (ms) => {
  return new Promise(resolve => setTimeout(resolve, ms));
};

const getOrderStatusText = (status) => {
  const statusMap = {
    0: '待接单',
    1: '已接单',
    2: '进行中',
    3: '待验收',
    4: '已完成',
    5: '已取消',
    6: '申诉中'
  };
  return statusMap[status] || '未知';
};

const getOrderStatusColor = (status) => {
  const colorMap = {
    0: '#ff976a',
    1: '#0f3460',
    2: '#e94560',
    3: '#07c160',
    4: '#999999',
    5: '#cccccc',
    6: '#ff976a'
  };
  return colorMap[status] || '#999999';
};

const clamp = (value, min, max) => {
  return Math.min(Math.max(value, min), max);
};

const generateId = () => {
  const timestamp = Date.now().toString(36);
  const random = Math.random().toString(36).substring(2, 10);
  return `${timestamp}${random}`;
};

module.exports = {
  formatTime,
  formatRelativeTime,
  formatMoney,
  fenToYuan,
  yuanToFen,
  maskPhone,
  maskIdCard,
  maskName,
  debounce,
  throttle,
  sleep,
  getOrderStatusText,
  getOrderStatusColor,
  clamp,
  generateId
};