<?php

namespace App\Traits;

use App\Store;
use Log;

trait FranchiseHelper
{

  public static function isStoreOfFranchiseMaster($storeId, $companyId, $isAdminFranchise)
  {
    return Store::whereHas('company.franchiseOf', function ($query) use ($companyId, $isAdminFranchise) {
      if (!$isAdminFranchise) return;
      $query->where('origin_company_id', $companyId);
    })
      ->orWhere('company_id', $companyId)
      ->pluck('id')
      ->contains($storeId);
  }
}
