<?php

namespace TCG\Voyager\Database\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform as DoctrineAbstractPlatform;
use Doctrine\DBAL\Types\Type as DoctrineType;
use TCG\Voyager\Database\Platforms\Platform;
use TCG\Voyager\Database\Schema\SchemaManager;

abstract class Type extends DoctrineType
{
    protected static $customTypesRegistered = false;
    protected static $platformTypeMapping = [];
    protected static $allTypes = [];
    protected static $platformTypes = [];
    protected static $customTypeOptions = [];
    protected static $typeCategories = [];
    protected static $registeredTypes = [];

    // Shared default DB->Doctrine type mapping used as a fallback in multiple places
    protected static $defaultTypeMap = [
        'int' => 'integer',
        'integer' => 'integer',
        'tinyint' => 'smallint',
        'smallint' => 'smallint',
        'mediumint' => 'integer',
        'bigint' => 'bigint',
        'varchar' => 'string',
        'char' => 'string',
        'text' => 'text',
        'mediumtext' => 'text',
        'longtext' => 'text',
        'blob' => 'blob',
        'decimal' => 'decimal',
        'numeric' => 'decimal',
        'float' => 'float',
        'double' => 'float',
        'real' => 'float',
        'timestamp' => 'datetime',
        'datetime' => 'datetime',
        'date' => 'date',
        'time' => 'time',
        'json' => 'json',
    ];

    public const NAME = 'UNDEFINED_TYPE_NAME';
    public const NOT_SUPPORTED = 'notSupported';
    public const NOT_SUPPORT_INDEX = 'notSupportIndex';

    // todo: make sure this is not overwrting DoctrineType properties

    // Note: length, precision and scale need default values manually

    public function getName()
    {
        return static::NAME;
    }

    public static function toArray(DoctrineType $type)
    {
        $customTypeOptions = $type->customOptions ?? [];

        $arr = array_merge([
            'name' => $type->getName(),
        ], $customTypeOptions);

        // Ensure a 'category' key exists so groupBy('category') doesn't produce an empty-string key.
        // Prefer existing option if provided. Otherwise, attempt to infer category from known type categories.
        if (!array_key_exists('category', $arr) || $arr['category'] === null || $arr['category'] === '') {
            // Build mapping of lowercase type name => category label
            try {
                $categories = static::getTypeCategories();
            } catch (\Throwable $_) {
                $categories = [];
            }

            $inferred = '';
            $typeName = strtolower($arr['name']);

            foreach ($categories as $label => $list) {
                foreach ($list as $candidate) {
                    if (strtolower($candidate) === $typeName) {
                        // Convert category key to human readable label (Numbers -> Numbers, datetime -> Date and Time)
                        switch ($label) {
                            case 'numbers':
                                $inferred = 'Numbers';
                                break;
                            case 'strings':
                                $inferred = 'Strings';
                                break;
                            case 'datetime':
                                $inferred = 'Date and Time';
                                break;
                            case 'lists':
                                $inferred = 'Lists';
                                break;
                            case 'binary':
                                $inferred = 'Binary';
                                break;
                            case 'network':
                                $inferred = 'Network';
                                break;
                            case 'geometry':
                                $inferred = 'Geometry';
                                break;
                            case 'objects':
                                $inferred = 'Objects';
                                break;
                            default:
                                $inferred = ucfirst($label);
                                break;
                        }
                        break 2;
                    }
                }
            }

            // Default to empty string if we couldn't infer
            $arr['category'] = $inferred;
        }

        return $arr;
    }

    public static function getPlatformTypes()
    {
        if (static::$platformTypes) {
            return static::$platformTypes;
        }

        if (!static::$customTypesRegistered) {
            static::registerCustomPlatformTypes();
        }

        // Get the platform driver name (mysql, pgsql, sqlite, etc.)
        $platform = SchemaManager::getDatabaseConnection()->getDriverName();

        // Obtain the Doctrine platform instance to build the doctrine type mapping
        // and pass it to getPlatformTypeMapping which expects a Doctrine platform object.
        $doctrineConnection = null;
        try {
            $doctrineConnection = SchemaManager::getDatabaseConnection()->getDoctrineConnection();
        } catch (\Throwable $e) {
            // Fallback: Some Laravel connections may expose getDoctrineSchemaManager instead
            $doctrineConnection = null;
        }

        $doctrinePlatform = null;
        if ($doctrineConnection) {
            $doctrinePlatform = $doctrineConnection->getDatabasePlatform();
        } elseif (method_exists(SchemaManager::getDatabaseConnection(), 'getDoctrineSchemaManager')) {
            $doctrinePlatform = SchemaManager::getDatabaseConnection()->getDoctrineSchemaManager()->getDatabasePlatform();
        }

        // If for some reason we could not obtain the Doctrine platform, pass null to keep
        // existing behavior, but prefer to pass the actual platform when available.
        $typeMappingArg = $doctrinePlatform ?? null;

        // Register common DB -> Doctrine type mappings on the Doctrine platform to
        // avoid DBAL being called with raw DB type names like 'int' or 'timestamp'.
        if ($doctrinePlatform !== null) {
            try {
                $map = static::$defaultTypeMap;

                if (method_exists($doctrinePlatform, 'registerDoctrineTypeMapping')) {
                    foreach ($map as $dbType => $doctrineType) {
                        // Register mapping (DB type -> Doctrine type)
                        $doctrinePlatform->registerDoctrineTypeMapping($dbType, $doctrineType);
                    }
                }
            } catch (\Throwable $_) {
                // If registering mappings fails, ignore and continue; we already have fallbacks elsewhere.
            }
        }

        // Retrieve platform-specific type classes (may be a Collection or array)
        $raw = Platform::getPlatformTypes(
            $platform,
            static::getPlatformTypeMapping($typeMappingArg)
        );

        // Ensure we have a collection of arrays: instantiate each type class and convert to array.
        // Keep only the minimal keys we need ('name' and 'category') to avoid duplicates caused by extra options.
        // Defensive: the $raw mapping may contain class names OR plain doctrine/db type names (strings).
        // When a plain name is provided (e.g. 'integer'), try to resolve it to a registered custom Type class
        // using static::$registeredTypes or the mapping key. This prevents attempts to instantiate
        // non-class strings and keeps the resulting metadata consistent.
        $items = collect($raw)->map(function ($typeClass, $typeName) {
            try {
                $instance = null;

                // If the value is a class name and exists, instantiate it
                if (is_string($typeClass) && class_exists($typeClass)) {
                    $instance = new $typeClass();
                }

                // If value is a string that matches a registered type NAME (e.g. 'integer'),
                // resolve it to the registered type class and instantiate.
                if ($instance === null && is_string($typeClass) && isset(static::$registeredTypes[$typeClass])) {
                    $class = static::$registeredTypes[$typeClass];
                    if (class_exists($class)) {
                        $instance = new $class();
                    }
                }

                // As a fallback, sometimes the mapping is name => name or key => className;
                // try resolving by the key (typeName) to the registered types map.
                if ($instance === null && is_string($typeName) && isset(static::$registeredTypes[$typeName])) {
                    $class = static::$registeredTypes[$typeName];
                    if (class_exists($class)) {
                        $instance = new $class();
                    }
                }

                // If the provided item is already an object (Doctrine Type or similar), use it directly
                if ($instance === null && is_object($typeClass)) {
                    $instance = $typeClass;
                }

                if ($instance === null) {
                    // Could not resolve to a known Type class; skip it
                    return null;
                }

                $arr = static::toArray($instance);
                return [
                    'name' => isset($arr['name']) ? $arr['name'] : (is_object($instance) && property_exists($instance, 'NAME') ? $instance::NAME : null),
                    'category' => $arr['category'] ?? '',
                ];
            } catch (\Throwable $_) {
                return null;
            }
        })->filter()->values();

        // Normalize category -> non-empty (use 'Other'), then group by category
        $grouped = $items->map(function ($item) {
            $cat = $item['category'] ?? '';
            $item['category'] = trim((string)$cat) === '' ? 'Other' : $cat;
            return $item;
        })->groupBy('category');

        // Deduplicate by normalized name (lowercase + trim) inside each category
        $deduped = $grouped->map(function ($col) {
            return collect($col)->unique(function ($item) {
                return isset($item['name']) ? strtolower(trim($item['name'])) : '';
            })->values();
        });

        // Prefer a stable category ordering for UI consistency
        $preferredOrder = [
            'Network',
            'Date and Time',
            'Numbers',
            'Strings',
            'Binary',
            'Lists',
            'Geometry',
            'Objects',
            'Other',
        ];

        $ordered = collect();
        foreach ($preferredOrder as $cat) {
            if ($deduped->has($cat)) {
                $ordered->put($cat, $deduped->get($cat));
            }
        }

        // Append any other categories discovered that aren't in preferredOrder
        foreach ($deduped as $k => $v) {
            if (!$ordered->has($k)) {
                $ordered->put($k, $v);
            }
        }

        static::$platformTypes = $ordered;

        return static::$platformTypes;
    }

    public static function getPlatformTypeMapping(?DoctrineAbstractPlatform $platform)
    {
        // If a cached mapping exists, validate it contains class names; if not, drop it
        if (static::$platformTypeMapping) {
            try {
                // If it's a collection, inspect the first value
                $first = static::$platformTypeMapping instanceof \Illuminate\Support\Collection
                    ? static::$platformTypeMapping->first()
                    : (is_array(static::$platformTypeMapping) ? reset(static::$platformTypeMapping) : null);

                // If first item looks like a valid class (exists or contains namespace separator), assume mapping ok
                if (is_string($first) && (class_exists($first) || strpos($first, '\\') !== false)) {
                    return static::$platformTypeMapping instanceof \Illuminate\Support\Collection
                        ? static::$platformTypeMapping
                        : collect(static::$platformTypeMapping);
                }

                // Otherwise, discard the invalid cached mapping
                static::$platformTypeMapping = [];
            } catch (\Throwable $_) {
                // ignore and rebuild mapping below
                static::$platformTypeMapping = [];
            }
        }

        // Ensure custom types are registered so static::$registeredTypes is populated
        if (!static::$customTypesRegistered) {
            static::registerCustomPlatformTypes();
        }

        // Build mapping from registered types: NAME => class
        // static::$registeredTypes is populated by registerCustomPlatformTypes()
        $mapping = static::$registeredTypes ?? [];

        // If for some reason there are no registered types, fall back to an empty map
        if (empty($mapping)) {
            // As a minimal fallback, build mapping from Common types and attempt to include platform-specific ones
            $common = static::getPlatformCustomTypes('Common');
            $mapping = [];
            foreach ($common as $class) {
                try {
                    if (property_exists($class, 'NAME')) {
                        $name = $class::NAME;
                        $mapping[$name] = $class;
                    }
                } catch (\Throwable $_) {
                    continue;
                }
            }
        }

        static::$platformTypeMapping = collect($mapping);

        return static::$platformTypeMapping;
    }

    public static function registerCustomPlatformTypes($force = false)
    {
        if (static::$customTypesRegistered && !$force) {
            return;
        }

        $platform = SchemaManager::getDatabaseConnection()->getDriverName();
        $platformName = ucfirst($platform);

        $customTypes = array_merge(
            static::getPlatformCustomTypes('Common'),
            static::getPlatformCustomTypes($platformName)
        );

        foreach ($customTypes as $type) {
            $name = $type::NAME;
            // Instead of overriding or adding Doctrine types,
            // you might want to register these types in your own type registry
            static::registerType($name, $type);
        }

        static::addCustomTypeOptions($platformName);

        // Additionally, try to register DB->Doctrine type mapping on the active Doctrine platform
        // so raw DB types (int, timestamp, etc.) will be mapped during DB introspection.
        try {
            $doctrinePlatform = null;
            try {
                $doctrineConn = SchemaManager::getDatabaseConnection()->getDoctrineConnection();
            } catch (\Throwable $_) {
                $doctrineConn = null;
            }

            if ($doctrineConn) {
                $doctrinePlatform = $doctrineConn->getDatabasePlatform();
            } elseif (method_exists(SchemaManager::getDatabaseConnection(), 'getDoctrineSchemaManager')) {
                $doctrinePlatform = SchemaManager::getDatabaseConnection()->getDoctrineSchemaManager()->getDatabasePlatform();
            }

            if ($doctrinePlatform && method_exists($doctrinePlatform, 'registerDoctrineTypeMapping')) {
                $map = static::$defaultTypeMap;

                foreach ($map as $dbType => $doctrineType) {
                    try {
                        $doctrinePlatform->registerDoctrineTypeMapping($dbType, $doctrineType);
                    } catch (\Throwable $_) {
                        // ignore individual mapping failures
                    }
                }
            }
        } catch (\Throwable $_) {
            // ignore top-level failures
        }

        static::$customTypesRegistered = true;
    }

    protected static function addCustomTypeOptions($platformName)
    {
        static::registerCommonCustomTypeOptions();

        Platform::registerPlatformCustomTypeOptions($platformName);

        // Add the custom options to the types
        foreach (static::$customTypeOptions as $option) {
            foreach ($option['types'] as $type) {
                if (static::hasType($type)) {
                    static::getType($type)->customOptions[$option['name']] = $option['value'];
                }
            }
        }
    }

    protected static function getPlatformCustomTypes($platformName)
    {
        $typesPath = __DIR__.DIRECTORY_SEPARATOR.$platformName.DIRECTORY_SEPARATOR;
        $namespace = __NAMESPACE__.'\\'.$platformName.'\\';
        $types = [];

        foreach (glob($typesPath.'*.php') as $classFile) {
            $types[] = $namespace.str_replace(
                '.php',
                '',
                str_replace($typesPath, '', $classFile)
            );
        }

        return $types;
    }

    public static function registerCustomOption($name, $value, $types)
    {
        if (is_string($types)) {
            $types = trim($types);

            if ($types == '*') {
                $types = static::getAllTypes()->toArray();
            } elseif (strpos($types, '*') !== false) {
                $searchType = str_replace('*', '', $types);
                $types = static::getAllTypes()->filter(function ($type) use ($searchType) {
                    return strpos($type, $searchType) !== false;
                })->toArray();
            } else {
                $types = [$types];
            }
        }

        static::$customTypeOptions[] = [
            'name'  => $name,
            'value' => $value,
            'types' => $types,
        ];
    }

    protected static function registerCommonCustomTypeOptions()
    {
        static::registerTypeCategories();
        static::registerTypeDefaultOptions();
    }

    protected static function registerTypeDefaultOptions()
    {
        $types = static::getTypeCategories();

        // Numbers
        static::registerCustomOption('default', [
            'type' => 'number',
            'step' => 'any',
        ], $types['numbers']);

        // Date and Time
        static::registerCustomOption('default', [
            'type' => 'date',
        ], 'date');
        static::registerCustomOption('default', [
            'type' => 'time',
            'step' => '1',
        ], 'time');
        static::registerCustomOption('default', [
            'type' => 'number',
            'min'  => '0',
        ], 'year');
    }

    protected static function registerTypeCategories()
    {
        $types = static::getTypeCategories();

        static::registerCustomOption('category', 'Numbers', $types['numbers']);
        static::registerCustomOption('category', 'Strings', $types['strings']);
        static::registerCustomOption('category', 'Date and Time', $types['datetime']);
        static::registerCustomOption('category', 'Lists', $types['lists']);
        static::registerCustomOption('category', 'Binary', $types['binary']);
        static::registerCustomOption('category', 'Geometry', $types['geometry']);
        static::registerCustomOption('category', 'Network', $types['network']);
        static::registerCustomOption('category', 'Objects', $types['objects']);
    }

    public static function getAllTypes()
    {
        if (static::$allTypes) {
            return static::$allTypes;
        }

        static::$allTypes = collect(static::getTypeCategories())->flatten();

        return static::$allTypes;
    }

    public static function getTypeCategories()
    {
        if (static::$typeCategories) {
            return static::$typeCategories;
        }

        $numbers = [
            'boolean',
            'tinyint',
            'smallint',
            'mediumint',
            'integer',
            'int',
            'bigint',
            'decimal',
            'numeric',
            'money',
            'float',
            'real',
            'double',
            'double precision',
        ];

        $strings = [
            'char',
            'character',
            'varchar',
            'character varying',
            'string',
            'guid',
            'uuid',
            'tinytext',
            'text',
            'mediumtext',
            'longtext',
            'tsquery',
            'tsvector',
            'xml',
        ];

        $datetime = [
            'date',
            'datetime',
            'year',
            'time',
            'timetz',
            'timestamp',
            'timestamptz',
            'datetimetz',
            'dateinterval',
            'interval',
        ];

        $lists = [
            'enum',
            'set',
            'simple_array',
            'array',
            'json',
            'jsonb',
            'json_array',
        ];

        $binary = [
            'bit',
            'bit varying',
            'binary',
            'varbinary',
            'tinyblob',
            'blob',
            'mediumblob',
            'longblob',
            'bytea',
        ];

        $network = [
            'cidr',
            'inet',
            'macaddr',
            'txid_snapshot',
        ];

        $geometry = [
            'geometry',
            'point',
            'linestring',
            'polygon',
            'multipoint',
            'multilinestring',
            'multipolygon',
            'geometrycollection',
        ];

        $objects = [
            'object',
        ];

        static::$typeCategories = [
            'numbers'  => $numbers,
            'strings'  => $strings,
            'datetime' => $datetime,
            'lists'    => $lists,
            'binary'   => $binary,
            'network'  => $network,
            'geometry' => $geometry,
            'objects'  => $objects,
        ];

        return static::$typeCategories;
    }

    public static function registerType($name, $typeClass)
    {
        static::$registeredTypes[$name] = $typeClass;
    }
}
