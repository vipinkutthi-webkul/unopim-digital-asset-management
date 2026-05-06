<x-admin::layouts>
    <x-slot:title>
        @lang('dam::app.admin.permissions.title')
    </x-slot>

    @if (empty($roles))
        <div>
            <div class="flex justify-between items-center">
                <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                    @lang('dam::app.admin.permissions.title')
                </p>

                <a
                    href="{{ route('admin.dam.index') }}"
                    class="transparent-button"
                >
                    @lang('dam::app.admin.permissions.back-btn')
                </a>
            </div>

            <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">
                @lang('dam::app.admin.permissions.subtitle-view')
            </p>

            <div class="mt-4 p-4 bg-white dark:bg-cherry-900 rounded border border-gray-200 dark:border-cherry-800 box-shadow">
                <p class="text-sm text-gray-700 dark:text-slate-200">
                    @lang('dam::app.admin.permissions.no-roles')
                </p>
            </div>
        </div>
    @else
        @php
            $selectedRole = collect($roles)->firstWhere('id', $initialRoleId);
            $grantedCount = is_array($initialGrants) ? count($initialGrants) : 0;

            $roleOptions = [];
            foreach ($roles as $r) {
                $roleOptions[] = [
                    'id'    => (int) $r['id'],
                    'label' => $r['name'],
                ];
            }
        @endphp

        <x-admin::form :action="route('admin.dam.directory_permissions.update')">
            <input
                type="hidden"
                name="role_id"
                value="{{ $initialRoleId }}"
            />

            <div class="flex justify-between items-center">
                <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                    @lang('dam::app.admin.permissions.title')
                </p>

                <div class="flex gap-x-2.5 items-center">
                    <a
                        href="{{ route('admin.dam.index') }}"
                        class="transparent-button"
                    >
                        @lang('dam::app.admin.permissions.back-btn')
                    </a>

                    @if ($canUpdate)
                        <button
                            type="submit"
                            class="primary-button"
                        >
                            @lang('dam::app.admin.permissions.save')
                        </button>
                    @endif
                </div>
            </div>

            <div class="flex gap-2.5 mt-3.5 max-xl:flex-wrap">
                <div class="flex flex-col gap-2 flex-1 max-xl:flex-auto">
                    <div class="p-4 bg-white dark:bg-cherry-900 rounded border border-gray-200 dark:border-cherry-800 box-shadow">
                        <p class="text-base text-gray-800 dark:text-white font-semibold mb-1">
                            @lang('dam::app.admin.permissions.directories')
                        </p>
                        <p class="text-sm text-gray-600 dark:text-slate-300 mb-4">
                            @lang($canUpdate ? 'dam::app.admin.permissions.subtitle' : 'dam::app.admin.permissions.subtitle-view')
                        </p>

                        @if (! $canUpdate && count($directoryTree) === 0)
                            <p class="text-sm text-gray-700 dark:text-slate-200">
                                @lang('dam::app.admin.permissions.no-grants')
                            </p>
                        @else
                            {{-- View-only: lock the entire tree via pointer-events-none + cursor-not-allowed.
                                 Update mode: tree is fully interactive (chevron toggle, checkboxes). --}}
                            <div @class([
                                'cursor-not-allowed' => ! $canUpdate,
                            ])>
                                <div @class([
                                    'pointer-events-none' => ! $canUpdate,
                                ])>
                                    <x-admin::tree.view
                                        input-type="checkbox"
                                        name-field="directories"
                                        value-field="id"
                                        id-field="id"
                                        label-field="name"
                                        selection-type="individual"
                                        :items="json_encode($directoryTree)"
                                        :value="json_encode($initialGrants)"
                                        :fallback-locale="config('app.fallback_locale')"
                                    />
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex flex-col gap-2 w-[360px] max-w-full max-sm:w-full">
                    <div class="p-4 bg-white dark:bg-cherry-900 rounded border border-gray-200 dark:border-cherry-800 box-shadow">
                        @if ($canUpdate)
                            <p class="text-base text-gray-800 dark:text-white font-semibold mb-3">
                                @lang('dam::app.admin.permissions.role-label')
                            </p>

                            {{-- Block Enter inside the role picker's search box from
                                 submitting the outer save form. --}}
                            <div onkeydown="if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); }">
                                <v-dam-permission-role-picker></v-dam-permission-role-picker>
                            </div>
                        @elseif ($selectedRole)
                            {{-- View-only: single inline "Role: <name>" line, no picker. --}}
                            <p class="text-sm text-gray-800 dark:text-white">
                                <span class="font-semibold">@lang('dam::app.admin.permissions.role-label'):</span>
                                {{ $selectedRole['name'] }}
                            </p>
                        @endif

                        @if ($selectedRole)
                            <div class="mt-4 pt-4 border-t border-gray-200 dark:border-cherry-800 text-sm text-gray-700 dark:text-slate-300 space-y-1">
                                @if ($canUpdate)
                                    <p>
                                        <span class="font-semibold">@lang('dam::app.admin.permissions.role-label'):</span>
                                        {{ $selectedRole['name'] }}
                                    </p>
                                @endif
                                <p>
                                    <span class="font-semibold">@lang('dam::app.admin.permissions.granted-count'):</span>
                                    {{ $grantedCount }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </x-admin::form>

        @push('scripts')
            <script type="module">
                (function () {
                    var COLLAPSED_ATTR = 'data-dam-perm-collapsed';

                    function swapChevron(item) {
                        var chev = item.querySelector(':scope > i.text-xl');
                        if (chev && chev.classList.contains('icon-chevron-right')) {
                            chev.classList.remove('icon-chevron-right');
                            chev.classList.add('icon-chevron-down');
                        }
                    }

                    function ensureActive(item) {
                        // Skip nodes the user explicitly collapsed via chevron.
                        if (item.hasAttribute(COLLAPSED_ATTR)) return;
                        if (item.querySelector('.v-tree-item') && ! item.classList.contains('active')) {
                            item.classList.add('active');
                            swapChevron(item);
                        }
                    }

                    function applyAll() {
                        var all = document.querySelectorAll('.v-tree-item');
                        if (all.length === 0) return false;
                        for (var i = 0; i < all.length; i++) ensureActive(all[i]);
                        return true;
                    }

                    function findTreeRoot() {
                        var any = document.querySelector('.v-tree-item-wrapper');
                        return any ? any.parentElement : null;
                    }

                    function attachChevronTracker() {
                        // Capture-phase handler runs BEFORE Admin's chevron
                        // onclick (which toggles `active` + chevron icon).
                        // We predict the post-toggle state (inverse of the
                        // current `active`) and set the user-collapse flag
                        // SYNCHRONOUSLY so the MutationObserver, which fires
                        // when Admin's handler patches the className, reads
                        // the up-to-date intent and skips re-applying active.
                        document.addEventListener('click', function (e) {
                            var t = e.target;
                            if (! t || t.tagName !== 'I') return;
                            if (! t.classList.contains('text-xl')) return;
                            var parent = t.parentElement;
                            if (! parent || ! parent.classList.contains('v-tree-item')) return;

                            var willBeActive = ! parent.classList.contains('active');
                            if (willBeActive) {
                                parent.removeAttribute(COLLAPSED_ATTR);
                            } else {
                                parent.setAttribute(COLLAPSED_ATTR, '1');
                            }
                        }, true);
                    }

                    function expandFromInput(input) {
                        var item = input.closest('.v-tree-item');
                        if (! item) return;

                        // Clear collapse flag + force-active on ancestors
                        // (path down to the clicked item) so the chain stays
                        // visible above.
                        var node = item;
                        while (node) {
                            node.removeAttribute(COLLAPSED_ATTR);
                            if (node.querySelector('.v-tree-item')) {
                                node.classList.add('active');
                                swapChevron(node);
                            }
                            node = node.parentElement
                                ? node.parentElement.closest('.v-tree-item')
                                : null;
                        }

                        // Also expand the entire subtree BELOW the clicked
                        // item so its children are immediately visible.
                        var descendants = item.querySelectorAll('.v-tree-item');
                        for (var i = 0; i < descendants.length; i++) {
                            descendants[i].removeAttribute(COLLAPSED_ATTR);
                            if (descendants[i].querySelector('.v-tree-item')) {
                                descendants[i].classList.add('active');
                                swapChevron(descendants[i]);
                            }
                        }
                    }

                    function attachCheckboxTracker() {
                        // Ticking ANY directory checkbox auto-expands the
                        // clicked item's full subtree AND its ancestor chain.
                        // Supersedes any prior user-collapse intent so the
                        // change is immediately visible regardless of which
                        // descendants are already selected.
                        document.addEventListener('change', function (e) {
                            var t = e.target;
                            if (! t || ! t.matches) return;
                            if (! t.matches('input[type="checkbox"][name="directories[]"]')) return;
                            if (! t.checked) return;
                            expandFromInput(t);
                        });
                    }

                    function attachObserver() {
                        var root = findTreeRoot();
                        if (! root || typeof MutationObserver === 'undefined') return;

                        var observer = new MutationObserver(function (mutations) {
                            for (var i = 0; i < mutations.length; i++) {
                                var m = mutations[i];
                                if (m.type === 'attributes' && m.attributeName === 'class') {
                                    var el = m.target;
                                    if (el && el.classList && el.classList.contains('v-tree-item')) {
                                        ensureActive(el);
                                    }
                                }
                                if (m.type === 'childList' && m.addedNodes && m.addedNodes.length) {
                                    for (var k = 0; k < m.addedNodes.length; k++) {
                                        var n = m.addedNodes[k];
                                        if (n && n.nodeType === 1) {
                                            if (n.classList && n.classList.contains('v-tree-item')) {
                                                ensureActive(n);
                                            }
                                            var nested = n.querySelectorAll ? n.querySelectorAll('.v-tree-item') : [];
                                            for (var j = 0; j < nested.length; j++) ensureActive(nested[j]);
                                        }
                                    }
                                }
                            }
                        });

                        observer.observe(root, {
                            attributes: true,
                            attributeFilter: ['class'],
                            childList: true,
                            subtree: true,
                        });
                    }

                    var attempts = 0;
                    var maxAttempts = 40;
                    function tick() {
                        if (applyAll() || attempts++ >= maxAttempts) {
                            attachChevronTracker();
                            attachCheckboxTracker();
                            attachObserver();
                            return;
                        }
                        setTimeout(tick, 100);
                    }
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', tick);
                    } else {
                        tick();
                    }
                })();
            </script>

            @if ($canUpdate)
            <script
                type="text/x-template"
                id="v-dam-permission-role-picker-template"
            >
                <div>
                    <x-admin::form
                        v-slot="{ meta, errors, handleSubmit }"
                        as="div"
                    >
                        <x-admin::form.control-group class="!mb-0">
                            <x-admin::form.control-group.control
                                type="select"
                                id="dam_permission_role_id"
                                name="dam_permission_role_id"
                                :label="trans('dam::app.admin.permissions.role-label')"
                                :placeholder="trans('dam::app.admin.permissions.role-placeholder')"
                                v-model="selectedRoleId"
                                :options="json_encode($roleOptions)"
                                track-by="id"
                                label-by="label"
                            />
                        </x-admin::form.control-group>
                    </x-admin::form>
                </div>
            </script>

            <script type="module">
                app.component('v-dam-permission-role-picker', {
                    template: '#v-dam-permission-role-picker-template',

                    data() {
                        return {
                            selectedRoleId: '{{ $initialRoleId }}',
                            initialRoleId: '{{ $initialRoleId }}',
                            redirectBase: "{{ route('admin.dam.directory_permissions.index') }}",
                        };
                    },

                    watch: {
                        selectedRoleId(value) {
                            const parsed = this.parseValue(value);
                            const raw = (parsed && typeof parsed === 'object') ? parsed.id : parsed;
                            // The select control's v-model carries the typed
                            // search string while the user filters options,
                            // then a real id when an option is committed. Only
                            // redirect for actual numeric ids — never for
                            // in-progress search text — otherwise the first
                            // keystroke would navigate the page.
                            const id = parseInt(raw, 10);
                            if (! Number.isInteger(id) || id <= 0) return;
                            if (String(id) === String(this.initialRoleId)) return;

                            window.location.href = this.redirectBase + '?role_id=' + id;
                        },
                    },

                    methods: {
                        parseValue(value) {
                            try {
                                return value ? JSON.parse(value) : null;
                            } catch (e) {
                                return value;
                            }
                        },
                    },
                });
            </script>
            @endif
        @endpush
    @endif
</x-admin::layouts>
