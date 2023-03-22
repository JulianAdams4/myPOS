<?php

namespace App\Traits;

use App\Employee;
use App\Store;

trait IntegrationsHelper
{
    public function getEmployeeIntegration($storeId)
    {
        $employee = Employee::where('store_id', $storeId)
                        ->where('name', "Integración")
                        ->first();
        if (!$employee) {
            $store = Store::where('id', $storeId)->first();
            $nameStoreStripped = str_replace(' ', '', $store->name);
            $employee = new Employee();
            $employee->name = "Integración";
            $employee->store_id = $storeId;
            $employee->email = 'integracion@' .strtolower($nameStoreStripped). '.com';
            $employee->password = '$2y$10$XBl3VT7NVYSDHnGJVRmlnumOv3jDjZKhfidkcss8GeWt0NIYwFU42';
            $employee->type_employee = 3;
            $employee->save();
        }
        return $employee;
    }
}
