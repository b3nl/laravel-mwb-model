<?php namespace b3nl\MWBModel\Models;

	use b3nl\MWBModel\Models\Migration\Base;

	/**
	 * Class to prepare the table for the laravel migrations.
	 * @package b3nl\MWBModel
	 * @subpackage Models
	 * @version $id$
	 */
	class TableMigration {
		/**
		 * General columns with unspeficied/amobiguous rows.
		 * @var array
		 */
		protected $genericCalls = [];

		/**
		 * The id of the field in the model.
		 * @var string
		 */
		protected $id = '';

		/**
		 * Caching of the database fields.
		 * @var MigrationField[]
		 */
		protected $fields = [];

		/**
		 * Name of this table.
		 * @var string
		 */
		protected $name = '';

		/**
		 * The used model node.
		 * @var null|\DOMNode
		 */
		protected $modelNode = null;

		/**
		 * Fields which should be skipped by default.
		 * @var array
		 */
		protected $skippedFields = ['created_at', 'id', 'updated_at'];

		/**
		 * Adds a field for the given name.
		 * @param string $name The field name.
		 * @return MigrationField
		 */
		protected function addField($name)
		{
			$this->fields[$name] = $field = new MigrationField($name);

			return $field;
		} // function

		/**
		 * Adds the foreign keys to the field.
		 * @param \DOMXPath $rootPath
		 * @return MigrationField[]
		 */
		protected function addForeignKeysToFields(\DOMXPath $rootPath)
		{
			$fields = $this->getFields();
			$idMap = array_flip(array_map(function(MigrationField $field) { return $field->getId(); }, $fields));

			/** @var MigrationField $field */
			foreach ($fields as $name => $field)
			{
				$usedId = $field->getId();
				$indexNodes = $this->getForeignKeysForField($field, $rootPath);

				if ($indexNodes && $indexNodes->length)
				{
					/** @var \DOMNode $indexNode */
					foreach ($indexNodes as $indexNode)
					{
						$call = new Base();

						$call
							->foreign($field->getName())
							->references('id')
							->on(
								$rootPath->evaluate(
									'string(.//link[@type="object" and @struct-name="db.mysql.Table" and @key="referencedTable"])' ,
									$indexNode
								)
						);

						if ($rule = $rootPath->evaluate('string(./value[@key="deleteRule"])', $indexNode))
						{
							$call->onDelete(strtolower($rule));
						} // if

						if ($rule = $rootPath->evaluate('string(./value[@key="updateRule"])', $indexNode))
						{
							$call->onUpdate(strtolower($rule));
						} // if
					} // foreach

					$this->addGenericCall($call);
				} // if
			} // foreach

			return $fields;
		} // function

		/**
		 * Adds a possible ambigiuous call.
		 * @param Base $call The migration call.
		 * @return TableMigration
		 * @todo Duplicates on "doubled" unique keys.
		 */
		protected function addGenericCall(Base $call)
		{
			$this->genericCalls[] = $call;

			return $this;
		} // function

		/**
		 * Adds the simple indices to the fields directly and returns the indices for multiple values.
		 * @param \DOMXPath $rootPath
		 * @return array
		 * @todo   Primary missing; Problems with m:ns.
		 */
		protected function addIndicesToFields(\DOMXPath $rootPath)
		{
			$fields = $this->getFields();
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
					$fks = $this->getForeignKeysForField($field, $rootPath);

					/** @var \DOMNode $indexNode */
					foreach ($indexNodes as $indexNode)
					{
						$indexColumns = $rootPath->query(
							'.//link[@type="object" and @struct-name="db.Column" and @key="referencedColumn"]',
							$indexNode
						);

						$isSingleColumn = $indexColumns && $indexColumns->length <= 1;
						$genericCall = !$isSingleColumn ? new Base() : null;

						if ($rootPath->evaluate('boolean(./value[@type="int" and @key="unique" and number() = 1])', $indexNode))
						{
							if ($isSingleColumn)
							{
								$field->unique();
							} // if
							else
							{
								$genericMethod = 'unique';
							} // else
						} // if

						$hasIndex = $rootPath->evaluate(
							'boolean(./value[@type="string" and @key="indexType" and text() = "INDEX"])', $indexNode
						);

						if ($hasIndex && (!$fks || !$fks->length))
						{
							if ($isSingleColumn)
							{
								$field->index();
							} // if
							else
							{
								$genericMethod = 'index';
							} // else
						} // if

						if ($genericCall) {
							$genericParams = [];
							/** @var \DOMNode $column */
							foreach ($indexColumns as $column) {
								$genericParams[] = $idMap[$column->nodeValue];
							} // foreach

							$this->addGenericCall(call_user_func_array(
								[$genericCall, $genericMethod], $genericParams ? [$genericParams] : []
							));
						} // if
					} // foreach
				} // if
			} // foreach

			return array_unique($multipleIndices);
		} // function

		/**
		 * Returns the foreign key fields for a field.
		 * @param MigrationField $field
		 * @param \DOMXPath $rootPath
		 * @return \DOMNodeList
		 */
		protected function getForeignKeysForField(MigrationField $field, \DOMXPath $rootPath)
		{
			return $rootPath->query(
				'./value[@content-struct-name="db.mysql.ForeignKey" and @key="foreignKeys"]/' .
					'value[@type="object" and @struct-name="db.mysql.ForeignKey"]//' .
							'value[@type="list" and @content-type="object" and @content-struct-name="db.Column" and @key="columns"]/' .
						'link[text() = "' . $field->getId() . '"]/' .
					'../' .
				'..'
			);
		} // function
		
		/**
		 * Returns the generic calls, the ambigiuous calls for fields.
		 * @return Base[]
		 */
		public function getGenericCalls()
		{
			return $this->genericCalls;
		} // function

		/**
		 * Returns the field with the given name
		 * @param string $name
		 * @return null
		 */
		public function getField($name)
		{
			return $this->fields[$name] ?: $this->addField($name);
		} // function

		/**
		 * Returns the migration fields.
		 * @return MigrationField[]
		 */
		public function getFields()
		{
			return $this->fields;
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
		 * Returns the name of the table.
		 * @return string
		 */
		public function getName()
		{
			return $this->name;
		} // function

		/**
		 * Returns the model name.
		 * @return string
		 */
		public function getModelName()
		{
			$tableName = $this->getName();

			return ucfirst($this->isReservedPHPWord($tmp = rtrim($tableName, 's')) ? $tableName : $tmp);
		} // function

		/**
		 * Returns true if the given word is a php keyword.
		 * @param string $word
		 * @return bool
		 */
		protected function isReservedPHPWord($word)
		{
			$keywords = [
				'__halt_compiler', 'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class',
				'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty',
				'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends',
				'final', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once',
				'instanceof', 'insteadof', 'interface', 'isset', 'list', 'namespace', 'new', 'or', 'print', 'private',
				'protected', 'public', 'require', 'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'try',
				'unset', 'use', 'var', 'while', 'xor'
			];

			$predefined_constants = [
				'__CLASS__', '__DIR__', '__FILE__', '__FUNCTION__', '__LINE__', '__METHOD__','__NAMESPACE__', '__TRAIT__'
			];

			return in_array($word, $keywords) || in_array($word, $predefined_constants);
		} // function

		public function load(\DOMNode $node)
		{
			$dom = new \DOMDocument('1.0');
			$dom->importNode($node, true);
			$dom->appendChild($node);

			$path = new \DOMXPath($dom);

			$this->setId($tableId = $node->attributes->getNamedItem('id')->nodeValue);
			$this->setName($tableName = $path->evaluate('string((./value[@key="name"])[1])'));

			if (!$tableId || !$tableName) {
				throw new \LogicException('Table name or id could not be fond.');
			} // if

			return $this->loadMigrationFields($path);
		} // function


		/**
		 * Returns the migration fields of the model.
		 * @param \DOMXPath $rootPath
		 * @return TableMigration
		 */
		protected function loadMigrationFields(\DOMXPath $rootPath)
		{
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

					$this->addField($fieldName)->load($field, $rootPath);
				} // foreach
			} // if

			$this->addIndicesToFields($rootPath);

			return $this->addForeignKeysToFields($rootPath);
		} // function

		/**
		 * Saves the table in the given migration file.
		 * @param string $file
		 * @param array $otherTables Mapping of the table ids to their names.
		 * @return bool
		 */
		public function save($file, $otherTables)
		{
			$replace = $search = "\$table->increments('id');\n";

			if ($fieldObjects = $this->getFields())
			{
				$replace .= implode("\n", $fieldObjects);
			} // if

			if ($calls = $this->getGenericCalls())
			{
				$replace .= "\n" . implode("\n", $calls);
			} // if

			foreach ($otherTables as $id => $name)
			{
				$replace = str_replace($id, $name, $replace);
			} // foreach

			return (bool) file_put_contents($file, str_replace($search, $replace, file_get_contents($file)));
		} // function

		/**
		 * Sets the generic calls, the ambigiuous calls for fields.
		 * @param array $genericCalls
		 * @return TableMigration
		 */
		public function setGenericCalls($genericCalls)
		{
			$this->genericCalls = $genericCalls;

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
		 * Sets the name of the table.
		 * @param string $name
		 * @return TableMigration
		 */
		public function setName($name)
		{
			$this->name = $name;

			return $this;
		} // function
	} // class