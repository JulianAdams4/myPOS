<?php
use Carbon\Carbon;

use App\MetricUnit;
use App\ComponentCategory;
use App\Component;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

class FixComponentUnits extends Migration
{
    /**
     * Run the migrations.
     * @return void
     */
    public function up()
    {
        /**=================================================
         *  Creamos un respaldo de la tabla 'components'
         * =================================================
         *  Nota: Si la migración fue bien, se eliminará
         *        la tabla 'components_backup' manualmente
         ==================================================*/
         if (!Schema::hasTable('components_backup')) {
            Schema::disableForeignKeyConstraints(); // SET FOREIGN_KEY_CHECKS=0;
            DB::statement("CREATE TABLE components_backup LIKE components;");
            DB::statement("INSERT INTO components_backup SELECT * FROM components;");
            Schema::enableForeignKeyConstraints(); // SET FOREIGN_KEY_CHECKS=1;
        }

        DB::transaction(function () {
            // Recorremos las companies porque las unidades estan definidas por cada una de estas
            DB::table('companies')->chunkById(5, function ($companies) {
                foreach ($companies as $company) {
                    $companyId = $company->id;
                    $defaultUnit = MetricUnit::where("short_name", "like", "unidades")->first();
                    if(!$defaultUnit){
                        $defaultUnit = new MetricUnit();
                        $defaultUnit->name       = "Unidades";
                        $defaultUnit->short_name = "unidades";
                        $defaultUnit->company_id = $companyId;
                        $defaultUnit->save();
                    }
                    // Obtenemos las categorias de la company
                    ComponentCategory::where('company_id', $companyId)
                        ->chunkById(10, function ($componentCategories) use ($defaultUnit) {
                            foreach ($componentCategories as $componentCategory) {

                                $startDate = Carbon::createFromDate(2020, 05, 01)->startOfDay(); // ***
                                $endDate   = Carbon::now()->endOfDay();   // ***

                                // Obtenemos los componentes de cada categoría
                                Component::where('component_category_id', $componentCategory->id)
                                    ->whereBetween('created_at', [$startDate, $endDate])       // ***
                                    ->chunkById(50, function ($components) use ($defaultUnit) {
                                        foreach ($components as $componentRecord) {
                                            /**==========================
                                             * Verificamos las unidades
                                             ===========================*/
                                            $c_unit_id = $componentRecord->conversion_metric_unit_id; // Consumo
                                            $unit_id = $componentRecord->metric_unit_id; // Compra
                                            if (!$c_unit_id && !$unit_id) {
                                                $componentRecord->conversion_metric_unit_id = $defaultUnit->id;
                                                $componentRecord->conversion_metric_factor = 1;
                                                $componentRecord->metric_unit_id = $defaultUnit->id;
                                                $componentRecord->metric_unit_factor = 1;
                                            } else if ($c_unit_id && !$unit_id) {
                                                // c_unit_id = Unidad de consumo (No es NULL)
                                                // unit_id   = Unidad de compra (Es NULL)
                                                $componentRecord->metric_unit_id = $componentRecord->conversion_metric_unit_id;
                                            } else if (!$c_unit_id && $unit_id) {
                                                // c_unit_id = Unidad de consumo (es NULL)
                                                // unit_id   = Unidad de compra  (No es NULL)
                                                $componentRecord->conversion_metric_unit_id = $componentRecord->metric_unit_id;
                                            }
                                            /**========================================
                                             * Verificamos los factores de conversion
                                             =========================================*/
                                            $c_factor = $componentRecord->conversion_metric_factor; // Consumo
                                            $u_factor = $componentRecord->metric_unit_factor; // Compra
                                            if (!$c_factor && !$u_factor) {
                                                $componentRecord->conversion_metric_factor = 1;
                                                $componentRecord->metric_unit_factor = 1;
                                            } else if ($c_factor && !$u_factor) {
                                                $componentRecord->metric_unit_factor = $componentRecord->conversion_metric_factor;
                                            } else if (!$c_factor && $u_factor) {
                                                $componentRecord->conversion_metric_factor = $componentRecord->metric_unit_factor;
                                            }
                                            $componentRecord->save();
                                        }
                                    }
                                );
                            }
                        }
                    );
                }
            });
        });
    }

    /**
     * Reverse the migrations.
     * @return void
     */
    public function down()
    {
        /**====================================================
         *  Restauramos la tabla 'components' desde el backup
         =====================================================*/
        if (Schema::hasTable('components_backup')) {
            Schema::disableForeignKeyConstraints(); // SET FOREIGN_KEY_CHECKS=0;
            DB::statement("DROP TABLE components;");
            DB::statement("CREATE TABLE components LIKE components_backup;");
            DB::statement("INSERT INTO components SELECT * FROM components_backup;");
            Schema::enableForeignKeyConstraints(); // SET FOREIGN_KEY_CHECKS=1;
        }
    }
}
