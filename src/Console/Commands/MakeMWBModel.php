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

			$fieldSkips = ['created_at', 'id', 'updated_at'];
			$typeEvals = [
				'bigInteger' => [
					'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.bigint"]',
					'./value[@type="int" and @key="precision"]'
				],
				'dateTime' => [
					'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.datetime"]'
				],
				'integer' => [
					'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.int"]',
					'./value[@type="int" and @key="precision"]'
				],
				'text' => [
					'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.text"]'
				],
				'tinyInteger' => [
					'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.tinyint"]',
					'./value[@type="int" and @key="precision"]'
				],
				'string' => [
					'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.varchar"]',
					'./value[@type="int" and @key="length"]'
				]
			];

			foreach (glob($dir . '*.mwb.xml') as $file) {
				$reader = new \XMLReader();
				$reader->open($file);

				while ($reader->read()) {
					// TODO Version Check.

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
							foreach ($tableNames as $tableNameObject) {
								$this->call('make:model', ['name' => $tableName = $tableNameObject->nodeValue]);
							} // foreach

							$fields = $path->query(
								'./value[@type="list" and @key="columns"]/value[@type="object" and @struct-name="db.mysql.Column"]'
							);

							if (count($fields)) {
								$fieldNames      = [];
								$fieldMigrations = '';

								/** @var \DOMElement $name */
								foreach ($fields as $field) {
									$fieldNames[] = $fieldName = $path->query('./value[@key="name"]', $field)->item(0)->nodeValue;
									$method       = '';

									if (in_array($fieldName, $fieldSkips)) {
										continue;
									} // if

									foreach ($typeEvals as $ruleMethod => $rules) {
										$evaledXPath = $path->evaluate(array_shift($rules), $field);

										// is it a found domnodelist or evaled the xpath to a scalar value !== false.
										if ((($evaledXPath instanceof \DOMNodeList) && ($evaledXPath->length)) ||
											((!($evaledXPath instanceof \DOMNodeList)) && $evaledXPath))
										{
											$method = $ruleMethod;
											break;
										} // if
									} // foreach

									if (!$method) {
										$this->info(sprintf(
											'Field %s of table %s could not be found. Fallback used.',
											$fieldName,
											$tableName
										));

										$method = 'string';
										$rules  = array();
									} // if

									$fieldMigrations .= "\$table->{$method}('{$fieldName}'";

									if ($rules) {
										foreach ($rules as $paramPath) {
											$pathResult = $path->query($paramPath, $field);

											if ($pathResult && $pathResult->length) {
												$fieldMigrations .= ', ' . var_export($pathResult->item(0)->nodeValue, true);
											} // if
										} // foreach
									} // if

									$fieldMigrations .= ");\n";
								} // foreach

								$migrationFiles = glob(
									$this->getMigrationPath() . DIRECTORY_SEPARATOR . "*_create_{$tableName}_table.php"
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
									$reflection = new \ReflectionClass('\\' . $this->getAppNamespace() . $tableName);

									file_put_contents(
										$modelFile  = $reflection->getFileName(),
										str_replace(
											"\t//",
											str_replace(
												['{{fillable}}', '{{table}}'],
												[var_export($fieldNames, true), $tableName],
												file_get_contents(realpath(__DIR__ . '/../stubs/model-content.stub'))
											),
											file_get_contents($modelFile)
										)
									);
								} catch (\ReflectionException $exc) {
									throw new \LogicException(sprintf('Model for table %s not found', $tableName));
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
