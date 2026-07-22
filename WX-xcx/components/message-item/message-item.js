const innerAudioContext = wx.createInnerAudioContext();

Component({
  properties: {
    message: {
      type: Object,
      value: {
        id: '',
        type: 'text', // text | voice | image | system
        content: '',
        isSelf: false,
        avatar: '',
        time: '',
        isRead: false,
        voiceDuration: 0,
        imageUrl: '',
        voiceUrl: ''
      }
    },
    extraClass: {
      type: String,
      value: ''
    }
  },

  data: {
    playing: false
  },

  lifetimes: {
    detached() {
      innerAudioContext.stop();
    }
  },

  methods: {
    onPlayVoice() {
      const { message, playing } = this.data;
      if (!message.voiceUrl) {
        wx.showToast({ title: '语音文件不存在', icon: 'none' });
        return;
      }

      if (playing) {
        innerAudioContext.stop();
        this.setData({ playing: false });
        return;
      }

      innerAudioContext.src = message.voiceUrl;
      innerAudioContext.play();

      this.setData({ playing: true });

      innerAudioContext.onEnded(() => {
        this.setData({ playing: false });
      });

      innerAudioContext.onError(() => {
        this.setData({ playing: false });
        wx.showToast({ title: '播放失败', icon: 'none' });
      });
    },

    onPreviewImage() {
      const { message } = this.data;
      if (message.imageUrl) {
        wx.previewImage({
          current: message.imageUrl,
          urls: [message.imageUrl]
        });
      }
    },

    onLongPress() {
      const { message } = this.data;
      if (!message.isSelf) return;

      wx.showActionSheet({
        itemList: ['撤回', '复制', '删除'],
        success: (res) => {
          if (res.tapIndex === 0) {
            this.triggerEvent('recall', { message: message });
          } else if (res.tapIndex === 1) {
            if (message.type === 'text') {
              wx.setClipboardData({
                data: message.content,
                success: () => {
                  wx.showToast({ title: '已复制', icon: 'none' });
                }
              });
            }
          } else if (res.tapIndex === 2) {
            this.triggerEvent('delete', { message: message });
          }
        }
      });
    }
  }
});