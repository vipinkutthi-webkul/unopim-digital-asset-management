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
