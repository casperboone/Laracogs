<?php

namespace Yab\Laracogs\Generators;

use Exception;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

/**
 * Generate the CRUD database components.
 */
class DatabaseGenerator
{
    protected $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * Create the migrations.
     *
     * @param string $section
     * @param string $table
     * @param array  $splitTable
     *
     * @return void
     */
    public function createMigration($section, $table, $splitTable)
    {
        try {
            if (!empty($section)) {
                $migrationName = 'create_'.str_plural(strtolower(implode('_', $splitTable))).'_table';
                $tableName = str_plural(strtolower(implode('_', $splitTable)));
            } else {
                $migrationName = 'create_'.str_plural(strtolower($table)).'_table';
                $tableName = str_plural(strtolower($table));
            }

            Artisan::call('make:migration', [
                'name'     => $migrationName,
                '--table'  => $tableName,
                '--create' => true,
            ]);
        } catch (Exception $e) {
            throw new Exception('Could not create the migration', 1);
        }
    }

    /**
     * Create the Schema.
     *
     * @param string $section
     * @param string $table
     * @param array  $splitTable
     *
     * @return void
     */
    public function createSchema($section, $table, $splitTable, $schema)
    {
        $migrationFiles = $this->filesystem->allFiles(base_path('database/migrations'));

        if (!empty($section)) {
            $migrationName = 'create_'.str_plural(strtolower(implode('_', $splitTable))).'_table';
        } else {
            $migrationName = 'create_'.str_plural(strtolower($table)).'_table';
        }

        foreach ($migrationFiles as $file) {
            if (stristr($file->getBasename(), $migrationName)) {
                $migrationData = file_get_contents($file->getPathname());
                $parsedTable = '';

                foreach (explode(',', $schema) as $key => $column) {
                    $columnDefinition = explode(':', $column);
                    if ($key === 0) {
                        $parsedTable .= "\$table->$columnDefinition[1]('$columnDefinition[0]');\n";
                    } else {
                        $parsedTable .= "\t\t\t\$table->$columnDefinition[1]('$columnDefinition[0]');\n";
                    }
                }

                $migrationData = str_replace("\$table->increments('id');", $parsedTable, $migrationData);
                file_put_contents($file->getPathname(), $migrationData);
            }
        }
    }
}
