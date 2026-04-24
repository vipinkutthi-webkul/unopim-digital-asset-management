@if ($asset->file_type === 'image')
<!-- Image Editor Modal -->
<div
    v-if="isEditOpen"
    class="fixed inset-0 z-[10010] flex items-center justify-center"
>
    <div class="absolute inset-0 bg-black/75" @click="isEditOpen = false; editTool = null; editPrompt = ''"></div>

    <div class="relative z-10 flex flex-col w-[90vw] h-[90vh] max-w-6xl rounded-xl overflow-hidden bg-white dark:bg-gray-900 shadow-2xl ring-1 ring-black/10">

        <!-- Header -->
        <div class="flex items-center gap-3 px-5 py-3 shrink-0 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
            <span class="text-lg icon-edit text-violet-600 dark:text-violet-400"></span>
            <p class="flex-1 text-sm font-semibold text-gray-800 dark:text-white truncate">
                {{ $asset->file_name }}
            </p>
            <button
                type="button"
                class="shrink-0 flex items-center justify-center w-8 h-8 rounded-full text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 hover:text-gray-900 dark:hover:text-white transition-colors"
                @click="isEditOpen = false; editTool = null; editPrompt = ''"
                aria-label="Close editor"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <!-- Body -->
        <div class="flex flex-1 min-h-0 overflow-hidden">

            <!-- Image preview panel -->
            <div class="flex items-center justify-center flex-1 min-w-0 bg-gray-50 dark:bg-gray-950 border-r border-gray-200 dark:border-gray-700">
                <img
                    src="{{ $asset->previewPath }}"
                    alt="{{ $asset->file_name }}"
                    class="max-h-full max-w-full object-contain p-6"
                />
            </div>

            <!-- Tools panel -->
            <div class="flex flex-col w-72 shrink-0 overflow-y-auto bg-white dark:bg-gray-900">
                <div class="px-4 pt-4 pb-2">
                    <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Tools</p>
                </div>
                <div class="flex flex-col gap-1 px-3 pb-4">

                    <!-- Background Remover -->
                    <button
                        type="button"
                        class="flex items-center gap-3 w-full px-3 py-3 rounded-lg text-left transition-colors"
                        :class="editTool === 'bg-remove' ? 'bg-violet-50 dark:bg-violet-900 text-violet-700 dark:text-violet-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'"
                        @click="editTool = 'bg-remove'"
                    >
                        <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-violet-100 dark:bg-violet-900 text-violet-600 dark:text-violet-300 shrink-0">
                            <span class="icon-magic-ai text-base"></span>
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium leading-tight">Background Remover</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 leading-tight mt-0.5">AI-powered</p>
                        </div>
                        <span class="shrink-0 text-[10px] font-bold px-1.5 py-0.5 rounded bg-violet-100 dark:bg-violet-900 text-violet-600 dark:text-violet-300">AI</span>
                    </button>

                    <!-- Crop & Resize -->
                    <button
                        type="button"
                        class="flex items-center gap-3 w-full px-3 py-3 rounded-lg text-left transition-colors"
                        :class="editTool === 'crop' ? 'bg-violet-50 dark:bg-violet-900 text-violet-700 dark:text-violet-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'"
                        @click="editTool = 'crop'"
                    >
                        <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300 shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 2 6 18 22 18"/><polyline points="2 6 18 6 18 22"/></svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium leading-tight">Crop & Resize</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 leading-tight mt-0.5">Trim or scale image</p>
                        </div>
                    </button>

                    <!-- Adjust -->
                    <button
                        type="button"
                        class="flex items-center gap-3 w-full px-3 py-3 rounded-lg text-left transition-colors"
                        :class="editTool === 'adjust' ? 'bg-violet-50 dark:bg-violet-900 text-violet-700 dark:text-violet-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'"
                        @click="editTool = 'adjust'"
                    >
                        <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900 text-amber-600 dark:text-amber-300 shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium leading-tight">Brightness & Contrast</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 leading-tight mt-0.5">Adjust light and tone</p>
                        </div>
                    </button>

                    <!-- Rotate & Flip -->
                    <button
                        type="button"
                        class="flex items-center gap-3 w-full px-3 py-3 rounded-lg text-left transition-colors"
                        :class="editTool === 'rotate' ? 'bg-violet-50 dark:bg-violet-900 text-violet-700 dark:text-violet-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'"
                        @click="editTool = 'rotate'"
                    >
                        <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900 text-emerald-600 dark:text-emerald-300 shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.49-3.5"/></svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium leading-tight">Rotate & Flip</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 leading-tight mt-0.5">Transform orientation</p>
                        </div>
                    </button>

                </div>

                <!-- AI Prompt (bg-remove only) -->
                <div v-if="editTool === 'bg-remove'" class="px-4 pb-3">
                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Prompt</label>
                    <textarea
                        v-model="editPrompt"
                        rows="3"
                        placeholder="Describe what to remove or how to process the image…"
                        class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 px-3 py-2 resize-none focus:outline-none focus:ring-2 focus:ring-violet-500 focus:border-transparent"
                    ></textarea>
                </div>

                <!-- Apply button -->
                <div class="mt-auto px-4 py-4 border-t border-gray-100 dark:border-gray-700">
                    <button
                        type="button"
                        class="w-full primary-button justify-center"
                        :disabled="!editTool"
                        :class="!editTool ? 'opacity-40 cursor-not-allowed' : ''"
                    >
                        <span v-if="editTool === 'bg-remove'" class="icon-magic-ai text-base"></span>
                        <span>Apply</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endif
