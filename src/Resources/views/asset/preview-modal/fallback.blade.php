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
