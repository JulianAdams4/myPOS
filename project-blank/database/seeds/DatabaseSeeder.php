<?php

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        ini_set('memory_limit', '1G');
        $this->call(RolesTableSeeder::class);
        $this->call(AdministratorTableSeeder::class);
        $this->call(AvailableMyposIntegrationTableSeeder::class);
        $this->call(CountriesTableSeeder::class);
        $this->call(CitiesTableSeeder::class);
        $this->call(CompanyTableSeeder::class);
        $this->call(StoresTableSeeder::class);
        $this->call(StoreTaxesTableSeeder::class);
        $this->call(SectionsTableSeeder::class);
        $this->call(SectionAvailabilityTableSeeder::class);
        $this->call(SectionAvailabilityPeriodsTableSeeder::class);
        $this->call(ProductCategoryTableSeeder::class);
        $this->call(SpecificationCategoriesTableSeeder::class);
        $this->call(ComponentCategoryTableSeeder::class);
        $this->call(CompanyTaxesTableSeeder::class);
        $this->call(CompanyElectronicBillingDetailsTableSeeder::class);
        $this->call(StoreConfigsTableSeeder::class);
        $this->call(AdminStoreTableSeeder::class);
        $this->call(EmployeeTableSeeder::class);
        $this->call(PermissionsTableSeeder::class);
        $this->call(StoreAdminProductionPermission::class);
        $this->call(ProductTableSeeder::class);
        $this->call(SpecificationsTableSeeder::class);
        $this->call(ProductSpecificationsTableSeeder::class);
        $this->call(ProductTaxesTableSeeder::class);
        $this->call(ProductDetailsTableSeeder::class);
        $this->call(InventoryActionSeeder::class);
        $this->call(SpotsTableSeeder::class);
        $this->call(MetricUnitTableSeeder::class);
        $this->call(ComponentTableSeeder::class);
        $this->call(ComponentVariationTableSeeder::class);
        $this->call(ComponentStockTableSeeder::class);
        $this->call(ProductComponentTableSeeder::class);
        $this->call(CardsTableSeeder::class);
        $this->call(CardStoreTableSeeder::class);
        $this->call(IntegrationsCitiesTableSeeder::class);
        $this->call(CashierBalanceNumbersTableSeeder::class);
        $this->call(StoreConfigXZFormatTableSeeder::class);
        $this->call(CitiesEcuadorSeeder::class);
        $this->call(CitiesColombiaSeeder::class);
        $this->call(CitiesMexicoSeeder::class);
        $this->call(PromotionTypesSeeder::class);
    }
}
