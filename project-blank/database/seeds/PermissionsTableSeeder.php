<?php

use App\Permission;
use App\Role;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PermissionsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Employee Modules and Actions.
        $employeeRole = Role::with('users')->where('name', 'employee')->first();
        $employeeModules = [
            [
                'type' => 'module',
                'identifier' => 'cashier-balance',
                'label' => 'Caja',
                'actions' => [
                    [
                        'type' => 'action',
                        'identifier' => 'open-cashier-balance',
                        'label' => 'Abrir caja'
                    ],
                    [
                        'type' => 'action',
                        'identifier' => 'close-cashier-balance',
                        'label' => 'Cerrar caja'
                    ]
                ]
            ],
            [
                'type' => 'module',
                'identifier' => 'view-orders',
                'label' => 'Órdenes / Pedidos',
                'actions' => [
                    [
                        'type' => 'action',
                        'identifier' => 'reprint-order',
                        'label' => 'Reimprimir facturas y comandas'
                    ]
                ]
            ],
            [
                'type' => 'module',
                'identifier' => 'commands',
                'label' => 'Comanda digital',
                'actions' => [
                    [
                        'type' => 'action',
                        'identifier' => 'dispatch-order',
                        'label' => 'Despachar orden'
                    ],
                    [
                        'type' => 'action',
                        'identifier' => 'dispatch-detail',
                        'label' => 'Despachar ítem'
                    ]
                ]
            ],
            [
                'type' => 'module',
                'identifier' => 'manage-orders',
                'label' => 'Manejo de órdenes',
                'actions' => [
                    [
                        'type' => 'action',
                        'identifier' => 'finish-order',
                        'label' => 'Realizar pedido'
                    ],
                    [
                        'type' => 'action',
                        'identifier' => 'delete-order',
                        'label' => 'Eliminar orden'
                    ],
                    [
                        'type' => 'action',
                        'identifier' => 'print-command',
                        'label' => 'Imprimir comanda'
                    ]
                ]
            ],
            [
                'type' => 'module',
                'identifier' => 'change-employee',
                'label' => 'Cambiar empleados (relevo)',
                'actions' => []
            ],
            [
                'type' => 'module',
                'identifier' => 'billing',
                'label' => 'Billing',
                'actions' => []
            ],
            [
                'type' => 'module',
                'identifier' => 'billing-products',
                'label' => 'Productos',
                'actions' => []
            ],
            [
                'type' => 'module',
                'identifier' => 'admin-companies',
                'label' => 'Compañías',
                'actions' => []
            ]
        ];
        foreach ($employeeModules as $employeeModule) {
            DB::table('permissions')->insert([
                'role_id' => $employeeRole->id,
                'module_id' => null,
                'type' => $employeeModule['type'],
                'identifier' => $employeeModule['identifier'],
                'label' => $employeeModule['label'],
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
            $newEmployeeModule = Permission::where('type', $employeeModule['type'])
                ->where('identifier', $employeeModule['identifier'])
                ->where('label', $employeeModule['label'])->first();
            $actions = $employeeModule['actions'];
            foreach ($actions as $action) {
                DB::table('permissions')->insert([
                    'role_id' => $employeeRole->id,
                    'module_id' => $newEmployeeModule->id,
                    'type' => $action['type'],
                    'identifier' => $action['identifier'],
                    'label' => $action['label'],
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
        $employeeRole->load('permissions');
        foreach ($employeeRole->users as $employee) {
            $employee->permissions()->sync($employeeRole->permissions);
        }

        // Admin Store Modules and Actions.
        $storeAdminRole = Role::with('users')->where('name', 'admin_store')->first();
        $storeAdminModules = [
            [
                'type' => 'module',
                'identifier' => 'inventory',
                'label' => 'Inventario',
                'actions' => [
                    [
                        'type' => 'action',
                        'identifier' => 'create-component-category',
                        'label' => 'Crear categorías de componentes'
                    ],
                    [
                        'type' => 'action',
                        'identifier' => 'crud-items',
                        'label' => 'Crear, actualizar y borrar ítems'
                    ],
                    [
                        'type' => 'action',
                        'identifier' => 'inventory-movements',
                        'label' => 'Movimientos de inventario'
                    ]
                ]
            ],
            [
                'type' => 'module',
                'identifier' => 'stock-transfers',
                'label' => 'Movimientos',
                'actions' => [
                    [
                        'type' => 'action',
                        'identifier' => 'pending-transfers',
                        'label' => 'Ver y aceptar transferencias de stock'
                    ],
                    [
                        'type' => 'action',
                        'identifier' => 'create-transfers',
                        'label' => 'Realizar transferencias de stock'
                    ]
                ]
            ],
            [
                'type' => 'module',
                'identifier' => 'taxes',
                'label' => 'Impuestos',
                'actions' => []
            ],
            [
                'type' => 'module',
                'identifier' => 'menus',
                'label' => 'Menús',
                'actions' => []
            ],
            [
                'type' => 'module',
                'identifier' => 'orders',
                'label' => 'Órdenes',
                'actions' => []
            ],
            [
                'type' => 'module',
                'identifier' => 'reports',
                'label' => 'Reportes',
                'actions' => []
            ],
            [
                'type' => 'module',
                'identifier' => 'goals',
                'label' => 'Metas',
                'actions' => []
            ],
            [
                'type' => 'module',
                'identifier' => 'providers',
                'label' => 'Proveedores',
                'actions' => []
            ],
            [
                'type' => 'module',
                'identifier' => 'configuration',
                'label' => 'Configuración',
                'actions' => []
            ],
        ];
        foreach ($storeAdminModules as $storeAdminModule) {
            DB::table('permissions')->insert([
                'role_id' => $storeAdminRole->id,
                'module_id' => null,
                'type' => $storeAdminModule['type'],
                'identifier' => $storeAdminModule['identifier'],
                'label' => $storeAdminModule['label'],
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
            $newStoreAdminModule = Permission::where('type', $storeAdminModule['type'])
                ->where('identifier', $storeAdminModule['identifier'])
                ->where('label', $storeAdminModule['label'])->first();
            $actions = $storeAdminModule['actions'];
            foreach ($actions as $action) {
                DB::table('permissions')->insert([
                    'role_id' => $storeAdminRole->id,
                    'module_id' => $newStoreAdminModule->id,
                    'type' => $action['type'],
                    'identifier' => $action['identifier'],
                    'label' => $action['label'],
                    'created_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            }
        }
        $storeAdminRole->load('permissions');
        foreach ($storeAdminRole->users as $storeAdmin) {
            $storeAdmin->permissions()->sync($storeAdminRole->permissions);
        }
    }
}
