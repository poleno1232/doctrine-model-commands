<?php

namespace Polion1232\Commands;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Polion1232\Exceptions\TableNotFoundException;
use Polion1232\Exceptions\UnknowIndetifierException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeModelFromTableCommand extends AbstractCommand
{

    protected const FIELD_DECLARATION =
    '
    /**
     * @ORM\Column(type="{{type}}")
     * {{orm}}
     */
    protected {{php_type}} ${{field}};
';

    protected const TYPE_TABLE = [
        '\BigInt' => ["php_type" => "int", "type" => "integer"],
        '\String' => ["php_type" => "string", "type" => "string"],
    ];

    protected const FOREIGN = 'foreign';
    protected const INDEX = 'index';

    protected const ONE_TO_ONE = '@OneToOne';
    protected const ONE_TO_MANY = '@OneToMany';

    /**
     * @var string[]ForeignKeyConstraint[]
     */
    protected $tablesKeys = [];

    protected function configure(): void
    {
        $this
            ->setName('make:model-from-db')
            ->addArgument('model', InputArgument::REQUIRED)
            ->addArgument('table', InputArgument::REQUIRED)
            ->addArgument('path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = $input->getArgument('table');
        $path = $input->getArgument('path');
        $model = $input->getArgument('model');

        $columnsData = [];
        /**
         * @var Column[] $columns
         */
        $columns = $this->schemaManager->listTableColumns($table);

        if (empty($columns)) {
            throw new TableNotFoundException();
        }

        $constraints = array_merge(
            $this->retrieveTableForeignKeys($table),
            $this->schemaManager->listTableIndexes($table)
        );
        $constraints = $this->retrieveColumnNamesFromConstraints($constraints);

        foreach ($columns as $column) {
            $name = $column->getName();
            $additionalOrm = null;

            if (in_array($name, array_keys($constraints['foreigns']))) {
                $additionalOrm = $constraints['foreigns'][$name];
            }

            if (in_array($name, array_keys($constraints['indexes']))) {
                $additionalOrm = $constraints['indexes'][$name];
            }

            $columnsData[$this->normalizeName($name)] = $this->formatElement($column->getType(), $name, $additionalOrm);
        }

        $file = file_get_contents(__DIR__ . '/../../stubs/model.stub');
        $replaceableData = '';

        foreach ($columnsData as $name => $data) {
            $replaceableData .= self::FIELD_DECLARATION;

            $replaceableData = str_replace(
                ['{{name}}', '{{orm}}', '{{field}}', '{{type}}', '{{php_type}}'],
                [$name, $this->transformOrm($data['orm'], $table), $data['field'], $data['type'], $data['php_type']],
                $replaceableData
            );
        }

        $file = str_replace('{{replaceableData}}', $replaceableData, $file);
        $file = str_replace('{{table}}', $table, $file);

        file_put_contents($path ? ($path . '/') : '' . $model . '.php', $file);

        return 0;
    }

    protected function formatElement(string $type, string $field, array $additional = null): array
    {
        return array_merge(
            $this->normalizeType($type),
            ['field' => $field],
            ['orm' => $this->processAdditionals($additional)]
        );
    }

    protected function normalizeType(string $type): string
    {
        return self::TYPE_TABLE[$type];
    }

    protected function normalizeName(string $field): string
    {
        return str_replace('_', '', ucwords($field, '_'));
    }

    protected function retrieveColumnNamesFromConstraints(array $constraints): array
    {
        $columns = [
            'foreigns' => [],
            'indexes' => [],
        ];

        foreach ($constraints as $constraint) {
            $data = $constraint->getColumns();

            switch (true) {
                case $constraint instanceof ForeignKeyConstraint:
                    $table = $constraint->getForeignTableName();
                    $foreignConstraints = $this->retrieveTableForeignKeys($table);

                    foreach ($data as $name) {
                        $columns['foreigns'][$name]['table'] = $table;
                        $columns['foreigns'][$name]['type'] = self::FOREIGN;

                        //Defaults to One-to-Many
                        $relationType = self::ONE_TO_MANY;

                        if (empty($foreignConstraints)) {
                            $columns['foreigns'][$name]['relation'] = $relationType;

                            break;
                        }

                        foreach ($foreignConstraints as $foreignConstraint) {
                            if (!in_array($name, $foreignConstraint->getUnquotedForeignColumns())) {
                                $relationType = self::ONE_TO_MANY;
                            } else {
                                $relationType = self::ONE_TO_ONE;
                            }
                        }

                        $columns['foreigns'][$name]['relation'] = $relationType;
                    }

                    break;
                case $constraint instanceof Index:
                    if (empty(array_diff($data, array_keys($columns['foreigns'])))) {
                        //Foreign keys are always first, so we just ignore any index, associated with them
                        break;
                    }

                    foreach ($data as $name) {
                        $columns['indexes'][$name]['type'] = self::INDEX;
                        $columns['indexes'][$name]['primary'] = $constraint->isPrimary();
                        $columns['indexes'][$name]['unique'] = $constraint->isUnique();
                    }

                    break;
            }
        }

        return $columns;
    }

    protected function retrieveTableForeignKeys(string $table): array
    {
        if (isset($this->tablesKeys[$table])) {
            return $this->tablesKeys[$table];
        }

        $this->tablesKeys[$table] = $this->schemaManager->listTableForeignKeys($table);

        return $this->tablesKeys[$table];
    }

    protected function processAdditionals(?array $data): array
    {
        if (is_null($data)) {
            return ['type' => null];
        }

        switch ($data['type']) {
            case self::INDEX:
                return $data;
            case self::FOREIGN:
                return [
                    'type' => self::FOREIGN,
                    'data' => $data['table'],
                    'relation' => $data['relation'],
                ];
        }

        throw (new UnknowIndetifierException());
    }

    protected function transformOrm(array $orm, string $thisTable): string
    {
        switch ($orm['type']) {
            case self::FOREIGN:
                if ($orm['relation'] === self::ONE_TO_ONE) {
                    return "@OneToOne(targetEntity=\"{$orm['data']}\", inversedBy=\"{$thisTable}\")";
                } else {
                    return "@OneToMany(targetEntity=\"{$orm['data']}\", mappedBy=\"{$thisTable}\")";
                }
            case self::INDEX:
                if ($orm['primary']) {
                    return '@ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")';
                }

                if ($orm['unique']) {
                    return '@UniqueIndex(order="asc")';
                }

                throw (new UnknowIndetifierException());
            default:
                return '';
        }
    }
}
