<?php namespace b3nl\MWBModel\Models;

	use b3nl\MWBModel\Models\Migration\Base;

	/**
	 * Class to prepare the database fields for the laravel migrations.
	 * @package b3nl\MWBModel
	 * @subpackage Models
	 * @version $id$
	 */
	class MigrationField extends Base {
		/**
		 * The id of the field in the model.
		 * @var string
		 */
		protected $id = '';

		/**
		 * The desired field.
		 * @var string
		 */
		protected $name = '';

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
					'boolean(./value[@type="int" and @key="autoIncrement" and number() = 1])',
					'boolean(./value[@content-type="string" and @key="flags"]/value[@type="string" and text() = "UNSIGNED"])'
				]
			],
			'softDeletes' => [
				'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.datetime"]/../value[@key="name" and text() = "deleted_at"]'
			],
			'dateTime' => [
				'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.datetime"]'
			],
			'increments' => [
				'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.int"] and ./value[@key="name" and text() = "id"] and ./value[@type="int" and @key="autoIncrement" and number() = 1]'
			],
			'integer' => [
				'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.int"]',
				[
					'boolean(./value[@type="int" and @key="autoIncrement" and number() = 1])',
					'boolean(./value[@content-type="string" and @key="flags"]/value[@type="string" and text() = "UNSIGNED"])'
				]
			],
			'mediumInteger' => [
				'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.mediumint"]',
				[
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
					'boolean(./value[@type="int" and @key="autoIncrement" and text() = 1])',
					'boolean(./value[@content-type="string" and @key="flags"]/value[@type="string" and text() = "UNSIGNED"])'
				]
			],
			'smallInteger' => [
				'./link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.smallint"]',
				[
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
		 * The construct.
		 * @param string $name The name of the field.
		 */
		public function __construct($name)
		{
			$this->setName($name);
		} // function

		/**
		 * Returns the id of this field in the model.
		 * @return string
		 */
		public function getId()
		{
			return $this->id;
		}

		/**
		 * Returns the field name.
		 * @return string
		 */
		public function getName()
		{
			return $this->name;
		}

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
				$params[] = $this->name;
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

		/**
		 * Sets the fieldname.
		 * @param string $name
		 * @return MigrationField
		 */
		public function setName($name)
		{
			$this->name = $name;

			return $this;
		} // function
	} // class