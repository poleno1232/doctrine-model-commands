<?php

namespace Polion1232\Commands;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Exception;
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

    protected function configure()
    {
        $this
            ->setName('make:model-from-db')
            ->addArgument('model', InputArgument::REQUIRED)
            ->addArgument('table', InputArgument::REQUIRED)
            ->addArgument('path');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
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
            // TableNotFoundException has multiple contraditing constructors, apparently
            throw new Exception("Table does not exist");
        }

        $constraints = array_merge(
            $this->schemaManager->listTableForeignKeys($table),
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
                $additionalOrm = [];
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

    protected function formatElement(string $type, string $field, array $additional = null)
    {
        return array_merge(
            $this->normalizeType($type),
            ['field' => $field],
            ['orm' => $this->processAdditionals($additional)]
        );
    }

    protected function normalizeType(string $type)
    {
        return self::TYPE_TABLE[$type];
    }

    protected function normalizeName(string $field)
    {
        return str_replace('_', '', ucwords($field, '_'));
    }

    protected function retrieveColumnNamesFromConstraints(array $constraints)
    {
        $columns = [
            'foreigns' => [],
            'indexes' => [],
        ];

        foreach ($constraints as $constraint) {
            $data = $constraint->getColumns();

            switch (true) {
                case $constraint instanceof ForeignKeyConstraint:
                    foreach ($data as $name) {
                        $columns['foreigns'][$name]['table'] = $constraint->getForeignTableName();
                    }

                    break;
                case $constraint instanceof Index:
                    if (empty(array_diff($data, array_keys($columns['foreigns'])))) {
                        //Foreign keys are always first, so we just ignore any index, associated with them
                        break;
                    }

                    foreach ($data as $name) {
                        $columns['indexes'][$name] = ['test'];
                    }

                    break;
            }
        }

        return $columns;
    }

    protected function processAdditionals(?array $data)
    {
        if (is_null($data)) {
            return ['type' => null];
        }

        if (empty($data)) {
            return ['type' => self::INDEX];
        }

        return [
            'type' => self::FOREIGN,
            'data' => $data['table'],
        ];
    }

    protected function transformOrm(array $orm, string $thisTable)
    {
        switch ($orm['type']) {
            case self::FOREIGN:
                return "@OneToOne(targetEntity=\"{$orm['data']}\", inversedBy=\"{$thisTable}\")";
            case self::INDEX:
                return '@ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")';
            default:
                return '';
        }
    }
}
