<?php namespace b3nl\MWBModel\Models;

	use b3nl\MWBModel\Models\Migration\ForeignKey;

	/**
	 * Saves content in the model stub.
	 * @method ModelContent setFillable() setFillable(array $fillable) Sets the fillable fields.
	 * @method ModelContent setTable() setTable(string $table) Sets the table name.
	 * @package b3nl\MWBModel
	 * @subpackage Models
	 * @version $id$
	 */
	class ModelContent {
		/**
		 * Strings which should be added additionally.
		 * @var ForeignKey[]
		 */
		protected $foreignKeys = [];

		/**
		 * Caches the used properties.
		 * @var array
		 */
		protected $properties = [];

		/**
		 * The extended model.
		 * @var object|string
		 */
		protected $targetModel = null;

		/**
		 * Construct.
		 * @param string|object $targetModel The target model for writing the properties.
		 */
		public function __construct($targetModel)
		{
			$this->targetModel = $targetModel;
		} // function

		/**
		 * Setter for the stubbed properties.
		 * @param string $method
		 * @param array $args
		 * @return ModelContent
		 */
		public function __call($method, array $args = [])
		{
			if (strpos($method, 'set') === 0) {
				$this->properties[lcfirst(substr($method, 3))] = $args[0];
			} // if

			return $this;
		} // function

		/**
		 * Adds a foreign key to this model.
		 * @param ForeignKey $key
		 * @return ModelContent
		 */
		public function addForeignKey(ForeignKey $key)
		{
			$this->foreignKeys[] = $key;

			return $this;
		}

		/**
		 * Returns the foreign keys.
		 * @return Migration\ForeignKey[]
		 */
		public function getForeignKeys()
		{
			return $this->foreignKeys;
		} // function

		/**
		 * Returns the parsed method calls for the foreign keys.
		 * @param \ReflectionClass $toModel
		 * @return array
		 */
		protected function getMethodCallsForForeignKeys(\ReflectionClass $toModel)
		{
			$methods = [];

			if ($keys = $this->getForeignKeys()) {
				/** @var ForeignKey $key */
				foreach ($keys as $key) {
					$relatedTable = $key->getRelatedTable();

					if ($key->isSource())
					{
						$methodName = strtolower($relatedTable->getName());
						$methodContent = 'return $this->has' . ($key->isForMany() ? 'Many' : 'One') .
							"('{$toModel->getNamespaceName()}\\{$relatedTable->getModelName()}');";
					} // if
					else
					{
						$methodName = strtolower($relatedTable->getModelName());
						$methodContent = "return \$this->belongsTo('{$toModel->getNamespaceName()}\\{$relatedTable->getModelName()}', '{$key->foreign}');";
					} // else

					$method = "/**\n * Getter for {$key->on}.\n * @return \\Illuminate\\Database\\Eloquent\\Relations\\Relation\n */\n" .
						"public function {$methodName}()\n{\n\t{$methodContent}\n} // function";

					$methods[] = $method;
				} // foreach
			} // if

			return $methods;
		} // function

		/**
		 * Returns the extended object.
		 * @return object|string
		 */
		public function getTargetModel()
		{
			return $this->targetModel;
		} // function

		/**
		 * Returns the used properties.
		 * @return array
		 */
		public function getProperties()
		{
			return $this->properties;
		} // function

		/**
		 * Saves the properties in the placeholder.
		 * @param string $inPlaceholder
		 * @return bool
		 */
		public function save($inPlaceholder = "\t//")
		{
			try {
				$reflection = new \ReflectionClass($target = $this->getTargetModel());

				$this->writeToModel($reflection, $inPlaceholder);
			} catch (\ReflectionException $exc) {
				throw new \LogicException(sprintf(
					'Model %s not found', is_object($target) ? get_class($target) : $target
				));
			} // catch

			return true;
		} // function

		/**
		 * Writes the stub properties to the target model.
		 * @param \ReflectionClass $toModel The reflection of the target model.
		 * @param string $inPlaceholder
		 * @return int
		 */
		protected function writeToModel(\ReflectionClass $toModel, $inPlaceholder = "\t//")
		{
			$modelFile = $toModel->getFileName();
			$replaces = [];
			$searches = [];

			foreach ($this->getProperties() as $property => $content) {
				$replaces[] = var_export($content, true);
				$searches[] = '{{' . $property . '}}';
			} // foreach

			$newContent = str_replace($searches, $replaces, file_get_contents(realpath(__DIR__ . '/stubs/model-content.stub')));
			$methodContent = '';

			if ($keys = $this->getForeignKeys()) {
				$methodContent = implode("\n\n", $this->getMethodCallsForForeignKeys($toModel));
			} // if

			$newContent = str_replace('// {{relations}}', $methodContent, $newContent);

			return file_put_contents(
				$modelFile,
				str_replace(
					$inPlaceholder,
					$newContent,
					file_get_contents($modelFile)
				)
			);
		} // function
	} // class