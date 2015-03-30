<?php namespace b3nl\MWBModel\Console\Commands;

	use b3nl\MWBModel\MWBModelReader,
		b3nl\MWBModel\Models\Migration\ForeignKey,
		b3nl\MWBModel\Models\ModelContent,
		b3nl\MWBModel\Models\TableMigration,
		Illuminate\Console\AppNamespaceDetectorTrait,
		Illuminate\Database\Console\Migrations\BaseCommand,
		Symfony\Component\Console\Input\InputArgument,
		Symfony\Component\Console\Input\InputOption;

	/**
	 * Console Command to convert a mwb modul to migrations and models.
	 * @package b3nl\MWBModel
	 * @subpackage Console\Commands
	 * @version $id$
	 */
	class MakeMWBModel extends BaseCommand {
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
		 * @var array
		 */
		protected $tables = [];

		/**
		 * Checks and extract the model file. If the file can be found and extracted, the extracted path will be returned.
		 * @return string
		 */
		protected function checkAndExtractModelFile()
		{
			if (!is_readable($file = $this->argument('modelfile')))
			{
				throw new \InvalidArgumentException('Could not find the model.');
			} // if

			$archive = new \ZipArchive();

			if (!$archive->open(realpath($file)))
			{
				throw new \InvalidArgumentException('Could not open the model.');
			} // if

			$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid() . DIRECTORY_SEPARATOR;

			if (!$archive->extractTo($dir))
			{
				throw new \InvalidArgumentException('Could not extract the model.');
			} // if

			return $dir;
		} // function

		/**
		 * Creates the migration file for the given table.
		 * @param TableMigration $table
		 * @return mixed|string
		 */
		protected function createMigrationFile(TableMigration $table)
		{
			if ($table->needsLaravelModel())
			{
				$this->call('make:model', ['name' => $table->getModelName()]);
			} // if
			else
			{
				$this->call(
					'make:migration', ['name' => "create_{$table->getName()}_table", '--create' => $table->getName()]
				);
			} // else

			$migrationFiles = glob(
				$this->getMigrationPath() . DIRECTORY_SEPARATOR . "*_create_{$table->getName()}_table.php"
			);

			return $migrationFiles ? end($migrationFiles) : '';
		} // function

		/**
		 * Creates the relations between the tables.
		 * @param TableMigration[] $tables
		 * @return TableMigration[]
		 */
		protected function createTableRelations($tables)
		{
			/** @var TableMigration $tableObject */
			foreach ($tables as $tableObject)
			{
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

			foreach (glob($dir . '*.mwb.xml') as $file)
			{
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
		 * Get the console command options.
		 * @return array
		 */
		protected function getOptions()
		{
			return [
				['pivots', null, InputOption::VALUE_OPTIONAL, 'Please provide the names of the m:n-pivot tables (table1,table2,...), if there are any:', ''],
			];
		} // function

		/**
		 * Loads the model tables out of the model file.
		 * @return MakeMWBModel
		 * @todo   We need to save a way to save the relations in the models.
		 */
		protected function handleModelTables()
		{
			$reader = $this->getModelReader();
			$tables = [];

			if (!$reader->isCompatibleVersion())
			{
				throw new \InvalidArgumentException('Wrong model version.');
			} // if

			while ($reader->read())
			{
				if ($reader->isModelTable())
				{
					$table = $this->loadModelTable($reader->expand());

					$tables[$table->getId()] = $table;
				} // if
			} // while

			$tables = $this->createTableRelations($tables);

			/** @var TableMigration $tableObject */
			foreach ($tables as $tableObject)
			{
				if ($migrationFile = $this->createMigrationFile($tableObject))
				{
					$tableObject->save($migrationFile);
				} // if

				if ($tableObject->needsLaravelModel())
				{
					$this->saveModelForTable($tableObject);
				} // if
			} // foreach

			return $this;
		} // function

		/**
		 * Generates the model and migration for/with laravel and extends the contents of the generated classes.
		 * @param \DOMNode $node The node of the table.
		 * @return MakeMWBModel
		 */
		protected function loadModelTable(\DOMNode $node)
		{
			$tableObject = new TableMigration();

			$tableObject
				->load($node)
				->isPivotTable($isPivot = in_array($tableObject->getName(), $this->pivotTables));

			return $tableObject;
		} // function

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
		} // function

		/**
		 * Saves the model content for a table.
		 * @param TableMigration $table
		 * @return MakeMWBModel
		 */
		protected function saveModelForTable(TableMigration $table)
		{
			$fields = $table->getFields();
			$modelContent =
				(new ModelContent('\\' . $this->getAppNamespace() . $table->getModelName()))
					->setTable($table->getName());

			if (array_key_exists($field = 'deleted_at', $fields))
			{
				unset($fields[$field]);

				$modelContent
					->setDates(['deleted_at'])
					->setTraits(['\Illuminate\Database\Eloquent\SoftDeletes']);
			} // if

			$modelContent->setFillable(array_keys($fields));

			if ($genericCalls = $table->getGenericCalls())
			{
				foreach ($genericCalls as $call)
				{
					if ($call instanceof ForeignKey)
					{
						$modelContent->addForeignKey($call);
					} // if
				} // foreach
			} // if

			if ($sources = $table->getRelationSources())
			{
				foreach ($sources as $call)
				{
					if ($call instanceof ForeignKey)
					{
						$modelContent->addForeignKey($call);
					} // if
				} // foreach
			} // if

			$modelContent->save();

			return $this;
		} // function

		/**
		 * Sets the model reader.
		 * @param MWBModelReader $modelReader
		 * @return MakeMWBModel
		 */
		public function setModelReader(MWBModelReader $modelReader)
		{
			$this->modelReader = $modelReader;

			return $this;
		} // function

		/**
		 * Callback to sort tables with foreign keys to the end.
		 * @param TableMigration[] $tables
		 * @return TableMigration[]
		 */
		protected function sortTablesWithFKsToEnd(array $tables)
		{
			$return = [];

			while ($tables)
			{
				/** @var TableMigration $table */
				foreach ($tables as $key => $table)
				{
					// if there are no fks or the required fks are allready saved.
					if ((!$fks = $table->getForeignKeys()) || (count(array_intersect_key($return, $fks)) === count($fks)))
					{
						$return[$table->getName()] = $table;
						unset($tables[$key]);
					} // if
				} // foreach
			} // while

			return $return;
		} // function
	} // class