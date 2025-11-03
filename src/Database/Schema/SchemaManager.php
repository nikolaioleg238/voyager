<?php

namespace TCG\Voyager\Database\Schema;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use TCG\Voyager\Database\Types\Type; // ensure Type is available

abstract class SchemaManager
{
    public static function __callStatic($method, $args)
    {
        return static::manager()->$method(...$args);
    }

    public static function manager()
    {
        return DB::connection();
    }

    public static function getDatabaseConnection()
    {
        return DB::connection();
    }

    public static function tableExists($table)
    {
        if (!is_array($table)) {
            $table = [$table];
        }

        return Schema::hasTable($table[0]);
    }

    public static function listTables()
    {
        $tables = [];
        $tableNames = Schema::getConnection()->getSchemaBuilder()->getTables();

        foreach ($tableNames as $tableName) {
            $tables[$tableName] = static::listTableDetails($tableName);
        }

        return $tables;
    }

    public static function listTableDetails($tableName)
    {
        // Ensure Doctrine/DBAL custom platform types are registered before introspection
        try {
            Type::registerCustomPlatformTypes();
        } catch (\Throwable $_) {
            // ignore failures, Column::make also registers defensively
        }

        $columns = Schema::getColumnListing($tableName);
        $columnDetails = collect($columns)->mapWithKeys(function ($column) use ($tableName) {
            return [$column => static::getColumnDetails($tableName, $column)];
        });

        $indexes = static::getTableIndexes($tableName);
        $foreignKeys = static::getTableForeignKeys($tableName);

        // Build Doctrine Column instances expected by Doctrine\DBAL\Schema\Table
        $doctrineColumns = [];
        foreach ($columnDetails as $colName => $colArr) {
            // Normalize to shape expected by Column::make
            $colArr['name'] = $colName;

            // Map Laravel/DB shorthand types to Doctrine types
            $rawType = $colArr['type'];

            // Determine a string representation of the raw type (handle arrays and strings)
            if (is_array($rawType)) {
                $typeName = $rawType['name'] ?? ($rawType['type'] ?? '');
            } elseif (is_string($rawType)) {
                $typeName = $rawType;
            } else {
                $typeName = '';
            }

            $typeName = trim((string) $typeName);

            // Extract the base type token (strip length/precision and modifiers like "unsigned")
            if ($typeName !== '') {
                if (preg_match('/^([a-z0-9_]+)/i', $typeName, $m)) {
                    $baseType = strtolower($m[1]);
                } else {
                    $baseType = strtolower($typeName);
                }
            } else {
                $baseType = '';
            }

            $typeMap = [
                'int' => 'integer',
                'integer' => 'integer',
                'bigint' => 'bigint',
                'smallint' => 'smallint',
                'tinyint' => 'smallint',
                'bool' => 'boolean',
                'boolean' => 'boolean',
                'varchar' => 'string',
                'char' => 'string',
                'string' => 'string',
                'text' => 'text',
                'mediumtext' => 'text',
                'longtext' => 'text',
                'datetime' => 'datetime',
                'timestamp' => 'datetime',
                'datetimetz' => 'datetimetz',
                'date' => 'date',
                'time' => 'time',
                'decimal' => 'decimal',
                'numeric' => 'decimal',
                'float' => 'float',
                'double' => 'float',
                'real' => 'float',
                'json' => 'json',
                'jsonb' => 'json',
                'enum' => 'string',
                'set' => 'string',
                'uuid' => 'guid',
                'guid' => 'guid',
                'binary' => 'binary',
                'blob' => 'blob',
            ];

            $doctrineTypeName = $typeMap[$baseType] ?? $baseType;

            // Map boolean nullable to Doctrine 'notnull'
            if (array_key_exists('nullable', $colArr)) {
                $colArr['notnull'] = !$colArr['nullable'];
                unset($colArr['nullable']);
            }
            // Normalize auto_increment key
            if (array_key_exists('auto_increment', $colArr)) {
                $colArr['autoincrement'] = (bool) $colArr['auto_increment'];
                unset($colArr['auto_increment']);
            }

            // Ensure type is an array with 'name' key that Column::make expects
            $colArr['type'] = ['name' => $doctrineTypeName];

            // Create a Doctrine Column instance via our Column::make helper
            $doctrineColumns[$colName] = Column::make($colArr, $tableName);
        }

        // Convert index arrays into Doctrine Index objects
        $doctrineIndexes = [];
        foreach ($indexes as $indexArr) {
            // Ensure table is provided so Index::make can generate names if needed
            if (!isset($indexArr['table'])) {
                $indexArr['table'] = $tableName;
            }
            $indexObj = Index::make($indexArr);
            $doctrineIndexes[$indexObj->getName()] = $indexObj;
        }

        // Convert foreign key arrays into Doctrine ForeignKeyConstraint objects
        $doctrineForeignKeys = [];
        foreach ($foreignKeys as $fkArr) {
            // Normalize keys from Laravel's schema output to what ForeignKey::make expects
            // Laravel provides: name, columns, foreign_schema, foreign_table, foreign_columns, on_update, on_delete
            if (!is_array($fkArr)) {
                continue; // skip malformed entry
            }

            $normalizedFk = [];

            // keep original name if present
            if (!empty($fkArr['name'])) {
                $normalizedFk['name'] = $fkArr['name'];
            }

            // local columns
            if (!empty($fkArr['columns'])) {
                $normalizedFk['localColumns'] = is_array($fkArr['columns']) ? $fkArr['columns'] : explode(',', $fkArr['columns']);
            } else {
                // if no local columns, skip this FK
                continue;
            }

            // foreign table
            if (!empty($fkArr['foreign_table'])) {
                $normalizedFk['foreignTable'] = $fkArr['foreign_table'];
            } elseif (!empty($fkArr['foreignTable'])) {
                $normalizedFk['foreignTable'] = $fkArr['foreignTable'];
            } else {
                // no foreign table, skip
                continue;
            }

            // foreign columns
            if (!empty($fkArr['foreign_columns'])) {
                $normalizedFk['foreignColumns'] = is_array($fkArr['foreign_columns']) ? $fkArr['foreign_columns'] : explode(',', $fkArr['foreign_columns']);
            } elseif (!empty($fkArr['foreignColumns'])) {
                $normalizedFk['foreignColumns'] = is_array($fkArr['foreignColumns']) ? $fkArr['foreignColumns'] : explode(',', $fkArr['foreignColumns']);
            } else {
                // default to referencing primary key if missing
                $normalizedFk['foreignColumns'] = ['id'];
            }

            // Provide localTable if available
            $normalizedFk['localTable'] = $tableName;

            // Build options from on_update/on_delete
            $options = [];
            if (!empty($fkArr['on_update'])) {
                $options['onUpdate'] = $fkArr['on_update'];
            }
            if (!empty($fkArr['on_delete'])) {
                $options['onDelete'] = $fkArr['on_delete'];
            }
            $normalizedFk['options'] = $options;

            // Now create the ForeignKey object
            try {
                $fkObj = ForeignKey::make($normalizedFk);
                $doctrineForeignKeys[$fkObj->getName()] = $fkObj;
            } catch (\Throwable $e) {
                // Skip invalid foreign key definitions rather than crashing the whole page
                continue;
            }
        }

        return new Table($tableName, $doctrineColumns, $doctrineIndexes, [], $doctrineForeignKeys, []);
    }

    public static function describeTable($tableName)
    {
        $columns = Schema::getColumnListing($tableName);

        return collect($columns)->map(function ($column) use ($tableName) {
            $columnDetails = static::getColumnDetails($tableName, $column);
            $indexes = static::getColumnIndexes($tableName, $column);

            // Normalize index keys so we can safely access numeric offsets (0,1)
            if ($indexes instanceof \Illuminate\Support\Collection) {
                $indexes = $indexes->values()->all();
            } elseif (is_array($indexes)) {
                $indexes = array_values($indexes);
            } else {
                // Fallback: cast to array and reindex
                $indexes = array_values((array) $indexes);
            }

            // If there is a second index (some DBs return multiple entries), keep only that one
            if (!empty($indexes) && isset($indexes[1])) {
                $indexes = [$indexes[1]];
            }

            return [
                'field' => $column,
                'type' => $columnDetails['type'],
                'null' => $columnDetails['nullable'],
                'key' => !empty($indexes) && isset($indexes[0]['type']) ? substr($indexes[0]['type'], 0, 3) : null,
                'default' => $columnDetails['default'],
                'extra' => !empty($columnDetails['auto_increment']) ? 'auto_increment' : '',
                'indexes' => $indexes,
            ];
        });
    }

    // Add alterTable to delegate to Doctrine's schema manager when available
    public static function alterTable($tableDiff)
    {
        // Register custom platform types in case they haven't been registered yet
        try {
            Type::registerCustomPlatformTypes();
        } catch (\Throwable $_) {
            // ignore
        }

        $connection = Schema::getConnection();

        $doctrineManager = null;

        // 1) Common Laravel helper: connection exposes getDoctrineSchemaManager()
        if (method_exists($connection, 'getDoctrineSchemaManager')) {
            try {
                $doctrineManager = $connection->getDoctrineSchemaManager();
            } catch (\Throwable $_) {
                $doctrineManager = null;
            }
        }

        // 2) Some connections expose the underlying Doctrine connection
        if (!$doctrineManager) {
            try {
                if (method_exists($connection, 'getDoctrineConnection')) {
                    $doctrineConn = $connection->getDoctrineConnection();
                } else {
                    $doctrineConn = null;
                }
            } catch (\Throwable $_) {
                $doctrineConn = null;
            }

            if ($doctrineConn) {
                // DBAL 2: getSchemaManager(); DBAL 3+: createSchemaManager()
                if (method_exists($doctrineConn, 'getSchemaManager')) {
                    try {
                        $doctrineManager = $doctrineConn->getSchemaManager();
                    } catch (\Throwable $_) {
                        $doctrineManager = null;
                    }
                } elseif (method_exists($doctrineConn, 'createSchemaManager')) {
                    try {
                        $doctrineManager = $doctrineConn->createSchemaManager();
                    } catch (\Throwable $_) {
                        $doctrineManager = null;
                    }
                } elseif (method_exists($doctrineConn, 'getDoctrineSchemaManager')) {
                    try {
                        $doctrineManager = $doctrineConn->getDoctrineSchemaManager();
                    } catch (\Throwable $_) {
                        $doctrineManager = null;
                    }
                }
            }
        }

        // 3) If still not found, try the connection's schema builder (some drivers wrap this)
        if (!$doctrineManager) {
            try {
                $schemaBuilder = $connection->getSchemaBuilder();
                if (method_exists($schemaBuilder, 'getDoctrineSchemaManager')) {
                    $doctrineManager = $schemaBuilder->getDoctrineSchemaManager();
                }
            } catch (\Throwable $_) {
                // ignore
            }
        }

        // 4) Final fallback: attempt to create a Doctrine DBAL connection from Laravel config
        if (!$doctrineManager) {
            try {
                if (class_exists('\Doctrine\DBAL\DriverManager')) {
                    $connectionName = config('database.default');
                    $cfg = config("database.connections.$connectionName", []);

                    if (!empty($cfg) && is_array($cfg)) {
                        $driver = $cfg['driver'] ?? null;

                        $params = [
                            'dbname' => $cfg['database'] ?? null,
                            'user' => $cfg['username'] ?? null,
                            'password' => $cfg['password'] ?? null,
                            'host' => $cfg['host'] ?? null,
                            'port' => $cfg['port'] ?? null,
                            'charset' => $cfg['charset'] ?? null,
                        ];

                        // map common Laravel driver names to DBAL drivers
                        if ($driver === 'mysql') {
                            $params['driver'] = 'pdo_mysql';
                        } elseif ($driver === 'pgsql') {
                            $params['driver'] = 'pdo_pgsql';
                        } elseif ($driver === 'sqlite') {
                            $params['driver'] = 'pdo_sqlite';
                            $params['path'] = $cfg['database'] ?? null;
                        } elseif ($driver === 'sqlsrv') {
                            $params['driver'] = 'pdo_sqlsrv';
                        } else {
                            $params['driver'] = $driver;
                        }

                        if (!empty($cfg['unix_socket'])) {
                            $params['unix_socket'] = $cfg['unix_socket'];
                        }

                        // Use DriverManager to create a Doctrine DBAL connection
                        $doctrineConn = \Doctrine\DBAL\DriverManager::getConnection($params);

                        if (method_exists($doctrineConn, 'createSchemaManager')) {
                            $doctrineManager = $doctrineConn->createSchemaManager();
                        } elseif (method_exists($doctrineConn, 'getSchemaManager')) {
                            $doctrineManager = $doctrineConn->getSchemaManager();
                        }
                    }
                }
            } catch (\Throwable $_) {
                $doctrineManager = null;
            }
        }

        // Finally, try to call alterTable if available on the doctrine manager
        if ($doctrineManager && method_exists($doctrineManager, 'alterTable')) {
            return $doctrineManager->alterTable($tableDiff);
        }

        // If we reach here, the platform doesn't support alterTable through Doctrine
        // Provide a helpful error message guiding the user to install/enable Doctrine DBAL
        throw new \BadMethodCallException(
            'Unable to obtain a Doctrine SchemaManager to perform alterTable. ' .
            'Ensure you have doctrine/dbal available and that your database connection exposes a Doctrine schema manager (getDoctrineSchemaManager / getDoctrineConnection()->getSchemaManager / createSchemaManager).'
        );
    }

    public static function listTableColumnNames($tableName)
    {
        return Schema::getColumnListing($tableName);
    }

    public static function createTable($table)
    {
        // Accept a Blueprint (Laravel schema builder) or a Doctrine Table (TCG\Voyager Table extends Doctrine Table)
        if ($table instanceof Blueprint) {
            Schema::create($table->getTable(), function (Blueprint $blueprint) use ($table) {
                foreach ($table->getColumns() as $column) {
                    $blueprint->addColumn(
                        $column->getType()->getName(),
                        $column->getName(),
                        // If these are Blueprint column objects they provide a toArray method
                        // which contains the appropriate parameters for addColumn
                        method_exists($column, 'toArray') ? $column->toArray() : []
                    );
                }
            });

            return;
        }

        // If an array or JSON string is provided, try to coerce to our Table representation
        if (is_array($table) || is_string($table)) {
            $table = Table::make($table);
        }

        // Handle Doctrine Table instances (including TCG\Voyager\Database\Schema\Table)
        if ($table instanceof \Doctrine\DBAL\Schema\Table) {
            $tableName = method_exists($table, 'getName') ? $table->getName() : null;
            if (empty($tableName)) {
                throw new \InvalidArgumentException('Unable to determine table name from Doctrine Table instance');
            }

            Schema::create($tableName, function (Blueprint $blueprint) use ($table) {
                foreach ($table->getColumns() as $column) {
                    // Convert Doctrine Column to an options array acceptable by Blueprint->addColumn
                    // Use our Column::toArray helper to produce a structured array
                    try {
                        $colArr = Column::toArray($column);
                    } catch (\Throwable $_) {
                        // Fallback minimal shape
                        $colArr = [
                            'name' => $column->getName(),
                            'length' => $column->getLength(),
                            'precision' => $column->getPrecision(),
                            'scale' => $column->getScale(),
                            'unsigned' => method_exists($column, 'getUnsigned') ? $column->getUnsigned() : false,
                            'notnull' => $column->getNotnull(),
                            'default' => $column->getDefault(),
                            'autoincrement' => $column->getAutoincrement(),
                        ];
                    }

                    // Remove keys that are not valid Blueprint column options
                    foreach (['name', 'oldName', 'type', 'null', 'extra', 'composite'] as $k) {
                        if (array_key_exists($k, $colArr)) {
                            unset($colArr[$k]);
                        }
                    }

                    // Convert Doctrine-style 'notnull' to Blueprint's 'nullable' flag
                    if (array_key_exists('notnull', $colArr)) {
                        $colArr['nullable'] = !((bool) $colArr['notnull']);
                        unset($colArr['notnull']);
                    }

                    // Blueprint expects 'nullable' => true/false, and will omit options with null values.
                    // Remove keys that are null or empty strings to avoid SQL like `varchar()`.
                    foreach ($colArr as $k => $v) {
                        if ($v === null || $v === '') {
                            unset($colArr[$k]);
                        }
                    }

                    // Ensure 'length' is an integer when present; if not, remove it so Blueprint uses defaults
                    if (isset($colArr['length'])) {
                        if (!is_numeric($colArr['length']) || (int) $colArr['length'] <= 0) {
                            unset($colArr['length']);
                        } else {
                            $colArr['length'] = (int) $colArr['length'];
                        }
                    }
                    $blueprint->addColumn(
                        $column->getType()->getName(),
                        $column->getName(),
                        $colArr
                    );
                }
            });

            return;
        }

        throw new \InvalidArgumentException('Table must be an instance of Blueprint or a Doctrine\DBAL\Schema\Table (TCG\\Voyager Table)');
    }

    protected static function getColumnDetails($table, $column)
    {
        $schema = Schema::getConnection()->getSchemaBuilder();
        $columnType = $schema->getColumnType($table, $column);
        $columnDefinition = $schema->getColumns($table);

        $columnInfo = collect($columnDefinition)->firstWhere('name', $column);

        if (!$columnInfo) {
            throw new \InvalidArgumentException("Column '$column' not found in table '$table'.");
        }

        // FIXES:
        // - Do not invert 'nullable' (Laravel's column info uses 'nullable' => true when NULL is allowed)
        // - Expose length/precision/scale/unsigned/charset/collation/comment so Column::make gets these options

        // Try to ensure 'length' is present by probing common fields returned by the schema builder
        $computedLength = $columnInfo['length'] ?? null;

        if ($computedLength === null) {
            // Some schema processors (MySQL) provide type_name or type which may contain the length like "varchar(255)"
            $typeCandidates = [];
            if (!empty($columnInfo['type_name'])) {
                $typeCandidates[] = $columnInfo['type_name'];
            }
            if (!empty($columnInfo['type'])) {
                $typeCandidates[] = $columnInfo['type'];
            }

            foreach ($typeCandidates as $candidate) {
                if (!is_string($candidate)) {
                    continue;
                }
                if (preg_match('/\((\d+)\)/', $candidate, $m)) {
                    $computedLength = (int) $m[1];
                    break;
                }
            }
        }

        return [
            // Prefer the DB-provided type string (which may include length like "varchar(255)")
            // If unavailable, fall back to the normalized column type returned by the schema builder.
            'type' => $columnInfo['type_name'] ?? $columnInfo['type'] ?? $columnType,
             'nullable' => $columnInfo['nullable'] ?? false,
             'default' => $columnInfo['default'] ?? null,
             'auto_increment' => ($columnInfo['auto_increment'] ?? false),
             // Keep length only when the database/schema builder explicitly provides it.
             // Do NOT use precision as a fallback for length; precision is a different concept
             // (numeric precision) and previously caused mediumint/other numeric types to show
             // a length of 10 in the UI when none was set. Leave it null when not specified.
             'length' => $computedLength ?? null,
             'precision' => $columnInfo['precision'] ?? null,
             'scale' => $columnInfo['scale'] ?? null,
             'unsigned' => $columnInfo['unsigned'] ?? false,
             'charset' => $columnInfo['charset'] ?? null,
             'collation' => $columnInfo['collation'] ?? null,
             'comment' => $columnInfo['comment'] ?? null,
         ];
    }

    protected static function getTableIndexes($table)
    {
        return DB::getSchemaBuilder()->getIndexes($table);
    }

    protected static function getColumnIndexes($table, $column)
    {
        $tableIndexes = static::getTableIndexes($table);
        return collect($tableIndexes)->filter(function ($index) use ($column) {
            return in_array($column, $index['columns']);
        });
    }

    protected static function getTableForeignKeys($table)
    {
        return DB::getSchemaBuilder()->getForeignKeys($table);
    }

    public static function listTableNames()
    {
        $connection = Schema::getConnection();

        // 1) Prefer schema builder getTables if available
        try {
            $schemaBuilder = $connection->getSchemaBuilder();
            if (method_exists($schemaBuilder, 'getTables')) {
                $tables = $schemaBuilder->getTables();
                return collect($tables)->pluck('name')->values()->all();
            }
        } catch (\Throwable $_) {
            // ignore and try doctrine fallbacks
        }

        $doctrineManager = null;

        // 2) Try connection->getDoctrineSchemaManager()
        try {
            if (method_exists($connection, 'getDoctrineSchemaManager')) {
                $doctrineManager = $connection->getDoctrineSchemaManager();
            }
        } catch (\Throwable $_) {
            $doctrineManager = null;
        }

        // 3) Try underlying doctrine connection
        if (!$doctrineManager) {
            try {
                if (method_exists($connection, 'getDoctrineConnection')) {
                    $doctrineConn = $connection->getDoctrineConnection();
                } else {
                    $doctrineConn = null;
                }
            } catch (\Throwable $_) {
                $doctrineConn = null;
            }

            if (!empty($doctrineConn)) {
                if (method_exists($doctrineConn, 'createSchemaManager')) {
                    try {
                        $doctrineManager = $doctrineConn->createSchemaManager();
                    } catch (\Throwable $_) {
                        $doctrineManager = null;
                    }
                } elseif (method_exists($doctrineConn, 'getSchemaManager')) {
                    try {
                        $doctrineManager = $doctrineConn->getSchemaManager();
                    } catch (\Throwable $_) {
                        $doctrineManager = null;
                    }
                } elseif (method_exists($doctrineConn, 'getDoctrineSchemaManager')) {
                    try {
                        $doctrineManager = $doctrineConn->getDoctrineSchemaManager();
                    } catch (\Throwable $_) {
                        $doctrineManager = null;
                    }
                }
            }
        }

        // 4) Try schema builder getDoctrineSchemaManager
        if (!$doctrineManager) {
            try {
                $schemaBuilder = $connection->getSchemaBuilder();
                if (method_exists($schemaBuilder, 'getDoctrineSchemaManager')) {
                    $doctrineManager = $schemaBuilder->getDoctrineSchemaManager();
                }
            } catch (\Throwable $_) {
                // ignore
            }
        }

        // 5) DriverManager fallback using config
        if (!$doctrineManager) {
            try {
                if (class_exists('\\Doctrine\\DBAL\\DriverManager')) {
                    $connectionName = config('database.default');
                    $cfg = config("database.connections.$connectionName", []);

                    if (!empty($cfg) && is_array($cfg)) {
                        $driver = $cfg['driver'] ?? null;

                        $params = [
                            'dbname' => $cfg['database'] ?? null,
                            'user' => $cfg['username'] ?? null,
                            'password' => $cfg['password'] ?? null,
                            'host' => $cfg['host'] ?? null,
                            'port' => $cfg['port'] ?? null,
                            'charset' => $cfg['charset'] ?? null,
                        ];

                        if ($driver === 'mysql') {
                            $params['driver'] = 'pdo_mysql';
                        } elseif ($driver === 'pgsql') {
                            $params['driver'] = 'pdo_pgsql';
                        } elseif ($driver === 'sqlite') {
                            $params['driver'] = 'pdo_sqlite';
                            $params['path'] = $cfg['database'] ?? null;
                        } elseif ($driver === 'sqlsrv') {
                            $params['driver'] = 'pdo_sqlsrv';
                        } else {
                            $params['driver'] = $driver;
                        }

                        if (!empty($cfg['unix_socket'])) {
                            $params['unix_socket'] = $cfg['unix_socket'];
                        }

                        $doctrineConn = \Doctrine\DBAL\DriverManager::getConnection($params);

                        if (method_exists($doctrineConn, 'createSchemaManager')) {
                            $doctrineManager = $doctrineConn->createSchemaManager();
                        } elseif (method_exists($doctrineConn, 'getSchemaManager')) {
                            $doctrineManager = $doctrineConn->getSchemaManager();
                        }
                    }
                }
            } catch (\Throwable $_) {
                $doctrineManager = null;
            }
        }

        if ($doctrineManager) {
            try {
                if (method_exists($doctrineManager, 'listTableNames')) {
                    $tables = $doctrineManager->listTableNames();
                } elseif (method_exists($doctrineManager, 'listTables')) {
                    $tables = array_map(function ($t) { return $t->getName(); }, $doctrineManager->listTables());
                } else {
                    $tables = [];
                }

                // Filter out tables that should be excluded (like migrations)
                $excludedTables = ['migrations', 'failed_jobs', 'password_resets'];
                return array_values(array_diff($tables, $excludedTables));
            } catch (\Throwable $_) {
                // fall through to return empty list
            }
        }

        return [];
    }
}
