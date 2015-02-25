<?php namespace b3nl\MWBModel\Console\Commands;

	use b3nl\MWBModel\MWBModelReader,
		b3nl\MWBModel\Models\MigrationField,
		b3nl\MWBModel\Models\ModelContent,
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
		 * The console command name.
		 *
		 * @var string
		 */
		protected $name = 'make:mwb-model';

		/**
		 * The console command description.
		 *
		 * @var string
		 */
		protected $description = 'Creates basic laravel migrations and models for the given MySQL Workbench model.';

		/**
		 * Fields which should be skipped by default.
		 * @var array
		 */
		protected $skippedFields = ['created_at', 'id', 'updated_at'];

		/**
		 * Saving of the table names and their ids.
		 * @var array
		 */
		protected $tables = [];

		/**
		 * Adds the simple indices to the fields directly and returns the indices for multiple values.
		 * @param MigrationField[] $fields
		 * @param \DOMXPath $rootPath
		 * @return array
		 * @todo   Primary missing; Problems with m:ns; Missing FK in Models.
		 */
		protected function addIndicesToFields(array $fields, \DOMXPath $rootPath)
		{
			$multipleIndices = [];
			$idMap = array_flip(array_map(function(MigrationField $field) { return $field->getId(); }, $fields));

			/** @var MigrationField $field */
			foreach ($fields as $name => $field)
			{
				$usedId = $field->getId();
				$indexNodes = $rootPath->query(
					'./value[@content-struct-name="db.mysql.Index" and @key="indices"]/' .
						'value[@type="object" and @struct-name="db.mysql.Index"]//' .
							'value[@type="object" and @struct-name="db.mysql.IndexColumn"]//' .
								'link[@type="object" and @struct-name="db.Column" and @key="referencedColumn" and text() = "' . $usedId . '"]/' .
							'../' .
						'../' .
					'..'
				);

				if ($indexNodes && $indexNodes->length)
				{
					/** @var \DOMNode $indexNode */
					foreach ($indexNodes as $indexNode)
					{
						$indexColumns = $rootPath->query(
							'.//link[@type="object" and @struct-name="db.Column" and @key="referencedColumn"]',
							$indexNode
						);

						$isSingleColumn = $indexColumns && $indexColumns->length <= 1;
						$multiCall = !$isSingleColumn ? '$table->' : '';

						if ($rootPath->evaluate('boolean(./value[@type="int" and @key="unique" and number() = 1])', $indexNode))
						{
							if ($isSingleColumn)
							{
								$field->unique();
							} // if
							else
							{
								$multiCall .= 'unique([';
							} // else
						} // if

						if ($rootPath->evaluate('boolean(./value[@type="string" and @key="indexType" and text() = "INDEX"])', $indexNode))
						{
							if ($isSingleColumn)
							{
								$field->index();
							} // if
							else
							{
								$multiCall .= 'index([';
							} // else
						} // if

						if ($multiCall) {
							/** @var \DOMNode $column */
							foreach ($indexColumns as $column) {
								$multiCall .= var_export($idMap[$column->nodeValue], true) . ', ';
							} // foreach

							$multipleIndices[] = rtrim($multiCall, ', ') . ']);';
						} // if
					} // foreach
				} // if
			} // foreach

			return array_unique($multipleIndices);
		} // function

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

				if (!$reader->isCompatibleVersion())
				{
					throw new \InvalidArgumentException('Wrong model version.');
				} // if

				while ($reader->read())
				{
					if ($reader->isModelTable())
					{
						$this->handleModelTable($reader->expand());
					} // if
				} // while
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
		 * Returns the migration fields of the model.
		 * @param \DOMXPath $rootPath
		 * @return MigrationField[]
		 */
		protected function getMigrationFields(\DOMXPath $rootPath)
		{
			$fieldObjects = [];

			$fields = $rootPath->query(
				'./value[@type="list" and @key="columns"]/value[@type="object" and @struct-name="db.mysql.Column"]'
			);

			if ($fields && $fields->length)
			{
				/** @var \DOMNode $field */
				foreach ($fields as $field)
				{
					$fieldName = $rootPath->query('./value[@key="name"]', $field)->item(0)->nodeValue;

					if (in_array($fieldName, $this->skippedFields))
					{
						continue;
					} // if

					$fieldObjects[$fieldName] = (new MigrationField($fieldName))->load($field, $rootPath);
				} // foreach
			} // if

			return $fieldObjects;
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
			$dom = new \DOMDocument('1.0');
			$dom->importNode($node, true);
			$dom->appendChild($node);

			$path = new \DOMXPath($dom);
			$tableNames = $path->query('(./value[@key="name"])[1]');

			if ($tableNames && $tableNames->length)
			{
				// TODO Use model name after naming check.
				$this->call('make:model', ['name' => $tableName = $tableNames->item(0)->nodeValue]);

				if ($fieldObjects = $this->getMigrationFields($path))
				{
					$multipleIndices = $this->addIndicesToFields($fieldObjects, $path);

					// TODO Move the following to a class.
					$migrationFiles = glob(
						$this->getMigrationPath() . DIRECTORY_SEPARATOR . "*_create_{$tableName}_table.php"
					);

					$search = "\$table->increments('id');\n";

					if ($migrationFiles)
					{
						file_put_contents(
							$migrationFile = end($migrationFiles),
							str_replace(
								$search = "\$table->increments('id');\n",
								$search . implode("\n", $fieldObjects) . "\n" . implode("\n", $multipleIndices),
								file_get_contents($migrationFile)
							)
						);
					} // if

					/*
					 * TODOS
					 * ./value[@struct-name="db.mysql.ForeignKey"]
					 */

					(new ModelContent('\\' . $this->getAppNamespace() . $tableName))
						->setFillable(array_keys($fieldObjects))
						->setTable($tableName)
						->save();
				} // if
			} // if

			return $this;
		} // function
	} // class