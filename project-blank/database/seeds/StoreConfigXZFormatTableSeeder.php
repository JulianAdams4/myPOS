<?php

use App\StoreConfig;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class StoreConfigXZFormatTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $format = '[{"cmd": "PRINT_XZ_HEADER", "payload": {"text": ""}}, {"cmd": "ALIGN", "payload": {"alignment": "CENTER"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "PRINT_TEXT", "payload": {"text": "-----------------------------------"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "ALIGN", "payload": {"alignment": "LEFT"}}, {"cmd": "PRINT_XZ_SUMMARY", "payload": {"text": ""}}, {"cmd": "ALIGN", "payload": {"alignment": "CENTER"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "PRINT_TEXT", "payload": {"text": "-----------------------------------"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "ALIGN", "payload": {"alignment": "LEFT"}}, {"cmd": "PRINT_XZ_PAYMENTS", "payload": {"text": ""}}, {"cmd": "ALIGN", "payload": {"alignment": "CENTER"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "PRINT_TEXT", "payload": {"text": "-----------------------------------"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "ALIGN", "payload": {"alignment": "LEFT"}}, {"cmd": "PRINT_XZ_STATS", "payload": {"text": ""}}, {"cmd": "ALIGN", "payload": {"alignment": "CENTER"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "PRINT_TEXT", "payload": {"text": "-----------------------------------"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "ALIGN", "payload": {"alignment": "LEFT"}}, {"cmd": "PRINT_XZ_TAXES_TYPES", "payload": {"text": ""}}, {"cmd": "ALIGN", "payload": {"alignment": "CENTER"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "PRINT_TEXT", "payload": {"text": "-----------------------------------"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "ALIGN", "payload": {"alignment": "LEFT"}}, {"cmd": "PRINT_XZ_CARD_DETAILS", "payload": {"text": ""}}, {"cmd": "ALIGN", "payload": {"alignment": "CENTER"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "PRINT_TEXT", "payload": {"text": "-----------------------------------"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "ALIGN", "payload": {"alignment": "LEFT"}}, {"cmd": "PRINT_XZ_EMPLOYEE_DETAILS", "payload": {"text": ""}}, {"cmd": "ALIGN", "payload": {"alignment": "CENTER"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "PRINT_TEXT", "payload": {"text": "-----------------------------------"}}, {"cmd": "FEED", "payload": {"lines": 1}}, {"cmd": "ALIGN", "payload": {"alignment": "LEFT"}}, {"cmd": "PRINT_XZ_RAPPI_DETAILS", "payload": {"text": ""}}, {"cmd": "CUT", "payload": {"mode": "FULL"}}]';
        foreach (StoreConfig::all() as $storeConfig) {
            $storeConfig->xz_format = $format;
            $storeConfig->save();
        }
    }
}
