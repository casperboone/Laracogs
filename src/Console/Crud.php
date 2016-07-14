<?php

namespace Yab\Laracogs\Console;

use Config;
use Exception;
use Illuminate\Console\AppNamespaceDetectorTrait;
use Illuminate\Console\Command;
use Yab\Laracogs\Generators\CrudGenerator;
use Yab\Laracogs\Generators\DatabaseGenerator;
use Yab\Laracogs\Services\CrudValidator;

class Crud extends Command
{
    use AppNamespaceDetectorTrait;

    /**
     * Column Types.
     *
     * @var array
     */
    public $columnTypes = [
        'bigIncrements',
        'increments',
        'bigInteger',
        'binary',
        'boolean',
        'char',
        'date',
        'dateTime',
        'decimal',
        'double',
        'enum',
        'float',
        'integer',
        'ipAddress',
        'json',
        'jsonb',
        'longText',
        'macAddress',
        'mediumInteger',
        'mediumText',
        'morphs',
        'smallInteger',
        'string',
        'string',
        'text',
        'time',
        'tinyInteger',
        'timestamp',
        'uuid',
    ];

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'laracogs:crud {table}
        {--api : Creates an API Controller and Routes}
        {--apiOnly : Creates only the API Controller and Routes}
        {--ui= : Select one of bootstrap|semantic for the UI}
        {--serviceOnly : Does not generate a Controller or Routes}
        {--withFacade : Creates a facade that can be bound in your app to access the CRUD service}
        {--migration : Generates a migration file}
        {--schema= : Basic schema support ie: id,increments,name:string,parent_id:integer}
        {--relationships= : Define the relationship ie: hasOne|App\Comment|comment,hasOne|App\Rating|rating or relation|class|column (without the _id)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a basic CRUD for a table with options for: Migration, API, UI, Schema and even Relationships';

    /**
     * Generate a CRUD stack.
     *
     * @return mixed
     */
    public function handle()
    {
        $validator = new CrudValidator();
        $section = '';
        $splitTable = [];

        $table = ucfirst(str_singular($this->argument('table')));

        $validator->validateSchema($this);
        $validator->validateOptions($this);

        $config = [
            'bootstrap'                  => false,
            'semantic'                   => false,
            'template_source'            => '',
            '_sectionPrefix_'            => '',
            '_sectionTablePrefix_'       => '',
            '_sectionRoutePrefix_'       => '',
            '_sectionNamespace_'         => '',
            '_path_facade_'              => app_path('Facades'),
            '_path_service_'             => app_path('Services'),
            '_path_repository_'          => app_path('Repositories/_table_'),
            '_path_model_'               => app_path('Repositories/_table_'),
            '_path_controller_'          => app_path('Http/Controllers/'),
            '_path_api_controller_'      => app_path('Http/Controllers/Api'),
            '_path_views_'               => base_path('resources/views'),
            '_path_tests_'               => base_path('tests'),
            '_path_request_'             => app_path('Http/Requests/'),
            '_path_routes_'              => app_path('Http/routes.php'),
            '_path_api_routes_'          => app_path('Http/api-routes.php'),
            'routes_prefix'              => '',
            'routes_suffix'              => '',
            '_app_namespace_'            => $this->getAppNamespace(),
            '_namespace_services_'       => $this->getAppNamespace().'Services',
            '_namespace_facade_'         => $this->getAppNamespace().'Facades',
            '_namespace_repository_'     => $this->getAppNamespace().'Repositories\_table_',
            '_namespace_model_'          => $this->getAppNamespace().'Repositories\_table_',
            '_namespace_controller_'     => $this->getAppNamespace().'Http\Controllers',
            '_namespace_api_controller_' => $this->getAppNamespace().'Http\Controllers\Api',
            '_namespace_request_'        => $this->getAppNamespace().'Http\Requests',
            '_table_name_'               => str_plural(strtolower($table)),
            '_lower_case_'               => strtolower($table),
            '_lower_casePlural_'         => str_plural(strtolower($table)),
            '_camel_case_'               => ucfirst(camel_case($table)),
            '_camel_casePlural_'         => str_plural(camel_case($table)),
            '_ucCamel_casePlural_'       => ucfirst(str_plural(camel_case($table))),
        ];

        if ($this->option('ui')) {
            $config[$this->option('ui')] = true;
        }

        $config['schema'] = $this->option('schema');
        $config['relationships'] = $this->option('relationships');

        $templateDirectory = __DIR__.'/../Templates';

        if (is_dir(base_path('resources/laracogs/crud'))) {
            $templateDirectory = base_path('resources/laracogs/crud');
        }

        $config['template_source'] = Config::get('laracogs.crud.template_source', $templateDirectory);

        if (stristr($table, '_')) {
            $splitTable = explode('_', $table);
            $table = $splitTable[1];
            $section = $splitTable[0];
            $config = $this->configASectionedCRUD($config, $section, $table, $splitTable);
        } else {
            $config = array_merge($config, Config::get('laracogs.crud.single', []));
            $config = $this->setConfig($config, $section, $table);
        }

        $this->createCRUD($config, $section, $table, $splitTable);

        $this->info("\nYou may wish to add this as your testing database:\n");
        $this->comment("'testing' => [ 'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '' ],");
        $this->info("\n".'You now have a working CRUD for '.$table."\n");
    }

    /**
     * Create a CRUD.
     *
     * @param array  $config
     * @param string $section
     * @param string $table
     * @param array  $splitTable
     *
     * @return void
     */
    public function createCRUD($config, $section, $table, $splitTable)
    {
        $bar = $this->output->createProgressBar(7);

        $crudGenerator = new CrudGenerator();
        $dbGenerator = new DatabaseGenerator();

        try {
            $this->generateCore($crudGenerator, $config, $bar);
            $this->generateAppBased($crudGenerator, $config, $bar);

            $crudGenerator->createTests(
                $config,
                $this->option('serviceOnly'),
                $this->option('apiOnly'),
                $this->option('api')
            );
            $bar->advance();

            $crudGenerator->createFactory($config);
            $bar->advance();

            $this->generateAPI($crudGenerator, $config, $bar);
            $bar->advance();

            $this->generateDB($dbGenerator, $bar, $section, $table, $splitTable);
            $bar->finish();

            $this->crudReport($table);
        } catch (Exception $e) {
            throw new Exception('Unable to generate your CRUD: '.$e->getMessage(), 1);
        }
    }

    /**
     * Set the config of the CRUD.
     *
     * @param array  $config
     * @param string $section
     * @param string $table
     * @param array  $splitTable
     *
     * @return array
     */
    public function configASectionedCRUD($config, $section, $table, $splitTable)
    {
        $sectionalConfig = [
            '_sectionPrefix_'            => strtolower($section).'.',
            '_sectionTablePrefix_'       => strtolower($section).'_',
            '_sectionRoutePrefix_'       => strtolower($section).'/',
            '_sectionNamespace_'         => ucfirst($section).'\\',
            '_path_facade_'              => app_path('Facades'),
            '_path_service_'             => app_path('Services'),
            '_path_repository_'          => app_path('Repositories/'.ucfirst($section).'/'.ucfirst($table)),
            '_path_model_'               => app_path('Repositories/'.ucfirst($section).'/'.ucfirst($table)),
            '_path_controller_'          => app_path('Http/Controllers/'.ucfirst($section).'/'),
            '_path_api_controller_'      => app_path('Http/Controllers/Api/'.ucfirst($section).'/'),
            '_path_views_'               => base_path('resources/views/'.strtolower($section)),
            '_path_tests_'               => base_path('tests'),
            '_path_request_'             => app_path('Http/Requests/'.ucfirst($section)),
            '_path_routes_'              => app_path('Http/routes.php'),
            '_path_api_routes_'          => app_path('Http/api-routes.php'),
            'routes_prefix'              => "\n\nRoute::group(['namespace' => '".ucfirst($section)."', 'prefix' => '".strtolower($section)."', 'middleware' => ['web']], function () { \n",
            'routes_suffix'              => "\n});",
            '_app_namespace_'            => $this->getAppNamespace(),
            '_namespace_services_'       => $this->getAppNamespace().'Services\\'.ucfirst($section),
            '_namespace_facade_'         => $this->getAppNamespace().'Facades',
            '_namespace_repository_'     => $this->getAppNamespace().'Repositories\\'.ucfirst($section).'\\'.ucfirst($table),
            '_namespace_model_'          => $this->getAppNamespace().'Repositories\\'.ucfirst($section).'\\'.ucfirst($table),
            '_namespace_controller_'     => $this->getAppNamespace().'Http\Controllers\\'.ucfirst($section),
            '_namespace_api_controller_' => $this->getAppNamespace().'Http\Controllers\Api\\'.ucfirst($section),
            '_namespace_request_'        => $this->getAppNamespace().'Http\Requests\\'.ucfirst($section),
            '_lower_case_'               => strtolower($splitTable[1]),
            '_lower_casePlural_'         => str_plural(strtolower($splitTable[1])),
            '_camel_case_'               => ucfirst(camel_case($splitTable[1])),
            '_camel_casePlural_'         => str_plural(camel_case($splitTable[1])),
            '_ucCamel_casePlural_'       => ucfirst(str_plural(camel_case($splitTable[1]))),
            '_table_name_'               => str_plural(strtolower(implode('_', $splitTable))),
        ];

        $config = array_merge($config, $sectionalConfig);
        $config = array_merge($config, Config::get('laracogs.crud.sectioned', []));
        $config = $this->setConfig($config, $section, $table);

        $pathsToMake = [
            '_path_repository_',
            '_path_model_',
            '_path_controller_',
            '_path_api_controller_',
            '_path_views_',
            '_path_request_',
        ];

        foreach ($config as $key => $value) {
            if (in_array($key, $pathsToMake) && !file_exists($value)) {
                mkdir($value, 0777, true);
            }
        }

        return $config;
    }

    /**
     * Set the config.
     *
     * @param array  $config
     * @param string $section
     * @param string $table
     *
     * @return array
     */
    public function setConfig($config, $section, $table)
    {
        if (!empty($section)) {
            foreach ($config as $key => $value) {
                $config[$key] = str_replace('_table_', ucfirst($table), str_replace('_section_', ucfirst($section), str_replace('_sectionLowerCase_', strtolower($section), $value)));
            }
        } else {
            foreach ($config as $key => $value) {
                $config[$key] = str_replace('_table_', ucfirst($table), $value);
            }
        }

        return $config;
    }

    /**
     * Generate core elements.
     *
     * @param \Yab\Laracogs\Generators\CrudGenerator        $crudGenerator
     * @param array                                         $config
     * @param \Symfony\Component\Console\Helper\ProgressBar $bar
     *
     * @return void
     */
    private function generateCore($crudGenerator, $config, $bar)
    {
        $crudGenerator->createRepository($config);
        $crudGenerator->createRequest($config);
        $crudGenerator->createService($config);
        $bar->advance();
    }

    /**
     * Generate app based elements.
     *
     * @param \Yab\Laracogs\Generators\CrudGenerator        $crudGenerator
     * @param array                                         $config
     * @param \Symfony\Component\Console\Helper\ProgressBar $bar
     *
     * @return void
     */
    private function generateAppBased($crudGenerator, $config, $bar)
    {
        if (!$this->option('serviceOnly') && !$this->option('apiOnly')) {
            $crudGenerator->createController($config);
            $crudGenerator->createViews($config);
            $crudGenerator->createRoutes($config, false);

            if ($this->option('withFacade')) {
                $crudGenerator->createFacade($config);
            }
        }
        $bar->advance();
    }

    /**
     * Generate db elements.
     *
     * @param \Yab\Laracogs\Generators\DatabaseGenerator    $dbGenerator
     * @param \Symfony\Component\Console\Helper\ProgressBar $bar
     * @param string                                        $section
     * @param string                                        $table
     * @param array                                         $splitTable
     *
     * @return void
     */
    private function generateDB($dbGenerator, $bar, $section, $table, $splitTable)
    {
        if ($this->option('migration')) {
            $dbGenerator->createMigration($section, $table, $splitTable);
            if ($this->option('schema')) {
                $dbGenerator->createSchema($section, $table, $splitTable, $this->option('schema'));
            }
        }
        $bar->advance();
    }

    /**
     * Generate api elements.
     *
     * @param \Yab\Laracogs\Generators\CrudGenerator        $crudGenerator
     * @param array                                         $config
     * @param \Symfony\Component\Console\Helper\ProgressBar $bar
     *
     * @return void
     */
    private function generateAPI($crudGenerator, $config, $bar)
    {
        if ($this->option('api') || $this->option('apiOnly')) {
            $crudGenerator->createApi($config);
        }
        $bar->advance();
    }

    /**
     * Generate a CRUD report.
     *
     * @param string $table
     *
     * @return void
     */
    private function crudReport($table)
    {
        $this->line("\n");
        $this->line('Built repository...');
        $this->line('Built request...');
        $this->line('Built service...');

        if (!$this->option('serviceOnly') && !$this->option('apiOnly')) {
            $this->line('Built controller...');
            $this->line('Built views...');
            $this->line('Built routes...');
        }

        if ($this->option('withFacade')) {
            $this->line('Built facade...');
        }

        $this->line('Built tests...');
        $this->line('Added '.$table.' to database/factories/ModelFactory...');

        if ($this->option('api') || $this->option('apiOnly')) {
            $this->line('Built api...');
            $this->comment("\nAdd the following to your app/Providers/RouteServiceProvider.php: \n");
            $this->info("require app_path('Http/api-routes.php'); \n");
        }

        if ($this->option('migration')) {
            $this->line('Built migration...');
            if ($this->option('schema')) {
                $this->line('Built schema...');
            }
        } else {
            $this->info("\nYou will want to create a migration in order to get the $table tests to work correctly.\n");
        }
    }
}
