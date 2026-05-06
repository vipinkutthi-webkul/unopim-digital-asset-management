<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Webkul\DAM\Models\Directory;
use Webkul\DAM\Repositories\DirectoryRolePermissionRepository;
use Webkul\DAM\Services\DirectoryPermissionService;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

function damCreateRoleAndLogin(string $type = 'custom', array $permissions = []): array
{
    $role = Role::factory()->create([
        'permission_type' => $type,
        'permissions'     => $permissions,
    ]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    test()->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    return ['role' => $role, 'admin' => $admin];
}

it('denies the permissions page when admin lacks the manage ACL key', function () {
    damCreateRoleAndLogin('custom', ['dashboard']);

    // Bouncer admin middleware aborts 401 for missing route ACL keys (matches
    // existing DAM/Admin convention).
    $this->get(route('admin.dam.directory_permissions.index'))->assertStatus(401);
});

it('allows the permissions page when admin has the manage ACL key', function () {
    damCreateRoleAndLogin('custom', [
        'dam.directory_permissions',
        'dam.directory_permissions.index',
        'dam.directory_permissions.update',
    ]);

    $this->get(route('admin.dam.directory_permissions.index'))->assertOk();
});

it('allows the permissions page for permission_type=all', function () {
    damCreateRoleAndLogin('all');

    $this->get(route('admin.dam.directory_permissions.index'))->assertOk();
});

it('hides the role picker for view-only admins', function () {
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.directory_permissions', 'dam.directory_permissions.index'],
    ]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $response = $this->get(route('admin.dam.directory_permissions.index'));
    $response->assertOk();
    // The Vue role-picker tag must NOT render for view-only.
    $response->assertDontSee('v-dam-permission-role-picker');
    // Own role name still rendered as static text.
    $response->assertSee($role->name);
});

it('shows only granted directories to view-only admins (no full tree)', function () {
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.directory_permissions', 'dam.directory_permissions.index'],
    ]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $granted = Directory::factory()->create(['name' => 'GrantedView_'.uniqid()]);
    $hidden = Directory::factory()->create(['name' => 'HiddenView_'.uniqid()]);

    DB::table('dam_directory_role')->insert([
        'directory_id' => $granted->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $response = $this->get(route('admin.dam.directory_permissions.index'));
    $response->assertOk();
    $response->assertSee($granted->name);
    $response->assertDontSee($hidden->name);
});

it('shows a friendly empty-state for view-only admins with zero grants', function () {
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.directory_permissions', 'dam.directory_permissions.index'],
    ]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $response = $this->get(route('admin.dam.directory_permissions.index'));
    $response->assertOk();
    $response->assertSeeText(trans('dam::app.admin.permissions.no-grants'));
});

it('restricts view-only admins to their own role in the dropdown', function () {
    // Two custom roles + one admin assigned to role A. Role B has its own
    // grants. The view-only admin must NOT see role B in the dropdown.
    $roleA = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.directory_permissions', 'dam.directory_permissions.index'],
    ]);
    $roleB = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);
    $admin = Admin::factory()->create(['role_id' => $roleA->id]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $dirForB = Directory::factory()->create();
    DB::table('dam_directory_role')->insert([
        'directory_id' => $dirForB->id,
        'role_id'      => $roleB->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $response = $this->get(route('admin.dam.directory_permissions.index'));
    $response->assertOk();
    $response->assertSee($roleA->name);
    $response->assertDontSee($roleB->name);
});

it('blocks view-only admins from fetching other roles via show', function () {
    $roleA = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.directory_permissions', 'dam.directory_permissions.index'],
    ]);
    $roleB = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);
    $admin = Admin::factory()->create(['role_id' => $roleA->id]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    // Own role: 200
    $this->getJson(route('admin.dam.directory_permissions.show', ['roleId' => $roleA->id]))
        ->assertOk();

    // Other role: 403
    $this->getJson(route('admin.dam.directory_permissions.show', ['roleId' => $roleB->id]))
        ->assertStatus(403);
});

it('returns granted directory ids for a role via show endpoint', function () {
    damCreateRoleAndLogin('all');

    $target = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);
    $dir = Directory::factory()->create();
    DB::table('dam_directory_role')->insert([
        'directory_id' => $dir->id,
        'role_id'      => $target->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $response = $this->getJson(route('admin.dam.directory_permissions.show', ['roleId' => $target->id]));
    $response->assertOk();
    expect($response->json('data.directory_ids'))->toContain($dir->id);
});

it('syncs grants on update and replaces previous selection', function () {
    damCreateRoleAndLogin('all');

    $target = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);
    $a = Directory::factory()->create();
    $b = Directory::factory()->create();
    $c = Directory::factory()->create();

    DB::table('dam_directory_role')->insert([
        'directory_id' => $a->id,
        'role_id'      => $target->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $response = $this->postJson(route('admin.dam.directory_permissions.update'), [
        'role_id'     => $target->id,
        'directories' => [$b->id, $c->id],
    ]);
    $response->assertOk();

    $ids = app(DirectoryRolePermissionRepository::class)->getDirectoryIdsForRole($target->id);
    expect($ids)->toEqualCanonicalizing([$b->id, $c->id]);
});

it('hides non-granted directories from the directory tree endpoint for custom roles', function () {
    $context = damCreateRoleAndLogin('custom', ['dam.directory.index']);

    $granted = Directory::factory()->create();
    $denied = Directory::factory()->create();

    DB::table('dam_directory_role')->insert([
        'directory_id' => $granted->id,
        'role_id'      => $context['role']->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $response = $this->getJson(route('admin.dam.directory.index'));
    $response->assertOk();

    $ids = extractDirectoryIdsRecursive($response->json('data'));
    expect($ids)->toContain($granted->id);
    expect($ids)->not->toContain($denied->id);
});

it('returns 403 when fetching children of a non-granted directory', function () {
    $context = damCreateRoleAndLogin('custom', ['dam.directory.index']);

    $denied = Directory::factory()->create();

    $this->getJson(route('admin.dam.directory.children', ['id' => $denied->id]))
        ->assertStatus(403);
});

it('returns 403 when storing a directory under a non-granted parent', function () {
    $context = damCreateRoleAndLogin('custom', ['dam.directory.index', 'dam.directory.store']);
    $denied = Directory::factory()->create();

    $this->postJson(route('admin.dam.directory.store'), [
        'name'      => 'Child',
        'parent_id' => $denied->id,
    ])->assertStatus(403);
});

it('returns 403 when destroying a non-granted directory', function () {
    damCreateRoleAndLogin('custom', ['dam.directory.index', 'dam.directory.destroy']);
    $denied = Directory::factory()->create();

    $this->deleteJson(route('admin.dam.directory.destroy', ['id' => $denied->id]))
        ->assertStatus(403);
});

it('returns 403 when uploading to a non-granted directory', function () {
    damCreateRoleAndLogin('custom', ['dam.directory.index', 'dam.asset.upload']);
    $denied = Directory::factory()->create();

    $this->post(route('admin.dam.assets.upload'), [
        'directory_id' => $denied->id,
        'files'        => [UploadedFile::fake()->image('a.png')],
    ])->assertStatus(403);
});

it('returns 403 when uploading to a tree-visible-but-not-directly-granted ancestor', function () {
    $context = damCreateRoleAndLogin('custom', ['dam.directory.index', 'dam.asset.upload']);

    // Build Root → Mid → Leaf, grant only Leaf.
    $root = Directory::create(['name' => 'AclUploadRoot', 'parent_id' => null]);
    $mid = Directory::create(['name' => 'Mid', 'parent_id' => $root->id]);
    $leaf = Directory::create(['name' => 'Leaf', 'parent_id' => $mid->id]);

    DB::table('dam_directory_role')->insert([
        'directory_id' => $leaf->id,
        'role_id'      => $context['role']->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    // Mid is visible in the tree (ancestor expansion) but uploading to it
    // must still be denied — only the directly-granted leaf accepts uploads.
    $this->post(route('admin.dam.assets.upload'), [
        'directory_id' => $mid->id,
        'files'        => [UploadedFile::fake()->image('a.png')],
    ])->assertStatus(403);
});

it('respects ACL key gate: missing dam.asset.upload returns 401 even with directory grant', function () {
    // Role has the directory granted but lacks the upload ACL key entirely.
    // Bouncer admin middleware must reject with 401 before our directory gate
    // even runs — proves the two layers compose.
    $context = damCreateRoleAndLogin('custom', ['dam.directory.index']);
    $dir = Directory::factory()->create();

    DB::table('dam_directory_role')->insert([
        'directory_id' => $dir->id,
        'role_id'      => $context['role']->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $this->post(route('admin.dam.assets.upload'), [
        'directory_id' => $dir->id,
        'files'        => [UploadedFile::fake()->image('a.png')],
    ])->assertStatus(401);
});

it('hides the Save button for view-only admins', function () {
    // Has the parent + .index keys (so middleware passes the GET) but no
    // .update key — admin can browse but cannot save.
    damCreateRoleAndLogin('custom', [
        'dam.directory_permissions',
        'dam.directory_permissions.index',
    ]);

    $response = $this->get(route('admin.dam.directory_permissions.index'));
    $response->assertOk();
    $response->assertDontSeeText(trans('dam::app.admin.permissions.save'));
});

it('renders every directory in the manager tree even when only a deep leaf is granted', function () {
    damCreateRoleAndLogin('all');

    $target = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);
    $root = Directory::create(['name' => 'PermTreeRoot', 'parent_id' => null]);
    $av = Directory::create(['name' => 'AudioVideo', 'parent_id' => $root->id]);
    $audio = Directory::create(['name' => 'AudioLeaf', 'parent_id' => $av->id]);
    $sibling = Directory::create(['name' => 'SiblingBranch', 'parent_id' => $root->id]);

    DB::table('dam_directory_role')->insert([
        'directory_id' => $audio->id,
        'role_id'      => $target->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $response = $this->get(route('admin.dam.directory_permissions.index', ['role_id' => $target->id]));
    $response->assertOk();
    // Tree is Vue-rendered from a JSON attribute on <x-admin::tree.view>, so
    // directory names appear in the encoded :items prop, not as direct text.
    // assertSee scans raw HTML which catches them in either form.
    $response->assertSee('PermTreeRoot');
    $response->assertSee('AudioVideo');
    $response->assertSee('AudioLeaf');
    $response->assertSee('SiblingBranch');
});

it('renders the Save button for admins with the update ACL key', function () {
    damCreateRoleAndLogin('custom', [
        'dam.directory_permissions',
        'dam.directory_permissions.index',
        'dam.directory_permissions.update',
    ]);

    $response = $this->get(route('admin.dam.directory_permissions.index'));
    $response->assertOk();
    $response->assertSeeText(trans('dam::app.admin.permissions.save'));
});

it('lets permission_type=all see every directory', function () {
    damCreateRoleAndLogin('all');
    Directory::factory()->count(3)->create();

    $response = $this->getJson(route('admin.dam.directory.index'));
    $response->assertOk();

    $count = count(array_unique(extractDirectoryIdsRecursive($response->json('data'))));
    expect($count)->toBeGreaterThanOrEqual(3);
});

/**
 * Helper — flatten a tree response to ids.
 */
function extractDirectoryIdsRecursive(array $nodes): array
{
    $ids = [];

    foreach ($nodes as $node) {
        if (isset($node['id'])) {
            $ids[] = $node['id'];
        }
        if (! empty($node['children']) && is_array($node['children'])) {
            $ids = array_merge($ids, extractDirectoryIdsRecursive($node['children']));
        }
    }

    return $ids;
}
