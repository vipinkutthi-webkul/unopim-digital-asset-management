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
    // Keep the plain CSRF token in the X-CSRF-TOKEN header so it's always valid
    // regardless of cookie state. Blade re-renders this on every page load.
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = '{{ csrf_token() }}';

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
                editApplying: false,
                editError:    null,

                // Crop / Resize
                cropWidth:  null,
                cropHeight: null,

                // Crop overlay (drag interaction)
                cropBox:        { x: 0, y: 0, w: 0, h: 0 },
                cropHandle:     null,
                cropStart:      null,
                cropImgW:       0,
                cropImgH:       0,
                cropNatW:       0,
                cropNatH:       0,
                cropImgOffsetX: 0,
                cropImgOffsetY: 0,

                // Brightness & Contrast
                brightness: 0,
                contrast:   0,

                // Rotate & Flip
                rotation: 0,
                flipH:    false,
                flipV:    false,

                // AI — platform / model selection
                editPlatforms:          [],
                editSelectedPlatformId: null,
                editSelectedModel:      null,

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

            cropPixelW() {
                return this.cropImgW ? Math.round(this.cropBox.w * this.cropNatW / this.cropImgW) : 0;
            },
            cropPixelH() {
                return this.cropImgH ? Math.round(this.cropBox.h * this.cropNatH / this.cropImgH) : 0;
            },

            editCurrentPlatformModels() {
                const p = this.editPlatforms.find(p => p.id === this.editSelectedPlatformId);
                return p ? (p.models || []) : [];
            },
            editPreviewFilter() {
                if (this.editTool !== 'adjust') return '';
                const b = (100 + this.brightness) / 100;
                const c = (100 + this.contrast) / 100;
                return `brightness(${b}) contrast(${c})`;
            },
            editPreviewTransform() {
                if (this.editTool !== 'rotate') return '';
                const sx = this.flipH ? -1 : 1;
                const sy = this.flipV ? -1 : 1;
                return `rotate(${this.rotation}deg) scaleX(${sx}) scaleY(${sy})`;
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

            // ── Crop overlay ──────────────────────────────────────────
            initCropBox() {
                this.$nextTick(() => {
                    const img = this.$refs.editImg;
                    if (!img) return;
                    const doInit = () => {
                        this.cropImgW       = img.offsetWidth;
                        this.cropImgH       = img.offsetHeight;
                        this.cropNatW       = img.naturalWidth  || img.offsetWidth;
                        this.cropNatH       = img.naturalHeight || img.offsetHeight;
                        this.cropImgOffsetX = img.offsetLeft;
                        this.cropImgOffsetY = img.offsetTop;
                        const padX          = Math.round(this.cropImgW * 0.1);
                        const padY          = Math.round(this.cropImgH * 0.1);
                        this.cropBox        = { x: padX, y: padY, w: this.cropImgW - 2 * padX, h: this.cropImgH - 2 * padY };
                    };
                    if (img.complete && img.naturalWidth > 0) {
                        doInit();
                    } else {
                        img.addEventListener('load', doInit, { once: true });
                    }
                });
            },

            cropMouseDown(e, handle) {
                this.cropHandle = handle;
                this.cropStart  = { mx: e.clientX, my: e.clientY, box: { ...this.cropBox } };
            },

            cropStartDraw(e) {
                if (!this.cropImgW) return;
                e.preventDefault();
                const img = this.$refs.editImg;
                if (!img) return;
                const rect      = img.getBoundingClientRect();
                const imgStartX = Math.max(0, Math.min(this.cropImgW, e.clientX - rect.left));
                const imgStartY = Math.max(0, Math.min(this.cropImgH, e.clientY - rect.top));
                this.cropBox    = { x: imgStartX, y: imgStartY, w: 0, h: 0 };
                this.cropHandle = 'draw';
                this.cropStart  = {
                    mx: e.clientX, my: e.clientY,
                    box: { x: imgStartX, y: imgStartY, w: 0, h: 0 },
                    imgLeft: rect.left, imgTop: rect.top,
                    imgStartX, imgStartY,
                };
            },

            cropMouseMove(e) {
                if (!this.cropHandle || !this.cropStart) return;

                if (this.cropHandle === 'draw') {
                    const { imgLeft, imgTop, imgStartX: sx, imgStartY: sy } = this.cropStart;
                    const cx = Math.max(0, Math.min(this.cropImgW, e.clientX - imgLeft));
                    const cy = Math.max(0, Math.min(this.cropImgH, e.clientY - imgTop));
                    this.cropBox = {
                        x: Math.min(cx, sx),
                        y: Math.min(cy, sy),
                        w: Math.max(1, Math.abs(cx - sx)),
                        h: Math.max(1, Math.abs(cy - sy)),
                    };
                    return;
                }

                const dx = e.clientX - this.cropStart.mx;
                const dy = e.clientY - this.cropStart.my;
                const { x, y, w, h } = this.cropStart.box;
                const MIN = 20;
                let nx = x, ny = y, nw = w, nh = h;

                if (this.cropHandle === 'move') {
                    nx = Math.max(0, Math.min(this.cropImgW - w, x + dx));
                    ny = Math.max(0, Math.min(this.cropImgH - h, y + dy));
                } else {
                    if (this.cropHandle.includes('l')) { nw = w - dx; nx = x + (w - nw); }
                    if (this.cropHandle.includes('r')) { nw = w + dx; }
                    if (this.cropHandle.includes('t')) { nh = h - dy; ny = y + (h - nh); }
                    if (this.cropHandle.includes('b')) { nh = h + dy; }
                    nw = Math.max(MIN, nw); nh = Math.max(MIN, nh);
                    if (this.cropHandle.includes('l')) nx = x + w - nw;
                    if (this.cropHandle.includes('t')) ny = y + h - nh;
                    if (nx < 0) { nw += nx; nx = 0; }
                    if (ny < 0) { nh += ny; ny = 0; }
                    if (nx + nw > this.cropImgW) nw = this.cropImgW - nx;
                    if (ny + nh > this.cropImgH) nh = this.cropImgH - ny;
                }

                this.cropBox = { x: nx, y: ny, w: Math.max(MIN, nw), h: Math.max(MIN, nh) };
            },

            cropMouseUp() {
                if (this.cropHandle === 'draw') {
                    const MIN = 20;
                    if (this.cropBox.w < MIN || this.cropBox.h < MIN) {
                        this.cropBox = { x: 0, y: 0, w: this.cropImgW, h: this.cropImgH };
                    }
                }
                this.cropHandle = null;
                this.cropStart  = null;
            },

            // ── Image Editor ──────────────────────────────────────────
            onEditToolSelect(tool) {
                this.editTool     = tool;
                this.editError    = null;
                this.editApplying = false;
                // reset per-tool state
                this.cropWidth    = null;
                this.cropHeight   = null;
                this.brightness   = 0;
                this.contrast     = 0;
                this.rotation     = 0;
                this.flipH        = false;
                this.flipV        = false;
                this.editPrompt   = '';
                if (tool === 'crop')      this.initCropBox();
                if (tool === 'bg-remove') this.loadEditPlatforms();
            },

            async loadEditPlatforms() {
                if (this.editPlatforms.length) return;
                try {
                    const { data } = await window.axios.get('{{ route('admin.magic_ai.platforms') }}');
                    this.editPlatforms = data.platforms || [];
                    const def = this.editPlatforms.find(p => p.is_default) || this.editPlatforms[0];
                    if (def) {
                        this.editSelectedPlatformId = def.id;
                        this.editSelectedModel      = (def.models || [])[0] || null;
                    }
                } catch (e) {
                    this.editError = '{{ trans('dam::app.admin.dam.asset.edit.image-editor.error-platforms') }}';
                }
            },

            onEditPlatformChange() {
                this.editSelectedModel = this.editCurrentPlatformModels[0] || null;
            },

            async applyEdit() {
                if (!this.editTool || this.editApplying) return;
                this.editApplying = true;
                this.editError    = null;

                const routeMap = {
                    crop:        '{{ route('admin.dam.assets.image_edit.resize',    ['id' => $asset->id]) }}',
                    adjust:      '{{ route('admin.dam.assets.image_edit.adjust',    ['id' => $asset->id]) }}',
                    rotate:      '{{ route('admin.dam.assets.image_edit.transform', ['id' => $asset->id]) }}',
                    'bg-remove': '{{ route('admin.dam.assets.image_edit.bg_remove', ['id' => $asset->id]) }}',
                };

                const bodyMap = {
                    crop: () => ({
                        crop_x:    Math.round(this.cropBox.x * this.cropNatW / this.cropImgW),
                        crop_y:    Math.round(this.cropBox.y * this.cropNatH / this.cropImgH),
                        crop_w:    this.cropPixelW,
                        crop_h:    this.cropPixelH,
                        img_nat_w: this.cropNatW,
                        img_nat_h: this.cropNatH,
                        width:     this.cropWidth  || null,
                        height:    this.cropHeight || null,
                    }),
                    adjust:      () => ({ brightness: this.brightness, contrast: this.contrast }),
                    rotate:      () => ({ rotation: this.rotation, flip_h: this.flipH, flip_v: this.flipV }),
                    'bg-remove': () => ({
                        prompt:      this.editPrompt,
                        platform_id: this.editSelectedPlatformId,
                        model:       this.editSelectedModel,
                    }),
                };

                try {
                    await window.axios.post(routeMap[this.editTool], bodyMap[this.editTool]());
                    this.isEditOpen = false;
                    this.editTool   = null;
                    // cache-bust so browser fetches the updated image
                    const url = new URL(window.location.href);
                    url.searchParams.set('_img', Date.now());
                    window.location.replace(url.toString());
                } catch (e) {
                    this.editError = e?.response?.data?.message || '{{ trans('dam::app.admin.dam.asset.edit.image-editor.error-operation') }}';
                } finally {
                    this.editApplying = false;
                }
            },
        },

        mounted() {
            window.addEventListener('keydown',   this.handleEscape);
            window.addEventListener('mousemove', this.imgOnMouseMove);
            window.addEventListener('mouseup',   this.imgOnMouseUp);
            window.addEventListener('mousemove', this.cropMouseMove);
            window.addEventListener('mouseup',   this.cropMouseUp);
        },

        beforeUnmount() {
            window.removeEventListener('keydown',   this.handleEscape);
            window.removeEventListener('mousemove', this.imgOnMouseMove);
            window.removeEventListener('mouseup',   this.imgOnMouseUp);
            window.removeEventListener('mousemove', this.cropMouseMove);
            window.removeEventListener('mouseup',   this.cropMouseUp);
            document.body.style.overflow = '';
        },
    });
</script>
