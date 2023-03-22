<?php

namespace App\Traits;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Log;

trait LocalImageHelper
{
    public function storeProductImageOnLocalServer($imageData, $productId)
    {
        $filename = 'producto_' . $productId;
        $imageStr = $imageData;
        $imageStr = str_replace('data:image/png;base64,', '', $imageStr);
        if (empty($imageStr)) {
            return null;
        }
        if (!file_exists(public_path().'/products_images')) {
            mkdir(public_path().'/products_images', 0777, true);
        }
        file_put_contents(public_path().'/products_images/'.$filename.'.jpg', base64_decode($imageStr));
        return $filename;
    }

    public function storeProductCategoryImageOnLocalServer($imageData, $id)
    {
        $filename = 'categoria_' . $id;
        $imageStr = $imageData;
        $imageStr = str_replace('data:image/png;base64,', '', $imageStr);
        if (empty($imageStr)) {
            return null;
        }
        if (!file_exists(public_path().'/product_category_images')) {
            mkdir(public_path().'/product_category_images', 0777, true);
        }
        file_put_contents(public_path().'/product_category_images/'.$filename.'.jpg', base64_decode($imageStr));
        return $filename;
    }

    public function storeBillingFileOnLocalServer($imageData, $unique_filename)
    {
        $imageStr = $imageData;
        $imageStr = str_replace(
            [
                'data:application/pdf;base64,',
                'data:image/png;base64,',
                'data:image/jpeg;base64,'
            ],
            '',
            $imageStr
        );
        if (empty($imageStr)) {
            return null;
        }
        if (!file_exists(public_path().'/billings')) {
            mkdir(public_path().'/billings', 0777, true);
        }
        $billing_fullpath = public_path() . '/billings/' . $unique_filename;
        file_put_contents($billing_fullpath, base64_decode($imageStr));
        return $billing_fullpath;
    }

    public function deleteBillingFileOnLocalServer($ufilename)
    {
        unlink(public_path() . '/billings/' . $ufilename);
    }

    public function deleteImageOnLocalServer($filename, $folder)
    {
        unlink(public_path().$folder.$filename.'.jpg');
    }
}
