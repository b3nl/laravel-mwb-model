<?php namespace b3nl\MWBModel\Models\Migration;

	use b3nl\MWBModel\Models\TableMigration;

	/**
	 * Class to migrate foreign keys.
	 * @author b3nl <github@b3nl.de>
	 * @package b3nl\MWBModel
	 * @subpackage Models\Migration
	 * @version $id$
	 */
	class ForeignKey extends Base {
		/**
		 * Is this foreign key for a 1:n?
		 * @var bool
		 */
		protected $isForMany = false;

		/**
		 * Is this foreign key for a pivot table?
		 * @var bool
		 */
		protected $isForPivot = false;

		/**
		 * Is this this the relation source?
		 * @var bool
		 */
		protected $isSource = false;

		/**
		 * The related table.
		 * @var TableMigration|void
		 */
		protected $relatedTable = null;

		/**
		 * Returns the related table.
		 * @return TableMigration|void
		 */
		public function getRelatedTable()
		{
			return $this->relatedTable;
		} // function

		/**
		 * Is this foreign key for a 1:n?
		 * @param bool $newStatus The new status.
		 * @return bool Returns the old status.
		 */
		public function isForMany($newStatus = false)
		{
			$oldStatus = $this->isForMany;

			if (func_num_args())
			{
				$this->isForMany = $newStatus;
			} // if

			return $oldStatus;
		} // function

		/**
		 * Is this foreign key for a m:n?
		 * @param bool $newStatus The new status.
		 * @return bool Returns the old status.
		 */
		public function isForPivotTable($newStatus = false)
		{
			$oldStatus = $this->isForPivot;

			if (func_num_args())
			{
				$this->isForPivot = $newStatus;
			} // if

			return $oldStatus;
		} // function

		/**
		 * Is this this the relation source?
		 * @param bool $newStatus The new status.
		 * @return bool Returns the old status.
		 */
		public function isSource($newStatus = true)
		{
			$oldStatus = $this->isSource;

			if (func_num_args())
			{
				$this->isSource = $newStatus;
			} // if

			return $oldStatus;
		} // function

		/**
		 * Sets the related table.
		 * @param TableMigration $relatedTable
		 * @return ForeignKey
		 */
		public function setRelatedTable(TableMigration $relatedTable)
		{
			$this->relatedTable = $relatedTable;

			return $this;
		} // function
	} // class