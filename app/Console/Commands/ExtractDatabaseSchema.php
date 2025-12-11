<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExtractDatabaseSchema extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:schema {--save : Save schema to file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Extract and display the database schema';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Extracting database schema...');

        try {
            // Get all tables
            $tables = $this->getTables();

            if (empty($tables)) {
                $this->error('No tables found in database');
                return;
            }

            $schema = [];
            $schema['database_info'] = $this->getDatabaseInfo();
            $schema['tables'] = [];

            $this->info("Found " . count($tables) . " tables");

            foreach ($tables as $table) {
                $tableName = $table->TABLE_NAME;
                $this->line("Processing table: {$tableName}");

                $schema['tables'][$tableName] = [
                    'columns' => $this->getTableColumns($tableName),
                    'indexes' => $this->getTableIndexes($tableName),
                    'foreign_keys' => $this->getTableForeignKeys($tableName),
                    'primary_key' => $this->getTablePrimaryKey($tableName),
                ];
            }

            // Display or save the schema
            if ($this->option('save')) {
                $this->saveSchema($schema);
            } else {
                $this->displaySchema($schema);
            }

        } catch (\Exception $e) {
            $this->error('Error extracting schema: ' . $e->getMessage());
            $this->error('Make sure the database connection is properly configured.');
        }
    }

    private function getDatabaseInfo()
    {
        try {
            $result = DB::select("SELECT DB_NAME() as database_name, @@VERSION as version");
            return [
                'name' => $result[0]->database_name ?? 'Unknown',
                'version' => $result[0]->version ?? 'Unknown',
                'connection' => config('database.default'),
                'driver' => config('database.connections.' . config('database.default') . '.driver'),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getTables()
    {
        try {
            // For MSSQL, use INFORMATION_SCHEMA.TABLES
            return DB::select("
                SELECT TABLE_NAME, TABLE_TYPE
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_TYPE = 'BASE TABLE'
                ORDER BY TABLE_NAME
            ");
        } catch (\Exception $e) {
            // Fallback for different database systems
            try {
                return DB::select("SHOW TABLES");
            } catch (\Exception $e2) {
                throw new \Exception('Could not retrieve tables: ' . $e->getMessage());
            }
        }
    }

    private function getTableColumns($tableName)
    {
        try {
            // MSSQL INFORMATION_SCHEMA.COLUMNS
            $columns = DB::select("
                SELECT
                    COLUMN_NAME,
                    DATA_TYPE,
                    CHARACTER_MAXIMUM_LENGTH,
                    NUMERIC_PRECISION,
                    NUMERIC_SCALE,
                    IS_NULLABLE,
                    COLUMN_DEFAULT
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION
            ", [$tableName]);

            $result = [];
            foreach ($columns as $col) {
                $result[$col->COLUMN_NAME] = [
                    'type' => $col->DATA_TYPE,
                    'max_length' => $col->CHARACTER_MAXIMUM_LENGTH,
                    'precision' => $col->NUMERIC_PRECISION,
                    'scale' => $col->NUMERIC_SCALE,
                    'nullable' => $col->IS_NULLABLE === 'YES',
                    'default' => $col->COLUMN_DEFAULT,
                ];
            }

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getTableIndexes($tableName)
    {
        try {
            // MSSQL sys.indexes and sys.index_columns
            $indexes = DB::select("
                SELECT
                    i.name as index_name,
                    i.type_desc as index_type,
                    c.name as column_name,
                    ic.key_ordinal as ordinal_position,
                    i.is_unique as is_unique,
                    i.is_primary_key as is_primary_key
                FROM sys.indexes i
                INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
                INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
                INNER JOIN sys.tables t ON i.object_id = t.object_id
                WHERE t.name = ? AND i.type > 0
                ORDER BY i.name, ic.key_ordinal
            ", [$tableName]);

            $result = [];
            foreach ($indexes as $idx) {
                $indexName = $idx->index_name;
                if (!isset($result[$indexName])) {
                    $result[$indexName] = [
                        'type' => $idx->index_type,
                        'unique' => $idx->is_unique,
                        'primary' => $idx->is_primary_key,
                        'columns' => [],
                    ];
                }
                $result[$indexName]['columns'][] = $idx->column_name;
            }

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getTableForeignKeys($tableName)
    {
        try {
            // MSSQL foreign key information
            $fks = DB::select("
                SELECT
                    fk.name as constraint_name,
                    c1.name as column_name,
                    t2.name as referenced_table,
                    c2.name as referenced_column
                FROM sys.foreign_keys fk
                INNER JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
                INNER JOIN sys.tables t1 ON fk.parent_object_id = t1.object_id
                INNER JOIN sys.columns c1 ON fkc.parent_object_id = c1.object_id AND fkc.parent_column_id = c1.column_id
                INNER JOIN sys.tables t2 ON fk.referenced_object_id = t2.object_id
                INNER JOIN sys.columns c2 ON fkc.referenced_object_id = c2.object_id AND fkc.referenced_column_id = c2.column_id
                WHERE t1.name = ?
            ", [$tableName]);

            $result = [];
            foreach ($fks as $fk) {
                $constraintName = $fk->constraint_name;
                if (!isset($result[$constraintName])) {
                    $result[$constraintName] = [
                        'columns' => [],
                        'referenced_table' => $fk->referenced_table,
                        'referenced_columns' => [],
                    ];
                }
                $result[$constraintName]['columns'][] = $fk->column_name;
                $result[$constraintName]['referenced_columns'][] = $fk->referenced_column;
            }

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getTablePrimaryKey($tableName)
    {
        try {
            $pk = DB::select("
                SELECT c.name as column_name
                FROM sys.indexes i
                INNER JOIN sys.index_columns ic ON i.object_id = ic.object_id AND i.index_id = ic.index_id
                INNER JOIN sys.columns c ON ic.object_id = c.object_id AND ic.column_id = c.column_id
                INNER JOIN sys.tables t ON i.object_id = t.object_id
                WHERE t.name = ? AND i.is_primary_key = 1
                ORDER BY ic.key_ordinal
            ", [$tableName]);

            return array_column($pk, 'column_name');
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function displaySchema($schema)
    {
        $this->info("\n" . str_repeat('=', 80));
        $this->info('DATABASE SCHEMA');
        $this->info(str_repeat('=', 80));

        $this->line("Database: {$schema['database_info']['name']}");
        $this->line("Version: {$schema['database_info']['version']}");
        $this->line("Connection: {$schema['database_info']['connection']}");
        $this->line("Driver: {$schema['database_info']['driver']}");

        foreach ($schema['tables'] as $tableName => $tableInfo) {
            $this->info("\n" . str_repeat('-', 60));
            $this->info("TABLE: {$tableName}");
            $this->info(str_repeat('-', 60));

            // Columns
            if (!empty($tableInfo['columns'])) {
                $this->line("COLUMNS:");
                foreach ($tableInfo['columns'] as $colName => $colInfo) {
                    $nullable = $colInfo['nullable'] ? 'NULL' : 'NOT NULL';
                    $default = $colInfo['default'] ? " DEFAULT {$colInfo['default']}" : '';
                    $this->line("  {$colName}: {$colInfo['type']} {$nullable}{$default}");
                }
            }

            // Primary Key
            if (!empty($tableInfo['primary_key'])) {
                $this->line("PRIMARY KEY: " . implode(', ', $tableInfo['primary_key']));
            }

            // Indexes
            if (!empty($tableInfo['indexes'])) {
                $this->line("INDEXES:");
                foreach ($tableInfo['indexes'] as $idxName => $idxInfo) {
                    $unique = $idxInfo['unique'] ? 'UNIQUE ' : '';
                    $type = $idxInfo['type'] ?? 'INDEX';
                    $columns = implode(', ', $idxInfo['columns']);
                    $this->line("  {$idxName}: {$unique}{$type} ({$columns})");
                }
            }

            // Foreign Keys
            if (!empty($tableInfo['foreign_keys'])) {
                $this->line("FOREIGN KEYS:");
                foreach ($tableInfo['foreign_keys'] as $fkName => $fkInfo) {
                    $columns = implode(', ', $fkInfo['columns']);
                    $refColumns = implode(', ', $fkInfo['referenced_columns']);
                    $this->line("  {$fkName}: {$columns} → {$fkInfo['referenced_table']}({$refColumns})");
                }
            }
        }

        $this->info("\n" . str_repeat('=', 80));
        $this->info("Schema extraction complete. Use --save to save to file.");
        $this->info(str_repeat('=', 80));
    }

    private function saveSchema($schema)
    {
        $filename = 'database_schema_' . date('Y-m-d_H-i-s') . '.json';
        $path = storage_path('app/' . $filename);

        file_put_contents($path, json_encode($schema, JSON_PRETTY_PRINT));

        $this->info("Schema saved to: {$path}");

        // Also create a text version
        $textFilename = 'database_schema_' . date('Y-m-d_H-i-s') . '.txt';
        $textPath = storage_path('app/' . $textFilename);

        ob_start();
        $this->displaySchema($schema);
        $textContent = ob_get_clean();

        file_put_contents($textPath, $textContent);
        $this->info("Text version saved to: {$textPath}");
    }
}
