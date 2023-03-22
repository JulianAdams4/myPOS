<?php

namespace App\Http\Controllers\API\JobControllers\Mails;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use Log;
use App\Company;
use App\Mail\SendSalesHIPOSummary;
use Illuminate\Support\Facades\Mail;

class SendSalesHIPOSummaryMailJob extends Controller
{

    public function sendSummaryMails()
    {
        $failed = [];
        $companies = config('app.env') != 'production'
            ? Company::where('id', 4)->get() // Only test company
            : Company::whereNotNull('email')->get(); // All companies
        foreach ($companies as $cp) {
            try {
                Mail::to($cp->email)->send(new SendSalesHIPOSummary($cp));
            } catch (\Exception $e) {
                Log::error("Fallo al enviar mail de la company: ".$e);
                array_push($failed, $cp->name);
            }
        }
        $msg = '';
        $status = null;
        if (count($failed) > 0) {
            $status = 409;
            $msg = 'Error al enviar los mails de las companias: '.implode(', ', $failed);
        } else {
            $status = 200;
            $msg = 'Se enviaron los mails de las compañias';
        }
        return response()->json(['status' => $msg], $status);
    }
}