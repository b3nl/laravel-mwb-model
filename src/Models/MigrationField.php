<?php namespace b3nl\MWBModel\Models;

	/**
	 * Class to prepare the database fields for the laravel migrations.
	 * @package b3nl\MWBModel
	 * @subpackage Models
	 * @version $id$
	 */
	class MigrationField {
		/**
		 * The desired field.
		 * @var string
		 */
		protected $fieldName = '';

		/**
		 * The id of the field in the model.
		 * @var string
		 */
		protected $id = '';

		/**
		 * The found migration calls.
		 * @var array
		 */
		protected $migrations = [];

		/**
		 * XPath evals to check if a field option is needed.
		 *
		 * Key is the laravel call, the first array value is the xpath to check, if the call is needed and the second
		 * array value are the XPaths to get the method arguments.
		 * @var array
		 */
		protected $optionEvals = [
			'nullable' => [
				'boolean(./value[@type="int" and @key="isNotNull" and text() = 0])'
			],
			'default' => [
				'boolean(./value[@type="string" and @key="defaultValue" and text() != ""])',
				['./value[@type="string" and @key="defaultValue"]']
			]
		];

		/**
		 * XPath evals for the main type of the desired database field in the migration.
		 *
		 * Key is the laravel call, the first array value is the xpath to check, if the call is needed and the second
		 * array value are the XPaths to get the method arguments.
		 * @var array
		 */
		protected $typeEvals = [
			'bigInteger' => [
				'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.bigint"]',
				[
					'number(./value[@type="int" and @key="precision"])',
					'boolean(./value[@type="int" and @key="autoIncrement" and number() = 1])',
					'boolean(./value[@content-type="string" and @key="flags"]/value[@type="string" and text() = "UNSIGNED"])'
				]
			],
			'dateTime' => [
				'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.datetime"]'
			],
			'integer' => [
				'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.int"]',
				[
					'number(./value[@type="int" and @key="precision"])',
					'boolean(./value[@type="int" and @key="autoIncrement" and number() = 1])',
					'boolean(./value[@content-type="string" and @key="flags"]/value[@type="string" and text() = "UNSIGNED"])'
				]
			],
			'mediumInteger' => [
				'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.mediumint"]',
				[
					'number(./value[@type="int" and @key="precision"])',
					'boolean(./value[@type="int" and @key="autoIncrement" and number() = 1])',
					'boolean(./value[@content-type="string" and @key="flags"]/value[@type="string" and text() = "UNSIGNED"])'
				]
			],
			'text' => [
				'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.text"]'
			],
			'tinyInteger' => [
				'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.tinyint"]',
				[
					'number(./value[@type="int" and @key="precision"])',
					'boolean(./value[@type="int" and @key="autoIncrement" and text() = 1])',
					'boolean(./value[@content-type="string" and @key="flags"]/value[@type="string" and text() = "UNSIGNED"])'
				]
			],
			'string' => [
				'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.varchar"]',
				['number(./value[@type="int" and @key="length"])']
			]
		];

		/**
		 * Adds this method call to the list of migration calls.
		 * @param string $method
		 * @param array $aArgs
		 * @return MigrationField
		 */
		public function __call($method, array $aArgs = [])
		{
			// an index can't overwrite a foreign key.
			if (($method !== 'index') || (!isset($this->migrations['foreign']))) {
				$this->migrations[$method] = $aArgs;
			} // if

			// the foreign key overwrites an index.
			if (($method === 'foreign') && (isset($this->migrations['index']))) {
				unset($this->migrations['index']);
			} // if

			return $this;
		} // function

		/**
		 * The construct.
		 * @param string $fieldName The name of the field.
		 */
		public function __construct($fieldName)
		{
			$this->fieldName = $fieldName;
		} // function

		/**
		 * Returns the method call, to be saved in the migration file.
		 * @return string
		 */
		public function __toString()
		{
			$fieldMigration = '';

			if ($migrations = $this->getMigrations())
			{
				$fieldMigration .= '$table';

				$paramParser = function ($value)
				{
					return var_export($value, true);
				};

				foreach ($migrations as $method => $params)
				{
					$fieldMigration .= "->{$method}(";

					if ($params)
					{
						$fieldMigration .= implode(', ', array_map($paramParser, $params));
					} // if

					$fieldMigration .= ')';
				} // foreach
			} // if

			return $fieldMigration . ";";
		}

		/**
		 * Returns the id of this field in the model.
		 * @return string
		 */
		public function getId()
		{
			return $this->id;
		} // function

		/**
		 * Returns the migration calls for a field.
		 * @return array
		 */
		public function getMigrations()
		{
			return $this->migrations;
		} // function

		/**
		 * Loads the database migrations calls of the database field..
		 * @param \DOMNode $field
		 * @param \DOMXPath $rootPath
		 * @return MigrationField
		 */
		public function load(\DOMNode $field, \DOMXPath $rootPath)
		{
			$this->setId($field->attributes->getNamedItem('id')->nodeValue);

			return $this
				->loadMigrationCalls($field, $rootPath, $this->typeEvals)
				->loadMigrationCalls($field, $rootPath, $this->optionEvals, true);
		} // function

		/**
		 * Loads the database migrations calls of the database field..
		 * @param \DOMNode $field
		 * @param \DOMXPath $rootPath
		 * @param array $migrationRules The rules how the mgiration call should be created.
		 * @param bool $isOptional Should the main type or the minor options for the field be configured?
		 * @return MigrationField
		 */
		protected function loadMigrationCalls(\DOMNode $field, \DOMXPath $rootPath, array $migrationRules, $isOptional = false) {
			$method = '';
			$params = [];

			if (!$isOptional)
			{
				$params[] = $this->fieldName;
			} // if

			foreach ($migrationRules as $ruleMethod => $rules)
			{
				$evaledXPath = $rootPath->evaluate($rules[0], $field);

				// is it a found domnodelist or evaled the xpath to a scalar value !== false.
				if ((($evaledXPath instanceof \DOMNodeList) && ($evaledXPath->length)) ||
						((!($evaledXPath instanceof \DOMNodeList)) && $evaledXPath))
				{
					$method = $ruleMethod;
					break;
				} // if
			} // foreach

			// optional calls are only added, if the desired method is found.
			if ($method || !$isOptional)
			{
				if (!$method)
				{
					$method = 'string';
					$rules = [];
				} // if

				if (@$rules[1] && is_array($rules[1]))
				{
					foreach ($rules[1] as $paramPath)
					{
						$paramResult = $rootPath->evaluate($paramPath, $field);
						$isResultNodeList = $paramResult instanceof \DOMNodeList;

						if ((($isResultNodeList) && ($paramResult->length)) || (!$isResultNodeList))
						{
							$params[] = $isResultNodeList ? $paramResult->item(0)->nodeValue : $paramResult;
						} // if
					} // foreach
				} // if

				call_user_func_array([$this, $method], $params);
			} // if

			return $this;
		} // function

		/**
		 * Sets the id of this field.
		 * @param string $id
		 * @return MigrationField
		 */
		public function setId($id)
		{
			$this->id = $id;

			return $this;
		} // function
	} // class