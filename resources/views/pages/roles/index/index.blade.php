<section class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Roles') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('Roles bundle permissions. System roles are protected and kept in step by the permission sync.') }}</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <div class="flex flex-wrap items-end justify-between gap-4">
        @unless (\App\Tenancy\Facades\Tenant::check())
            <div class="w-full max-w-xs">
                <flux:select wire:model.live="organisation" :label="__('Organisation')" data-test="organisation-filter">
                    <flux:select.option value="">{{ __('All organisations') }}</flux:select.option>
                    @foreach ($this->organisationOptions as $id => $label)
                        <flux:select.option :value="$id">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endunless

        @can('create', \Spatie\Permission\Models\Role::class)
            <flux:button variant="primary" icon="plus" wire:click="create" data-test="create-role-button">
                {{ __('New role') }}
            </flux:button>
        @endcan
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('System roles') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('Permissions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->systemRoles as $role)
                    <flux:table.row :key="'system-'.$role->getKey()">
                        <flux:table.cell variant="strong">
                            <div class="flex items-center gap-2">
                                <span>{{ \App\Authorization\Role::from($role->name)->label() }}</span>
                                <flux:badge size="sm" color="zinc" icon="lock-closed">{{ __('Protected') }}</flux:badge>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @forelse ($role->permissions as $permission)
                                    <flux:badge size="sm" data-test="system-role-permission">
                                        {{ \App\Authorization\Permission::tryFrom($permission->name)?->label() ?? $permission->name }}
                                    </flux:badge>
                                @empty
                                    <flux:text>{{ __('No permissions: the super admin flag grants access.') }}</flux:text>
                                @endforelse
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <div class="space-y-2">
        <flux:heading size="lg" level="2">{{ __('Custom roles') }}</flux:heading>

        <flux:table :paginate="$this->roles">
            <flux:table.columns>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                @unless (\App\Tenancy\Facades\Tenant::check())
                    <flux:table.column>{{ __('Organisation') }}</flux:table.column>
                @endunless
                <flux:table.column>{{ __('Permissions') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->roles as $role)
                    <flux:table.row :key="'role-'.$role->getKey()">
                        <flux:table.cell variant="strong">{{ $role->name }}</flux:table.cell>
                        @unless (\App\Tenancy\Facades\Tenant::check())
                            <flux:table.cell>{{ $this->organisationOptions[(int) $role->getAttribute('team_id')] ?? '' }}</flux:table.cell>
                        @endunless
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($role->permissions as $permission)
                                    <flux:badge size="sm">{{ \App\Authorization\Permission::tryFrom($permission->name)?->label() ?? $permission->name }}</flux:badge>
                                @endforeach
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-1">
                                @can('update', $role)
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $role->getKey() }})" data-test="edit-role-button">
                                        {{ __('Edit') }}
                                    </flux:button>
                                @endcan
                                @can('delete', $role)
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $role->getKey() }})" data-test="delete-role-button">
                                        {{ __('Delete') }}
                                    </flux:button>
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">{{ __('No custom roles yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="role-editor" class="md:w-[36rem]" wire:model="showRoleModal" @close="closeRoleModal">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingRoleId === null ? __('New role') : __('Edit role') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" type="text" required data-test="role-name-input" />

            <flux:checkbox.group wire:model="permissions" :label="__('Permissions')">
                <div class="space-y-4">
                    @foreach ($this->permissionGroups as $group => $groupPermissions)
                        <div class="space-y-2">
                            <flux:heading size="sm">{{ \App\Authorization\PermissionGroup::from($group)->label() }}</flux:heading>
                            @foreach ($groupPermissions as $permission)
                                <flux:checkbox :value="$permission->value" :label="$permission->label()" :description="$permission->description()" />
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </flux:checkbox.group>

            <flux:error name="organisation" />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeRoleModal">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit" data-test="save-role-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-role" class="max-w-md md:min-w-md" wire:model="showDeleteModal" @close="closeDeleteModal">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Delete role') }}</flux:heading>
                <flux:text>{{ __('Delete the role ":name"? :count users hold it and lose it.', ['name' => $deletingRoleName, 'count' => $this->holderCount]) }}</flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button variant="outline" wire:click="closeDeleteModal">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-role-button">{{ __('Delete role') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
