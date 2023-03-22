<?php

namespace App\Jobs\MasterSyncing;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\BadResponseException;
use App\Traits\GacelaIntegration;
use App\CashierBalance;
use App\ExpensesBalance;
use App\PendingSync;
use App\AdminStore;
use Log;

class CloseBalanceInMaster implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $balance;
    public $syncPending;

    //public $tries = 5;
    //public $timeout = 90;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(CashierBalance $balance, PendingSync $syncPending)
    {
        $this->balance = $balance;
        $this->syncPending = $syncPending;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $myposEndpoint = config('app.prod_api') . 'v2/slave/close/balance';
        $client = new \GuzzleHttp\Client();
        try{
            $balancePayload = $this->balance;
            $balancePayload->makeHidden(['id']);
            $balancePayload->makeVisible(['updated_at','employee_id_close','date_close','hour_close','value_close']);
            // $accessToken = config('app.employee_prod_token');
            $expenses = ExpensesBalance::where('cashier_balance_id', $this->balance->id)->get();
            $totalPayload = [
                'balance' => $balancePayload,
                'expenses' => $expenses
            ];
            $adminStore = AdminStore::where('store_id', $this->balance->store_id)->first();
            $accessToken = $adminStore->api_token;
            $headers = [
                'Authorization' => 'Bearer '. $accessToken,
                'Content-Type' => 'application/json'
            ];
            $request = new Request('POST', $myposEndpoint, $headers, json_encode($totalPayload));
            $response = $client->send($request, ['timeout' => 5]);
            Log::info("printing status code");
            Log::info($response->getStatusCode());
            Log::info($response->getBody());
            if($response->getStatusCode() === 200){
                $this->syncPending->delete();
            }
        }catch (ClientException  $e) {
            $response =$e->getResponse()->getStatusCode();
            Log::info("Catching errors Client Error");
            Log::info($response);
            $this->syncPending->tries = $this->syncPending->tries +1;
            $this->syncPending->save();
        }
        catch (ServerException $e) {
            $response =$e->getResponse()->getStatusCode();
            Log::info("Catching errors Server Error");
            Log::info($response);
            $this->syncPending->tries = $this->syncPending->tries +1;
            $this->syncPending->save();
        }
        catch (BadResponseException $e) {
            $response =$e->getResponse()->getStatusCode();
            Log::info("Catching errors Bad Response Error");
            Log::info($response);
            $this->syncPending->tries = $this->syncPending->tries +1;
            $this->syncPending->save();
        }
        catch (RequestException $e){
            $response =$e->getResponse()->getStatusCode();
            Log::info("Catching errors Request Error");
            Log::info($response);
            $this->syncPending->tries = $this->syncPending->tries +1;
            $this->syncPending->save();
        }
    }

}
