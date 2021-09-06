<?php

namespace Polion1232\Commands;

use Doctrine\DBAL\Schema\Column;
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
     */
    protected {{php_type}} ${{field}};
';

    protected const FIELD_SETTER = '
    public function set{{name}}({{php_type}} ${{field}})
    {
        $this->{{field}} = ${{field}};

        return $this;
    }';

    protected const FIELD_GETTER = '
    public function get{{name}}(): {{php_type}}
    {
        return $this->{{field}};
    }
';

    protected const TYPE_TABLE = [
        '\BigInt' => ["php_type" => "int", "type" => "integer"],
        '\String' => ["php_type" => "string", "type" => "string"],
    ];

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
        $columns = $this->schemaManager->listTableColumns($table);

        if (empty($columns)) {
            // TableNotFoundException has multiple contraditing constructors, apparently
            throw new Exception("Table does not exist");
        }

        foreach ($columns as $column) {
            $name = $column->getName();
            if ($name === 'id') {
                continue;
            }

            $columnsData[$this->normalizeName($name)] = $this->formatElement($column->getType(), $name);
        }

        $file = file_get_contents(__DIR__ . '/../../stubs/model.stub');
        $replaceableData = '';

        foreach ($columnsData as $name => $data) {
            $replaceableData .=
                self::FIELD_DECLARATION .
                self::FIELD_GETTER .
                self::FIELD_SETTER;

            $replaceableData = str_replace(
                ['{{name}}', '{{field}}', '{{type}}', '{{php_type}}'],
                [$name, $data['field'], $data['type'], $data['php_type']],
                $replaceableData
            );
        }

        $file = str_replace('{{replaceableData}}', $replaceableData, $file);

        file_put_contents($path ? ($path . '/') : '' . $model . '.php', $file);

        return 0;
    }

    protected function formatElement(string $type, string $field)
    {
        return array_merge($this->normalizeType($type), ['field' => $field]);
    }

    protected function normalizeType(string $type)
    {
        return self::TYPE_TABLE[$type];
    }

    protected function normalizeName(string $field)
    {
        return str_replace('_', '', ucwords($field, '_'));
    }
}
