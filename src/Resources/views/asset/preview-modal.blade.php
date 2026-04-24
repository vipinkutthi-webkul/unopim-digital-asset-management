@php
    $mediaUrl = route('admin.dam.file.preview', ['path' => urlencode($asset->path)]);

    $placeholderSvg = match($asset->file_type) {
        'video'    => asset('storage/dam/preview/video.svg'),
        'audio'    => asset('storage/dam/preview/audio.svg'),
        'document' => asset('storage/dam/preview/file.svg'),
        default    => asset('storage/dam/preview/unspecified.svg'),
    };

    $typeColor = 'bg-violet-100 text-violet-700 dark:bg-violet-900 dark:text-violet-300';

    $bytes    = (int) ($asset->file_size ?? 0);
    $fileSize = $bytes >= 1048576
        ? number_format($bytes / 1048576, 2) . ' MB'
        : ($bytes >= 1024 ? number_format($bytes / 1024, 1) . ' KB' : ($bytes > 0 ? $bytes . ' B' : null));
@endphp

<script
    type="text/x-template"
    id="v-asset-preview-modal-template"
>
    <div class="flex flex-col items-center gap-2 w-full">
        @include('dam::asset.preview-modal.card')
        @include('dam::asset.preview-modal.info-modal')
        @include('dam::asset.preview-modal.viewer-modal')
        @include('dam::asset.preview-modal.editor-modal')
    </div>
</script>

<script type="module">
    app.component('v-asset-preview-modal', {
        template: '#v-asset-preview-modal-template',

        data() {
            return {
                isOpen:     false,
                infoHover:  false,
                isInfoOpen: false,
                isEditOpen:  false,
                editTool:    null,
                editPrompt:  '',

                // Image viewer
                imgZoom:       1,
                imgRotation:   0,
                imgPanX:       0,
                imgPanY:       0,
                imgIsDragging: false,
                imgDragStartX: 0,
                imgDragStartY: 0,
                imgPanStartX:  0,
                imgPanStartY:  0,

                // Video
                videoSpeed: 1,

                // Audio
                audioIsPlaying:   false,
                audioCurrentTime: 0,
                audioDuration:    0,
                audioVolume:      1,
                audioEnded:       false,
                audioIsSeeking:   false,
            };
        },

        computed: {
            imgTransformStyle() {
                return `translate(${this.imgPanX}px,${this.imgPanY}px) scale(${this.imgZoom}) rotate(${this.imgRotation}deg)`;
            },
            imgZoomPercent() {
                return Math.round(this.imgZoom * 100);
            },
            audioCurrentTimeDisplay() {
                return this._formatTime(this.audioCurrentTime);
            },
            audioDurationDisplay() {
                return this._formatTime(this.audioDuration);
            },
        },

        methods: {
            // ── Open / close ──────────────────────────────────────────
            openPreview() {
                this.imgZoom = 1; this.imgRotation = 0;
                this.imgPanX = 0; this.imgPanY = 0; this.imgIsDragging = false;
                this.videoSpeed = 1;
                this.audioIsPlaying = false; this.audioCurrentTime = 0;
                this.audioDuration = 0; this.audioVolume = 1; this.audioEnded = false;
                this.isOpen = true;
                document.body.style.overflow = 'hidden';
                this.$nextTick(() => {
                    if (this.$refs.videoEl) this.$refs.videoEl.playbackRate = 1;
                    if (this.$refs.audioEl) {
                        this.$refs.audioEl.pause();
                        this.$refs.audioEl.currentTime = 0;
                        this.$refs.audioEl.volume = 1;
                    }
                    if (this.$refs.seekBar) this.$refs.seekBar.value = 0;
                });
            },

            closePreview() {
                if (this.$refs.audioEl) this.$refs.audioEl.pause();
                this.audioIsPlaying = false;
                this.isOpen = false;
                document.body.style.overflow = '';
            },

            handleEscape(e) {
                if (e.key === 'Escape' && this.isInfoOpen) { this.isInfoOpen = false; return; }
                if (e.key === 'Escape' && this.isEditOpen) { this.isEditOpen = false; this.editTool = null; this.editPrompt = ''; return; }
                if (!this.isOpen) return;
                switch (e.key) {
                    case 'Escape': this.closePreview(); break;
                    case '+': case '=': e.preventDefault(); this.imgZoomIn(); break;
                    case '-':           e.preventDefault(); this.imgZoomOut(); break;
                    case 'r': case 'R': this.imgRotateRight(); break;
                    case 'l': case 'L': this.imgRotateLeft(); break;
                    case '0':           this.imgReset(); break;
                }
            },

            // ── Image viewer ──────────────────────────────────────────
            imgZoomIn()      { this.imgZoom = Math.min(10,  parseFloat((this.imgZoom + 0.25).toFixed(2))); },
            imgZoomOut()     { this.imgZoom = Math.max(0.1, parseFloat((this.imgZoom - 0.25).toFixed(2))); },
            imgRotateRight() { this.imgRotation = (this.imgRotation + 90) % 360; },
            imgRotateLeft()  { this.imgRotation = (this.imgRotation - 90 + 360) % 360; },
            imgFitToScreen() { this.imgZoom = 1; this.imgPanX = 0; this.imgPanY = 0; },
            imgActualSize()  { this.imgZoom = 1; this.imgPanX = 0; this.imgPanY = 0; },
            imgReset()       { this.imgZoom = 1; this.imgRotation = 0; this.imgPanX = 0; this.imgPanY = 0; },

            imgOnWheel(e) {
                const factor = e.deltaY < 0 ? 1.1 : 0.9;
                this.imgZoom = Math.min(10, Math.max(0.1, parseFloat((this.imgZoom * factor).toFixed(3))));
            },

            imgOnMouseDown(e) {
                if (e.button !== 0) return;
                this.imgIsDragging = true;
                this.imgDragStartX = e.clientX; this.imgDragStartY = e.clientY;
                this.imgPanStartX  = this.imgPanX; this.imgPanStartY = this.imgPanY;
                e.preventDefault();
            },

            imgOnMouseMove(e) {
                if (!this.imgIsDragging) return;
                this.imgPanX = this.imgPanStartX + (e.clientX - this.imgDragStartX);
                this.imgPanY = this.imgPanStartY + (e.clientY - this.imgDragStartY);
            },

            imgOnMouseUp() { this.imgIsDragging = false; },

            // ── Video ─────────────────────────────────────────────────
            setVideoSpeed(rate) {
                this.videoSpeed = rate;
                if (this.$refs.videoEl) this.$refs.videoEl.playbackRate = rate;
            },

            videoSkip(sec) {
                const el = this.$refs.videoEl;
                if (!el) return;
                el.currentTime = Math.max(0, el.currentTime + sec);
            },

            // ── Audio ─────────────────────────────────────────────────
            audioTogglePlay() {
                const el = this.$refs.audioEl;
                if (!el) return;
                if (this.audioEnded) {
                    el.currentTime = 0;
                    this.audioCurrentTime = 0;
                    this.audioEnded = false;
                }
                if (el.paused) { el.play(); this.audioIsPlaying = true; }
                else           { el.pause(); this.audioIsPlaying = false; }
            },

            audioSeekStart() {
                this.audioIsSeeking = true;
            },

            audioOnSeek(e) {
                const t = parseFloat(e.target.value);
                this.audioCurrentTime = t;
                if (this.$refs.audioEl) this.$refs.audioEl.currentTime = t;
            },

            audioSeekEnd() {
                this.audioIsSeeking = false;
            },

            audioOnVolume(e) {
                const v = parseFloat(e.target.value);
                this.audioVolume = v;
                this.$refs.audioEl.volume = v;
            },

            audioOnTimeUpdate() {
                if (!this.$refs.audioEl) return;
                this.audioCurrentTime = this.$refs.audioEl.currentTime;
                if (!this.audioIsSeeking && this.$refs.seekBar) {
                    this.$refs.seekBar.value = this.$refs.audioEl.currentTime;
                }
            },

            audioOnLoadedMeta() {
                if (this.$refs.audioEl) this.audioDuration = this.$refs.audioEl.duration;
            },

            audioOnEnded() {
                this.audioIsPlaying = false;
                this.audioEnded = true;
            },

            audioSkip(sec) {
                const el = this.$refs.audioEl;
                if (!el) return;
                el.currentTime = Math.max(0, el.currentTime + sec);
                this.audioCurrentTime = el.currentTime;
                if (this.$refs.seekBar) this.$refs.seekBar.value = el.currentTime;
            },

            _formatTime(s) {
                if (!s || isNaN(s)) return '0:00';
                const m = Math.floor(s / 60);
                return `${m}:${Math.floor(s % 60).toString().padStart(2, '0')}`;
            },
        },

        mounted() {
            window.addEventListener('keydown',   this.handleEscape);
            window.addEventListener('mousemove', this.imgOnMouseMove);
            window.addEventListener('mouseup',   this.imgOnMouseUp);
        },

        beforeUnmount() {
            window.removeEventListener('keydown',   this.handleEscape);
            window.removeEventListener('mousemove', this.imgOnMouseMove);
            window.removeEventListener('mouseup',   this.imgOnMouseUp);
            document.body.style.overflow = '';
        },
    });
</script>
