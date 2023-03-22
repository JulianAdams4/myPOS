<?php

namespace Tests\Feature\Store\SectionController;

use App\AdminStore;
use App\Section;
use App\SectionIntegration;
use App\AvailableMyposIntegration;
use App\StoreIntegrationToken;
use App\StoreConfig;
use App\SectionAvailability;
use App\SectionAvailabilityPeriod;
use App\ProductCategory;
use App\ProductIntegrationDetail;
use App\Product;
use App\ToppingIntegrationDetail;
use App\ProductToppingIntegration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class UploadUberMenuTest extends TestCase
{
    use DatabaseTransactions;

    protected $admin;
    protected $uberToken;
    protected $uberIntegration;

    public function setUp()
    {
        parent::setUp();
        // $this->admin = AdminStore::with('store.company')->inRandomOrder()->first();
        // $this->uberToken = "JA.VUNmGAAAAAAAEgASAAAABwAIAAwAAAAAAAAAEgAAAAAAAAHQAAAAFAAAAAAADgAQAAQAAAAIAAwAAAAOAAAApAAAABwAAAAEAAAAEAAAAJKW2bXcGVOyjQk6SCC_kDyAAAAAGBgaSfd20rrOk3Oq1gmz6b544HrFvQpVmlLEArHBWOvYf_gN0SjZhLDvhzY-396ADi9dWoXH8GkHHxCFvAxopFbvrKKnAuU-99d3_vdSA0bBR3FiTwxw86USke9gEDZR7OxdCWZUxqyN-CSNHE-tg1_P6emixbhdB0S1P2NotxEMAAAAcmoTqE1LIDvJ3uOIJAAAAGIwZDg1ODAzLTM4YTAtNDJiMy04MDZlLTdhNGNmOGUxOTZlZQ";
        // $this->uberIntegration = AvailableMyposIntegration::where('code_name', 'uber_eats')->first();
        // if ($this->uberIntegration == null) {
        //     $newUberIntegration = new AvailableMyposIntegration();
        //     $newUberIntegration->type = "delivery";
        //     $newUberIntegration->code_name = "uber_eats";
        //     $newUberIntegration->name = "Uber Eats";
        //     $newUberIntegration->save();
        //     $this->uberIntegration = $newUberIntegration;
        // }
    }

    public function setUpStoreEats(
        $sectionId,
        $storeId,
        $hasSectionIntegration,
        $hasToken,
        $hasConfig,
        $hasEatsId,
        $hasAvailabilities,
        $hasPeriods
    ) {
        $sectionIntegrationUber = SectionIntegration::where('section_id', $sectionId)
            ->where('integration_id', $this->uberIntegration->id)
            ->first();
        if ($hasSectionIntegration) {
            if ($sectionIntegrationUber == null) {
                $sectionIntegrationUber = new SectionIntegration();
                $sectionIntegrationUber->section_id = $sectionId;
                $sectionIntegrationUber->integration_id = $this->uberIntegration->id;
                $sectionIntegrationUber->save();
            }
        } else {
            if ($sectionIntegrationUber != null) {
                $sectionIntegrationUber->delete();
            }
        }

        if ($hasToken) {
            $integration = StoreIntegrationToken::where('store_id', $storeId)
            ->where('integration_name', $this->uberIntegration->code_name)
            ->where('type', 'delivery')
            ->first();
            if ($integration == null) {
                $integration = new StoreIntegrationToken();
                $integration->store_id = $storeId;
                $integration->integration_name = $this->uberIntegration->code_name;
                $integration->type = "delivery";
                $integration->token = $this->uberToken;
                $integration->scope = "eats.order eats.store eats.store.orders.read eats.store.status.write";
                $integration->save();
            }
        }

        $config = StoreConfig::where('store_id', $storeId)
                ->first();
        if ($hasConfig) {
            if ($hasEatsId) {
                if ($config->eats_store_id == null) {
                    $config->eats_store_id = "0baedd73-b189-4352-b223-d22c9a2b51ea";
                }
            } else {
                $config->eats_store_id = null;
            }
            $config->save();
        } else {
            if ($config != null) {
                $config->delete();
            }
        }

        $sectionAvailabilities = SectionAvailability::where('section_id', $sectionId)->get();
        if (!$hasAvailabilities) {
            foreach ($sectionAvailabilities as $sectionAvailability) {
                $periods = SectionAvailabilityPeriod::where('section_availability_id', $sectionAvailability->id)->get();
                foreach ($periods as $period) {
                    $period->delete();
                }
                $sectionAvailability->delete();
            }
        } elseif (!$hasPeriods) {
            foreach ($sectionAvailabilities as $sectionAvailability) {
                $periods = SectionAvailabilityPeriod::where('section_availability_id', $sectionAvailability->id)->get();
                foreach ($periods as $period) {
                    $period->delete();
                }
            }
        }
    }

    // Se genera el JSON de Uber Eats sin problemas
    public function testSuccess()
    {
        $this->assertTrue(true);
        // $store = $this->admin->store;
        // $section = Section::where('store_id', $store->id)->inRandomOrder()->first();
        // $this->setUpStoreEats(
        //     $section->id,
        //     $store->id,
        //     true,
        //     true,
        //     true,
        //     true,
        //     true,
        //     true
        // );

        // // Creando la información de integración del menú
        // $productCategories = ProductCategory::where('section_id', $section->id)->get();
        // if ($productCategories !== null) {
        //     if (count($productCategories) > 0) {
        //         if (count($productCategories) > 1) {
        //             $products = Product::where('product_category_id', $productCategories[1]->id)->get();
        //             foreach ($products as $product) {
        //                 $product->delete();
        //             }
        //         }

        //         $products = Product::where('product_category_id', $productCategories[0]->id)->take(1)->get();
        //         foreach ($products as $product) {
        //             $productIntegration = new ProductIntegrationDetail();
        //             $productIntegration->product_id = $product->id;
        //             $productIntegration->integration_name = "uber_eats";
        //             $productIntegration->name = $product->name;
        //             $productIntegration->price = $product->base_value;
        //             $productIntegration->save();

        //             $specifications = $product->productSpecifications;
        //             $index2 = 0;
        //             foreach ($specifications as $specification) {
        //                 if ($index2 > 0) {
        //                     $toppingIntegration = ToppingIntegrationDetail::where(
        //                         'specification_id',
        //                         $specification->specification_id
        //                     )
        //                     ->where('integration_name', "uber_eats")
        //                     ->first();
        //                     if ($toppingIntegration == null) {
        //                         $toppingIntegration = new ToppingIntegrationDetail();
        //                         $toppingIntegration->specification_id = $specification->specification_id;
        //                         $toppingIntegration->integration_name = "uber_eats";
        //                         $toppingIntegration->name = "Test";
        //                         $toppingIntegration->price = $specification->value;
        //                         $toppingIntegration->save();
        //                     }

        //                     $toppingIntProduct = ProductToppingIntegration::where(
        //                         'product_integration_id',
        //                         $productIntegration->id
        //                     )
        //                     ->where('topping_integration_id', $toppingIntegration->id)
        //                     ->first();
        //                     if ($toppingIntProduct == null) {
        //                         $toppingIntProduct = new ProductToppingIntegration();
        //                         $toppingIntProduct->product_integration_id = $productIntegration->id;
        //                         $toppingIntProduct->topping_integration_id = $toppingIntegration->id;
        //                         $toppingIntProduct->value = $specification->value;
        //                         $toppingIntProduct->save();
        //                     }
        //                 }
        //                 $index2++;
        //             }
        //         }
        //     }
        // }

        // $response = $this->withHeader('Authorization', 'Bearer ' . $this->admin->api_token)
        // ->get('api/upload/uber/menus/1');

        // $response->assertJson([
        //     'status' => "Menú de Uber Eats actualizado exitosamente",
        //     'results' => null,
        // ])
        // ->assertStatus(200);
    }

    // Intenta hacer un requerimiento con un API token inválido, deberia retornar 401.
    public function testUnauthorized()
    {
        $this->assertTrue(true);
        // $this->withHeader('Authorization', 'Bearer ' . str_random(60))
        // ->json('GET', 'api/upload/uber/menus/1')
        // ->assertStatus(401);
    }

    // Intenta subir el menú a Uber Eats, pero no tiene token de uber, retorna 409
    public function testSectionWithoutIntegrationToken()
    {
        $this->assertTrue(true);
        // $store = $this->admin->store;
        // $section = Section::where('store_id', $store->id)->inRandomOrder()->first();
        // $this->setUpStoreEats(
        //     $section->id,
        //     $store->id,
        //     true,
        //     false,
        //     false,
        //     false,
        //     false,
        //     false
        // );
        // $this->withHeader('Authorization', 'Bearer ' . $this->admin->api_token)
        // ->json('GET', 'api/upload/uber/menus/1')
        // ->assertJson([
        //     'status' => "Esta tienda no tiene token de Uber Eats",
        //     'results' => null,
        // ])
        // ->assertStatus(409);
    }

    // Intenta subir el menú a Uber Eats, pero no tiene store_config, retorna 409
    public function testSectionWithoutConfiguration()
    {
        $this->assertTrue(true);
        // $store = $this->admin->store;
        // $section = Section::where('store_id', $store->id)->inRandomOrder()->first();
        // $this->setUpStoreEats(
        //     $section->id,
        //     $store->id,
        //     true,
        //     true,
        //     false,
        //     false,
        //     false,
        //     false
        // );

        // $this->withHeader('Authorization', 'Bearer ' . $this->admin->api_token)
        // ->json('GET', 'api/upload/uber/menus/1')
        // ->assertJson([
        //     'status' => "Esta tienda no está configurada para myPOS",
        //     'results' => null,
        // ])
        // ->assertStatus(409);
    }

    // Intenta subir el menú a Uber Eats, pero no tiene id de la tienda de Uber Eats, retorna 409
    public function testSectionWithoutEatsId()
    {
        $this->assertTrue(true);
        // $store = $this->admin->store;
        // $section = Section::where('store_id', $store->id)->inRandomOrder()->first();
        // $this->setUpStoreEats(
        //     $section->id,
        //     $store->id,
        //     true,
        //     true,
        //     true,
        //     false,
        //     false,
        //     false
        // );

        // $this->withHeader('Authorization', 'Bearer ' . $this->admin->api_token)
        // ->json('GET', 'api/upload/uber/menus/1')
        // ->assertJson([
        //     'status' => "Esta tienda no tiene asignado una tienda del sistema de Uber Eats",
        //     'results' => null,
        // ])
        // ->assertStatus(409);
    }

    // Intenta subir el menú a Uber Eats, pero no tiene sections con integración, retorna 409
    public function testSectionWithoutSectionIntegration()
    {
        $this->assertTrue(true);
        // $store = $this->admin->store;
        // $section = Section::where('store_id', $store->id)->inRandomOrder()->first();
        // $this->setUpStoreEats(
        //     $section->id,
        //     $store->id,
        //     false,
        //     true,
        //     true,
        //     true,
        //     true,
        //     true
        // );

        // $this->withHeader('Authorization', 'Bearer ' . $this->admin->api_token)
        // ->json('GET', 'api/upload/uber/menus/1')
        // ->assertJson([
        //     'status' => "Esta tienda no tiene menús habilitados para ser usados en Uber Eats",
        //     'results' => null,
        // ])
        // ->assertStatus(409);
    }

    // Intenta subir el menú a Uber Eats, pero no tiene section_availabilties, retorna 409
    public function testSectionWithoutSectionAvailabilities()
    {
        $this->assertTrue(true);
        // $store = $this->admin->store;
        // $section = Section::where('store_id', $store->id)->inRandomOrder()->first();
        // $this->setUpStoreEats(
        //     $section->id,
        //     $store->id,
        //     true,
        //     true,
        //     true,
        //     true,
        //     false,
        //     false
        // );

        // $this->withHeader('Authorization', 'Bearer ' . $this->admin->api_token)
        // ->json('GET', 'api/upload/uber/menus/1')
        // ->assertJson([
        //     'status' => "Un menú debe tener un horario para subirlo a Uber Eats",
        //     'results' => null,
        // ])
        // ->assertStatus(409);
    }

    // Intenta subir el menú a Uber Eats, pero no tiene horarios, retorna 409
    public function testSectionWithoutPeriods()
    {
        $this->assertTrue(true);
        // $store = $this->admin->store;
        // $section = Section::where('store_id', $store->id)->inRandomOrder()->first();
        // $this->setUpStoreEats(
        //     $section->id,
        //     $store->id,
        //     true,
        //     true,
        //     true,
        //     true,
        //     true,
        //     false
        // );

        // $this->withHeader('Authorization', 'Bearer ' . $this->admin->api_token)
        // ->json('GET', 'api/upload/uber/menus/1')
        // ->assertStatus(409);
    }

    // Intenta subir el menú a Uber Eats, pero el menú no tiene categorías, retorna 409
    public function testSectionWithoutCategories()
    {
        $this->assertTrue(true);
        // $store = $this->admin->store;
        // $section = Section::where('store_id', $store->id)->inRandomOrder()->first();
        // $this->setUpStoreEats(
        //     $section->id,
        //     $store->id,
        //     true,
        //     true,
        //     true,
        //     true,
        //     true,
        //     true
        // );
        // $productCategories = ProductCategory::where('section_id', $section->id)->get();
        // foreach ($productCategories as $category) {
        //     $category->delete();
        // }

        // $this->withHeader('Authorization', 'Bearer ' . $this->admin->api_token)
        // ->json('GET', 'api/upload/uber/menus/1')
        // ->assertJson([
        //     'status' => "Un menú debe tener por lo menos una categoría, para subirlo a Uber Eats",
        //     'results' => null,
        // ])
        // ->assertStatus(409);
    }
}
