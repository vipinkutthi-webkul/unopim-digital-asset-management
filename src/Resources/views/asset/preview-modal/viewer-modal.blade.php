<!-- Fullscreen Preview Modal -->
<div
    v-if="isOpen"
    class="fixed inset-0 z-[10010] flex items-center justify-center"
>
    <!-- Backdrop -->
    <div
        class="absolute inset-0 bg-black/75"
        @click="closePreview"
    ></div>

    <!-- Modal panel -->
    <div class="relative z-10 flex flex-col w-[85vw] h-[88vh] max-w-6xl rounded-xl overflow-hidden bg-white dark:bg-gray-900 shadow-2xl ring-1 ring-black/10">

        <!-- Header -->
        <div class="flex items-center gap-3 px-5 py-3 shrink-0 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
            <span class="shrink-0 px-2 py-0.5 rounded text-xs font-semibold {{ $typeColor }}">
                {{ strtoupper($asset->extension) }}
            </span>
            <p class="flex-1 text-sm font-semibold text-gray-800 dark:text-white truncate">
                {{ $asset->file_name }}
            </p>
            @if ($fileSize)
                <span class="shrink-0 text-xs text-gray-400 dark:text-gray-500 hidden sm:block">{{ $fileSize }}</span>
            @endif
            <button
                type="button"
                class="shrink-0 flex items-center justify-center w-8 h-8 rounded-full text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white transition-colors"
                @click="closePreview"
                aria-label="Close preview"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>

        <!-- Content -->
        <div class="flex-1 min-h-0 overflow-hidden flex items-center justify-center bg-gray-50 dark:bg-gray-900">

            @if ($asset->file_type === 'video')
                <div class="relative w-full h-full">
                    <video ref="videoEl" controls autoplay class="w-full h-full py-4">
                        <source src="{{ $mediaUrl }}" type="{{ $asset->mime_type }}">
                        @lang('dam::app.admin.dam.asset.edit.preview-modal.not-available')
                    </video>
                    <!-- Skip buttons -->
                    <div class="absolute top-3 left-3 flex items-center gap-1 z-10">
                        <button
                            type="button"
                            class="flex items-center gap-1 px-2 py-0.5 rounded text-xs font-semibold bg-black/50 text-white/80 hover:bg-black/70 transition-colors"
                            title="Back 10s"
                            @click="videoSkip(-10)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="19 20 9 12 19 4 19 20"/><line x1="5" y1="19" x2="5" y2="5"/>
                            </svg>
                            10s
                        </button>
                        <button
                            type="button"
                            class="flex items-center gap-1 px-2 py-0.5 rounded text-xs font-semibold bg-black/50 text-white/80 hover:bg-black/70 transition-colors"
                            title="Forward 10s"
                            @click="videoSkip(10)"
                        >
                            10s
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="5 4 15 12 5 20 5 4"/><line x1="19" y1="4" x2="19" y2="20"/>
                            </svg>
                        </button>
                    </div>
                    <!-- Speed selector -->
                    <div class="absolute top-3 right-3 flex items-center gap-1 z-10">
                        <template v-for="rate in [0.5, 0.75, 1, 1.25, 1.5, 2]" :key="rate">
                            <button
                                type="button"
                                class="px-2 py-0.5 rounded text-xs font-semibold transition-colors"
                                :class="videoSpeed === rate ? 'bg-violet-600 text-white' : 'bg-black/50 text-white/80 hover:bg-black/70'"
                                @click="setVideoSpeed(rate)"
                            >@{{ rate }}×</button>
                        </template>
                    </div>
                </div>

            @elseif ($asset->file_type === 'audio')
                <div class="flex flex-col items-center justify-center gap-6 w-full h-full p-8">
                    <img
                        src="{{ $placeholderSvg }}"
                        alt="{{ $asset->file_name }}"
                        class="h-28 w-28 object-contain opacity-50"
                    />
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate max-w-xl text-center">{{ $asset->file_name }}</p>

                    <!-- Hidden native audio element driven by Vue -->
                    <audio
                        ref="audioEl"
                        class="hidden"
                        @timeupdate="audioOnTimeUpdate"
                        @loadedmetadata="audioOnLoadedMeta"
                        @ended="audioOnEnded"
                    >
                        <source src="{{ $mediaUrl }}" type="{{ $asset->mime_type }}">
                    </audio>

                    <div class="flex flex-col gap-3 w-full max-w-lg">
                        <!-- Seek bar -->
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-500 dark:text-gray-400 font-mono tabular-nums w-10 text-right shrink-0">@{{ audioCurrentTimeDisplay }}</span>
                            <input
                                type="range"
                                ref="seekBar"
                                class="flex-1 h-1.5 accent-violet-600 cursor-pointer"
                                min="0"
                                :max="audioDuration || 100"
                                step="0.1"
                                @mousedown="audioSeekStart"
                                @touchstart="audioSeekStart"
                                @input="audioOnSeek"
                                @mouseup="audioSeekEnd"
                                @touchend="audioSeekEnd"
                            />
                            <span class="text-xs text-gray-500 dark:text-gray-400 font-mono tabular-nums w-10 shrink-0">@{{ audioDurationDisplay }}</span>
                        </div>
                        <!-- Controls row -->
                        <div class="flex items-center justify-center gap-4">
                            <!-- Volume -->
                            <div class="flex items-center gap-2 mr-auto">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-500 dark:text-gray-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                                    <path v-if="audioVolume > 0.5" d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/>
                                    <path v-else-if="audioVolume > 0" d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
                                </svg>
                                <input
                                    type="range"
                                    class="w-24 h-1.5 accent-violet-600 cursor-pointer"
                                    min="0"
                                    max="1"
                                    step="0.01"
                                    :value="audioVolume"
                                    @input="audioOnVolume"
                                />
                            </div>
                            <!-- Skip back 10s -->
                            <button
                                type="button"
                                class="flex items-center gap-1 px-2 py-1 rounded-lg text-gray-500 dark:text-gray-400 hover:text-violet-600 dark:hover:text-violet-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors text-xs font-semibold shrink-0"
                                title="Back 10s"
                                @click="audioSkip(-10)"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="19 20 9 12 19 4 19 20"/><line x1="5" y1="19" x2="5" y2="5"/>
                                </svg>
                                <span>10s</span>
                            </button>
                            <!-- Play / Pause -->
                            <button
                                type="button"
                                class="flex items-center justify-center w-12 h-12 rounded-full bg-violet-600 hover:bg-violet-700 text-white shadow-md transition-colors shrink-0"
                                @click="audioTogglePlay"
                            >
                                <svg v-if="audioIsPlaying" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                                    <rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>
                                </svg>
                                <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 translate-x-0.5" viewBox="0 0 24 24" fill="currentColor">
                                    <polygon points="5 3 19 12 5 21 5 3"/>
                                </svg>
                            </button>
                            <!-- Skip forward 10s -->
                            <button
                                type="button"
                                class="flex items-center gap-1 px-2 py-1 rounded-lg text-gray-500 dark:text-gray-400 hover:text-violet-600 dark:hover:text-violet-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors text-xs font-semibold shrink-0"
                                title="Forward 10s"
                                @click="audioSkip(10)"
                            >
                                <span>10s</span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="5 4 15 12 5 20 5 4"/><line x1="19" y1="4" x2="19" y2="20"/>
                                </svg>
                            </button>
                            <div class="ml-auto w-[calc(1rem+6rem+1rem)]"></div>
                        </div>
                    </div>
                </div>

            @elseif ($asset->extension === 'pdf')
                <iframe
                    src="{{ $mediaUrl }}"
                    class="w-full h-full"
                ></iframe>

            @elseif ($asset->file_type === 'image')
                <div
                    class="relative w-full h-full overflow-hidden flex items-center justify-center select-none"
                    @wheel.prevent="imgOnWheel"
                    @mousedown="imgOnMouseDown"
                    :class="imgIsDragging ? 'cursor-grabbing' : (imgZoom > 1 ? 'cursor-grab' : 'cursor-default')"
                >
                    <img
                        src="{{ $asset->previewPath }}"
                        alt="{{ $asset->file_name }}"
                        class="max-w-none max-h-none block pointer-events-none"
                        :style="{
                            transform: imgTransformStyle,
                            transformOrigin: 'center center',
                            transition: imgIsDragging ? 'none' : 'transform 0.15s ease',
                            maxHeight: '100%',
                            maxWidth: '100%',
                        }"
                        draggable="false"
                    />
                    <!-- Toolbar overlay -->
                    <div class="absolute bottom-3 left-1/2 -translate-x-1/2 flex items-center gap-1 px-3 py-1.5 rounded-full bg-black/60 text-white text-xs shadow-lg z-10 select-none">
                        <!-- Rotate left -->
                        <button type="button" class="flex items-center justify-center w-7 h-7 rounded hover:bg-white/20 transition-colors" title="Rotate left (L)" @click="imgRotateLeft">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                        </button>
                        <!-- Rotate right -->
                        <button type="button" class="flex items-center justify-center w-7 h-7 rounded hover:bg-white/20 transition-colors" title="Rotate right (R)" @click="imgRotateRight">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>
                        </button>
                        <span class="w-px h-4 bg-white/30 mx-1"></span>
                        <!-- Zoom out -->
                        <button type="button" class="flex items-center justify-center w-7 h-7 rounded hover:bg-white/20 transition-colors" title="Zoom out (-)" @click="imgZoomOut">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                        </button>
                        <!-- Zoom % -->
                        <span class="min-w-[44px] text-center font-mono tabular-nums">@{{ imgZoomPercent }}%</span>
                        <!-- Zoom in -->
                        <button type="button" class="flex items-center justify-center w-7 h-7 rounded hover:bg-white/20 transition-colors" title="Zoom in (+)" @click="imgZoomIn">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                        </button>
                        <span class="w-px h-4 bg-white/30 mx-1"></span>
                        <!-- Fit to screen -->
                        <button type="button" class="flex items-center justify-center w-7 h-7 rounded hover:bg-white/20 transition-colors" title="Fit to screen" @click="imgFitToScreen">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                        </button>
                        <!-- 1:1 -->
                        <button type="button" class="flex items-center justify-center w-7 h-7 rounded hover:bg-white/20 transition-colors text-[11px] font-bold" title="Actual size" @click="imgActualSize">1:1</button>
                        <!-- Reset -->
                        <button type="button" class="flex items-center justify-center w-7 h-7 rounded hover:bg-white/20 transition-colors" title="Reset all (0)" @click="imgReset">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.5"/></svg>
                        </button>
                    </div>
                </div>

            @else
                <div class="flex flex-col items-center gap-4 text-center p-8">
                    <img
                        src="{{ $placeholderSvg }}"
                        alt="{{ $asset->file_name }}"
                        class="h-20 w-20 object-contain opacity-40"
                    />
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        @lang('dam::app.admin.dam.asset.edit.preview-modal.not-available')
                    </p>
                    <a
                        href="{{ route('admin.dam.assets.download', $asset->id) }}"
                        class="primary-button inline-flex"
                    >
                        @lang('dam::app.admin.dam.asset.edit.preview-modal.download-file')
                    </a>
                </div>
            @endif

        </div>
    </div>
</div>
