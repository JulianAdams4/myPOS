<?php

namespace App\Traits;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Log;

trait AWSHelper
{
    public function uploadFileToS3(Request $request, $storeId)
    {
        if (!$request->hasFile('image')) {
            throw new Exception('No existe imagen en el request');
        }

        if ($storeId == null) {
            throw new Exception('Id del store no está definido');
        }

        $file = $request->file('image');
        $filePath = 'store-' . $storeId . '/' . 'images/' . $file->getClientOriginalName();

        Storage::disk('s3')->put($filePath, file_get_contents($file));
    }

    public function uploadLocalFileToS3($path, $storeId, $name, $actualVersion, $subfolderName = null)
    {
        if ($path == null || $path == "") {
            throw new Exception('No existe esta imagen');
        }

        if ($storeId == null) {
            throw new Exception('Id del store no está definido');
        }

        $subfolder = $subfolderName ?: 'images';
        $filePath = 'store-' . $storeId . '/' . $subfolder . '/' . $name;
        if (!$subfolderName) {
            if (Storage::disk('s3')->exists($filePath . '_' . $actualVersion)) {
                Storage::disk('s3')->delete($filePath . '_' . $actualVersion);
            }
            $nextVersion = $actualVersion + 1;
            Storage::disk('s3')->put($filePath . '_' . $nextVersion, file_get_contents($path));
        } else {
            if (Storage::disk('s3')->exists($filePath)) {
                Storage::disk('s3')->delete($filePath);
            }
            Storage::disk('s3')->put($filePath, file_get_contents($path));
        }
    }

    public function saveProductImageAWSUrlDB($product, $filename, $storeId)
    {
        $nextVersion = $product->image_version + 1;
        $product->image = 'https://...'.$storeId.'/images/'.$filename.'_'.$nextVersion;
        $product->image_version = $nextVersion;
        $product->save();
    }

    public function saveProductCategoryImageAWSUrlDB($productCategory, $filename, $storeId)
    {
        $nextVersion = $productCategory->image_version + 1;
        $productCategory->image = 'https://...'.$storeId.'/images/'.$filename.'_'.$nextVersion;
        $productCategory->image_version = $nextVersion;
        $productCategory->save();
    }

    public function saveInvoiceProviderFileAWSUrlDB($invoiceProvider, $storeId, $subfolder, $ufilename, $mimetype)
    {
        $invoiceProvider->file_url = 'https://...'.$storeId.'/'.$subfolder.'/'.$ufilename;
        $invoiceProvider->file_type = $mimetype;
        $invoiceProvider->save();
    }

    public function deleteFileFromS3($productCategory, $storeId, $name)
    {
        if ($storeId == null) {
            throw new Exception('Id del store no está definido');
        }
        $actualVersion = $productCategory->image_version;
        $filePath = 'store-' . $storeId . '/' . 'images/' . $name;
        if (Storage::disk('s3')->exists($filePath . '_' . $actualVersion)) {
            Storage::disk('s3')->delete($filePath . '_' . $actualVersion);
        }
        $productCategory->image = null;
        $productCategory->image_version = 0;
        $productCategory->save();
    }
}
