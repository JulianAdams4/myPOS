<?php

namespace App\Http\Controllers\SuperAdmin;

// Libraries
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;
use Illuminate\Support\Facades\DB;
use Auth;
use Carbon\Carbon;

// Models
use App\Company;
use App\MetricUnit;
use App\CompanyElectronicBillingDetail;
use App\Card;
use App\CardStore;
use App\SubscriptionInvoices;

// Helpers
use App\Traits\LoggingHelper;
use App\Traits\AuthTrait;

use Log;

class CompanyController extends Controller
{
    use LoggingHelper;
    use AuthTrait;
    public $authUser;
    public $channel;

    public function __construct()
    {
        $this->middleware('api');
        $this->authUser = Auth::user();
        $this->channel = "#laravel_logs";
    }

    public function searchCompanies($text)
    {
        $stores = Company::search($text)
            ->get();

        return response()->json($stores);
    }

    public function getAllWithBilling(Request $request)
    {
        @$country = $request->country;
        @$invoiceStatus = $request->invoiceStatus;
        @$plan = $request->plan;

        $query = Company::withCount('stores');

        if (isset($country)) {
            $query->whereHas('stores.city.country', function ($query) use ($country) {
                $query->where('code', $country);
            });
        }

        if (isset($invoiceStatus)) {
            if ($invoiceStatus) {
                $query->whereHas('stores.subscriptionInvoiceDetails.invoices')
                    ->whereDoesntHave('stores.subscriptionInvoiceDetails.invoices', function ($queryInvoices) {
                        $queryInvoices->whereIn('status', [SubscriptionInvoices::PENDING, SubscriptionInvoices::FAILED]);
                    });
            } else {
                $query->whereHas('stores.subscriptionInvoiceDetails.invoices', function ($query) {
                    $query->where('status', '<>', SubscriptionInvoices::PAID);
                });
            }
        }

        if (isset($plan)) {
            $query->whereHas('stores.subscriptions', function ($query) use ($plan) {
                $query->where('subscription_plan_id', $plan);
            });
        }

        $companies = $query->get()
            ->makeVisible('has_unpaid_invoices');

        return response()->json($companies);
    }

    public function getAll()
    {
        $companies = Company::select(
            'id',
            'name',
            'contact',
            'TIN'
        )
            ->withCount('stores')
            ->orderBy('name')
            ->get();

        return response()
            ->json($companies);
    }

    public function create(Request $request)
    {
        $user = $this->authUser;

        $data = $request->all();
        if (!isset($data['name'])) {
            return response()->json([
                'status' => 'La compañía debe tene un nombre!',
                'results' => null
            ], 409);
        }

        if (!isset($data['contact'])) {
            return response()->json([
                'status' => 'La compañía debe tene un contacto!',
                'results' => null
            ], 409);
        }

        if (!isset($data['email'])) {
            return response()->json([
                'status' => 'La compañía debe tene un correo!',
                'results' => null
            ], 409);
        }

        $companyExist = Company::where('name', $data['name'])->get();
        if (count($companyExist) > 0) {
            return response()->json([
                'status' => 'Este nombre no está disponible!',
                'results' => null
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($data) {
                    $company = new Company();
                    $company->name = $data['name'];
                    $company->identifier = Uuid::uuid5(Uuid::NAMESPACE_DNS, $data['name']);
                    $company->contact = isset($data['contact']) ? $data['contact'] : null;
                    $company->tin = isset($data['tin']) ? $data['tin'] : null;
                    $company->email = isset($data['email']) ? $data['email'] : null;
                    $company->save();

                    // Crear unidades de medidas por defecto
                    $namesUnit = ["Unidades", "Gramo", "Kilogramo", "Litro", "Mililitro", "Fundas", "Piezas", "Baldes"];
                    $shortNamesUnit = ["unidades", "g", "kg", "l", "ml", "fundas", "piezas", "baldes"];
                    for ($i = 0; $i < count($namesUnit); $i++) {
                        $metric = new MetricUnit();
                        $metric->company_id = $company->id;
                        $metric->name = $namesUnit[$i];
                        $metric->short_name = $shortNamesUnit[$i];
                        $metric->status = 1;
                        $metric->save();
                    }
                    return response()->json([
                        "status" => "Compañía creada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "CompanyController: ERROR CREAR COMPAÑÍA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "CompanyController: ERROR CREAR COMPAÑÍA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo crear la compañía',
                'results' => null,
            ], 409);
        }
    }

    public function get(Request $request, $id)
    {
        $company = Company::find($id)
            ->load(['stores', 'stores.subscriptions']);

        return response()->json($company);
    }

    public function update(Request $request, $id)
    {
        $user = $this->authUser;
        $data = $request->all();

        if (!isset($data['name'])) {
            return response()->json([
                'status' => 'La compañía debe tene un nombre!',
                'results' => null
            ], 409);
        }

        if (!isset($data['contact'])) {
            return response()->json([
                'status' => 'La compañía debe tene un contacto!',
                'results' => null
            ], 409);
        }

        if (!isset($data['email'])) {
            return response()->json([
                'status' => 'La compañía debe tene un correo!',
                'results' => null
            ], 409);
        }

        $companyExist = Company::where('name', $data['name'])
            ->where('id', '!=', $id)
            ->get();

        if (count($companyExist) > 0) {
            return response()->json([
                'status' => 'Este nombre no está disponible!',
                'results' => null
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($data, $id) {
                    $company = Company::find($id);
                    $company->name = $data['name'];
                    $company->identifier = Uuid::uuid5(Uuid::NAMESPACE_DNS, $data['name']);
                    $company->contact = isset($data['contact']) ? $data['contact'] : null;
                    $company->tin = isset($data['tin']) ? $data['tin'] : null;
                    $company->email = isset($data['email']) ? $data['email'] : null;

                    Log::info('before save company-->');
                    Log::info($company);

                    $company->save();

                    return response()->json([
                        "status" => "Compañía actualizada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "CompanyController: ERROR ACTUALIZAR COMPAÑÍA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );

            $slackMessage = "CompanyController: ERROR ACTUALIZAR COMPAÑÍA, userId: " . $user->id .
                "Provocado por: " . $data;

            Log::info('$data->exception ------>');
            Log::info($data->exception);

            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo actualizar la compañía',
                'results' => null,
            ], 409);
        }
    }

    public function getInvoices(Request $request, $id)
    {
        $to = Carbon::now()->endOfDay();
        $from = Carbon::now()->startOfDay()->subYears(1);

        $invoices = SubscriptionInvoices::whereHas(
            'subscriptionInvoiceDetails.store',
            function ($query) use ($id) {
                $query->where('company_id', $id);
            }
        )
            ->whereBetween('created_at', [$from, $to])
            ->where('status', SubscriptionInvoices::PAID)
            ->with(['subscriptionInvoiceDetails.plan'])
            ->get();

        $pendingInvoices = SubscriptionInvoices::whereHas(
            'subscriptionInvoiceDetails.store',
            function ($query) use ($id) {
                $query->where('company_id', $id);
            }
        )
            ->where('status', '<>', SubscriptionInvoices::PAID)
            ->with(['subscriptionInvoiceDetails.plan'])
            ->get();

        return response()->json([
            'status' => "",
            'pendingInvoices' => $pendingInvoices,
            'invoices' => $invoices
        ], 200);
    }

    public function getMetriucUnits($companyId)
    {
        $company = Company::find($companyId);
        if ($company == null) {
            return response()->json([
                'status' => 'Esta compañía no existe',
                'results' => null
            ], 409);
        }

        $metricUnits = MetricUnit::select(
            'id',
            'company_id',
            'name',
            'short_name'
        )
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();
        return response()->json($metricUnits);
    }

    public function createMetricUnit(Request $request, $companyId)
    {
        $user = $this->authUser;

        $company = Company::find($companyId);
        if ($company == null) {
            return response()->json([
                'status' => 'Esta compañía no existe',
                'results' => null
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'short_name' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos",
                'results' => null
            ], 409);
        }

        $data = $request->all();
        $metricUnitsExist = MetricUnit::where('company_id', $companyId)
            ->where(function ($query) use ($data) {
                $query->orWhere('name', $data['name'])
                    ->orWhere('short_name', $data['short_name']);
            })
            ->get();
        if (count($metricUnitsExist) > 0) {
            return response()->json([
                'status' => "Este nombre o símbolo no está disponible",
                'results' => null
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($data, $companyId) {
                    $metric = new MetricUnit();
                    $metric->company_id = $companyId;
                    $metric->name = $data['name'];
                    $metric->short_name = $data['short_name'];
                    $metric->status = 1;
                    $metric->save();
                    return response()->json([
                        "status" => "Unidad de medida creada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "CompanyController: ERROR CREAR UNIDAD, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "CompanyController: ERROR CREAR UNIDAD, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo crear la unidad de medida',
                'results' => null,
            ], 409);
        }
    }

    public function updateMetricUnit(Request $request, $companyId, $metricId)
    {
        $user = $this->authUser;

        $company = Company::find($companyId);
        if ($company == null) {
            return response()->json([
                'status' => 'Esta compañía no existe',
                'results' => null
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'short_name' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos",
                'results' => null
            ], 409);
        }

        $data = $request->all();
        $metricUnitsExist = MetricUnit::where('company_id', $companyId)
            ->where('id', '!=', $metricId)
            ->where(function ($query) use ($data) {
                $query->orWhere('name', $data['name'])
                    ->orWhere('short_name', $data['short_name']);
            })
            ->get();
        if (count($metricUnitsExist) > 0) {
            return response()->json([
                'status' => "Este nombre o símbolo no está disponible",
                'results' => null
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($data, $metricId) {
                    $metric = MetricUnit::find($metricId);
                    $metric->name = $data['name'];
                    $metric->short_name = $data['short_name'];
                    $metric->save();
                    return response()->json([
                        "status" => "Unidad de medida actualizada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "CompanyController: ERROR ACTUALIZAR UNIDAD, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "CompanyController: ERROR ACTUALIZAR UNIDAD, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo actualizar la unidad de medida',
                'results' => null,
            ], 409);
        }
    }

    public function deleteMetricUnit($companyId, $metricId)
    {
        $user = $this->authUser;

        $company = Company::find($companyId);
        if ($company == null) {
            return response()->json([
                'status' => 'Esta compañía no existe',
                'results' => null
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($metricId) {
                    $metric = MetricUnit::find($metricId);
                    $metric->status = 0;
                    $metric->save();
                    $metric->delete();
                    return response()->json([
                        "status" => "Unidad de medida eliminada con éxito!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "CompanyController: ERROR ELIMINAR UNIDAD, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "CompanyController: ERROR ELIMINAR UNIDAD, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo eliminar la unidad de medida',
                'results' => null,
            ], 409);
        }
    }

    public function getCompanyBillingDetails($companyId)
    {
        $company = Company::find($companyId);
        if ($company == null) {
            return response()->json([
                'status' => 'Esta compañía no existe',
                'results' => null
            ], 409);
        }

        $companyElectronicBillingDetails = CompanyElectronicBillingDetail::select(
            'id',
            'company_id',
            'data_for',
            'special_contributor',
            'accounting_needed',
            'business_name',
            'tradename',
            'address'
        )->where([
            "company_id" => $companyId
        ])->first();

        $details = [
            "data_for" => "physical",
            "business_name" => null,
            "tradename" => null,
            "address" => null,
            "special_contributor" => null,
            "accounting_needed" => 0,
        ];
        if ($companyElectronicBillingDetails != null) {
            $details = $companyElectronicBillingDetails;
        }

        $data = [
            "info" => $details,
            "targets" => [
                [
                    "name" => "myPOS",
                    "value" => "physical"
                ],
                [
                    "name" => "Dátil",
                    "value" => "datil"
                ]
            ]
        ];

        return response()->json($data);
    }

    public function updateBillingDetails(Request $request, $companyId)
    {
        $user = $this->authUser;

        $company = Company::find($companyId);
        if ($company == null) {
            return response()->json([
                'status' => 'Esta compañía no existe',
                'results' => null
            ], 409);
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            "data_for" => "required|string",
            "special_contributor" => "string|nullable",
            "accounting_needed" => "required|integer|max:1|nullable",
            "business_name" => "required|string",
            "tradename" => "required|string",
            "address" => "required|string",
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos",
                'results' => null
            ], 409);
        }


        $companyElectronicBillingDetails = CompanyElectronicBillingDetail::where([
            "company_id" => $companyId
        ])->first();

        $data["env_prod"] = $data["data_for"] == "datil" ? 2 : 1;
        $data["special_contributor"] = $data["special_contributor"] == null ? "" : $data["special_contributor"];

        try {
            $operationJSON = DB::transaction(
                function () use ($companyElectronicBillingDetails, $data, $companyId) {
                    if ($companyElectronicBillingDetails != null) {
                        $requestBody = array_merge(
                            $companyElectronicBillingDetails->toArray(),
                            $data
                        );
                        $companyElectronicBillingDetails->update($requestBody);
                    } else {
                        $requestBody = array_merge(
                            ['company_id' => $companyId],
                            $data
                        );
                        $companyElectronicBillingDetail = new CompanyElectronicBillingDetail($requestBody);
                        $companyElectronicBillingDetail->save();
                    }
                    return response()->json([
                        "status" => "Información de facturación electrónica actualizada exitosamente!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "CompanyController: ERROR ACTUALIZAR ELECTRONIC, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "CompanyController: ERROR ACTUALIZAR ELECTRONIC, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo actualizar la información de facturación electrónica',
                'results' => null,
            ], 409);
        }
    }

    public function getCards()
    {
        $cards = Card::all();
        return response()->json($cards);
    }

    public function createCard(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "name" => "string|required",
            "type" => "integer|required",
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => true,
                "errors" => $validator->messages(),
            ]);
        }

        $card = new Card();
        $card->name = $request->get('name');
        $card->type = $request->get('type');
        $card->save();

        return response()->json([
            "status" => true,
            "message" => "The card has been created successfully"
        ]);
    }

    public function updateCard(Request $request, $cardId)
    {
        $validator = Validator::make($request->all(), [
            "name" => "string|required",
            "type" => "integer|required",
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => true,
                "errors" => $validator->messages(),
            ]);
        }

        $card = Card::find($cardId);
        $card->name = $request->get('name');
        $card->type = $request->get('type');
        $card->save();

        return response()->json([
            "status" => true,
            "message" => "The card has been created successfully"
        ]);
    }

    public function deleteCard($cardId)
    {
        $card = Card::find($cardId);
        $card->delete();

        return response()->json([
            "status" => true,
            "message" => "The card has been deleted successfully"
        ]);
    }

    public function assignCardToStore(Request $request)
    {
        $user = $this->authUser;

        $data = $request->all();
        $validator = Validator::make($data, [
            "store_id" => "required",
            "card_id" => "required"
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos",
                'results' => null
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($data) {
                    $store = $data['store_id'];
                    $card = $data['card_id'];
                    $actualCard = CardStore::where('store_id', $store)->where('card_id', $card)->get();

                    if ($actualCard->count() == 0) {
                        $cardStore = new CardStore();
                        $cardStore->store_id =  $store;
                        $cardStore->card_id = $card;
                        $cardStore->save();

                        return response()->json([
                            "status" => "Tarjeta agregada a la tienda exitosamente!",
                            "results" => null,
                        ], 200);
                    } else {
                        return response()->json([
                            "status" => "Esta tarjeta ya se encuentra asignada a la tienda!",
                            "results" => null,
                        ], 409);
                    }
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "CompanyController: ERROR ASIGNAR TARJETA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "CompanyController: ERROR ASIGNAR TARJETA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo asignar la tarjeta a la tienda',
                'results' => null,
            ], 409);
        }
    }

    public function deleteCardStoreAssign(Request $request)
    {
        $user = $this->authUser;

        $data = $request->all();
        $validator = Validator::make($data, [
            "store_id" => "required",
            "card_id" => "required"
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => "Los datos enviados contienen errores como tipos de
                    datos incorrectos o campos obligatorios vacíos",
                'results' => null
            ], 409);
        }

        try {
            $operationJSON = DB::transaction(
                function () use ($data) {
                    $store = $data['store_id'];
                    $card = $data['card_id'];

                    CardStore::where('store_id', $store)->where('card_id', $card)->delete();

                    return response()->json([
                        "status" => "Tarjeta removida de la tienda exitosamente!",
                        "results" => null,
                    ], 200);
                },
                5
            );
            return $operationJSON;
        } catch (\Exception $e) {
            $this->printLogFile(
                "CompanyController: ERROR ELIMINAR TARJETA TIENDA, userId: " . $user->id,
                "daily",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $data
            );
            $slackMessage = "CompanyController: ERROR ELIMINAR TARJETA TIENDA, userId: " . $user->id .
                "Provocado por: " . $data;
            $this->sendSlackMessage(
                $this->channel,
                $slackMessage
            );
            return response()->json([
                'status' => 'No se pudo remover la tarjeta de la tienda',
                'results' => null,
            ], 409);
        }
    }
}
