const request = require('../../utils/request');
const util = require('../../utils/util');
const websocket = require('../../utils/websocket');

const recorderManager = wx.getRecorderManager();
const innerAudioContext = wx.createInnerAudioContext();

Page({
  data: {
    conversationId: '',
    targetName: '',
    targetOnline: false,
    myAvatar: '',
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
    currentPlayingId: null
  },

  onLoad(options) {
    const { conversationId, targetName } = options;
    this.setData({
      conversationId: conversationId || '',
      targetName: decodeURIComponent(targetName || '聊天')
    });

    const userInfo = wx.getStorageSync('user_info');
    if (userInfo) {
      this.setData({ myAvatar: userInfo.avatar || '' });
    }

    this.initRecorder();
    this.initAudio();
    this.loadMessages();
    this.initWebSocket();
    this.markAsRead();
  },

  onUnload() {
    this.stopVoice();
    innerAudioContext.destroy();
    websocket.off('chat_message', this.onReceiveMessage);
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
    websocket.on('chat_message', this.onReceiveMessage);
  },

  onReceiveMessage(data) {
    if (data.conversation_id === this.data.conversationId) {
      const msg = this.formatMessage(data);
      this.setData({
        messageList: [...this.data.messageList, msg]
      });
      this.scrollToBottom();
    }
  },

  markAsRead() {
    request.post('/api/v1/chat/read', {
      conversation_id: this.data.conversationId
    }).catch(() => {});
  },

  loadMessages() {
    request.get('/api/v1/chat/messages', {
      conversation_id: this.data.conversationId,
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
      from_self: item.from_self || false,
      playing: false,
      sensitive_blocked: item.sensitive_blocked || false
    };
  },

  scrollToBottom() {
    if (this.data.messageList.length > 0) {
      const lastMsg = this.data.messageList[this.data.messageList.length - 1];
      this.setData({
        scrollToView: 'msg-' + lastMsg.msg_id
      });
    }
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
      sending: true
    };

    this.setData({
      messageList: [...this.data.messageList, tempMsg],
      inputText: ''
    });
    this.scrollToBottom();

    request.post('/api/v1/chat/send', {
      conversation_id: this.data.conversationId,
      type: 'text',
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
      sending: true
    };

    this.setData({
      messageList: [...this.data.messageList, tempMsg]
    });
    this.scrollToBottom();

    wx.uploadFile({
      url: 'https://api.example.com/api/v1/chat/upload',
      filePath: filePath,
      name: 'file',
      header: {
        'Authorization': 'Bearer ' + (wx.getStorageSync('token') || '')
      },
      success: (res) => {
        try {
          const data = JSON.parse(res.data);
          if (data.code === 0) {
            request.post('/api/v1/chat/send', {
              conversation_id: this.data.conversationId,
              type: 'image',
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
      sending: true
    };

    this.setData({
      messageList: [...this.data.messageList, tempMsg]
    });
    this.scrollToBottom();

    wx.uploadFile({
      url: 'https://api.example.com/api/v1/chat/upload',
      filePath: filePath,
      name: 'file',
      header: {
        'Authorization': 'Bearer ' + (wx.getStorageSync('token') || '')
      },
      success: (res) => {
        try {
          const data = JSON.parse(res.data);
          if (data.code === 0) {
            request.post('/api/v1/chat/send', {
              conversation_id: this.data.conversationId,
              type: 'voice',
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

  onImageLoad(e) {
    // 图片加载完成后，自动滚动到底部
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
          request.post('/api/v1/chat/recall', {
            msg_id: msg.msg_id,
            conversation_id: this.data.conversationId
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