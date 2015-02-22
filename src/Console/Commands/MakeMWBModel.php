<?php namespace b3nl\MWBModel\Console\Commands;

	use Illuminate\Console\AppNamespaceDetectorTrait;
	use Illuminate\Database\Console\Migrations\BaseCommand;
	use Symfony\Component\Console\Input\InputOption;
	use Symfony\Component\Console\Input\InputArgument;

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
		 * Execute the console command.
		 *
		 * @return mixed
		 */
		public function fire()
		{
			if (!is_readable($file = $this->argument('MWB-Model'))) {
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

			$fieldSkips = array('created_at', 'id', 'updated_at');

			$typeMap = array(
				'com.mysql.rdbms.mysql.datatype.bigint'   => 'bigInteger',
				'com.mysql.rdbms.mysql.datatype.datetime' => 'dateTime',
				'com.mysql.rdbms.mysql.datatype.int'      => 'integer',
				'com.mysql.rdbms.mysql.datatype.text'     => 'text',
				'com.mysql.rdbms.mysql.datatype.tinyint'  => 'tinyInteger',
				'com.mysql.rdbms.mysql.datatype.varchar'  => 'string'
			);

			foreach (glob($dir . '*.mwb.xml') as $file) {
				$reader = new \XMLReader();
				$reader->open($file);

				while ($reader->read()) {
					if (($reader->nodeType === \XMLReader::ELEMENT) && $reader->hasAttributes &&
							($reader->getAttribute('struct-name') === 'db.mysql.Table'))
					{
						$dom = new \DOMDocument('1.0');
						$dom->importNode($node = $reader->expand(), true);
						$dom->appendChild($node);

						$path = new \DOMXPath($dom);
						$tableNames = $path->query('(./value[@key="name"])[1]');

						if ($tableNames && $tableNames->length) {
							/** @var \DOMElement $name */
							foreach ($tableNames as $tableName) {
								$this->call('make:model', ['name' => $tableName->nodeValue]);
							} // foreach

							$fields = $path->query(
								'./value[@type="list" and @key="columns"]/value[@type="object" and @struct-name="db.mysql.Column"]'
							);

							if (count($fields)) {
								$fieldNames      = array();
								$fieldMigrations = '';

								/** @var \DOMElement $name */
								foreach ($fields as $field) {
									$fieldName = $path->query('./value[@key="name"]', $field)->item(0)->nodeValue;

									if (!in_array($fieldName, $fieldSkips)) {
										$fieldNames[]     = $fieldName;
										$fieldMigrations .= "\$table->{$typeMap[$path->query('./link[@key="simpleType"]', $field)->item(0)->nodeValue]}('{$fieldName}');\n";
									} // if
								} // foreach

								$migrationFiles = glob(
									$this->getMigrationPath() . DIRECTORY_SEPARATOR . "*_create_{$tableName->nodeValue}_table.php"
								);

								if ($migrationFiles) {
									file_put_contents(
										$migrationFile = end($migrationFiles),
										str_replace(
											$search = "\$table->increments('id');\n",
											$search . $fieldMigrations,
											file_get_contents($migrationFile)
										)
									);
								} // if

								try {
									$reflection = new \ReflectionClass('\\' . $this->getAppNamespace() . $tableName->nodeValue);

									file_put_contents(
										$modelFile  = $reflection->getFileName(),
										str_replace(
											"\t//",
											str_replace(
												array('{{fillable}}', '{{table}}'),
												array(var_export($fieldNames, true), $tableName->nodeValue),
												file_get_contents(realpath(__DIR__ . '/../stubs/model-content.stub'))
											),
											file_get_contents($modelFile)
										)
									);
								} catch (\ReflectionException $exc) {
									throw new \LogicException(sprintf('Model for table %s not found', $tableName->nodeValue));
								} // catch
							} // if
						} // if
					} // if
				} // while
			} // foreach
		} // function

		/**
		 * Get the console command arguments.
		 * @return array
		 */
		protected function getArguments()
		{
			return [
				['MWB-Model', InputArgument::REQUIRED, 'The file path to your *.mwb-file.'],
			];
		} // function

		/**
		 * Get the console command options.
		 *
		 * @return array
		 */
		protected function getOptions()
		{
			return [
				['example', null, InputOption::VALUE_OPTIONAL, 'An example option.', null],
			];
		} // function
	} // class
