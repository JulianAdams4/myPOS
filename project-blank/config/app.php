<?php

return [

	/*
	|--------------------------------------------------------------------------
	| Application Name
	|--------------------------------------------------------------------------
	|
	| This value is the name of your application. This value is used when the
	| framework needs to place the application's name in a notification or
	| any other location as required by the application or its packages.
	|
	*/

	'name' => env('APP_NAME', 'Laravel'),

	/*
	|--------------------------------------------------------------------------
	| Application Environment
	|--------------------------------------------------------------------------
	|
	| This value determines the "environment" your application is currently
	| running in. This may determine how you prefer to configure various
	| services the application utilizes. Set this in your ".env" file.
	|
	*/

	'env' => env('APP_ENV', 'production'),

	/*
	|--------------------------------------------------------------------------
	| Application Debug Mode
	|--------------------------------------------------------------------------
	|
	| When your application is in debug mode, detailed error messages with
	| stack traces will be shown on every error that occurs within your
	| application. If disabled, a simple generic error page is shown.
	|
	*/

	'debug' => env('APP_DEBUG', false),

	/*
	|--------------------------------------------------------------------------
	| Application URL
	|--------------------------------------------------------------------------
	|
	| This URL is used by the console to properly generate URLs when using
	| the Artisan command line tool. You should set this to the root of
	| your application so that it is used when running Artisan tasks.
	|
	*/

	'url' => env('APP_URL', 'http://localhost:8000'),
	'url_api' => env('APP_API_URL', 'https://...'),

	/*
	|--------------------------------------------------------------------------
	| Application Timezone
	|--------------------------------------------------------------------------
	|
	| Here you may specify the default timezone for your application, which
	| will be used by the PHP date and date-time functions. We have gone
	| ahead and set this to a sensible default for you out of the box.
	|
	*/

	'timezone' => 'America/Guayaquil',
	'default_store_timezone' => 'America/Tegucigalpa',

	/*
	|--------------------------------------------------------------------------
	| Application Locale Configuration
	|--------------------------------------------------------------------------
	|
	| The application locale determines the default locale that will be used
	| by the translation service provider. You are free to set this value
	| to any of the locales which will be supported by the application.
	|
	*/

	'locale' => 'en',

	/*
	|--------------------------------------------------------------------------
	| Application Fallback Locale
	|--------------------------------------------------------------------------
	|
	| The fallback locale determines the locale to use when the current one
	| is not available. You may change the value to correspond to any of
	| the language folders that are provided through your application.
	|
	*/

	'fallback_locale' => 'en',

	/*
	|--------------------------------------------------------------------------
	| Faker Locale
	|--------------------------------------------------------------------------
	|
	| This locale will be used by the Faker PHP library when generating fake
	| data for your database seeds. For example, this will be used to get
	| localized telephone numbers, street address information and more.
	|
	*/

	'faker_locale' => 'en_US',

	/*
	|--------------------------------------------------------------------------
	| Encryption Key
	|--------------------------------------------------------------------------
	|
	| This key is used by the Illuminate encrypter service and should be set
	| to a random, 32 character string, otherwise these encrypted strings
	| will not be safe. Please do this before deploying an application!
	|
	*/

	'key' => env('APP_KEY'),

	'cipher' => 'AES-256-CBC',

	/*
	|--------------------------------------------------------------------------
	| GACELA CONFIGURATIONS
	|--------------------------------------------------------------------------
	|
	*/
	'gacela_api' => env('GACELA_API', 'http://...'),
	'gacela_tere_company_token' => env('GACELA_TERE_COMPANY_TOKEN', '...'),
	'gacela_tere_store_token' => env('GACELA_TERE_STORE_TOKEN', '...'),

	//DATIL integration
	'datil_api' => env('DATIL_API', 'https://...'),

	//Rappi integration
	//DEV MODE
	'rappi_dev_api' => 'http://...',
	//PROD MODE
	//Argentina
	'rappi_prod_api_ar' => 'http://...',
	//Mexico
	'rappi_prod_api_mx' => 'https://...',
	//Colombia
	'rappi_prod_api_co' => 'https://...',
	//Chile
	'rappi_prod_api_cl' => 'http://...',
	//Brasil
	'rappi_prod_api_br' => 'http://...',
	//Uruguay
	'rappi_prod_api_uy' => 'https://...',
	//Ecuador
	'rappi_prod_api_ec' => 'http://...',
	// PERU
	'rappi_prod_api_pe' => 'https://...',

	//Rappi Pay Integration CO
	//PROD
	'rappi_pay_prod_api_co' => 'https://....',
	//DEV
	'rappi_pay_dev_api_co' => 'https://...',

	//Siigo Integration
	'siigo_api' => 'http://...',
	'siigo_namespace' => 'v1',

	//mercado pago
	'mercado_pago_api' => 'https:/...',

	//Rappi Pay Integration MX
	//PROD
	'rappi_pay_prod_api_mx' => 'https://...',
	//DEV
	'rappi_pay_dev_api_mx' => 'https://...',

	//Rappi Pay Kiosko
	//Login PROD
	'rappi_pay_kiosko_login_prod' => 'https://...',
	//Login DEV
	'rappi_pay_kiosko_login_dev' => 'https://...',
	//Requests PROD
	'rappi_pay_kiosko_req_prod' => 'https://...',
	//Requests DEV
	'rappi_pay_kiosko_req_dev' => 'https://...',

	//PUSHER key
	'pusher_key' => env('PUSHER_APP_KEY'),


	//Printers
	'printer_name' => env('PRINTER_NAME', ''),

	//SLAVE
	//if server is slave:
	'slave' => env('MIX_SLAVE_SERVER', false),

	//PROD
	'prod_api' => env('MIX_MASTER_SERVER', 'https://...'),

	//Uber Eats Integration
	'eats_client_id' => env('UBER_EATS_CLIENT_ID', null),
	'eats_client_secret' => env('UBER_EATS_CLIENT_SECRET', null),
	'eats_client_redirect_url' => env('UBER_EATS_REDIRECT_URL', null),
	'eats_login_api' => env('UBER_EATS_LOGIN_URL', 'https://...'),
	'eats_url_api' => env('UBER_EATS_BASE_API_URL', 'https://...'),
	'eats_client_id_v2' => env('UBER_EATS_CLIENT_ID_V2', null),
	'eats_client_secret_v2' => env('UBER_EATS_CLIENT_SECRET_V2', null),

	// Didi Integration
	'didi_url_api' => env('DIDI_BASE_API_URL', 'http://...'),
	'didi_app_id' => env('DIDI_APP_ID', null),
	'didi_app_secret' => env('DIDI_APP_SECRET', null),

	// Aloha Integration
	'aloha_url_api' => env('ALOHA_BASE_API_URL', 'http://...'),

	//Mely Integration
    'mely_user' => env('MELY_USER', null),
    'mely_password' => env('MELY_PASSWORD', null),
    'mely_grant_type' => env('MELY_GRANT_TYPE', null),
    'mely_client_id' => env('MELY_CLIENT_ID', null),
    'mely_client_secret' => env('MELY_CLIENT_SECRET', null),
	'mely_url_api' => env('MELY_BASE_API_URL'),

	//Stocky Integration
    'stocky_user' => env('STOCKY_USER', env('MELY_USER', null)),
    'stocky_password' => env('STOCKY_PASSWORD', env('MELY_PASSWORD', null)),
    'stocky_grant_type' => env('STOCKY_GRANT_TYPE', env('MELY_GRANT_TYPE', null)),
    'stocky_client_id' => env('STOCKY_CLIENT_ID', env('MELY_CLIENT_ID', null)),
    'stocky_client_secret' => env('STOCKY_CLIENT_SECRET', env('MELY_CLIENT_SECRET', null)),
	'stocky_url_api' => env('STOCKY_BASE_API_URL',env('MELY_BASE_API_URL')),

	//Facturama
	'facturama_dev_api' => 'https://...',
	'facturama_prod_api' => 'https://...',

	'facturama_dev_user' => 'xxx',
	'facturama_dev_pass' => '...',
	'facturama_prod_user' => '...',
	'facturama_prod_pass' => 'xxx@xxx',

	// iFood Integration
	'ifood_url_api' => env('IFOOD_BASE_API_URL', 'https://...'),
	'ifood_client_id' => env('IFOOD_CLIENT_ID', null),
	'ifood_client_secret' => env('IFOOD_CLIENT_SECRET', null),
	'ifood_username' => env('IFOOD_USERNAME', null),
	'ifood_password' => env('IFOOD_PASSWORD', null),

	// RabbitMQ
	'rabbitmq_host' => env('RABBITMQ_HOST', 'rabbitmq'),
	'rabbitmq_port' => env('RABBITMQ_PORT', 5672),
	'rabbitmq_username' => env('RABBITMQ_USERNAME', '...'),
	'rabbitmq_password' => env('RABBITMQ_PASSWORD', '...'),
	'rabbitmq_vhost' => env('RABBITMQ_VHOST', '/'),

	//Employee access token
	'employee_prod_token' => "...",

	// Mail recipient during development
	'mail_development' => env('MAIL_DEVELOPMENT', 'xxx@xxx.xxx'),

	'log' => env('APP_LOG', 'daily'),
	'log_level' => env('APP_LOG_LEVEL', 'debug'),

	// Slack
	'slack_env' => env('LOG_SLACK_WEBHOOK_URL', null),
	'slack_base_url_api' => 'https://...',
	'slack_botuser_token' => '...',

	// Log actions
	'log_action_username' => env('LOG_ACTION_USERNAME', ''),
	'log_action_password' => env('LOG_ACTION_PASSWORD', ''),

	/**
	 * Stripe
	 */

	'stripe_secret_token' => env('STRIPE_SECRET_TOKEN', '...'),

	/*
	|--------------------------------------------------------------------------
	| Autoloaded Service Providers
	|--------------------------------------------------------------------------
	|
	| The service providers listed here will be automatically loaded on the
	| request to your application. Feel free to add your own services to
	| this array to grant expanded functionality to your applications.
	|
	*/

	'providers' => [

		/*
		 * Laravel Framework Service Providers...
		 */
		Illuminate\Auth\AuthServiceProvider::class,
		Illuminate\Broadcasting\BroadcastServiceProvider::class,
		Illuminate\Bus\BusServiceProvider::class,
		Illuminate\Cache\CacheServiceProvider::class,
		Illuminate\Foundation\Providers\ConsoleSupportServiceProvider::class,
		Illuminate\Cookie\CookieServiceProvider::class,
		Illuminate\Database\DatabaseServiceProvider::class,
		Illuminate\Encryption\EncryptionServiceProvider::class,
		Illuminate\Filesystem\FilesystemServiceProvider::class,
		Illuminate\Foundation\Providers\FoundationServiceProvider::class,
		Illuminate\Hashing\HashServiceProvider::class,
		Illuminate\Mail\MailServiceProvider::class,
		Illuminate\Notifications\NotificationServiceProvider::class,
		Illuminate\Pagination\PaginationServiceProvider::class,
		Illuminate\Pipeline\PipelineServiceProvider::class,
		Illuminate\Queue\QueueServiceProvider::class,
		Illuminate\Redis\RedisServiceProvider::class,
		Illuminate\Auth\Passwords\PasswordResetServiceProvider::class,
		Illuminate\Session\SessionServiceProvider::class,
		Illuminate\Translation\TranslationServiceProvider::class,
		Illuminate\Validation\ValidationServiceProvider::class,
		Illuminate\View\ViewServiceProvider::class,

		/**
		 *  PDF creation provider
		 */
		Barryvdh\DomPDF\ServiceProvider::class,

		/*
		 * Package Service Providers...
		 */
		Laravel\Socialite\SocialiteServiceProvider::class,
		Laravel\Passport\PassportServiceProvider::class,
		qoraiche\mailEclipse\mailEclipseServiceProvider::class,
		TeamTNT\Scout\TNTSearchScoutServiceProvider::class,
		Laravel\Scout\ScoutServiceProvider::class,

		/*
		 * Application Service Providers...
		 */
		App\Providers\AppServiceProvider::class,
		App\Providers\AuthServiceProvider::class,
		App\Providers\BroadcastServiceProvider::class,
		App\Providers\EventServiceProvider::class,
		App\Providers\RouteServiceProvider::class,
		// App\Providers\PayUServiceProvider::class,
		LaravelFCM\FCMServiceProvider::class,


		/**
	 * PayU Provider
	 */
		// CodemanCompany\LaravelPayU\Providers\PayUServiceProvider::class,

	],

	/*
	|--------------------------------------------------------------------------
	| Class Aliases
	|--------------------------------------------------------------------------
	|
	| This array of class aliases will be registered when this application
	| is started. However, feel free to register as many as you wish as
	| the aliases are "lazy" loaded so they don't hinder performance.
	|
	*/

	'aliases' => [

		'App' => Illuminate\Support\Facades\App::class,
		'Artisan' => Illuminate\Support\Facades\Artisan::class,
		'Auth' => Illuminate\Support\Facades\Auth::class,
		'Blade' => Illuminate\Support\Facades\Blade::class,
		'Broadcast' => Illuminate\Support\Facades\Broadcast::class,
		'Bus' => Illuminate\Support\Facades\Bus::class,
		'Cache' => Illuminate\Support\Facades\Cache::class,
		'Config' => Illuminate\Support\Facades\Config::class,
		'Cookie' => Illuminate\Support\Facades\Cookie::class,
		'Crypt' => Illuminate\Support\Facades\Crypt::class,
		'DB' => Illuminate\Support\Facades\DB::class,
		'Eloquent' => Illuminate\Database\Eloquent\Model::class,
		'Event' => Illuminate\Support\Facades\Event::class,
		'File' => Illuminate\Support\Facades\File::class,
		'Gate' => Illuminate\Support\Facades\Gate::class,
		'Hash' => Illuminate\Support\Facades\Hash::class,
		'Lang' => Illuminate\Support\Facades\Lang::class,
		'Log' => Illuminate\Support\Facades\Log::class,
		'Mail' => Illuminate\Support\Facades\Mail::class,
		'Notification' => Illuminate\Support\Facades\Notification::class,
		'Password' => Illuminate\Support\Facades\Password::class,
		'Queue' => Illuminate\Support\Facades\Queue::class,
		'Redirect' => Illuminate\Support\Facades\Redirect::class,
		'Redis' => Illuminate\Support\Facades\Redis::class,
		'Request' => Illuminate\Support\Facades\Request::class,
		'Response' => Illuminate\Support\Facades\Response::class,
		'Route' => Illuminate\Support\Facades\Route::class,
		'Schema' => Illuminate\Support\Facades\Schema::class,
		'Session' => Illuminate\Support\Facades\Session::class,
		'Storage' => Illuminate\Support\Facades\Storage::class,
		'URL' => Illuminate\Support\Facades\URL::class,
		'Validator' => Illuminate\Support\Facades\Validator::class,
		'View' => Illuminate\Support\Facades\View::class,
		'Socialite' => Laravel\Socialite\Facades\Socialite::class,
		'FCM'      => LaravelFCM\Facades\FCM::class,
		'JWTAuth' => Tymon\JWTAuth\Facades\JWTAuth::class,
		'JWTFactory' => Tymon\JWTAuth\Facades\JWTFactory::class,
		'PDF' => Barryvdh\DomPDF\Facade::class,
	],

];
