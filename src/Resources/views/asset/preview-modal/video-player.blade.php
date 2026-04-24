<div class="relative w-full h-full">
    <video ref="videoEl" controls autoplay class="w-full h-full py-4">
        <source src="{{ $mediaUrl }}" type="{{ $asset->mime_type }}">
        @lang('dam::app.admin.dam.asset.edit.preview-modal.not-available')
    </video>

    <!-- Playback speed selector -->
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
