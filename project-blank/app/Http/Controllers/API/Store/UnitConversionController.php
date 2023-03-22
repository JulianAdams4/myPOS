<?php

namespace App\Http\Controllers\API\Store;

// Libraries
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

//Models
use App\UnitConversion;
use App\MetricUnit;

// Helpers
use App\Traits\AuthTrait;
use App\Traits\Logs\Logging;

class UnitConversionController extends Controller
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

    /**
     *
     * Devuelve el listado de las conversiones de unidades de la compañía a la que pertenece la tienda
     *
     * @return Response          Respuesta json con el estado del requerimiento
     *
     */
    public function getUnitConversions()
    {
        $store = $this->authStore;
        $units = UnitConversion::whereHas(
            'unitOrigin',
            function ($unit) use ($store) {
                return $unit->where('company_id', $store->company_id)->where('status', 1);
            }
        )
        ->with(['unitOrigin', 'unitDestination'])
        ->get();

        $data = [];
        foreach ($units as $unit) {
            array_push(
                $data,
                [
                    "id" => $unit->id,
                    "conversion_value" => $unit->multiplier,
                    "origin" => [
                        "id" => $unit->unitOrigin->id,
                        "name" => $unit->unitOrigin->name,
                        "short_name" => $unit->unitOrigin->short_name
                    ],
                    "destination" => [
                        "id" => $unit->unitDestination->id,
                        "name" => $unit->unitDestination->name,
                        "short_name" => $unit->unitDestination->short_name
                    ],
                ]
            );
        }
        
        return response()->json([
            'status' => 'Listando conversiones de unidades',
            'results' => $data
        ], 200);
    }

    /**
     *
     * Crear una conversión de unidades
     *
     * @param Request $request   Data para crear la conversión de unidades
     *
     * @return Response          Respuesta json con el estado del requerimiento
     *
     */
    public function create(Request $request)
    {
        $store = $this->authStore;

        $validator = Validator::make(
            $request->all(),
            [
                'origin_id' => ['required', 'filled'],
                'destination_id' => ['required', 'filled'],
                'conversion_value' => ['required', 'filled'],
            ],
            [
                'origin_id.required' => 'El id de la unidad de medida origen es obligatorio',
                'destination_id.required' => 'El id de la unidad de medida resultado de la conversión es obligatorio',
                'conversion_value.required' => 'El valor de la conversión es obligatorio',
                'origin_id.filled' => 'El id de la unidad de medida origen no puede estar vacío',
                'destination_id.filled' => 'El id de la unidad de medida resultado de la conversión no puede estar
                    vacío',
                'conversion_value.filled' => 'El valor de la conversión no puede estar vacío',
            ]
        );
        if ($validator->fails()) {
            return response()->json([
                'status' => 'Algunos campos no son válidos.',
                'results' => $validator->errors(),
            ], 400);
        }

        $jsonRequest = $request->json()->all();

        // Verificando que ambas unidades de conversión no sean las mismas
        if ($jsonRequest["origin_id"] == $jsonRequest["destination_id"]) {
            return response()->json([
                'status' => 'La unidad de origen y la unidad final de la conversión no pueden ser las mismas',
                'results' => null,
            ], 409);
        }

        // Verificando si la conversión ya existe
        $unitConversion1 = UnitConversion::where("unit_origin_id", $jsonRequest["origin_id"])
            ->where("unit_destination_id", $jsonRequest["destination_id"])
            ->first();
        $unitConversion2 = UnitConversion::where("unit_origin_id", $jsonRequest["destination_id"])
            ->where("unit_destination_id", $jsonRequest["origin_id"])
            ->first();
        if (!is_null($unitConversion1) || !is_null($unitConversion2)) {
            return response()->json([
                'status' => "Esta conversión ya existe, utilice la opción de modificar
                    conversión para cambiar el valor de esta conversión",
                'results' => null,
            ], 409);
        }

        // Verificando si las unidades de medida existen
        $unit1 = MetricUnit::where("id", $jsonRequest["origin_id"])
            ->where('company_id', $store->company_id)
            ->where('status', 1)
            ->first();
        $unit2 = MetricUnit::where("id", $jsonRequest["destination_id"])
            ->where('company_id', $store->company_id)
            ->where('status', 1)
            ->first();
        if (is_null($unit1) || is_null($unit2)) {
            return response()->json([
                'status' => 'Una o ambas unidades de medidas seleccionadas no existen',
                'results' => null,
            ], 409);
        }

        try {
            $resultJSON = DB::transaction(
                function () use ($jsonRequest, $store) {
                    // Crear la conversión inversa de unidades
                    $unitConversion = new UnitConversion();
                    $unitConversion->unit_origin_id = $jsonRequest["destination_id"];
                    $unitConversion->unit_destination_id = $jsonRequest["origin_id"];
                    $unitConversion->multiplier = 1 / $jsonRequest["conversion_value"];
                    $unitConversion->save();
                    // Crear la conversión de unidades
                    $unitConversion = new UnitConversion();
                    $unitConversion->unit_origin_id = $jsonRequest["origin_id"];
                    $unitConversion->unit_destination_id = $jsonRequest["destination_id"];
                    $unitConversion->multiplier = $jsonRequest["conversion_value"];
                    $unitConversion->save();

                    // Creando conversiones al resto de unidades de la compañía
                    $metricUnitIds = MetricUnit::where(
                        'company_id',
                        $store->company_id
                    )
                        ->where('status', 1)
                        ->where('id', '!=', $jsonRequest["origin_id"])
                        ->where('id', '!=', $jsonRequest["destination_id"])
                        ->sharedLock()
                        ->get();
                    $unitIds = $metricUnitIds->pluck('id')->toArray();
                    $analyzedIds = [$jsonRequest["origin_id"], $jsonRequest["destination_id"]];
                    $previousAnalyzedIds = [$jsonRequest["origin_id"], $jsonRequest["destination_id"]];
                    // Parte 1
                    // Ids del resto de unidades para analizar conversiones indirectas
                    $analyzeIds = $unitIds;
                    $previousAnalyzeIds = $unitIds;
                    while (count($analyzeIds) > 0) {
                        $removeIds = [];
                        foreach ($analyzeIds as $analyzeId) {
                            // Parte 2
                            // Buscando conversiones del resto de unidades
                            $consumableUnitConversions = UnitConversion::where(
                                'unit_origin_id',
                                $analyzeId
                            )
                                ->sharedLock()
                                ->get();
                            foreach ($consumableUnitConversions as $consumableUnitConversion) {
                                // Parte 3
                                // Buscando conversiones de las unidades convertidas a la unidad original
                                $indirectConversion = UnitConversion::where(
                                    'unit_origin_id',
                                    $consumableUnitConversion->unit_destination_id
                                )
                                    ->where('unit_destination_id', $jsonRequest["origin_id"])
                                    ->sharedLock()
                                    ->first();
                                if (!is_null($indirectConversion)) {
                                    // Verificando si la conversión ya existe
                                    $unitConversion1 = UnitConversion::where("unit_origin_id", $analyzeId)
                                        ->where("unit_destination_id", $jsonRequest["origin_id"])
                                        ->first();
                                    if (!is_null($unitConversion1)) {
                                        array_push($analyzedIds, $analyzeId);
                                        array_push($removeIds, $analyzeId);
                                        continue;
                                    }

                                    // Parte 4
                                    // Creando las conversiones a partir de las conversiones indirectas
                                    $conversion
                                        = $consumableUnitConversion->multiplier * $indirectConversion->multiplier;
                                    if ($conversion > 0.0000000001) {
                                        $unitConversion2 = new UnitConversion();
                                        $unitConversion2->unit_origin_id = $analyzeId;
                                        $unitConversion2->unit_destination_id = $jsonRequest["origin_id"];
                                        $unitConversion2->multiplier = $conversion;
                                        $unitConversion2->save();

                                        $unitConversion2 = new UnitConversion();
                                        $unitConversion2->unit_origin_id = $jsonRequest["origin_id"];
                                        $unitConversion2->unit_destination_id = $analyzeId;
                                        $unitConversion2->multiplier = 1 / $conversion;
                                        $unitConversion2->save();
                                    }
                                }
                            }
                        }
                        $analyzeIds = array_diff($analyzeIds, $removeIds);
                        if ((count($previousAnalyzeIds) == count($analyzeIds))
                            && (count($previousAnalyzedIds) == count($analyzedIds))) {
                            $analyzeIds = [];
                        }
                        $previousAnalyzeIds = $analyzeIds;
                        $previousAnalyzedIds = $analyzedIds;
                    }

                    // Append de las unidades
                    $unitConversion->load('unitOrigin', 'unitDestination');
                    unset($unitConversion['unit_origin_id']);
                    unset($unitConversion['unit_destination_id']);

                    return response()->json([
                        "status" => "Conversión creada con éxito",
                        "results" => $unitConversion,
                    ], 200);
                }
            );
            return $resultJSON;
        } catch (\Exception $e) {
            Logging::logError(
                "UnitConversionController API Store: ERROR CREATE, storeId: " . $store->id,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                json_encode($request)
            );
            return response()->json([
                'status' => 'No se pudo crear la conversión de estas unidades',
                'results' => null,
            ], 409);
        }
    }
}
