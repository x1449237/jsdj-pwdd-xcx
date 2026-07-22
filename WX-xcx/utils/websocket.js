let socketTask = null;
let isConnecting = false;
let reconnectTimer = null;
let heartbeatTimer = null;
let messageQueue = [];
let listeners = {};

const WS_URL = 'wss://ws.example.com/ws';
const HEARTBEAT_INTERVAL = 25000;
const RECONNECT_INTERVAL = 3000;
const MAX_RECONNECT_COUNT = 10;

let reconnectCount = 0;

const connect = () => {
  if (isConnecting || (socketTask && socketTask.readyState === 1)) {
    return;
  }

  isConnecting = true;

  const app = getApp();
  const token = app.globalData.token;

  if (!token) {
    isConnecting = false;
    return;
  }

  socketTask = wx.connectSocket({
    url: `${WS_URL}?token=${token}`,
    success() {
      console.log('WebSocket 连接中...');
    },
    fail(err) {
      console.error('WebSocket 连接失败:', err);
      isConnecting = false;
      scheduleReconnect();
    }
  });

  socketTask.onOpen(() => {
    console.log('WebSocket 连接成功');
    isConnecting = false;
    reconnectCount = 0;
    app.globalData.wsConnected = true;
    startHeartbeat();
    flushMessageQueue();
  });

  socketTask.onMessage((res) => {
    try {
      const data = JSON.parse(res.data);
      handleMessage(data);
    } catch (e) {
      console.error('WebSocket 消息解析失败:', e);
    }
  });

  socketTask.onClose((res) => {
    console.log('WebSocket 连接关闭:', res);
    isConnecting = false;
    app.globalData.wsConnected = false;
    stopHeartbeat();
    scheduleReconnect();
  });

  socketTask.onError((err) => {
    console.error('WebSocket 错误:', err);
    isConnecting = false;
    app.globalData.wsConnected = false;
    stopHeartbeat();
    scheduleReconnect();
  });
};

const scheduleReconnect = () => {
  if (reconnectCount >= MAX_RECONNECT_COUNT) {
    console.log('WebSocket 重连次数已达上限');
    return;
  }

  if (reconnectTimer) {
    clearTimeout(reconnectTimer);
  }

  reconnectCount++;
  console.log(`WebSocket 将在 ${RECONNECT_INTERVAL / 1000} 秒后重连 (第${reconnectCount}次)`);

  reconnectTimer = setTimeout(() => {
    connect();
  }, RECONNECT_INTERVAL);
};

const startHeartbeat = () => {
  stopHeartbeat();
  heartbeatTimer = setInterval(() => {
    send({ type: 'ping' });
  }, HEARTBEAT_INTERVAL);
};

const stopHeartbeat = () => {
  if (heartbeatTimer) {
    clearInterval(heartbeatTimer);
    heartbeatTimer = null;
  }
};

const handleMessage = (data) => {
  const { type } = data;

  if (type === 'pong') return;

  // 消息类型路由
  const typeMap = {
    'chat_message': 'chat_message',
    'group_chat': 'group_chat',
    'group_chat_message': 'group_chat',
    'after_sale': 'after_sale',
    'after_sale_message': 'after_sale',
    'platform_intervene': 'platform_intervene',
    'new_message': 'new_message',
    'message_read': 'message_read'
  };

  const mappedType = typeMap[type] || type;

  if (listeners[mappedType]) {
    listeners[mappedType].forEach(callback => callback(data));
  }

  // 同时触发原始 type 的监听器（兼容旧代码）
  if (mappedType !== type && listeners[type]) {
    listeners[type].forEach(callback => callback(data));
  }

  if (listeners['*']) {
    listeners['*'].forEach(callback => callback(data));
  }
};

const flushMessageQueue = () => {
  while (messageQueue.length > 0) {
    const msg = messageQueue.shift();
    send(msg);
  }
};

const send = (data) => {
  const msg = typeof data === 'string' ? data : JSON.stringify(data);

  if (socketTask && socketTask.readyState === 1) {
    socketTask.send({
      data: msg,
      fail(err) {
        console.error('WebSocket 发送失败:', err);
        messageQueue.push(data);
      }
    });
  } else {
    messageQueue.push(data);
  }
};

const on = (type, callback) => {
  if (!listeners[type]) {
    listeners[type] = [];
  }
  listeners[type].push(callback);
};

const off = (type, callback) => {
  if (!listeners[type]) return;
  if (callback) {
    const index = listeners[type].indexOf(callback);
    if (index > -1) {
      listeners[type].splice(index, 1);
    }
  } else {
    listeners[type] = [];
  }
};

const close = () => {
  stopHeartbeat();
  if (reconnectTimer) {
    clearTimeout(reconnectTimer);
    reconnectTimer = null;
  }
  reconnectCount = MAX_RECONNECT_COUNT;
  if (socketTask) {
    socketTask.close();
    socketTask = null;
  }
  isConnecting = false;
  const app = getApp();
  app.globalData.wsConnected = false;
};

module.exports = {
  connect,
  send,
  on,
  off,
  close
};