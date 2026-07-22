const recorderManager = wx.getRecorderManager();
const innerAudioContext = wx.createInnerAudioContext();

Component({
  properties: {
    extraClass: {
      type: String,
      value: ''
    },
    maxDuration: {
      type: Number,
      value: 60
    }
  },

  data: {
    recording: false,
    showCancelHint: false,
    duration: 0,
    formatDuration: '00:00',
    cancelThreshold: 80,
    startY: 0,
    timer: null
  },

  lifetimes: {
    attached() {
      this.initRecorder();
    },
    detached() {
      this.clearTimer();
      recorderManager.stop();
    }
  },

  methods: {
    initRecorder() {
      recorderManager.onStart(() => {
        this.setData({ recording: true });
        this.startTimer();
      });

      recorderManager.onStop((res) => {
        this.setData({ recording: false });
        this.clearTimer();

        const { tempFilePath, duration } = res;
        if (!this.data.showCancelHint && tempFilePath) {
          this.triggerEvent('finish', {
            tempFilePath: tempFilePath,
            duration: Math.ceil(duration / 1000)
          });
        }
      });

      recorderManager.onError((err) => {
        this.setData({ recording: false });
        this.clearTimer();
        wx.showToast({ title: '录音失败', icon: 'none' });
        this.triggerEvent('error', { error: err });
      });
    },

    onTouchStart(e) {
      this.setData({
        startY: e.touches[0].clientY,
        showCancelHint: false,
        duration: 0,
        formatDuration: '00:00'
      });

      wx.authorize({
        scope: 'scope.record',
        success: () => {
          recorderManager.start({
            duration: this.properties.maxDuration * 1000,
            sampleRate: 16000,
            numberOfChannels: 1,
            encodeBitRate: 48000,
            format: 'mp3'
          });
        },
        fail: () => {
          wx.showModal({
            title: '提示',
            content: '需要录音权限才能使用语音功能',
            showCancel: false
          });
        }
      });
    },

    onTouchMove(e) {
      if (!this.data.recording) return;

      const moveY = e.touches[0].clientY;
      const diff = this.data.startY - moveY;
      this.setData({
        showCancelHint: diff > this.data.cancelThreshold
      });
    },

    onTouchEnd() {
      recorderManager.stop();
    },

    startTimer() {
      this.clearTimer();
      this.data.timer = setInterval(() => {
        const duration = this.data.duration + 1;
        const minutes = Math.floor(duration / 60);
        const seconds = duration % 60;

        this.setData({
          duration: duration,
          formatDuration: `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
        });

        if (duration >= this.properties.maxDuration) {
          recorderManager.stop();
        }
      }, 1000);
    },

    clearTimer() {
      if (this.data.timer) {
        clearInterval(this.data.timer);
        this.data.timer = null;
      }
    }
  }
});