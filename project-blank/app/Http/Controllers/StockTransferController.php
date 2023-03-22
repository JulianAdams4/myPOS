<?php

namespace App\Http\Controllers;

use App\StockTransfer;
use App\Store;
use App\Helper;
use App\Employee;
use App\Traits\AuthTrait;
use App\Traits\LoggingHelper;
use App\ComponentStock;
use App\InventoryAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use DB;
use App\StockMovement;

class StockTransferController extends Controller
{
    use AuthTrait, LoggingHelper;

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

    /*
    getTransferStores
    Retorna tiendas a la que se puede transferir stock
    */
    public function getTransferStores()
    {
        $store = $this->authStore;

        $stores = Store::where([
            ['company_id', '=', $store->company_id],
            ['id', '<>', $store->id],
        ])
            ->get(['id', 'name']);

        $stock = ComponentStock::where('store_id', $store->id)
            ->whereHas('component', function ($variation) {
                $variation->where('status', 1);
            })
            ->with(['component', 'pendingStockTransfers'])
            ->get();

        return response()->json([
            'status'  => 'Tiendas encontradas',
            'results' => [
                'stores' => $stores,
                'stock'  => $stock
            ],
        ], 200);
    }

    /*
    getTransferStores
    Retorna el Modelo de ComponentStock añade min_stock que incluye
          la información del stock del día en curso
    */
    public function getTransferStoreData($id)
    {
        $componentStocks = ComponentStock::where('store_id', $id)
            ->whereHas('component', function ($variation) {
                $variation->where('status', 1);
            })
            ->with(['component', 'dailyStocks'])
            ->select('id', 'store_id', 'component_id', 'stock', 'alert_stock')
            ->get();

        return response()->json([
            'status'  => 'Componentes encontrados',
            'results' => $componentStocks
        ], 200);
    }

    /*
    getPendingStockTransfers
    Transferencias de stock pendientes de una tienda
    */
    public function getPendingStockTransfers(Request $request)
    {
        $store = $this->authStore;

        $stockTransfers = StockTransfer::where('destination_store_id', $store->id)
            ->where(function ($query) {
                $query->where('status', StockTransfer::PENDING)
                    ->orWhere('status', StockTransfer::FAILED);
            })
            ->with(['originStore', 'originStock.component'])
            ->get();

        return response()->json([
            'status' => 'Transferencias pendientes listadas correctamente',
            'results' => $stockTransfers
        ], 200);
    }

    /*
    sendItemStockTransfer
    Enviar transferencia de stock de una tienda a otra de la misma compañía
    */
    public function sendItemStockTransfer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'item_id'  => 'required|exists:component_stock,id',
            'store_id' => 'required|exists:stores,id',
            'quantity' => 'required|min:1',
        ], [
            'item_id.required'  => 'Debe elegir un item',
            'item_id.exists'    => 'El item no existe',
            'store_id.required' => 'La tienda destino es obligatoria',
            'store_id.exists'   => 'La tienda no existe',
            'quantity.required' => 'Debe enviar la cantidad',
            'quantity.min'      => 'La cantidad debe ser mayor a 0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 400);
        }

        $store = $this->authStore;
        $store->load('configs.inventoryStore');
        if ($store->configs->inventoryStore && $store->configs->inventory_store_id !== $store->id) {
            return response()->json([
                'status' => 'No tiene acceso a esta funcionalidad.',
            ], 404);
        }

        $user = $this->authUser;
        try {
            $componentJSON = DB::transaction(
                function () use ($request, $store, $user) {
                    $originStock = ComponentStock::find($request->item_id);

                    $quantity = round(floatval($request->quantity), 2);
                    if ($quantity <= 0) {
                        return response()->json(['status' => 'La cantidad debe ser mayor a 0'], 404);
                    }

                    // Valida si tiene stock disponible para crear el movimiento (Restrictive Stock) ***
                    if ($store->configs->restrictive_stock_production && $originStock->stock < $quantity) {
                        return response()->json([
                            'status' => 'Restricción: No tiene stock suficiente para realizar el movimiento',
                        ], 404);
                    }

                    $destinationStock = ComponentStock::where([
                        ['store_id', '=', $request->store_id],
                        ['component_id', '=', $originStock->component_id],
                    ])->first();

                    $stockTransfer = new StockTransfer();
                    $stockTransfer->origin_store_id      = $store->id;
                    $stockTransfer->origin_stock_id      = $originStock->id;
                    $stockTransfer->destination_store_id = $request->store_id;
                    $stockTransfer->destination_stock_id = $destinationStock ? $destinationStock->id : null;
                    $stockTransfer->quantity             = $quantity;
                    $stockTransfer->status               = StockTransfer::PENDING;
                    $stockTransfer->save();

                    return response()->json([
                        'status' => 'Transferencia realizada con éxito'
                    ], 200);
                }
            );
            return $componentJSON;
        } catch (\Exception $e) {
            $this->logError(
                'API/StockFransferController sendItemStockTransfer: No se pudo realizar la transferencia.',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $request->all()
            );

            return response()->json([
                'status' => 'No se pudo realizar la transferencia',
            ], 409);
        }
    }

    /*
    sendBatchStockTransfer
    Enviar transferencias de stock de todos los items de una tienda a otra de la misma compañía (PENDING)
    */
    public function sendBatchStockTransfer(Request $request)
    {
        $employee = $this->authEmployee;
        $store    = $employee->store;

        // To-Do: Cambiar a ComponentStock cuando se acepte el PR de stock por tienda.
        $destination = Store::find($request->store_id);

        if (!$destination) {
            return response()->json([
                'status' => 'Tienda destino no existe.',
            ], 404);
        }

        try {
            $componentJSON = DB::transaction(
                function () use ($request, $employee, $store) {
                    return response()->json([
                        'status' => 'Transferencia realizada con éxito'
                    ], 200);
                }
            );
            return $componentJSON;
        } catch (\Exception $e) {
            $this->logError(
                'API/StockFransferController sendBatchStockTransfer: No se pudo realizar la transferencia.',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $request->all()
            );

            return response()->json([
                'status' => 'No se pudo realizar la transferencia',
            ], 409);
        }
    }

    /*
    applyStockTransfer
    Procesar la transferencia de stock. Puede resultar: aceptado, editado o fallido.
    */
    public function applyStockTransfer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'transfer_id' => 'required|exists:stock_transfers,id',
            'quantity'    => 'required|min:1',
        ], [
            'transfer_id.required' => 'Debe elegir la transferencia.',
            'transfer_id.exists'   => 'La transferencia no existe.',
            'quantity.required'    => 'Debe enviar la cantidad.',
            'quantity.min'         => 'La cantidad debe ser mayor a 0.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 400);
        }

        $user = $this->authUser;
        $employee = $this->authEmployee;
        $store = $this->authStore;
        $store->load('configs.inventoryStore');
        if ($store->configs->inventoryStore && $store->configs->inventory_store_id !== $store->id) {
            return response()->json([
                'status' => 'No tiene acceso a esta funcionalidad.',
            ], 404);
        }
        try {
            $transferJSON = DB::transaction(
                function () use ($request, $employee, $store, $user) {
                    $stockTransfer = StockTransfer::find($request->transfer_id);
                    $quantity = round(floatval($request->quantity), 2);

                    // Verifica si la transferencia ya fue procesada (aceptada o editada).
                    if (!$stockTransfer->canBeProcessed()) {
                        return response()->json(['status' => 'La transferencia ya fue procesada'], 404);
                    }

                    // Verifica stock (Restrictive Stock)
                    $stockTransfer->load('originStore');
                    if ($stockTransfer->originStore->configs->restrictive_stock_production) {
                        $stockTransfer->load('originStock');
                        if ($stockTransfer->originStock->stock < $quantity) {
                            $stockTransfer->status = StockTransfer::FAILED;
                            $stockTransfer->save();

                            return response()->json(['status' => 'Restricción: La tienda de origen no tiene stock suficiente'], 404);
                        }
                    }

                    // Verifica si el ComponentStock ya existe en la tienda de destino.
                    $stockTransfer->load('originStock.component');
                    $originStock      = $stockTransfer->originStock;

                    $destinationStock = ComponentStock::where([
                        ['store_id', '=', $stockTransfer->destination_store_id],
                        ['component_id', '=', $originStock->component_id],
                    ])->first();

                    // Si no existe, lo crea; si ya existe, acumula el stock.
                    if (!$destinationStock) {
                        $destinationStock = new ComponentStock();
                        $destinationStock->store_id     = $stockTransfer->destination_store_id;
                        $destinationStock->component_id = $originStock->component_id;
                        $destinationStock->stock        = $quantity;
                    } else {
                        $destinationStock->stock += $quantity;
                    }

                    // Actualiza costo del stock destino
                    $destinationStock->cost = $originStock->cost;
                    $destinationStock->save();

                    // Reduce el stock de la tienda origen.
                    $result = $originStock->stock - $quantity;
                    // Check Zero Lower Limit
                    if ($originStock->store->configs->zero_lower_limit) {
                        if ($originStock->stock <= 0) { // No baja del Negativo previo
                            $result = $originStock->stock;
                        } else if ($result < 0) { // Si da negativo, setea el 0
                            $result = 0;
                        }
                    }
                    $originStock->stock = $result;
                    $originStock->save();
                    // $destinationStock->load('component');

                    // Acciones de enviar stock y recibir stock de otras tiendas.
                    $sendAction = InventoryAction::firstOrCreate(
                        ['code' => 'send_transfer'],
                        ['name' => 'Enviar a otra tienda', 'action' => 3]
                    );
                    $receiveAction = InventoryAction::firstOrCreate(
                        ['code' => 'receive_transfer'],
                        ['name' => 'Recibir de otra tienda', 'action' => 1]
                    );

                    // ----- Movimiento de Enviar existencias -----
                    $sendMovement = new StockMovement();
                    $sendMovement->inventory_action_id = $sendAction->id;
                    $initialStockMovementOrigin = 0;
                    $lastStockMovementOrigin = StockMovement::where('component_stock_id', $originStock->id)
                        ->orderBy('id', 'desc')->first();
                    if ($lastStockMovementOrigin) {
                        $initialStockMovementOrigin = $lastStockMovementOrigin->final_stock;
                    }
                    $sendMovement->initial_stock = $initialStockMovementOrigin;
                    $sendMovement->value = $quantity;
                    $sendMovement->final_stock = $initialStockMovementOrigin - $quantity; // Es envío
                    // Check Zero Lower Limit
                    if ($store->configs->zero_lower_limit) {
                        if ($initialStockMovementOrigin <= 0) { // No baja del Negativo previo
                            $sendMovement->final_stock = $initialStockMovementOrigin;
                        } else if ($sendMovement->final_stock < 0) { // Si da negativo, setea el 0
                            $sendMovement->final_stock = 0;
                        }
                    }
                    $sendMovement->cost = $originStock->cost;
                    $sendMovement->component_stock_id  = $originStock->id;
                    $sendMovement->created_by_id = $store->id;
                    $sendMovement->user_id = $user->id;
                    $sendMovement->save();

                    // ----- Movimiento de Recibir existencias -----
                    $receiveMovement = new StockMovement();
                    $receiveMovement->inventory_action_id = $receiveAction->id;
                    $initialStockMovementTarget = 0;
                    $lastStockMovementTarget = StockMovement::where('component_stock_id', $destinationStock->id)
                        ->orderBy('id', 'desc')->first();
                    if ($lastStockMovementTarget) {
                        $initialStockMovementTarget = $lastStockMovementTarget->final_stock;
                    }
                    $receiveMovement->initial_stock = $initialStockMovementTarget;
                    $receiveMovement->value = $quantity;
                    $receiveMovement->final_stock = $initialStockMovementTarget + $quantity; // Es recibo
                    $receiveMovement->cost = $originStock->cost;
                    $receiveMovement->component_stock_id = $destinationStock->id;
                    $receiveMovement->created_by_id = $store->id; // ***
                    $receiveMovement->user_id = $user->id;
                    $receiveMovement->save();

                    // Cambia el estado de la transferencia a aceptado o editado.
                    $status = $stockTransfer->quantity == $quantity ? StockTransfer::ACCEPTED : StockTransfer::EDITED;
                    $stockTransfer->status               = $status;
                    $stockTransfer->accepted_quantity    = $quantity;
                    $stockTransfer->destination_stock_id = $destinationStock->id;
                    $stockTransfer->processed_by_id      = $employee->id;
                    $stockTransfer->processed_at         = Carbon::now()->toDateTimeString();
                    $stockTransfer->save();

                    return response()->json([
                        'status' => 'Transferencia realizada con éxito'
                    ], 200);
                }
            );
            return $transferJSON;
        } catch (\Exception $e) {
            $this->logError(
                'API/StockFransferController applyStockTransfer: No se pudo realizar la transferencia.',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $request->all()
            );

            return response()->json([
                'status' => 'No se pudo realizar la transferencia',
            ], 409);
        }
    }
}
