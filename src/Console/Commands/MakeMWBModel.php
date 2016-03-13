<?php
namespace b3nl\MWBModel\Console\Commands;

use b3nl\MWBModel\MWBModelReader;
use b3nl\MWBModel\Models\Migration\ForeignKey;
use b3nl\MWBModel\Models\ModelContent;
use b3nl\MWBModel\Models\TableMigration;
use Illuminate\Console\AppNamespaceDetectorTrait;
use Illuminate\Database\Console\Migrations\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Console Command to convert a mwb modul to migrations and models.
 * @package b3nl\MWBModel
 * @subpackage Console\Commands
 * @version $id$
 */
class MakeMWBModel extends BaseCommand
{
    use AppNamespaceDetectorTrait;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates basic laravel migrations and models for the given MySQL Workbench model.';

    /**
     * The reader of the model.
     * @var MWBModelReader
     */
    protected $modelReader = null;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:mwb-model';

    /**
     * Caches the names of the m:n tables.
     * @var array
     */
    protected $pivotTables = [];

    /**
     * Saving of the table names and their ids.
     * @var TableMigration[]|void
     */
    protected $tables = null;

    /**
     * Checks and extract the model file. If the file can be found and extracted,the extracted path will be returned.
     * @return string
     */
    protected function checkAndExtractModelFile()
    {
        if (!is_readable($file = $this->argument('modelfile'))) {
            throw new \InvalidArgumentException('Could not find the model.');
        } // if

        $archive = new \ZipArchive();

        if (!$archive->open(realpath($file))) {
            throw new \InvalidArgumentException('Could not open the model.');
        } // if

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid() . DIRECTORY_SEPARATOR;

        if (!$archive->extractTo($dir)) {
            throw new \InvalidArgumentException('Could not extract the model.');
        } // if

        return $dir;
    } // function

    /**
     * Creates the relations between the tables.
     * @param TableMigration[] $tables
     * @return TableMigration[]
     */
    protected function createTableRelations(array $tables)
    {
        /** @var TableMigration $tableObject */
        foreach ($tables as $tableObject) {
            $tableObject->relateToOtherTables($tables);
        } // foreach

        return $this->sortTablesWithFKsToEnd($tables);
    } // function

    /**
     * Opens the zipped model container and reads the xml model file.
     * @return void
     */
    public function fire()
    {
        $this->loadMNTables();
        $dir = $this->checkAndExtractModelFile();

        foreach (glob($dir . '*.mwb.xml') as $file) {
            $reader = new MWBModelReader();
            $reader->open($file);

            $this
                ->setModelReader($reader)
                ->handleModelTables();
        } // foreach

        return null;
    } // function

    /**
     * Get the console command arguments.
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['modelfile', InputArgument::REQUIRED, 'The file path to your *.mwb-file.'],
        ];
    } // function

    /**
     * Returns the used model reader.
     * @return MWBModelReader
     */
    public function getModelReader()
    {
        return $this->modelReader;
    } // function

    /**
     * Returns the loaded model tables.
     * @return TableMigration[]
     */
    public function getModelTables()
    {
        if ($this->tables === null) {
            $this->setModelTables($this->loadModelTables());
        }

        return $this->tables;
    }

    /**
     * Get the console command options.
     * @return array
     */
    protected function getOptions()
    {
        return [
            [
                'pivots',
                null,
                InputOption::VALUE_OPTIONAL,
                'Please provide the names of the m:n-pivot tables (table1,table2,...), if there are any:',
                ''
            ],
        ];
    } // function

    /**
     * Loads the model tables out of the model file.
     * @return MakeMWBModel
     * @todo   We need to save a way to save the relations in the models.
     */
    protected function handleModelTables()
    {
        if (!$this->getModelReader()->isCompatibleVersion()) {
            throw new \InvalidArgumentException('Wrong model version.');
        } // if

        $bar = $this->output->createProgressBar();

        $bar->start(count($tables = $this->getModelTables()));

        /** @var TableMigration $tableObject */
        foreach ($this->createTableRelations($tables) as $tableObject) {
            $tableObject->save($this);

            $bar->advance();
        } // foreach

        return $this;
    }

    /**
     * Loads the names of the m:n tables.
     * @return MakeMWBModel
     */
    protected function loadMNTables()
    {
        if ($tables = $this->option('pivots')) {
            $this->pivotTables = array_map('trim', array_filter(explode(',', rtrim($tables, ','))));
        } // if

        return $this;
    }

    /**
     * Generates the model and migration for/with laravel and extends the contents of the generated classes.
     * @param \DOMNode $node The node of the table.
     * @return MakeMWBModel|bool False if the table should be ignored.
     */
    protected function loadModelTable(\DOMNode $node)
    {
        $tableObject = new TableMigration($this);
        $loaded = $tableObject->load($node);

        if ($loaded && in_array($tableObject->getName(), $this->pivotTables)) {
            $tableObject->isPivotTable(true);
        } else if ($tableObject->isPivotTable()) {
            $this->pivotTables[] = $tableObject->getName();
        } // else if

        return $loaded ? $tableObject : false;
    }

    /**
     * Loads the model tables from the file.
     * @return TableMigration[]
     */
    protected function loadModelTables()
    {
        $reader = $this->getModelReader();
        $tables = [];

        while ($reader->read()) {
            if ($reader->isModelTable()) {
                if ($table = $this->loadModelTable($reader->expand())) {
                    $tables[$table->getId()] = $table;
                } // if
            } // if
        } // while

        return $tables;
    }

    /**
     * Sets the model reader.
     * @param MWBModelReader $modelReader
     * @return MakeMWBModel
     */
    public function setModelReader(MWBModelReader $modelReader)
    {
        $this->modelReader = $modelReader;

        return $this;
    }

    /**
     * Sets the model tables.
     * @param TableMigration[] $tables
     * @return $this
     */
    public function setModelTables(array $tables)
    {
        $this->tables = $tables;

        return $this;
    }

    /**
     * Callback to sort tables with foreign keys to the end.
     * @param TableMigration[] $tables
     * @return TableMigration[]
     */
    protected function sortTablesWithFKsToEnd(array $tables)
    {
        $return = [];

        while ($tables) {
            /** @var TableMigration $table */
            foreach ($tables as $key => $table) {
                // if there are no fks or the required fks are allready saved.
                if ((!$fks = $table->getForeignKeys()) || (count(array_intersect_key($return, $fks)) === count($fks))) {
                    $return[$table->getName()] = $table;
                    unset($tables[$key]);
                } // if
            } // foreach
        } // while

        return $return;
    } // function
}
