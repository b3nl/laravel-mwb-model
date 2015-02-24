<?php namespace b3nl\MWBModel\Models;

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
		 * Returns the extended object.
		 * @return object|string
		 */
		public function getTargetModel()
		{
			return $this->targetModel;
		}

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

				$this->writeToModel($reflection->getFileName(), $inPlaceholder);
			} catch (\ReflectionException $exc) {
				throw new \LogicException(sprintf(
					'Model %s not found', is_object($target) ? get_class($target) : $target
				));
			} // catch

			return true;
		} // function

		/**
		 * Writes the stub properties to the target model.
		 * @param string $modelFile
		 * @param string $inPlaceholder
		 * @return int
		 */
		protected function writeToModel($modelFile, $inPlaceholder = "\t//")
		{
			$replaces = [];
			$searches = [];

			foreach ($this->getProperties() as $property => $content) {
				$replaces[] = var_export($content, true);
				$searches[] = '{{' . $property . '}}';
			} // foreach

			return file_put_contents(
				$modelFile,
				str_replace(
					$inPlaceholder,
					str_replace($searches, $replaces, file_get_contents(realpath(__DIR__ . '/stubs/model-content.stub'))),
					file_get_contents($modelFile)
				)
			);
		} // function
	} // class