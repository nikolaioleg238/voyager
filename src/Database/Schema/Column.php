<?php

namespace TCG\Voyager\Database\Schema;

use Doctrine\DBAL\Schema\Column as DoctrineColumn;
use Doctrine\DBAL\Types\Type as DoctrineType;
use TCG\Voyager\Database\Types\Type;
use Illuminate\Support\Facades\Log;

abstract class Column
{
    public static function make(array $column, ?string $tableName = null)
    {
        // Ensure custom platform/alias types are registered before any Doctrine lookups
        try {
            Type::registerCustomPlatformTypes();
        } catch (\Throwable $_) {
            // ignore failures: we'll fallback to safe doctrine types below
        }

        $name = Identifier::validate($column['name'], 'Column');

        // Normalize type input to a Doctrine Type instance
        $incomingType = $column['type'] ?? null;

        if ($incomingType instanceof DoctrineType) {
            $doctrineType = $incomingType;
        } else {
            // Determine type name from different possible shapes
            if (is_array($incomingType)) {
                $typeName = $incomingType['name'] ?? ($incomingType['type'] ?? '');
            } elseif (is_string($incomingType)) {
                $typeName = $incomingType;
            } else {
                $typeName = '';
            }

            $typeName = trim((string) $typeName);

            // Extract base type (strip length, unsigned, modifiers, e.g. "integer(11) unsigned" -> "integer")
            if ($typeName !== '') {
                if (preg_match('/^([a-z0-9_]+)/i', $typeName, $m)) {
                    $baseType = strtolower($m[1]);
                } else {
                    $baseType = strtolower($typeName);
                }
            } else {
                $baseType = '';
            }

            // Common alias map to Doctrine type names
            $aliasMap = [
                'int' => 'integer',
                'integer' => 'integer',
                'mediumint' => 'integer',
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

            $normalized = $baseType;
            $doctrineName = $aliasMap[$normalized] ?? $normalized;

            // Try to get Doctrine type, fallback to 'string' if unknown or not registered
            // First prefer a registered type, otherwise fallback quietly to 'string'
            $typeToUse = $doctrineName ?: 'string';

            try {
                // If Doctrine DBAL does not know about this type name, fall back to 'string'
                if (!DoctrineType::hasType($typeToUse)) {
                    try {
                        Log::warning('Voyager: requested doctrine type is not registered, falling back to string', [
                            'requested' => $typeToUse,
                            'normalized' => $normalized,
                        ]);
                    } catch (\Throwable $_) {
                        // ignore logging failures
                    }

                    $typeToUse = 'string';
                }

                $doctrineType = DoctrineType::getType($typeToUse);
            } catch (\Throwable $e) {
                // Log the problematic input so we can inspect what the web request passed
                try {
                    Log::error('Voyager: unknown doctrine type requested', [
                        'input_type' => $incomingType,
                        'normalized' => $normalized,
                        'doctrine_name' => $doctrineName,
                        'exception' => (string) $e->getMessage(),
                    ]);
                } catch (\Throwable $_) {
                    // ignore logging failures
                }

                // As a last resort, use 'string' to avoid crashing when unknown type is provided
                try {
                    $doctrineType = DoctrineType::getType('string');
                } catch (\Throwable $_) {
                    // This is extremely unlikely; rethrow original exception
                    throw $e;
                }
            }
        }

        // Allow the Type implementations to know the table name if needed
        $doctrineType->tableName = $tableName;

        $options = array_diff_key($column, array_flip(['name', 'composite', 'oldName', 'null', 'extra', 'type', 'charset', 'collation']));

        // Ensure web requests do not crash the page - if DoctrineColumn creation fails, log and fallback
        try {
            return new DoctrineColumn($name, $doctrineType, $options);
        } catch (\Throwable $e) {
            try {
                Log::error('Voyager: failed to create Doctrine Column', [
                    'name' => $name,
                    'type' => $column['type'] ?? null,
                    'doctrine_type' => $doctrineType->getName() ?? null,
                    'exception' => (string) $e->getMessage(),
                ]);
            } catch (\Throwable $_) {
                // ignore logging failures
            }

            // fallback to a safe string type column
            $fallbackType = DoctrineType::getType('string');
            return new DoctrineColumn($name, $fallbackType, $options);
        }
    }

    /**
     * @return array
     */
    public static function toArray(DoctrineColumn $column)
    {
        // Start with a minimal array derived from Doctrine column getters to avoid version differences
        $typeObj = $column->getType();

        // Ensure type is represented as an array with a 'name' key
        $typeArr = Type::toArray($typeObj);

        $columnArr = [];
        $columnArr['name'] = $column->getName();
        $columnArr['oldName'] = $columnArr['name'];
        $columnArr['type'] = $typeArr;

        // Doctrine may return length=null for numeric types while precision is set (e.g. mediumint).
        // Prefer explicit length when available; otherwise fall back to precision where sensible.
        $colLength = $column->getLength();
        $colPrecision = $column->getPrecision();
//        if ($colLength === null && is_numeric($colPrecision) && $colPrecision > 0) {
//            $columnArr['length'] = $colPrecision;
//        } else {
//            $columnArr['length'] = $colLength;
//        }
        // Keep length exactly as Doctrine reports it (may be null). Do NOT derive length
        // from precision: precision is a different concept and was causing UI to show
        // values like 10 for mediumint as a "length". Leave length null when not set.
        $columnArr['length'] = $colLength;

        $columnArr['precision'] = $colPrecision;
        $columnArr['scale'] = $column->getScale();


        $columnArr['unsigned'] = (bool) $column->getUnsigned();
        $columnArr['fixed'] = (bool) method_exists($column, 'getFixed') ? (bool) $column->getFixed() : false;
        $columnArr['notnull'] = (bool) $column->getNotnull();
        $columnArr['null'] = $columnArr['notnull'] ? 'NO' : 'YES';
        $columnArr['default'] = $column->getDefault();
        $columnArr['autoincrement'] = (bool) $column->getAutoincrement();
        $columnArr['extra'] = static::getExtra($column);
        $columnArr['composite'] = false;

        return $columnArr;
    }

    /**
     * @return string
     */
    protected static function getExtra(DoctrineColumn $column)
    {
        $extra = '';

        $extra .= $column->getAutoincrement() ? 'auto_increment' : '';
        // todo: Add Extra stuff like mysql 'onUpdate' etc...

        return $extra;
    }
}
