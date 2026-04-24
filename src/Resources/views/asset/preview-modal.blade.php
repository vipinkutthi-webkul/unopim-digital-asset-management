@php
    $mediaUrl = route('admin.dam.file.preview', ['path' => urlencode($asset->path)]);

    $placeholderSvg = match($asset->file_type) {
        'video'    => asset('storage/dam/preview/video.svg'),
        'audio'    => asset('storage/dam/preview/audio.svg'),
        'document' => asset('storage/dam/preview/file.svg'),
        default    => asset('storage/dam/preview/unspecified.svg'),
    };

    $typeColor = 'bg-violet-100 text-violet-700 dark:bg-violet-900 dark:text-violet-300';

    $bytes     = (int) ($asset->file_size ?? 0);
    $fileSize  = $bytes >= 1048576
        ? number_format($bytes / 1048576, 2) . ' MB'
        : ($bytes >= 1024 ? number_format($bytes / 1024, 1) . ' KB' : ($bytes > 0 ? $bytes . ' B' : null));
@endphp

<script
    type="text/x-template"
    id="v-asset-preview-modal-template"
>
    <div class="flex flex-col items-center gap-3 w-full min-h-[440px]">

        <!-- Thumbnail / placeholder -->
        <div class="flex items-center justify-center w-full flex-1 min-h-[200px] rounded-lg overflow-hidden bg-gray-50 dark:bg-gray-800 border border-gray-100 dark:border-gray-700">
            @if ($asset->file_type === 'image')
                <img
                    src="{{ $asset->previewPath }}"
                    alt="{{ $asset->file_name }}"
                    class="max-h-full max-w-full object-contain"
                />
            @else
                <img
                    src="{{ $placeholderSvg }}"
                    alt="{{ $asset->file_name }}"
                    class="h-24 w-24 object-contain opacity-60"
                />
            @endif
        </div>

        <!-- File metadata strip -->
        <div class="flex items-center gap-2 text-xs">
            <span class="px-2 py-0.5 rounded font-semibold {{ $typeColor }}">
                {{ strtoupper($asset->extension) }}
            </span>
            @if ($fileSize)
                <span class="text-gray-400 dark:text-gray-500">·</span>
                <span class="text-gray-500 dark:text-gray-400">{{ $fileSize }}</span>
            @endif
            @if ($asset->file_type === 'image' && !empty($asset->width) && !empty($asset->height))
                <span class="text-gray-400 dark:text-gray-500">·</span>
                <span class="text-gray-500 dark:text-gray-400">{{ $asset->width }} × {{ $asset->height }}px</span>
            @endif
        </div>

        <!-- Preview button -->
        <button type="button" class="secondary-button" @click="openPreview">
            <span class="text-xl text-violet-700 icon-dam-preview"></span>
            <span>@lang('dam::app.admin.dam.asset.edit.button.preview')</span>
        </button>

        <!-- Fullscreen Modal Overlay -->
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
                        <video controls autoplay class="w-full h-full py-4">
                            <source src="{{ $mediaUrl }}" type="{{ $asset->mime_type }}">
                            @lang('dam::app.admin.dam.asset.edit.preview-modal.not-available')
                        </video>

                    @elseif ($asset->file_type === 'audio')
                        <div class="flex flex-col items-center justify-center gap-8 w-full h-full p-8">
                            <img
                                src="{{ $placeholderSvg }}"
                                alt="{{ $asset->file_name }}"
                                class="h-32 w-32 object-contain opacity-50"
                            />
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate max-w-xl">{{ $asset->file_name }}</p>
                            <audio controls autoplay class="w-full max-w-2xl">
                                <source src="{{ $mediaUrl }}" type="{{ $asset->mime_type }}">
                                @lang('dam::app.admin.dam.asset.edit.preview-modal.not-available')
                            </audio>
                        </div>

                    @elseif ($asset->extension === 'pdf')
                        <iframe
                            src="{{ $mediaUrl }}"
                            class="w-full h-full"
                        ></iframe>

                    @elseif ($asset->file_type === 'image')
                        <img
                            src="{{ $asset->previewPath }}"
                            alt="{{ $asset->file_name }}"
                            class="max-w-full max-h-full object-contain p-4"
                        />

                    @else
                        <div class="flex flex-col items-center gap-4 text-center">
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
    </div>
</script>

<script type="module">
    app.component('v-asset-preview-modal', {
        template: '#v-asset-preview-modal-template',

        data() {
            return {
                isOpen: false,
            };
        },

        methods: {
            openPreview() {
                this.isOpen = true;
                document.body.style.overflow = 'hidden';
            },

            closePreview() {
                this.isOpen = false;
                document.body.style.overflow = '';
            },

            handleEscape(event) {
                if (event.key === 'Escape' && this.isOpen) {
                    this.closePreview();
                }
            },
        },

        mounted() {
            window.addEventListener('keydown', this.handleEscape);
        },

        beforeUnmount() {
            window.removeEventListener('keydown', this.handleEscape);
            document.body.style.overflow = '';
        },
    });
</script>
