<?php

namespace Polion1232\Commands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\Schema\AbstractSchemaManager as SchemaManager;

abstract class AbstractCommand extends Command
{
    protected $connection;
    /**
     * @var SchemaManager
     */
    protected $schemaManager;

    public function __construct(Connection $conn)
    {
        parent::__construct();
        $this->connection = $conn;
        $this->schemaManager = $conn->getSchemaManager();
    }

    public function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->output = $output;
    }
}