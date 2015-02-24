<?php namespace b3nl\MWBModel;

	/**
	 * XMl Reader for the model file.
	 * @package b3nl\MWBModel
	 * @version $id$
	 */
	class MWBModelReader extends \XMLReader {
		/**
		 * Returns true, if the model is of the right version.
		 * @return bool
		 * @todo   This method changes the position of the xml cursor!
		 */
		public function isCompatibleVersion()
		{
			$return = false;

			while ($this->read() && !$return)
			{
				$return = ($this->nodeType === \XMLReader::ELEMENT) && $this->hasAttributes && ($this->getAttribute('version') === '1.4.4');
			} // while

			return $return;
		} // function

		/**
		 * Returns true, if the actual node is a model table.
		 * @return bool
		 */
		public function isModelTable()
		{
			return ($this->nodeType === \XMLReader::ELEMENT) && $this->hasAttributes &&
				($this->getAttribute('struct-name') === 'db.mysql.Table');
		} // function
	} // class