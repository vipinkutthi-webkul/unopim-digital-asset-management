<div ref="videoContainer" class="flex flex-col w-full h-full" @mousemove="videoShowControls" @mouseleave="videoShowControls">

    <!-- Video area — click to play/pause -->
    <div
        class="flex-1 min-h-0 flex items-center justify-center bg-black select-none"
        :class="!videoControlsVisible ? 'cursor-none' : 'cursor-pointer'"
        @click="videoTogglePlay"
    >
        <video
            ref="videoEl"
            autoplay
            class="max-w-full max-h-full"
            @timeupdate="videoOnTimeUpdate"
            @loadedmetadata="videoOnLoadedMeta"
            @ended="videoOnEnded"
            @play="videoIsPlaying = true"
            @pause="videoIsPlaying = false"
        >
            <source src="{{ $mediaUrl }}" type="{{ $asset->mime_type }}">
            @lang('dam::app.admin.dam.asset.edit.preview-modal.not-available')
        </video>
    </div>

    <!-- Custom control bar — auto-hides when playing, shown on mouse move -->
    <div
        class="shrink-0 flex flex-col bg-gray-900 border-t border-white/10 transition-opacity duration-300"
        :class="videoControlsVisible ? 'opacity-100' : 'opacity-0 pointer-events-none'"
        @mouseenter="videoKeepControls"
        @mouseleave="videoShowControls"
    >

        <!-- Seek bar -->
        <div class="px-4 pt-3 pb-1">
            <input
                type="range"
                ref="videoSeekBar"
                class="w-full h-1.5 accent-violet-600 cursor-pointer"
                min="0"
                :max="videoDuration || 100"
                step="0.1"
                @mousedown="videoSeekStart"
                @touchstart="videoSeekStart"
                @input="videoOnSeek"
                @mouseup="videoSeekEnd"
                @touchend="videoSeekEnd"
            />
        </div>

        <!-- Controls row -->
        <div
            class="flex items-center gap-2 px-4 pb-3"
            :class="videoIsFullscreen ? 'text-white' : 'text-gray-500 dark:text-white'"
        >

            <!-- Play / Pause -->
            <button
                type="button"
                class="flex items-center justify-center w-8 h-8 rounded-full bg-violet-600 hover:bg-violet-700 text-white shadow transition-colors shrink-0"
                @click="videoTogglePlay"
            >
                <svg v-if="videoIsPlaying" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>
                </svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 translate-x-0.5" viewBox="0 0 24 24" fill="currentColor">
                    <polygon points="5 3 19 12 5 21 5 3"/>
                </svg>
            </button>

            <!-- Skip back -->
            <button
                type="button"
                class="flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium hover:text-white hover:bg-white/10 border border-gray-500 hover:border-gray-300 transition-colors shrink-0"
                title="Back 10s"
                @click="videoSkip(-10)"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>
                </svg>
                10s
            </button>

            <!-- Skip forward -->
            <button
                type="button"
                class="flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium hover:text-white hover:bg-white/10 border border-gray-500 hover:border-gray-300 transition-colors shrink-0"
                title="Forward 10s"
                @click="videoSkip(10)"
            >
                10s
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12a9 9 0 1 1-9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>
                </svg>
            </button>

            <!-- Time display -->
            <span class="text-xs font-mono tabular-nums shrink-0">
                @{{ videoCurrentTimeDisplay }} / @{{ videoDurationDisplay }}
            </span>

            <div class="flex-1"></div>

            <!-- Speed selector -->
            <div class="flex items-center gap-1 shrink-0">
                <span class="text-xs mr-1 opacity-70">Speed</span>
                <template v-for="rate in [0.5, 0.75, 1, 1.25, 1.5, 2]" :key="rate">
                    <button
                        type="button"
                        class="px-2 py-1 rounded text-xs font-semibold transition-colors"
                        :class="videoSpeed === rate ? 'bg-violet-600 !text-white' : 'hover:text-white hover:bg-white/10'"
                        @click="setVideoSpeed(rate)"
                    >@{{ rate }}×</button>
                </template>
            </div>

            <!-- Volume -->
            <div class="flex items-center gap-1.5 shrink-0">
                <button
                    type="button"
                    class="shrink-0 hover:text-white transition-colors"
                    title="Toggle mute"
                    @click="videoToggleMute"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                        <template v-if="!videoIsMuted">
                            <path v-if="videoVolume > 0.5" d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/>
                            <path v-else-if="videoVolume > 0" d="M15.54 8.46a5 5 0 0 1 0 7.07"/>
                        </template>
                        <template v-else>
                            <line x1="23" y1="9" x2="17" y2="15"/>
                            <line x1="17" y1="9" x2="23" y2="15"/>
                        </template>
                    </svg>
                </button>
                <input
                    type="range"
                    class="w-20 h-1.5 accent-violet-600 cursor-pointer"
                    min="0" max="1" step="0.01"
                    :value="videoIsMuted ? 0 : videoVolume"
                    @input="videoOnVolume"
                />
            </div>

            <!-- Fullscreen -->
            <button
                type="button"
                class="flex items-center justify-center w-7 h-7 rounded hover:text-white hover:bg-white/10 transition-colors shrink-0"
                title="Fullscreen"
                @click="videoToggleFullscreen"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
                </svg>
            </button>

        </div>
    </div>
</div>
