<?php

namespace App;

use Log;
use App\Order;
use App\Store;
use App\StoreConfig;
use Carbon\Carbon;
use App\CashierBalance;
use Illuminate\Database\Eloquent\Model;

class Helper extends Model
{
    public static $iva = 0.12;

    public static function bankersRounding($cents, $places) {
        return intval(round($cents, $places, PHP_ROUND_HALF_EVEN));
    }

    public static function bankersRoundingUp($cents, $places = 0) {
        return intval(round($cents, $places, PHP_ROUND_HALF_UP));
    }

    public static function getNextOrderIdentifier($storeId) {
        $cashierBalance = CashierBalance::where('store_id', $storeId)
                                    ->whereNull('date_close')
                                    ->first();

        if ($cashierBalance) {
            $maxIdentifier = Order::where('store_id', $storeId)
                            ->where('cashier_balance_id', $cashierBalance->id)
                            ->where('status', 1)
                            ->max('identifier');

            if ($maxIdentifier) {
                return $maxIdentifier + 1;
            }

            return 1;
        }

        return 0;
    }

    public static function randomString($length = 12) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public static function randomLowerLetterString($length = 12) {
        $characters = 'abcdefghijklmnopqrstuvwxyz';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public static function randomUpperLetterString($length = 12) {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public static function randomNumericString($length = 12) {
        $characters = '0123456789';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public static function formattedDate($created_at) {
       $fecha = new Carbon(date("Y-m-d H:i:s", strtotime($created_at)));
            $minutos = $fecha->minute;
            if ($minutos < 10) {
                $minutos = '0'.$minutos;
            }
            $mes = $fecha->month;
            $year = $fecha->year;
            if($mes===1){$mes="ene";}if($mes===2){$mes="feb";}if($mes===3){$mes="mar";}
            if($mes===4){$mes="abr";}if($mes===5){$mes="may";}if($mes===6){$mes="jun";}
            if($mes===7){$mes="jul";}if($mes===8){$mes="ago";}if($mes===9){$mes="sep";}
            if($mes===10){$mes="oct";}if($mes===11){$mes="nov";}if($mes===12){$mes="dic";}
            $hora = $fecha->hour;
            if ($hora > 12) {
                $hora = $hora - 12;
            }
            $am_pm = "";
            if ($fecha->hour > 11) {
                $am_pm = "PM";
            } else {
                $am_pm = "AM";
            }
            $formato = $fecha->day.'/'.$mes.'/'.$year.', '.$hora.':'.$minutos.' '.$am_pm;
            return $formato;
    }

    static function get_locale()
    {
        return env('APP_LOCALE', 'en');
    }

    static function mbstring_binary_safe_encoding($reset = false)
    {
        static $encodings = [];
        static $overloaded = null;
        if (is_null($overloaded)) {
            $overloaded = function_exists('mb_internal_encoding') && (ini_get('mbstring.func_overload') & 2);
        }
        if (false === $overloaded) {
            return;
        }
        if (!$reset) {
            $encoding = mb_internal_encoding();
            array_push($encodings, $encoding);
            mb_internal_encoding('ISO-8859-1');
        }
        if ($reset && $encodings) {
            $encoding = array_pop($encodings);
            mb_internal_encoding($encoding);
        }
    }

    static function reset_mbstring_encoding()
    {
        Helper::mbstring_binary_safe_encoding(true);
    }

    static function seems_utf8($str)
    {
        Helper::mbstring_binary_safe_encoding();
        $length = strlen($str);
        Helper::reset_mbstring_encoding();
        for ($i = 0; $i < $length; $i++) {
            $c = ord($str[$i]);
            if ($c < 0x80) {
                $n = 0;
            }
            elseif (($c & 0xE0) == 0xC0) {
                $n = 1;
            }
            elseif (($c & 0xF0) == 0xE0) {
                $n = 2;
            }
            elseif (($c & 0xF8) == 0xF0) {
                $n = 3;
            }
            elseif (($c & 0xFC) == 0xF8) {
                $n = 4;
            }
            elseif (($c & 0xFE) == 0xFC) {
                $n = 5;
            }
            else {
                return false;
            }
            for ($j = 0; $j < $n; $j++) {
                if ((++$i == $length) || ((ord($str[$i]) & 0xC0) != 0x80)) {
                    return false;
                }
            }
        }
        return true;
    }

    public static function remove_accents($string)
    {
        if (!preg_match('/[\x80-\xff]/', $string)) {
            return strtolower($string);
        }
        if (Helper::seems_utf8($string)) {
            $chars = [
            chr(194).chr(170) => 'a', chr(194).chr(186) => 'o',
            chr(195).chr(128) => 'A', chr(195).chr(129) => 'A',
            chr(195).chr(130) => 'A', chr(195).chr(131) => 'A',
            chr(195).chr(132) => 'A', chr(195).chr(133) => 'A',
            chr(195).chr(134) => 'AE', chr(195).chr(135) => 'C',
            chr(195).chr(136) => 'E', chr(195).chr(137) => 'E',
            chr(195).chr(138) => 'E', chr(195).chr(139) => 'E',
            chr(195).chr(140) => 'I', chr(195).chr(141) => 'I',
            chr(195).chr(142) => 'I', chr(195).chr(143) => 'I',
            chr(195).chr(144) => 'D', chr(195).chr(145) => 'N',
            chr(195).chr(146) => 'O', chr(195).chr(147) => 'O',
            chr(195).chr(148) => 'O', chr(195).chr(149) => 'O',
            chr(195).chr(150) => 'O', chr(195).chr(153) => 'U',
            chr(195).chr(154) => 'U', chr(195).chr(155) => 'U',
            chr(195).chr(156) => 'U', chr(195).chr(157) => 'Y',
            chr(195).chr(158) => 'TH', chr(195).chr(159) => 's',
            chr(195).chr(160) => 'a', chr(195).chr(161) => 'a',
            chr(195).chr(162) => 'a', chr(195).chr(163) => 'a',
            chr(195).chr(164) => 'a', chr(195).chr(165) => 'a',
            chr(195).chr(166) => 'ae', chr(195).chr(167) => 'c',
            chr(195).chr(168) => 'e', chr(195).chr(169) => 'e',
            chr(195).chr(170) => 'e', chr(195).chr(171) => 'e',
            chr(195).chr(172) => 'i', chr(195).chr(173) => 'i',
            chr(195).chr(174) => 'i', chr(195).chr(175) => 'i',
            chr(195).chr(176) => 'd', chr(195).chr(177) => 'n',
            chr(195).chr(178) => 'o', chr(195).chr(179) => 'o',
            chr(195).chr(180) => 'o', chr(195).chr(181) => 'o',
            chr(195).chr(182) => 'o', chr(195).chr(184) => 'o',
            chr(195).chr(185) => 'u', chr(195).chr(186) => 'u',
            chr(195).chr(187) => 'u', chr(195).chr(188) => 'u',
            chr(195).chr(189) => 'y', chr(195).chr(190) => 'th',
            chr(195).chr(191) => 'y', chr(195).chr(152) => 'O',
            chr(196).chr(128) => 'A', chr(196).chr(129) => 'a',
            chr(196).chr(130) => 'A', chr(196).chr(131) => 'a',
            chr(196).chr(132) => 'A', chr(196).chr(133) => 'a',
            chr(196).chr(134) => 'C', chr(196).chr(135) => 'c',
            chr(196).chr(136) => 'C', chr(196).chr(137) => 'c',
            chr(196).chr(138) => 'C', chr(196).chr(139) => 'c',
            chr(196).chr(140) => 'C', chr(196).chr(141) => 'c',
            chr(196).chr(142) => 'D', chr(196).chr(143) => 'd',
            chr(196).chr(144) => 'D', chr(196).chr(145) => 'd',
            chr(196).chr(146) => 'E', chr(196).chr(147) => 'e',
            chr(196).chr(148) => 'E', chr(196).chr(149) => 'e',
            chr(196).chr(150) => 'E', chr(196).chr(151) => 'e',
            chr(196).chr(152) => 'E', chr(196).chr(153) => 'e',
            chr(196).chr(154) => 'E', chr(196).chr(155) => 'e',
            chr(196).chr(156) => 'G', chr(196).chr(157) => 'g',
            chr(196).chr(158) => 'G', chr(196).chr(159) => 'g',
            chr(196).chr(160) => 'G', chr(196).chr(161) => 'g',
            chr(196).chr(162) => 'G', chr(196).chr(163) => 'g',
            chr(196).chr(164) => 'H', chr(196).chr(165) => 'h',
            chr(196).chr(166) => 'H', chr(196).chr(167) => 'h',
            chr(196).chr(168) => 'I', chr(196).chr(169) => 'i',
            chr(196).chr(170) => 'I', chr(196).chr(171) => 'i',
            chr(196).chr(172) => 'I', chr(196).chr(173) => 'i',
            chr(196).chr(174) => 'I', chr(196).chr(175) => 'i',
            chr(196).chr(176) => 'I', chr(196).chr(177) => 'i',
            chr(196).chr(178) => 'IJ', chr(196).chr(179) => 'ij',
            chr(196).chr(180) => 'J', chr(196).chr(181) => 'j',
            chr(196).chr(182) => 'K', chr(196).chr(183) => 'k',
            chr(196).chr(184) => 'k', chr(196).chr(185) => 'L',
            chr(196).chr(186) => 'l', chr(196).chr(187) => 'L',
            chr(196).chr(188) => 'l', chr(196).chr(189) => 'L',
            chr(196).chr(190) => 'l', chr(196).chr(191) => 'L',
            chr(197).chr(128) => 'l', chr(197).chr(129) => 'L',
            chr(197).chr(130) => 'l', chr(197).chr(131) => 'N',
            chr(197).chr(132) => 'n', chr(197).chr(133) => 'N',
            chr(197).chr(134) => 'n', chr(197).chr(135) => 'N',
            chr(197).chr(136) => 'n', chr(197).chr(137) => 'n',
            chr(197).chr(138) => 'N', chr(197).chr(139) => 'n',
            chr(197).chr(140) => 'O', chr(197).chr(141) => 'o',
            chr(197).chr(142) => 'O', chr(197).chr(143) => 'o',
            chr(197).chr(144) => 'O', chr(197).chr(145) => 'o',
            chr(197).chr(146) => 'OE', chr(197).chr(147) => 'oe',
            chr(197).chr(148) => 'R', chr(197).chr(149) => 'r',
            chr(197).chr(150) => 'R', chr(197).chr(151) => 'r',
            chr(197).chr(152) => 'R', chr(197).chr(153) => 'r',
            chr(197).chr(154) => 'S', chr(197).chr(155) => 's',
            chr(197).chr(156) => 'S', chr(197).chr(157) => 's',
            chr(197).chr(158) => 'S', chr(197).chr(159) => 's',
            chr(197).chr(160) => 'S', chr(197).chr(161) => 's',
            chr(197).chr(162) => 'T', chr(197).chr(163) => 't',
            chr(197).chr(164) => 'T', chr(197).chr(165) => 't',
            chr(197).chr(166) => 'T', chr(197).chr(167) => 't',
            chr(197).chr(168) => 'U', chr(197).chr(169) => 'u',
            chr(197).chr(170) => 'U', chr(197).chr(171) => 'u',
            chr(197).chr(172) => 'U', chr(197).chr(173) => 'u',
            chr(197).chr(174) => 'U', chr(197).chr(175) => 'u',
            chr(197).chr(176) => 'U', chr(197).chr(177) => 'u',
            chr(197).chr(178) => 'U', chr(197).chr(179) => 'u',
            chr(197).chr(180) => 'W', chr(197).chr(181) => 'w',
            chr(197).chr(182) => 'Y', chr(197).chr(183) => 'y',
            chr(197).chr(184) => 'Y', chr(197).chr(185) => 'Z',
            chr(197).chr(186) => 'z', chr(197).chr(187) => 'Z',
            chr(197).chr(188) => 'z', chr(197).chr(189) => 'Z',
            chr(197).chr(190) => 'z', chr(197).chr(191) => 's',
            chr(200).chr(152) => 'S', chr(200).chr(153) => 's',
            chr(200).chr(154) => 'T', chr(200).chr(155) => 't',
            chr(226).chr(130).chr(172) => 'E',
            chr(194).chr(163) => '',
            chr(198).chr(160) => 'O', chr(198).chr(161) => 'o',
            chr(198).chr(175) => 'U', chr(198).chr(176) => 'u',
            chr(225).chr(186).chr(166) => 'A', chr(225).chr(186).chr(167) => 'a',
            chr(225).chr(186).chr(176) => 'A', chr(225).chr(186).chr(177) => 'a',
            chr(225).chr(187).chr(128) => 'E', chr(225).chr(187).chr(129) => 'e',
            chr(225).chr(187).chr(146) => 'O', chr(225).chr(187).chr(147) => 'o',
            chr(225).chr(187).chr(156) => 'O', chr(225).chr(187).chr(157) => 'o',
            chr(225).chr(187).chr(170) => 'U', chr(225).chr(187).chr(171) => 'u',
            chr(225).chr(187).chr(178) => 'Y', chr(225).chr(187).chr(179) => 'y',
            chr(225).chr(186).chr(162) => 'A', chr(225).chr(186).chr(163) => 'a',
            chr(225).chr(186).chr(168) => 'A', chr(225).chr(186).chr(169) => 'a',
            chr(225).chr(186).chr(178) => 'A', chr(225).chr(186).chr(179) => 'a',
            chr(225).chr(186).chr(186) => 'E', chr(225).chr(186).chr(187) => 'e',
            chr(225).chr(187).chr(130) => 'E', chr(225).chr(187).chr(131) => 'e',
            chr(225).chr(187).chr(136) => 'I', chr(225).chr(187).chr(137) => 'i',
            chr(225).chr(187).chr(142) => 'O', chr(225).chr(187).chr(143) => 'o',
            chr(225).chr(187).chr(148) => 'O', chr(225).chr(187).chr(149) => 'o',
            chr(225).chr(187).chr(158) => 'O', chr(225).chr(187).chr(159) => 'o',
            chr(225).chr(187).chr(166) => 'U', chr(225).chr(187).chr(167) => 'u',
            chr(225).chr(187).chr(172) => 'U', chr(225).chr(187).chr(173) => 'u',
            chr(225).chr(187).chr(182) => 'Y', chr(225).chr(187).chr(183) => 'y',
            chr(225).chr(186).chr(170) => 'A', chr(225).chr(186).chr(171) => 'a',
            chr(225).chr(186).chr(180) => 'A', chr(225).chr(186).chr(181) => 'a',
            chr(225).chr(186).chr(188) => 'E', chr(225).chr(186).chr(189) => 'e',
            chr(225).chr(187).chr(132) => 'E', chr(225).chr(187).chr(133) => 'e',
            chr(225).chr(187).chr(150) => 'O', chr(225).chr(187).chr(151) => 'o',
            chr(225).chr(187).chr(160) => 'O', chr(225).chr(187).chr(161) => 'o',
            chr(225).chr(187).chr(174) => 'U', chr(225).chr(187).chr(175) => 'u',
            chr(225).chr(187).chr(184) => 'Y', chr(225).chr(187).chr(185) => 'y',
            chr(225).chr(186).chr(164) => 'A', chr(225).chr(186).chr(165) => 'a',
            chr(225).chr(186).chr(174) => 'A', chr(225).chr(186).chr(175) => 'a',
            chr(225).chr(186).chr(190) => 'E', chr(225).chr(186).chr(191) => 'e',
            chr(225).chr(187).chr(144) => 'O', chr(225).chr(187).chr(145) => 'o',
            chr(225).chr(187).chr(154) => 'O', chr(225).chr(187).chr(155) => 'o',
            chr(225).chr(187).chr(168) => 'U', chr(225).chr(187).chr(169) => 'u',
            chr(225).chr(186).chr(160) => 'A', chr(225).chr(186).chr(161) => 'a',
            chr(225).chr(186).chr(172) => 'A', chr(225).chr(186).chr(173) => 'a',
            chr(225).chr(186).chr(182) => 'A', chr(225).chr(186).chr(183) => 'a',
            chr(225).chr(186).chr(184) => 'E', chr(225).chr(186).chr(185) => 'e',
            chr(225).chr(187).chr(134) => 'E', chr(225).chr(187).chr(135) => 'e',
            chr(225).chr(187).chr(138) => 'I', chr(225).chr(187).chr(139) => 'i',
            chr(225).chr(187).chr(140) => 'O', chr(225).chr(187).chr(141) => 'o',
            chr(225).chr(187).chr(152) => 'O', chr(225).chr(187).chr(153) => 'o',
            chr(225).chr(187).chr(162) => 'O', chr(225).chr(187).chr(163) => 'o',
            chr(225).chr(187).chr(164) => 'U', chr(225).chr(187).chr(165) => 'u',
            chr(225).chr(187).chr(176) => 'U', chr(225).chr(187).chr(177) => 'u',
            chr(225).chr(187).chr(180) => 'Y', chr(225).chr(187).chr(181) => 'y',
            chr(201).chr(145) => 'a',
            chr(199).chr(149) => 'U', chr(199).chr(150) => 'u',
            chr(199).chr(151) => 'U', chr(199).chr(152) => 'u',
            chr(199).chr(141) => 'A', chr(199).chr(142) => 'a',
            chr(199).chr(143) => 'I', chr(199).chr(144) => 'i',
            chr(199).chr(145) => 'O', chr(199).chr(146) => 'o',
            chr(199).chr(147) => 'U', chr(199).chr(148) => 'u',
            chr(199).chr(153) => 'U', chr(199).chr(154) => 'u',
            chr(199).chr(155) => 'U', chr(199).chr(156) => 'u',
            ];
            $locale = Helper::get_locale();
            if ('de_DE' == $locale || 'de_DE_formal' == $locale || 'de_CH' == $locale || 'de_CH_informal' == $locale) {
                $chars[chr(195).chr(132)] = 'Ae';
                $chars[chr(195).chr(164)] = 'ae';
                $chars[chr(195).chr(150)] = 'Oe';
                $chars[chr(195).chr(182)] = 'oe';
                $chars[chr(195).chr(156)] = 'Ue';
                $chars[chr(195).chr(188)] = 'ue';
                $chars[chr(195).chr(159)] = 'ss';
            } elseif ('da_DK' === $locale) {
                $chars[chr(195).chr(134)] = 'Ae';
                $chars[chr(195).chr(166)] = 'ae';
                $chars[chr(195).chr(152)] = 'Oe';
                $chars[chr(195).chr(184)] = 'oe';
                $chars[chr(195).chr(133)] = 'Aa';
                $chars[chr(195).chr(165)] = 'aa';
            } elseif ('ca' === $locale) {
                $chars[chr(108).chr(194).chr(183).chr(108)] = 'll';
            }
            $string = strtr($string, $chars);
        } else {
            $chars = [];
            $chars['in'] = chr(128).chr(131).chr(138).chr(142).chr(154).chr(158)
                .chr(159).chr(162).chr(165).chr(181).chr(192).chr(193).chr(194)
                .chr(195).chr(196).chr(197).chr(199).chr(200).chr(201).chr(202)
                .chr(203).chr(204).chr(205).chr(206).chr(207).chr(209).chr(210)
                .chr(211).chr(212).chr(213).chr(214).chr(216).chr(217).chr(218)
                .chr(219).chr(220).chr(221).chr(224).chr(225).chr(226).chr(227)
                .chr(228).chr(229).chr(231).chr(232).chr(233).chr(234).chr(235)
                .chr(236).chr(237).chr(238).chr(239).chr(241).chr(242).chr(243)
                .chr(244).chr(245).chr(246).chr(248).chr(249).chr(250).chr(251)
                .chr(252).chr(253).chr(255);
            $chars['out'] = 'EfSZszYcYuAAAAAACEEEEIIIINOOOOOOUUUUYaaaaaaceeeeiiiinoooooouuuuyy';
            $string = strtr($string, $chars['in'], $chars['out']);
            $double_chars = [];
            $double_chars['in'] = [chr(140), chr(156), chr(198), chr(208), chr(222), chr(223), chr(230), chr(240), chr(254)];
            $double_chars['out'] = ['OE', 'oe', 'AE', 'DH', 'TH', 'ss', 'ae', 'dh', 'th'];
            $string = str_replace($double_chars['in'], $double_chars['out'], $string);
        }
        $punctuation = [".", ",", ";"];
        $string = str_replace($punctuation, "", $string);
        return strtolower($string);
    }

    public static function getValueInCents($value, $country_code = "MX")
    {
        $valueNumber = preg_replace("/[^0-9.,]/", "", $value);
        $explodeChar = $country_code == "MX" ? "." : ",";
        $valueArr = explode($explodeChar, $valueNumber);
        $valueArr[0] = preg_replace("/[^0-9]/", "", $valueArr[0]);
        $totalValue = (int) $valueArr[0] * 100;
        if (count($valueArr) > 1) {
            if (strlen($valueArr[1]) == 1) {
                $totalValue += (int) $valueArr[1] *10;
            } else {
                $totalValue += (int) substr($valueArr[1], 0, 2);
            }
        }
        return $totalValue;
    }

    // public static function groupByCompountKeyUsingPrintStatus($details)
    // {
    //     $invoiceItemsGroup = $details->groupBy('compound_key')->toArray();
    //     $detailsWithQuantity = collect([]);
    //     foreach ($invoiceItemsGroup as $key => $group) {
    //         $detailsGroup = $group;
    //         if ($key !== "") {
    //             $detailWithQuantity = $detailsGroup[0];
    //             foreach (array_slice($detailsGroup, 1) as $detail) {
    //                 $detailWithQuantity["quantity"] += $detail["quantity"];
    //                 $detailWithQuantity["total"] += $detail["total"];
    //                 $detailWithQuantity["base_value"] += $detail["base_value"];
    //                 if (count($detail['process_status']) > 0 && $detailWithQuantity['process_status'] > 0) {
    //                     if ($detail['process_status'][0]['process_status'] === 2) {
    //                         $detailWithQuantity['process_status'][0]['process_status'] = 2;
    //                     }
    //                 }
    //             }
    //             $detailsWithQuantity->push($detailWithQuantity);
    //         } else {
    //             foreach ($detailsGroup as $detail) {
    //                 $detailsWithQuantity->push($detail);
    //             }
    //         }

    //     }
    //     return $detailsWithQuantity;
    // }

    public static function getDetailsUniqueGroupedByCompoundKey($details)
    {
        $invoiceItemsGroup = $details->groupBy('group')->toArray();
        $detailsWithQuantity = collect([]);
        foreach ($invoiceItemsGroup as $key => $group) {
            $detailsGroup = $group;
            if ($key !== "") {
                $detailWithQuantity = $detailsGroup[0];
                foreach (array_slice($detailsGroup, 1) as $detail) {
                    $detailWithQuantity["quantity"] += $detail["quantity"];
                    $detailWithQuantity["total"] += $detail["total"];
                    $detailWithQuantity["base_value"] += $detail["base_value"];
                }
                $detailsWithQuantity->push($detailWithQuantity);
            } else {
                foreach ($detailsGroup as $detail) {
                    $detailsWithQuantity->push($detail);
                }
            }

        }
        return $detailsWithQuantity;
    }

    public static function getNextBillingOfficialNumber($storeId, $save = false)
    {
        $store = Store::where('id', $storeId)->first();
        $number = "";
        if ($store != null
            && !is_null($store->max_sequence)
            && !is_null($store->bill_sequence)
            && !is_null($store->issuance_point)
            && !is_null($store->code)
        ) {
            $maxSequence = (string) $store->max_sequence;
            $actualSequence = (string) $store->bill_sequence;
            $diffLength = strlen($maxSequence) - strlen($actualSequence);
            for ($i = 0; $i < $diffLength; $i++) {
                $actualSequence = "0" . $actualSequence;
            }
            // Para Ecuador y Colombia se maneja el numero de factura como codigo
            if ($save && $store->country_code != "EC" && $store->country_code != "CO") {
                $number = $actualSequence;
            } else {
            // Para los demas paises, se maneja como un secuencial simple
                $number = $store->issuance_point . $store->code . $actualSequence;
            }

            // Se mantiene sin guardar para Ecuador por Datil (luego descuadra las facturas)
            if ($save && $store->country_code != "EC") {
                $store->bill_sequence = $store->bill_sequence + 1;
                $store->save();
            } elseif ($save && $store->id == 6) {
                $number = $actualSequence;
                $store->bill_sequence = $store->bill_sequence + 1;
                $store->save();
            }
        }
        if ($number == "" && $store->country_code == "CO") {
            $code = !is_null($store->code) ? $store->code : "";
            $point = !is_null($store->issuance_point) ? $store->issuance_point : "";
            $actualSequence = "";
            if (!is_null($store->max_sequence)) {
                $maxSequence = (string) $store->max_sequence;
                $actualSequence = (string) $store->bill_sequence;
                $diffLength = strlen($maxSequence) - strlen($actualSequence);
                for ($i = 0; $i < $diffLength; $i++) {
                    $actualSequence = "0" . $actualSequence;
                }
            } else if (!is_null($store->bill_sequence)) {
                $actualSequence = $store->bill_sequence + 1;
            }
            $number = $code . $point . $actualSequence;
            if ($number == "") {
                $number = "NO_APLICA";
            }

        }
        return $number;
    }

    /// alternativeSwitch: manejara si se usa el alternate o el official bill sequence
    //// donde true es alternate y false es official
    public static function getAlternatingBillingNumber($storeId, $alternateSwitch)
    {
        $store = Store::find($storeId);
        $number = "";
        if ($store != null) {
            $config = $store->configs;
            if ($config->alternate_bill_sequence > 0) {
                if ($alternateSwitch) {
                    $number = $config->alternate_bill_sequence;
                    $config->alternate_bill_sequence = $config->alternate_bill_sequence + 1;
                    $config->save();
                } else {
                    $number = $store->bill_sequence;
                    $store->bill_sequence = $store->bill_sequence + 1;
                    $store->save();
                }
            }
        }
        return $number;
    }

    public static function getNextCreditNoteNumber($companyId)
    {
        $lastCreditNote = CreditNote::where('company_id', $companyId)->latest()->first();
        if (!$lastCreditNote) {
            return 1;
        }
        $currentSequence = $lastCreditNote->credit_sequence;
        return $currentSequence + 1;
    }

    public static function checkTaxType($neededCountry, $neededTax, $neededCode, $neededType){
        foreach($neededCountry as $typeTax){
            /*Verificamos si es un impuesto del tipo solicitado */
            if($typeTax['tax_type'] !== $neededType){
                continue;
            }
               
            /*Verificamos si el impuesto es el solicitado, por medio de su código y nombre*/
            if ($typeTax['tax_code'] === $neededCode && $typeTax['tax_name'] === $neededTax) {
                return true;
            }
        }
        return false;
    }

    public static function getTaxCodeByName($neededCountry, $neededTax){
        foreach($neededCountry as $typeTax){
            /*Devolvemos el tax_code cuando lo encontremos por medio del nombre solicitado*/
            if ($typeTax['tax_name'] === $neededTax) {
                return $typeTax['tax_code'];
            }
        }
        return false;
    }

    public static function getOrderSpecifications(Order $order){

        $newOrders = [];
        $newOrderDetails = collect([]);
        foreach ($order->orderDetails as $storedOrderDetail) {
            $storedOrderDetail->append('spec_fields');
            $newOrderDetails->push($storedOrderDetail);
        }
        
        $specifications = [];
        foreach ($newOrderDetails as $orderDetail) {
            array_push(
                $specifications,
                [
                    "id" => $orderDetail['id'],
                    "product" => $orderDetail['invoice_name'],
                    "quantity" => $orderDetail['quantity'],
                    "instructions" => $orderDetail['spec_fields']['instructions']
                ]
            );
        }

        return $specifications;
    }

    public static function carbon($country, $dateHour = null){
        $timeZone = 'America/Mexico_City';
        switch ($country) {
            case 'MX':
                $timeZone = 'America/Mexico_City';
            break;

            case 'CO':
                $timeZone = 'America/Bogota';
            break;
        }

        if(empty($dateHour)){
            $carbon = Carbon::now()->setTimezone($timeZone);
        }else{
            $carbon = Carbon::parse($dateHour)->setTimezone($timeZone);
        }

        return $carbon;
    }

}
