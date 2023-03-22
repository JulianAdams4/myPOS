<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * No se borra por las migraciones pasadas que aún lo llaman
 */
class HistoricalInventoryItem extends Model {}