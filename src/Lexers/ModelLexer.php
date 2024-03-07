<?php

namespace Blueprint\Lexers;

use Blueprint\Contracts\Lexer;
use Blueprint\Models\Column;
use Blueprint\Models\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ModelLexer implements Lexer
{
    private static array $relationships = [
        'belongsto' => 'belongsTo',
        'hasone' => 'hasOne',
        'hasmany' => 'hasMany',
        'belongstomany' => 'belongsToMany',
        'morphone' => 'morphOne',
        'morphmany' => 'morphMany',
        'morphto' => 'morphTo',
        'morphtomany' => 'morphToMany',
        'morphedbymany' => 'morphedByMany',
    ];

    private static array $dataTypes = [
        'bigincrements' => 'bigIncrements',
        'biginteger' => 'bigInteger',
        'binary' => 'binary',
        'boolean' => 'boolean',
        'char' => 'char',
        'date' => 'date',
        'datetime' => 'dateTime',
        'datetimetz' => 'dateTimeTz',
        'decimal' => 'decimal',
        'double' => 'double',
        'enum' => 'enum',
        'float' => 'float',
        'fulltext' => 'fullText',
        'geometry' => 'geometry',
        'geometrycollection' => 'geometryCollection',
        'increments' => 'increments',
        'integer' => 'integer',
        'ipaddress' => 'ipAddress',
        'json' => 'json',
        'jsonb' => 'jsonb',
        'linestring' => 'lineString',
        'longtext' => 'longText',
        'macaddress' => 'macAddress',
        'mediumincrements' => 'mediumIncrements',
        'mediuminteger' => 'mediumInteger',
        'mediumtext' => 'mediumText',
        'morphs' => 'morphs',
        'uuidmorphs' => 'uuidMorphs',
        'multilinestring' => 'multiLineString',
        'multipoint' => 'multiPoint',
        'multipolygon' => 'multiPolygon',
        'nullablemorphs' => 'nullableMorphs',
        'nullableuuidmorphs' => 'nullableUuidMorphs',
        'nullabletimestamps' => 'nullableTimestamps',
        'point' => 'point',
        'polygon' => 'polygon',
        'remembertoken' => 'rememberToken',
        'set' => 'set',
        'smallincrements' => 'smallIncrements',
        'smallinteger' => 'smallInteger',
        'softdeletes' => 'softDeletes',
        'softdeletestz' => 'softDeletesTz',
        'string' => 'string',
        'text' => 'text',
        'time' => 'time',
        'timetz' => 'timeTz',
        'timestamp' => 'timestamp',
        'timestamptz' => 'timestampTz',
        'timestamps' => 'timestamps',
        'timestampstz' => 'timestampsTz',
        'tinyincrements' => 'tinyIncrements',
        'tinyinteger' => 'tinyInteger',
        'unsignedbiginteger' => 'unsignedBigInteger',
        'unsigneddecimal' => 'unsignedDecimal',
        'unsignedinteger' => 'unsignedInteger',
        'unsignedmediuminteger' => 'unsignedMediumInteger',
        'unsignedsmallinteger' => 'unsignedSmallInteger',
        'unsignedtinyinteger' => 'unsignedTinyInteger',
        'ulid' => 'ulid',
        'uuid' => 'uuid',
        'year' => 'year',
    ];

    private static array $modifiers = [
        'autoincrement' => 'autoIncrement',
        'charset' => 'charset',
        'collation' => 'collation',
        'default' => 'default',
        'nullable' => 'nullable',
        'unsigned' => 'unsigned',
        'usecurrent' => 'useCurrent',
        'usecurrentonupdate' => 'useCurrentOnUpdate',
        'always' => 'always',
        'unique' => 'unique',
        'index' => 'index',
        'primary' => 'primary',
        'foreign' => 'foreign',
        'ondelete' => 'onDelete',
        'onupdate' => 'onUpdate',
        'comment' => 'comment',
    ];

    public function analyze(array $tokens): array
    {
        $registry = [
            'models' => [],
            'cache' => [],
        ];
        if (!Arr::has($tokens, 'components.schemas')) {
            return $registry;
        }

        foreach (Arr::get($tokens, 'components.schemas') as $name => $schema) {
            if (!Arr::hasAny($schema, ['enum', 'properties'])) {
                continue;
            }
            //            @TODO
            //            if (Arr::has($schema, 'enum')) {
            //                $registry['models'][$name] = $this->buildEnum($name, $schema);
            //            }
            if (Arr::has($schema, 'properties')) {
                // @TODO make DTO here
                $name_without_action = Str::remove(['query', 'create', 'replace'], $name);
                $registry['models'][$name] = $this->buildModel($name, $schema);
            }

        }

        if (!empty($tokens['models'])) {
            foreach ($tokens['models'] as $name => $definition) {
                $registry['models'][$name] = $this->buildModel($name, $definition);
            }
        }

        if (!empty($tokens['cache'])) {
            foreach ($tokens['cache'] as $name => $definition) {
                $registry['cache'][$name] = $this->buildModel($name, $definition);
            }
        }

        return $registry;
    }

    private function buildModel(string $name, array $columns): Model
    {
        $model = new Model($name);
        if (!empty($columns['type']) && $columns['type'] === 'object') {
            foreach ($columns['properties'] as $name => $definition) {
                $column = $this->buildColumn($name, $definition);
                $model->addColumn($column);
            }
        }

        //        $this->inferMissingBelongsToRelationships($model);

        return $model;
    }

    private function buildColumn(string $name, array $definition): Column
    {
        $data_type = null;
        $modifiers = [];

        $data_type = $this->getDataType($definition) ?: $data_type;

        if (!Arr::exists($definition, 'type') && Arr::exists($definition, 'oneOf')) {
            foreach (Arr::get($definition, 'oneOf') as $item) {
                $data_type = $this->getDataType($item) ?: $data_type;
                if (Arr::exists($item,'allOf')) {
                    $data_type = 'enum';
                }
                if (!empty($item['nullable']) && is_bool($item['nullable']) && $item['nullable'] === true) {
                    $modifiers[] = 'nullable';
                }
            }
        }



        if ($name === 'uuid') {
            $data_type = 'uuid';
        }

        return new Column($name, $data_type, $modifiers, $attributes ?? []);
    }

    /**
     * @param  string  $data_type
     */
    public function getDataType(array $definition): ?string
    {
        if (empty($definition['type'])) {
            return null;
        }
        if (empty($definition['format'])) {
            $definition['format'] = 'normalizedString';
        }

        if ($definition['type'] === 'string' && $definition['format'] === 'normalizedString') {
            $data_type = 'string';
        }

        if ($definition['type'] === 'string' && $definition['format'] === 'string') {
            $data_type = 'text';
        }



        if ($definition['type'] === 'array' || $definition['type'] === 'object') {
            $data_type = 'json';
        }

        return $data_type ?: null;
    }

    /**
     * Here we infer additional `belongsTo` relationships. First by checking
     * for those defined in `relationships`. Then by reviewing the model
     * columns which follow the conventional naming of `model_id`.
     */
    private function inferMissingBelongsToRelationships(Model $model): void
    {
        foreach ($model->relationships()['belongsTo'] ?? [] as $relationship) {
            $column = $this->columnNameFromRelationship($relationship);

            $attributes = [];
            if (str_contains($relationship, ':')) {
                $attributes = [Str::before($relationship, ':')];
            }

            if (!$model->hasColumn($column)) {
                $model->addColumn(new Column($column, 'id', attributes: $attributes));
            }
        }

        foreach ($model->columns() as $column) {
            $foreign = $column->isForeignKey();

            if (
                ($column->name() !== 'id' && $column->dataType() === 'id')
                || ($column->dataType() === 'uuid' && Str::endsWith($column->name(), '_id'))
                || $foreign
            ) {
                $reference = $column->name();

                if ($foreign && $foreign !== 'foreign') {
                    $table = $foreign;
                    $key = 'id';

                    if (Str::contains($foreign, '.')) {
                        [$table, $key] = explode('.', $foreign);
                    }

                    $reference = Str::singular($table) . ($key === 'id' ? '' : '.' . $key) . ':' . $column->name();
                } elseif ($column->attributes()) {
                    $reference = $column->attributes()[0] . ':' . $column->name();
                }

                if (!$this->hasBelongsToRelationship($model, $reference)) {
                    $model->addRelationship('belongsTo', $reference);
                }
            }
        }
    }

    private function columnNameFromRelationship(string $relationship): string
    {
        $model = $relationship;
        if (str_contains($relationship, ':')) {
            $model = Str::after($relationship, ':');
        }

        if (str_contains($relationship, '\\')) {
            $model = Str::afterLast($relationship, '\\');
        }

        return Str::snake($model) . '_id';
    }

    private function hasBelongsToRelationship(Model $model, string $reference): bool
    {
        foreach ($model->relationships()['belongsTo'] ?? [] as $relationship) {
            if (Str::after($reference, ':') === $this->columnNameFromRelationship($relationship)) {
                return true;
            }
        }

        return false;
    }

    private function removeDataTypes(string $definition): string
    {
        $tokens = array_filter(
            $this->parseColumn($definition),
            fn ($token) => strtolower($token) !== 'unsigned' && !isset(self::$dataTypes[strtolower($token)])
        );

        return implode(' ', $tokens);
    }

    private function parseColumn(string $definition): array
    {
        return preg_split('#("|\').*?\1(*SKIP)(*FAIL)|\s+#', $definition);
    }

    private function buildEnum(int|string $name, mixed $schema)
    {
    }
}
