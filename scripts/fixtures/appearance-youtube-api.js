// QA transport adapter only. Every clock, decode count and playback event comes
// from a real HTMLVideoElement. No application/player implementation is replaced.
window.__players = [];
window.__videoEvents = [];
window.__qaTransport = 'youtube-api-boundary-with-real-local-webm';
window.YT = { Player: class {
    constructor(mount, options) {
        this.options = options;
        this.iframe = document.createElement('iframe');
        this.iframe.allow = 'autoplay';
        this.iframe.srcdoc = '<!doctype html><html><body style="margin:0;overflow:hidden"><video playsinline preload="auto" src="https://jakawi.club/__appearance-media.webm" style="display:block;width:100vw;height:100vh;object-fit:cover"></video></body></html>';
        this.iframe.onload = () => {
            this.media = this.iframe.contentDocument.querySelector('video');
            for (const [event, data] of [['playing',1],['pause',2],['ended',0],['waiting',3]]) {
                this.media.addEventListener(event, () => this.emit('onStateChange', data));
            }
            this.media.addEventListener('error', () => this.emit('onError', this.media.error?.code));
            if (this.media.readyState >= 1) this.emit('onReady');
            else this.media.addEventListener('loadedmetadata', () => this.emit('onReady'), { once: true });
        };
        mount.replaceWith(this.iframe);
        window.__players.push(this);
    }
    emit(name, data) {
        window.__videoEvents.push({ name, data, nativeTime: this.media?.currentTime });
        this.options.events[name]?.({ target: this, data });
    }
    playVideo() { this.media.play().catch(() => this.emit('onAutoplayBlocked')); }
    pauseVideo() { this.media.pause(); }
    seekTo(time) { this.media.currentTime = time; }
    mute() { this.media.muted = true; }
    unMute() { this.media.muted = false; }
    isMuted() { return this.media.muted; }
    getVolume() { return this.media.volume * 100; }
    setVolume(volume) { this.media.volume = volume / 100; }
    getCurrentTime() { return this.media?.currentTime ?? 0; }
    getDuration() { return this.media?.duration ?? 0; }
    getPlayerState() { return this.media.ended ? 0 : this.media.paused ? 2 : 1; }
    getVideoData() { return { video_id: this.options.videoId }; }
    destroy() { this.media?.pause(); this.iframe.remove(); }
}};
