<?php

namespace App\Jobs\Mails;

use Log;
use App\Company;
use App\Invoice;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use App\Mail\SendSalesHIPOSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendSalesHIPOSummaryMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('SendSalesHIPOSummaryMailJob');
        $companies = Company::whereNotNull('email')->get();

        foreach ($companies as $cp) {
            try {
                Mail::to($cp->email)->send(new SendSalesHIPOSummary($cp));
            } catch (\Exception $e) {
                Log::info('Se capturo el ERROR');
                Log::info($e);
            }
        }
    }
}
