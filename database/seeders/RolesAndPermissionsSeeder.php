<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'editar usuario']);
        Permission::firstOrCreate(['name' => 'visualizar periodo letivo']);
        Permission::firstOrCreate(['name' => 'visualizar turmas']);
        Permission::firstOrCreate(['name' => 'visualizar dobradinhas']);
        Permission::firstOrCreate(['name' => 'visualizar turmas externas']);
        Permission::firstOrCreate(['name' => 'visualizar salas']);
        Permission::firstOrCreate(['name' => 'visualizar menu config']);

        Role::firstOrCreate(['name' => 'Operador'])
            ->givePermissionTo('visualizar periodo letivo')
            ->givePermissionTo('visualizar turmas')
            ->givePermissionTo('visualizar dobradinhas')
            ->givePermissionTo('visualizar turmas externas')
            ->givePermissionTo('visualizar salas')
            ->givePermissionTo('visualizar menu config');

        Role::firstOrCreate(['name' => 'Administrador'])
            ->givePermissionTo(Permission::all());

    }
}