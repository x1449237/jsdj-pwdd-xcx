const request = require('../../utils/request');
const util = require('../../utils/util');
const websocket = require('../../utils/websocket');

const recorderManager = wx.getRecorderManager();
const innerAudioContext = wx.createInnerAudioContext();

Page({
  data: {
    sessionId: '',
    orderSn: '',
    myAvatar: '',
    myUserId: '',
    myRole: '', // player 或 cs
    messageList: [],
    inputText: '',
    inputMode: 'text',
    recording: false,
    willCancel: false,
    scrollToView: '',
    showMorePanel: false,
    loadingMore: false,
    hasMoreHistory: true,
    page: 1,
    pageSize: 20,
    voiceStartY: 0,
    currentPlayingId: null,
    // 介入相关
    interveneStatus: 0, // 0未介入, 1介入中, 2已解除
    showInterveneBanner: false, // 客服端红色警示横幅
    interveneBannerText: '', // 横幅文案
    autoIntervene: false // 是否关键词自动介入
  },

  onLoad(options) {
    const { sessionId, orderSn } = options;
    this.setData({
      sessionId: sessionId || '',
      orderSn: decodeURIComponent(orderSn || '')
    });

    const userInfo = wx.getStorageSync('user_info');
    if (userInfo) {
      this.setData({
        myAvatar: userInfo.avatar || '',
        myUserId: userInfo.user_id || '',
        myRole: userInfo.role || 'player'
      });
    }

    this.initRecorder();
    this.initAudio();
    this.loadSessionDetail();
    this.loadMessages();
    this.initWebSocket();
  },

  onUnload() {
    this.stopVoice();
    innerAudioContext.destroy();
    websocket.off('after_sale', this.onReceiveAfterSaleMessage);
    websocket.off('platform_intervene', this.onPlatformIntervene);
  },

  initRecorder() {
    recorderManager.onStart(() => {
      console.log('录音开始');
    });

    recorderManager.onStop((res) => {
      if (this.data.willCancel) {
        this.setData({ recording: false, willCancel: false });
        return;
      }
      this.uploadVoice(res.tempFilePath, res.duration);
      this.setData({ recording: false, willCancel: false });
    });

    recorderManager.onError((err) => {
      console.error('录音错误:', err);
      wx.showToast({ title: '录音失败', icon: 'none' });
      this.setData({ recording: false, willCancel: false });
    });
  },

  initAudio() {
    innerAudioContext.onEnded(() => {
      this.setPlayingStatus(null);
    });

    innerAudioContext.onError((err) => {
      console.error('音频播放错误:', err);
      this.setPlayingStatus(null);
    });
  },

  initWebSocket() {
    websocket.on('after_sale', this.onReceiveAfterSaleMessage);
    websocket.on('platform_intervene', this.onPlatformIntervene);
  },

  onReceiveAfterSaleMessage(data) {
    if (data.session_id === this.data.sessionId) {
      const msg = this.formatMessage(data);
      this.setData({
        messageList: [...this.data.messageList, msg]
      });
      this.scrollToBottom();
    }
  },

  onPlatformIntervene(data) {
    if (data.session_id !== this.data.sessionId) return;

    this.setData({
      interveneStatus: 1
    });

    if (data.trigger_type === 'keyword') {
      // 关键词自动介入
      if (this.data.myRole === 'player') {
        this.addSystemMessage('检测到敏感词汇，为了保证您的合法权益不受侵害，平台方已强制介入');
      } else {
        this.setData({
          showInterveneBanner: true,
          autoIntervene: true,
          interveneBannerText: '系统检测到敏感词汇，为了保证消费者的合法权益不受侵害，平台方已强制介入。请您务必积极响应并配合举证，超时未响应将按规则判责'
        });
      }
    }
  },

  loadSessionDetail() {
    request.get('/api/v1/after_sale/detail', {
      session_id: this.data.sessionId
    }).then((res) => {
      this.setData({
        orderSn: res.order_sn || this.data.orderSn,
        interveneStatus: res.intervene_status || 0
      });

      if (res.intervene_status === 1 && this.data.myRole === 'cs') {
        this.setData({
          showInterveneBanner: true,
          interveneBannerText: '买家已申请平台官方介入，请及时响应并配合举证，超时未处理将按规则判责。'
        });
      }
    }).catch(() => {});
  },

  loadMessages() {
    request.get('/api/v1/after_sale/messages', {
      session_id: this.data.sessionId,
      page: this.data.page,
      page_size: this.data.pageSize
    }).then((res) => {
      const list = (res.list || []).map(item => this.formatMessage(item)).reverse();

      this.setData({
        messageList: this.data.page === 1 ? list : [...list, ...this.data.messageList],
        hasMoreHistory: list.length >= this.data.pageSize,
        page: this.data.page + 1
      });

      if (this.data.page === 1) {
        this.scrollToBottom();
      }
    }).catch(() => {
      this.setData({ loadingMore: false });
    });
  },

  formatMessage(item) {
    return {
      ...item,
      from_self: item.user_id === this.data.myUserId,
      playing: false,
      sensitive_blocked: item.sensitive_blocked || false
    };
  },

  scrollToBottom() {
    if (this.data.messageList.length > 0) {
      const lastMsg = this.data.messageList[this.data.messageList.length - 1];
      this.setData({
        scrollToView: 'msg-' + (lastMsg.msg_id || lastMsg.id)
      });
    }
  },

  addSystemMessage(content) {
    const sysMsg = {
      msg_id: 'sys-' + util.generateId(),
      type: 'system',
      content: content,
      from_self: false
    };
    this.setData({
      messageList: [...this.data.messageList, sysMsg]
    });
    this.scrollToBottom();
  },

  onLoadMore() {
    if (this.data.hasMoreHistory && !this.data.loadingMore) {
      this.setData({ loadingMore: true });
      this.loadMessages();
      setTimeout(() => {
        this.setData({ loadingMore: false });
      }, 1000);
    }
  },

  onBack() {
    wx.navigateBack();
  },

  onMore() {
    this.setData({ showMorePanel: !this.data.showMorePanel });
  },

  onTextInput(e) {
    this.setData({ inputText: e.detail.value });
  },

  onSendText() {
    const text = this.data.inputText.trim();
    if (!text) {
      wx.showToast({ title: '请输入消息内容', icon: 'none' });
      return;
    }

    const tempMsgId = util.generateId();
    const tempMsg = {
      msg_id: tempMsgId,
      type: 'text',
      content: text,
      from_self: true,
      sending: true,
      user_id: this.data.myUserId
    };

    this.setData({
      messageList: [...this.data.messageList, tempMsg],
      inputText: ''
    });
    this.scrollToBottom();

    request.post('/api/v1/after_sale/send_text', {
      session_id: this.data.sessionId,
      content: text
    }).then((res) => {
      this.updateMessageStatus(tempMsgId, res);
    }).catch((err) => {
      if (err.code === 4001) {
        this.setMessageSensitiveBlocked(tempMsgId);
      } else {
        this.removeMessage(tempMsgId);
        wx.showToast({ title: '发送失败', icon: 'none' });
      }
    });
  },

  onSwitchMode() {
    this.setData({
      inputMode: this.data.inputMode === 'text' ? 'voice' : 'text',
      showMorePanel: false
    });
  },

  onChooseImage() {
    this.setData({ showMorePanel: false });
    wx.chooseMedia({
      count: 1,
      mediaType: ['image'],
      sizeType: ['compressed'],
      sourceType: ['album'],
      success: (res) => {
        const tempFilePath = res.tempFiles[0].tempFilePath;
        this.sendImage(tempFilePath);
      }
    });
  },

  onTakePhoto() {
    this.setData({ showMorePanel: false });
    wx.chooseMedia({
      count: 1,
      mediaType: ['image'],
      sizeType: ['compressed'],
      sourceType: ['camera'],
      success: (res) => {
        const tempFilePath = res.tempFiles[0].tempFilePath;
        this.sendImage(tempFilePath);
      }
    });
  },

  sendImage(filePath) {
    const tempMsgId = util.generateId();
    const tempMsg = {
      msg_id: tempMsgId,
      type: 'image',
      image_url: filePath,
      from_self: true,
      sending: true,
      user_id: this.data.myUserId
    };

    this.setData({
      messageList: [...this.data.messageList, tempMsg]
    });
    this.scrollToBottom();

    const token = wx.getStorageSync('token') || '';
    wx.uploadFile({
      url: 'https://api.example.com/api/v1/after_sale/upload',
      filePath: filePath,
      name: 'file',
      header: {
        'Authorization': 'Bearer ' + token
      },
      success: (res) => {
        try {
          const data = JSON.parse(res.data);
          if (data.code === 0) {
            request.post('/api/v1/after_sale/send_image', {
              session_id: this.data.sessionId,
              image_url: data.data.url
            }).then((res) => {
              this.updateMessageStatus(tempMsgId, res);
            }).catch(() => {
              this.removeMessage(tempMsgId);
              wx.showToast({ title: '发送失败', icon: 'none' });
            });
          } else {
            this.removeMessage(tempMsgId);
            wx.showToast({ title: '上传失败', icon: 'none' });
          }
        } catch (e) {
          this.removeMessage(tempMsgId);
        }
      },
      fail: () => {
        this.removeMessage(tempMsgId);
        wx.showToast({ title: '网络异常', icon: 'none' });
      }
    });
  },

  onVoiceStart(e) {
    this.setData({
      recording: true,
      willCancel: false,
      voiceStartY: e.touches[0].clientY
    });

    recorderManager.start({
      duration: 60000,
      sampleRate: 16000,
      numberOfChannels: 1,
      encodeBitRate: 48000,
      format: 'mp3'
    });
  },

  onVoiceMove(e) {
    const moveY = e.touches[0].clientY;
    const diff = this.data.voiceStartY - moveY;
    this.setData({
      willCancel: diff > 50
    });
  },

  onVoiceEnd() {
    recorderManager.stop();
  },

  stopVoice() {
    try {
      recorderManager.stop();
    } catch (e) {}
    this.setData({ recording: false, willCancel: false });
  },

  uploadVoice(filePath, duration) {
    const tempMsgId = util.generateId();
    const tempMsg = {
      msg_id: tempMsgId,
      type: 'voice',
      duration: Math.round(duration / 1000),
      from_self: true,
      sending: true,
      user_id: this.data.myUserId
    };

    this.setData({
      messageList: [...this.data.messageList, tempMsg]
    });
    this.scrollToBottom();

    const token = wx.getStorageSync('token') || '';
    wx.uploadFile({
      url: 'https://api.example.com/api/v1/after_sale/upload',
      filePath: filePath,
      name: 'file',
      header: {
        'Authorization': 'Bearer ' + token
      },
      success: (res) => {
        try {
          const data = JSON.parse(res.data);
          if (data.code === 0) {
            request.post('/api/v1/after_sale/send_voice', {
              session_id: this.data.sessionId,
              voice_url: data.data.url,
              duration: Math.round(duration / 1000)
            }).then((res) => {
              this.updateMessageStatus(tempMsgId, res);
            }).catch(() => {
              this.removeMessage(tempMsgId);
              wx.showToast({ title: '发送失败', icon: 'none' });
            });
          } else {
            this.removeMessage(tempMsgId);
          }
        } catch (e) {
          this.removeMessage(tempMsgId);
        }
      },
      fail: () => {
        this.removeMessage(tempMsgId);
        wx.showToast({ title: '网络异常', icon: 'none' });
      }
    });
  },

  onPlayVoice(e) {
    const msg = e.currentTarget.dataset.msg;
    if (!msg.voice_url) return;

    if (this.data.currentPlayingId === msg.msg_id) {
      innerAudioContext.stop();
      this.setPlayingStatus(null);
      return;
    }

    if (this.data.currentPlayingId) {
      innerAudioContext.stop();
    }

    innerAudioContext.src = msg.voice_url;
    innerAudioContext.play();
    this.setPlayingStatus(msg.msg_id);
  },

  setPlayingStatus(msgId) {
    const list = this.data.messageList.map(item => ({
      ...item,
      playing: item.msg_id === msgId && msgId !== null
    }));
    this.setData({
      messageList: list,
      currentPlayingId: msgId
    });
  },

  onPreviewImage(e) {
    const url = e.currentTarget.dataset.url;
    const urls = this.data.messageList
      .filter(item => item.type === 'image' && item.image_url)
      .map(item => item.image_url);
    wx.previewImage({
      current: url,
      urls: urls
    });
  },

  onLongPressMessage(e) {
    const msg = e.currentTarget.dataset.msg;
    if (!msg.from_self) return;

    const now = Date.now();
    const msgTime = new Date(msg.create_time).getTime();
    if (now - msgTime > 120000) {
      wx.showToast({ title: '超过2分钟无法撤回', icon: 'none' });
      return;
    }

    wx.showActionSheet({
      itemList: ['撤回', '删除'],
      success: (res) => {
        if (res.tapIndex === 0) {
          this.recallMessage(msg);
        } else if (res.tapIndex === 1) {
          this.deleteMessage(msg);
        }
      }
    });
  },

  recallMessage(msg) {
    wx.showModal({
      title: '确认撤回',
      content: '撤回后对方将无法看到此消息',
      success: (res) => {
        if (res.confirm) {
          request.post('/api/v1/after_sale/recall', {
            msg_id: msg.msg_id,
            session_id: this.data.sessionId
          }).then(() => {
            this.setMessageRecalled(msg.msg_id);
          }).catch(() => {
            wx.showToast({ title: '撤回失败', icon: 'none' });
          });
        }
      }
    });
  },

  deleteMessage(msg) {
    wx.showModal({
      title: '确认删除',
      content: '删除后该消息将不再显示',
      success: (res) => {
        if (res.confirm) {
          this.removeMessage(msg.msg_id);
        }
      }
    });
  },

  onRequestIntervene() {
    if (this.data.interveneStatus === 1) {
      wx.showToast({ title: '平台已介入', icon: 'none' });
      return;
    }

    wx.showModal({
      title: '申请平台介入',
      content: '申请后平台方将在48小时内强行介入，确定要申请吗？',
      success: (res) => {
        if (res.confirm) {
          request.post('/api/v1/after_sale/request_intervene', {
            session_id: this.data.sessionId
          }).then(() => {
            this.setData({ interveneStatus: 1 });

            if (this.data.myRole === 'player') {
              this.addSystemMessage('您的申请已提交，平台方将在48小时内强行介入，请耐心等待');
            } else {
              this.setData({
                showInterveneBanner: true,
                interveneBannerText: '买家已申请平台官方介入，请及时响应并配合举证，超时未处理将按规则判责。'
              });
            }

            wx.showToast({ title: '申请已提交', icon: 'success' });
          }).catch(() => {
            wx.showToast({ title: '申请失败', icon: 'none' });
          });
        }
      }
    });
  },

  setMessageRecalled(msgId) {
    const list = this.data.messageList.map(item => {
      if (item.msg_id === msgId) {
        return { ...item, type: 'recall', content: '', image_url: '', voice_url: '' };
      }
      return item;
    });
    this.setData({ messageList: list });
  },

  setMessageSensitiveBlocked(msgId) {
    const list = this.data.messageList.map(item => {
      if (item.msg_id === msgId) {
        return { ...item, sensitive_blocked: true };
      }
      return item;
    });
    this.setData({ messageList: list });
  },

  removeMessage(msgId) {
    const list = this.data.messageList.filter(item => item.msg_id !== msgId);
    this.setData({ messageList: list });
  },

  updateMessageStatus(tempMsgId, serverMsg) {
    const list = this.data.messageList.map(item => {
      if (item.msg_id === tempMsgId) {
        return {
          ...item,
          msg_id: serverMsg.msg_id || tempMsgId,
          sending: false,
          ...serverMsg
        };
      }
      return item;
    });
    this.setData({ messageList: list });
  }
});