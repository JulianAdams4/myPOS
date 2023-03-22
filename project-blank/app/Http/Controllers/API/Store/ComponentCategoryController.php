<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use App\ComponentCategory;
use App\Employee;
use Illuminate\Http\Request;
use App\Traits\AuthTrait;
use Carbon\Carbon;
use App\Helper;
use App\PendingSync;

class ComponentCategoryController extends Controller
{
    use AuthTrait;

    public $authUser;
    public $authEmployee;
    public $authStore;

    public function __construct()
    {
        $this->middleware('api');
        [$this->authUser, $this->authEmployee, $this->authStore] = $this->getAuth();
        if (!$this->authUser || !$this->authEmployee || !$this->authStore) {
            return response()->json([
                'status' => 'Usuario no autorizado',
            ], 401);
        }
    }

    public function getCategoriesByCompany()
    {
        $store = $this->authStore;
        $categories = ComponentCategory::where('company_id', $store->company_id)->where('status', 1)->get();
        return response()->json(
            [
                'status' => 'Listando categorías',
                'results' => $categories
            ],
            200
        );
    }

    public function create(Request $request)
    {
        $store = $this->authStore;
        $store->load('configs.inventoryStore');
        if ($store->configs->inventoryStore && $store->configs->inventory_store_id !== $store->id) {
            return response()->json([
                'status' => 'No tiene acceso a esta funcionalidad.',
            ], 404);
        }
        $componentCategory = ComponentCategory::where('name', $request->name)
                            ->where('company_id', $store->company_id)
                            ->withTrashed()
                            ->first();
        // If not exists, create it
        if (!$componentCategory) {
            $componentCategory = new ComponentCategory();
            $componentCategory->name = $request->name;
            $componentCategory->search_string = Helper::remove_accents($request->name);
            $componentCategory->status = 1;
            $componentCategory->priority = 0;
            $componentCategory->company_id = $store->company_id;
            $componentCategory->created_at = Carbon::now()->toDateTimeString();
            $componentCategory->updated_at = Carbon::now()->toDateTimeString();
            $componentCategory->save();

            if (!$componentCategory) {
                return response()->json([
                    'status' => 'No se pudo crear la categoría',
                    'results' => null
                ], 409);
            }

            if (config('app.slave')) {
                $pendingSyncing = new PendingSync();
                $pendingSyncing->store_id = $store->id;
                $pendingSyncing->syncing_id = $componentCategory->id;
                $pendingSyncing->type = "component_category";
                $pendingSyncing->action = "insert";
                $pendingSyncing->save();
            }
            return response()->json([
                'status' => 'Categoría creada exitosamente',
                'results' => $componentCategory
            ], 200);

        } else {
            // If exists and it's trashed, restore it
            // If exists and it's not deleted, return error
            if ($componentCategory->trashed()) {

                $componentCategory->restore();

                if (config('app.slave')) {
                    $pendingSyncing = new PendingSync();
                    $pendingSyncing->store_id = $store->id;
                    $pendingSyncing->syncing_id = $componentCategory->id;
                    $pendingSyncing->type = "component_category";
                    $pendingSyncing->action = "insert";
                    $pendingSyncing->save();
                }

                return response()->json([
                    'status' => 'Categoría creada exitosamente',
                    'results' => $componentCategory
                ], 200);
            } else {
                return response()->json([
                    'status' => 'Esta categoría ya existe',
                    'results' => null
                ], 400);
            }
        }
    }
}
