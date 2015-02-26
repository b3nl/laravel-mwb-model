<?php namespace b3nl\MWBModel\Console\Commands;

	use b3nl\MWBModel\MWBModelReader,
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
		 * Opens the zipped model container and reads the xml model file.
		 * @return void
		 */
		public function fire()
		{
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
				['example', null, InputOption::VALUE_OPTIONAL, 'An example option.', null],
			];
		} // function

		/**
		 * Generates the model and migration for/with laravel and extends the contents of the generated classes.
		 * @param \DOMNode $node The node of the table.
		 * @return MakeMWBModel
		 */
		protected function handleModelTable(\DOMNode $node)
		{
			$tableObject = new TableMigration();
			$tableObject->load($node);

			$this->call('make:model', ['name' => $tableObject->getModelName()]);

			return $tableObject;
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
					$table = $this->handleModelTable($reader->expand());

					$tables[$table->getId()] = $table;
				} // if
			} // while

			$tableIdMap = array_map(function(TableMigration $table) { return $table->getName(); }, $tables);

			/** @var TableMigration $tableObject */
			foreach ($tables as $tableName => $tableObject) {
				$tableName = $tableObject->getName();

				$migrationFiles = glob(
					$this->getMigrationPath() . DIRECTORY_SEPARATOR . "*_create_{$tableName}_table.php"
				);

				if ($migrationFiles)
				{
					$tableObject->save(end($migrationFiles), $tableIdMap);
				} // if

				(new ModelContent('\\' . $this->getAppNamespace() . $tableObject->getModelName()))
					->setFillable(array_keys($tableObject->getFields()))
					->setTable($tableName)
					->save();
			} // foreach

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
	} // class