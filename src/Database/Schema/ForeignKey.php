<?php

namespace TCG\Voyager\Database\Schema;

use Doctrine\DBAL\Schema\ForeignKeyConstraint as DoctrineForeignKey;

abstract class ForeignKey
{
    public static function make(array $foreignKey)
    {
        // Set the local table if provided
        $localTable = null;
        if (!empty($foreignKey['localTable'])) {
            $localTable = SchemaManager::getDoctrineTable($foreignKey['localTable']);
        }

        // Normalize local columns (accept 'localColumns', 'columns', 'local_columns')
        $localColumns = $foreignKey['localColumns'] ?? $foreignKey['columns'] ?? $foreignKey['local_columns'] ?? null;
        if ($localColumns === null) {
            throw new \InvalidArgumentException('Foreign key definition must contain local columns (localColumns or columns)');
        }
        if (!is_array($localColumns)) {
            $localColumns = is_string($localColumns) ? explode(',', $localColumns) : (array) $localColumns;
        }

        // Normalize foreign table name (accept 'foreignTable' or 'foreign_table')
        $foreignTable = $foreignKey['foreignTable'] ?? $foreignKey['foreign_table'] ?? null;
        if ($foreignTable === null) {
            throw new \InvalidArgumentException('Foreign key definition must contain foreign table (foreignTable or foreign_table)');
        }

        // Normalize foreign columns (accept 'foreignColumns' or 'foreign_columns')
        $foreignColumns = $foreignKey['foreignColumns'] ?? $foreignKey['foreign_columns'] ?? null;
        if ($foreignColumns === null) {
            // default to 'id' if nothing is provided
            $foreignColumns = ['id'];
        }
        if (!is_array($foreignColumns)) {
            $foreignColumns = is_string($foreignColumns) ? explode(',', $foreignColumns) : (array) $foreignColumns;
        }

        $options = $foreignKey['options'] ?? [];

        // Set the name
        $name = isset($foreignKey['name']) ? trim($foreignKey['name']) : '';
        if (empty($name)) {
            $table = isset($localTable) ? $localTable->getName() : null;
            $name = Index::createName($localColumns, 'foreign', $table);
        } else {
            $name = Identifier::validate($name, 'Foreign Key');
        }

        $doctrineForeignKey = new DoctrineForeignKey(
            $localColumns,
            $foreignTable,
            $foreignColumns,
            $name,
            $options
        );

        if (isset($localTable)) {
            $doctrineForeignKey->setLocalTable($localTable);
        }

        return $doctrineForeignKey;
    }

    /**
     * @return array
     */
    public static function toArray(DoctrineForeignKey $fk)
    {
        return [
            'name'           => $fk->getName(),
            'localTable'     => $fk->getLocalTableName(),
            'localColumns'   => $fk->getLocalColumns(),
            'foreignTable'   => $fk->getForeignTableName(),
            'foreignColumns' => $fk->getForeignColumns(),
            'options'        => $fk->getOptions(),
        ];
    }
}
