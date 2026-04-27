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
                aria-label="{{ trans('dam::app.admin.dam.asset.edit.image-editor.close') }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <!-- Body -->
        <div class="flex flex-1 min-h-0 overflow-hidden">

            <!-- Image preview panel -->
            <div
                ref="editImgContainer"
                class="relative flex items-center justify-center flex-1 min-w-0 bg-gray-50 dark:bg-gray-950 border-r border-gray-200 dark:border-gray-700 overflow-hidden p-6"
                :class="editTool === 'crop' ? 'cursor-crosshair select-none' : ''"
                @mousedown="editTool === 'crop' && cropStartDraw($event)"
            >
                <img
                    ref="editImg"
                    src="{{ $asset->previewPath }}"
                    alt="{{ $asset->file_name }}"
                    class="block max-h-full max-w-full transition-all duration-200"
                    :style="{
                        filter:    editPreviewFilter    || undefined,
                        transform: editPreviewTransform || undefined,
                    }"
                    draggable="false"
                />

                <!-- Crop overlay -->
                <div
                    v-if="editTool === 'crop' && cropImgW > 0"
                    class="absolute pointer-events-none"
                    :style="{
                        left:   cropImgOffsetX + 'px',
                        top:    cropImgOffsetY + 'px',
                        width:  cropImgW + 'px',
                        height: cropImgH + 'px',
                    }"
                >
                    <!-- crop box with box-shadow dark mask outside -->
                    <div
                        class="absolute border-2 border-white/90 pointer-events-auto"
                        :style="{
                            left:      cropBox.x + 'px',
                            top:       cropBox.y + 'px',
                            width:     cropBox.w + 'px',
                            height:    cropBox.h + 'px',
                            boxShadow: '0 0 0 9999px rgba(0,0,0,0.55)',
                            cursor:    cropHandle === 'move' ? 'grabbing' : 'move',
                        }"
                        @mousedown.stop.prevent="cropMouseDown($event, 'move')"
                    >
                        <!-- Rule-of-thirds grid -->
                        <div class="absolute top-1/3 inset-x-0 h-px bg-white/30 pointer-events-none"></div>
                        <div class="absolute top-2/3 inset-x-0 h-px bg-white/30 pointer-events-none"></div>
                        <div class="absolute left-1/3 inset-y-0 w-px bg-white/30 pointer-events-none"></div>
                        <div class="absolute left-2/3 inset-y-0 w-px bg-white/30 pointer-events-none"></div>

                        <!-- Corner handles -->
                        <div class="absolute -top-1.5 -left-1.5 w-3 h-3 bg-white shadow rounded-sm cursor-nwse-resize" @mousedown.stop.prevent="cropMouseDown($event, 'tl')"></div>
                        <div class="absolute -top-1.5 -right-1.5 w-3 h-3 bg-white shadow rounded-sm cursor-nesw-resize" @mousedown.stop.prevent="cropMouseDown($event, 'tr')"></div>
                        <div class="absolute -bottom-1.5 -left-1.5 w-3 h-3 bg-white shadow rounded-sm cursor-nesw-resize" @mousedown.stop.prevent="cropMouseDown($event, 'bl')"></div>
                        <div class="absolute -bottom-1.5 -right-1.5 w-3 h-3 bg-white shadow rounded-sm cursor-nwse-resize" @mousedown.stop.prevent="cropMouseDown($event, 'br')"></div>

                        <!-- Edge handles -->
                        <div class="absolute -top-1.5 left-1/2 -translate-x-1/2 h-3 w-5 bg-white shadow rounded-sm cursor-ns-resize" @mousedown.stop.prevent="cropMouseDown($event, 't')"></div>
                        <div class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 h-3 w-5 bg-white shadow rounded-sm cursor-ns-resize" @mousedown.stop.prevent="cropMouseDown($event, 'b')"></div>
                        <div class="absolute top-1/2 -left-1.5 -translate-y-1/2 w-3 h-5 bg-white shadow rounded-sm cursor-ew-resize" @mousedown.stop.prevent="cropMouseDown($event, 'l')"></div>
                        <div class="absolute top-1/2 -right-1.5 -translate-y-1/2 w-3 h-5 bg-white shadow rounded-sm cursor-ew-resize" @mousedown.stop.prevent="cropMouseDown($event, 'r')"></div>

                        <!-- Dimension badge -->
                        <div class="absolute -bottom-7 left-1/2 -translate-x-1/2 whitespace-nowrap text-xs text-white bg-black/65 px-2 py-0.5 rounded-full pointer-events-none font-mono">
                            @{{ cropPixelW }} × @{{ cropPixelH }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tools panel -->
            <div class="flex flex-col w-80 shrink-0 overflow-y-auto bg-white dark:bg-gray-900">

                <!-- Tool list -->
                <div class="px-4 pt-4 pb-2">
                    <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">{{ trans('dam::app.admin.dam.asset.edit.image-editor.tools') }}</p>
                </div>
                <div class="flex flex-col gap-1 px-3 pb-3">

                    <!-- Background Remover -->
                    <button
                        type="button"
                        class="flex items-center gap-3 w-full px-3 py-3 rounded-lg text-left transition-colors"
                        :class="editTool === 'bg-remove' ? 'bg-violet-50 dark:bg-violet-900/50 text-violet-700 dark:text-violet-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'"
                        @click="onEditToolSelect('bg-remove')"
                    >
                        <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-violet-100 dark:bg-violet-900 text-violet-600 dark:text-violet-300 shrink-0">
                            <span class="icon-magic-ai text-base"></span>
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium leading-tight">{{ trans('dam::app.admin.dam.asset.edit.image-editor.bg-remove') }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 leading-tight mt-0.5">{{ trans('dam::app.admin.dam.asset.edit.image-editor.bg-remove-sub') }}</p>
                        </div>
                        <span class="shrink-0 text-[10px] font-bold px-1.5 py-0.5 rounded bg-violet-100 dark:bg-violet-900 text-violet-600 dark:text-violet-300">{{ trans('dam::app.admin.dam.asset.edit.image-editor.ai-badge') }}</span>
                    </button>

                    <!-- Crop & Resize -->
                    <button
                        type="button"
                        class="flex items-center gap-3 w-full px-3 py-3 rounded-lg text-left transition-colors"
                        :class="editTool === 'crop' ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'"
                        @click="onEditToolSelect('crop')"
                    >
                        <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300 shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 2 6 18 22 18"/><polyline points="2 6 18 6 18 22"/></svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium leading-tight">{{ trans('dam::app.admin.dam.asset.edit.image-editor.crop') }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 leading-tight mt-0.5">{{ trans('dam::app.admin.dam.asset.edit.image-editor.crop-sub') }}</p>
                        </div>
                    </button>

                    <!-- Adjust -->
                    <button
                        type="button"
                        class="flex items-center gap-3 w-full px-3 py-3 rounded-lg text-left transition-colors"
                        :class="editTool === 'adjust' ? 'bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'"
                        @click="onEditToolSelect('adjust')"
                    >
                        <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900 text-amber-600 dark:text-amber-300 shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium leading-tight">{{ trans('dam::app.admin.dam.asset.edit.image-editor.adjust') }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 leading-tight mt-0.5">{{ trans('dam::app.admin.dam.asset.edit.image-editor.adjust-sub') }}</p>
                        </div>
                    </button>

                    <!-- Rotate & Flip -->
                    <button
                        type="button"
                        class="flex items-center gap-3 w-full px-3 py-3 rounded-lg text-left transition-colors"
                        :class="editTool === 'rotate' ? 'bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'"
                        @click="onEditToolSelect('rotate')"
                    >
                        <span class="flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900 text-emerald-600 dark:text-emerald-300 shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.49-3.5"/></svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium leading-tight">{{ trans('dam::app.admin.dam.asset.edit.image-editor.rotate') }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 leading-tight mt-0.5">{{ trans('dam::app.admin.dam.asset.edit.image-editor.rotate-sub') }}</p>
                        </div>
                    </button>

                </div>

                <!-- Divider -->
                <div v-if="editTool" class="mx-4 border-t border-gray-100 dark:border-gray-700"></div>

                <!-- ── Crop & Resize controls ── -->
                <div v-if="editTool === 'crop'" class="px-4 py-4 flex flex-col gap-3">
                    <!-- Live selection readout -->
                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ trans('dam::app.admin.dam.asset.edit.image-editor.selection') }}</p>
                    <div class="flex items-center gap-2 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-3 py-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-blue-500 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 2 6 18 22 18"/><polyline points="2 6 18 6 18 22"/></svg>
                        <span class="text-sm font-mono text-gray-700 dark:text-gray-200">@{{ cropPixelW }} × @{{ cropPixelH }} px</span>
                        <span class="ml-auto text-xs text-gray-400 dark:text-gray-500">{{ trans('dam::app.admin.dam.asset.edit.image-editor.drag-handles') }}</span>
                    </div>

                    <!-- Optional scale-after-crop -->
                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mt-1">
                        {{ trans('dam::app.admin.dam.asset.edit.image-editor.scale-after-crop') }}
                        <span class="font-normal normal-case text-gray-400 dark:text-gray-600">({{ trans('dam::app.admin.dam.asset.edit.image-editor.optional') }})</span>
                    </p>
                    <div class="flex gap-3">
                        <div class="flex-1">
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ trans('dam::app.admin.dam.asset.edit.image-editor.width-px') }}</label>
                            <input
                                type="number"
                                v-model.number="cropWidth"
                                min="1" max="10000"
                                placeholder="auto"
                                class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm text-gray-700 dark:text-gray-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        </div>
                        <div class="flex-1">
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ trans('dam::app.admin.dam.asset.edit.image-editor.height-px') }}</label>
                            <input
                                type="number"
                                v-model.number="cropHeight"
                                min="1" max="10000"
                                placeholder="auto"
                                class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm text-gray-700 dark:text-gray-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        </div>
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-500">{{ trans('dam::app.admin.dam.asset.edit.image-editor.blank-keep-dims') }}</p>
                </div>

                <!-- ── Brightness & Contrast controls ── -->
                <div v-if="editTool === 'adjust'" class="px-4 py-4 flex flex-col gap-4">
                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ trans('dam::app.admin.dam.asset.edit.image-editor.adjustments') }}</p>

                    <div>
                        <div class="flex justify-between mb-1">
                            <label class="text-xs text-gray-500 dark:text-gray-400">{{ trans('dam::app.admin.dam.asset.edit.image-editor.brightness') }}</label>
                            <span class="text-xs font-mono text-gray-700 dark:text-gray-300">@{{ brightness > 0 ? '+' : '' }}@{{ brightness }}</span>
                        </div>
                        <input
                            type="range"
                            v-model.number="brightness"
                            min="-100" max="100" step="1"
                            class="w-full h-1.5 accent-amber-500 cursor-pointer"
                        />
                        <div class="flex justify-between mt-0.5 text-[10px] text-gray-400 dark:text-gray-600">
                            <span>-100</span><span>0</span><span>+100</span>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between mb-1">
                            <label class="text-xs text-gray-500 dark:text-gray-400">{{ trans('dam::app.admin.dam.asset.edit.image-editor.contrast') }}</label>
                            <span class="text-xs font-mono text-gray-700 dark:text-gray-300">@{{ contrast > 0 ? '+' : '' }}@{{ contrast }}</span>
                        </div>
                        <input
                            type="range"
                            v-model.number="contrast"
                            min="-100" max="100" step="1"
                            class="w-full h-1.5 accent-amber-500 cursor-pointer"
                        />
                        <div class="flex justify-between mt-0.5 text-[10px] text-gray-400 dark:text-gray-600">
                            <span>-100</span><span>0</span><span>+100</span>
                        </div>
                    </div>
                </div>

                <!-- ── Rotate & Flip controls ── -->
                <div v-if="editTool === 'rotate'" class="px-4 py-4 flex flex-col gap-4">
                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ trans('dam::app.admin.dam.asset.edit.image-editor.rotation') }}</p>
                    <div class="grid grid-cols-4 gap-1.5">
                        <template v-for="deg in [0, 90, 180, 270]" :key="deg">
                            <button
                                type="button"
                                class="flex flex-col items-center justify-center py-2 px-1 rounded-lg border text-xs font-semibold transition-colors"
                                :class="rotation === deg
                                    ? 'bg-emerald-500 text-white border-emerald-500'
                                    : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'"
                                @click="rotation = deg"
                            >
                                @{{ deg }}°
                            </button>
                        </template>
                    </div>

                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ trans('dam::app.admin.dam.asset.edit.image-editor.flip') }}</p>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-lg border text-xs font-semibold transition-colors"
                            :class="flipH
                                ? 'bg-emerald-500 text-white border-emerald-500'
                                : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'"
                            @click="flipH = !flipH"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18M5 8l7-5 7 5"/></svg>
                            {{ trans('dam::app.admin.dam.asset.edit.image-editor.horizontal') }}
                        </button>
                        <button
                            type="button"
                            class="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-lg border text-xs font-semibold transition-colors"
                            :class="flipV
                                ? 'bg-emerald-500 text-white border-emerald-500'
                                : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'"
                            @click="flipV = !flipV"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h18M8 5l-5 7 5 7"/></svg>
                            {{ trans('dam::app.admin.dam.asset.edit.image-editor.vertical') }}
                        </button>
                    </div>
                </div>

                <!-- ── Background Remover controls ── -->
                <div v-if="editTool === 'bg-remove'" class="px-4 py-4 flex flex-col gap-4">
                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ trans('dam::app.admin.dam.asset.edit.image-editor.ai-settings') }}</p>

                    <!-- Platform select -->
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ trans('dam::app.admin.dam.asset.edit.image-editor.platform') }}</label>
                        <select
                            v-model="editSelectedPlatformId"
                            @change="onEditPlatformChange"
                            class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm text-gray-700 dark:text-gray-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-violet-500"
                        >
                            <option v-if="!editPlatforms.length" :value="null" disabled>{{ trans('dam::app.admin.dam.asset.edit.image-editor.platform-loading') }}</option>
                            <option v-for="p in editPlatforms" :key="p.id" :value="p.id">
                                @{{ p.label || p.provider }}
                            </option>
                        </select>
                    </div>

                    <!-- Model select -->
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ trans('dam::app.admin.dam.asset.edit.image-editor.model') }}</label>
                        <select
                            v-model="editSelectedModel"
                            class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm text-gray-700 dark:text-gray-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-violet-500"
                        >
                            <option v-if="!editCurrentPlatformModels.length" :value="null" disabled>{{ trans('dam::app.admin.dam.asset.edit.image-editor.no-models') }}</option>
                            <option v-for="m in editCurrentPlatformModels" :key="m" :value="m">@{{ m }}</option>
                        </select>
                    </div>

                    <!-- Prompt -->
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ trans('dam::app.admin.dam.asset.edit.image-editor.prompt') }}</label>
                        <textarea
                            v-model="editPrompt"
                            rows="3"
                            placeholder="{{ trans('dam::app.admin.dam.asset.edit.image-editor.prompt-placeholder') }}"
                            class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 px-3 py-2 resize-none focus:outline-none focus:ring-2 focus:ring-violet-500"
                        ></textarea>
                    </div>
                </div>

                <!-- Error -->
                <div v-if="editError" class="mx-4 mb-2 px-3 py-2 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 text-xs text-red-600 dark:text-red-400">
                    @{{ editError }}
                </div>

                <!-- Apply button -->
                <div class="mt-auto px-4 py-4 border-t border-gray-100 dark:border-gray-700">
                    <button
                        type="button"
                        class="w-full primary-button justify-center"
                        :disabled="!editTool || editApplying"
                        :class="(!editTool || editApplying) ? 'opacity-60 cursor-not-allowed' : ''"
                        @click="applyEdit"
                    >
                        <template v-if="editApplying">
                            <svg class="animate-spin w-4 h-4 mr-1 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                            </svg>
                            <span>{{ trans('dam::app.admin.dam.asset.edit.image-editor.applying') }}</span>
                        </template>
                        <template v-else>
                            <span v-if="editTool === 'bg-remove'" class="icon-magic-ai text-base mr-1"></span>
                            <span>{{ trans('dam::app.admin.dam.asset.edit.image-editor.apply') }}</span>
                        </template>
                    </button>
                </div>

            </div>
        </div>
    </div>
</div>
@endif
