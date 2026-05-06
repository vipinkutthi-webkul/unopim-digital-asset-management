<?php

namespace Webkul\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\DAM\Http\Requests\DirectoryPermissionRequest;
use Webkul\DAM\Repositories\DirectoryRepository;
use Webkul\DAM\Repositories\DirectoryRolePermissionRepository;
use Webkul\DAM\Services\DirectoryPermissionService;

class DirectoryPermissionController
{
    public function __construct(
        protected DirectoryRepository $directoryRepository,
        protected DirectoryRolePermissionRepository $rolePermissionRepository,
        protected DirectoryPermissionService $permissionService,
    ) {}

    public function index(): View
    {
        $this->authorizeManage();

        $canUpdate = $this->permissionService->canUpdateAcl();

        // Update-capable admins manage every custom role. View-only admins
        // see ONLY their own role's grants — RBAC privacy: don't leak which
        // dirs other roles have access to.
        $roles = $canUpdate
            ? $this->rolePermissionRepository->listAssignableRoles()
            : $this->ownRoleEntry();

        // Update-capable: full directory tree so any directory can be granted.
        // View-only: only the directories this admin's role can already view
        // (granted + ancestor breadcrumbs) — same scope as the DAM tree.
        $directoryTree = $canUpdate
            ? $this->fullDirectoryTree()
            : $this->directoryRepository->getDirectoryTreeOnly();

        $requestedRoleId = (int) request()->query('role_id', 0);
        $availableIds = array_column($roles, 'id');

        $initialRoleId = in_array($requestedRoleId, $availableIds, true)
            ? $requestedRoleId
            : ($roles[0]['id'] ?? null);

        $initialGrants = $initialRoleId
            ? $this->rolePermissionRepository->getDirectoryIdsForRole($initialRoleId)
            : [];

        return view('dam::permissions.index', [
            'roles'         => $roles,
            'directoryTree' => $directoryTree,
            'initialRoleId' => $initialRoleId,
            'initialGrants' => $initialGrants,
            'canUpdate'     => $canUpdate,
        ]);
    }

    /**
     * Build a single-entry role list for the current admin's own role —
     * used by view-only admins so the dropdown only exposes their role.
     *
     * @return array<int, array{id:int,name:string}>
     */
    protected function ownRoleEntry(): array
    {
        $admin = auth()->guard('admin')->user();

        if (! $admin || ! $admin->role) {
            return [];
        }

        return [[
            'id'   => (int) $admin->role->id,
            'name' => $admin->role->name,
        ]];
    }

    public function show(int $roleId): JsonResponse
    {
        $this->authorizeManage();

        // View-only admins can only fetch grants for their own role —
        // matches the dropdown's filtered list on the index page.
        if (! $this->permissionService->canUpdateAcl()) {
            $admin = auth()->guard('admin')->user();
            $ownRoleId = (int) optional($admin?->role)->id;

            abort_unless($ownRoleId === $roleId, 403, trans('dam::app.admin.permissions.unauthorized'));
        }

        return new JsonResponse([
            'data' => [
                'role_id'        => $roleId,
                'directory_ids'  => $this->rolePermissionRepository->getDirectoryIdsForRole($roleId),
            ],
        ]);
    }

    public function update(DirectoryPermissionRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorizeManage();

        // Strict write gate: view-only admins (with .index but not .update)
        // would otherwise be able to POST directly. Bouncer middleware also
        // gates this route, but defense-in-depth here covers any future
        // changes that might decouple the route → ACL key mapping.
        abort_unless(
            $this->permissionService->canUpdateAcl(),
            403,
            trans('dam::app.admin.permissions.unauthorized')
        );

        $roleId = (int) $request->input('role_id');
        $directoryIds = (array) $request->input('directories', []);

        $this->rolePermissionRepository->syncForRole($roleId, $directoryIds);
        $this->permissionService->flush();

        if ($request->wantsJson()) {
            return new JsonResponse([
                'message'       => trans('dam::app.admin.permissions.saved'),
                'directory_ids' => $this->rolePermissionRepository->getDirectoryIdsForRole($roleId),
            ]);
        }

        session()->flash('success', trans('dam::app.admin.permissions.saved'));

        return redirect()->route('admin.dam.directory_permissions.index', ['role_id' => $roleId]);
    }

    /**
     * Build the directory tree without applying ACL filtering — the manager
     * page must always show the full tree so admins can grant any directory.
     */
    protected function fullDirectoryTree()
    {
        return $this->directoryRepository->getFullDirectoryTreeOnly();
    }

    protected function authorizeManage(): void
    {
        abort_unless(
            $this->permissionService->canManageAcl(),
            403,
            trans('dam::app.admin.permissions.unauthorized')
        );
    }
}
