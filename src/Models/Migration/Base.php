<?php namespace b3nl\MWBModel\Models\Migration;

    /**
     * Basic migration call.
     * @author b3nl <github@b3nl.de>
     * @package b3nl\MWBModel
     * @subpackage Models\Migration
     * @version $id$
     */
class Base
{
    /**
         * The found migration calls.
         * @var array
         */
    protected $migrations = [];

    /**
         * Startpoint of the call hierarchy.
         * @var string
         */
    protected $startPoint = '$table';

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
         * Returns the parameters of the given migrated method.
         * @param string $name
         * @return null|mixed
         */
    public function __get($name)
    {
        $return = null;

        if (isset($this->$name)) {
            $tmp = $this->migrations[$name];

            $return = is_array($tmp) && count($tmp) === 1 ? current($tmp) : $tmp;
        } //

        return $return;
    } // function

    /**
         * Returns true if there is a migration with the given method call.
         * @param string $name
         * @return bool
         */
    public function __isset($name)
    {
        return isset($this->migrations[$name]);
    } // function

    /**
         * Changes the parameters of a migration call.
         * @param string $name The method name.
         * @param $value
         * @return void
         */
    public function __set($name, $value)
    {
        $this->migrations[$name] = array($value);
    } // function

    /**
         * Returns the method call, to be saved in the migration file.
         * @return string
         */
    public function __toString()
    {
        $fieldMigration = '';

        if ($migrations = $this->getMigrations()) {
            $fieldMigration .= $this->getStartPoint();

            $paramParser = function ($value) {
                
                return var_export($value, true);
            };

            foreach ($migrations as $method => $params) {
                $fieldMigration .= "->{$method}(";

                if ($params) {
                    $fieldMigration .= implode(', ', array_map($paramParser, $params));
                } // if

                $fieldMigration .= ')';
            } // foreach
        } // if

        return $fieldMigration . ";";
    } // function

    /**
         * Returns the migration calls for a field.
         * @return array
         */
    public function getMigrations()
    {
        return $this->migrations;
    }

    /**
         * Returns the startpoint of the call hierarchy.
         * @return string
         */
    public function getStartPoint()
    {
        return $this->startPoint;
    } // function

    /**
         * Sets the startpoint for the call hierarchy.
         * @param string $startPoint
         * @return Base
         */
    public function setStartPoint($startPoint)
    {
        $this->startPoint = $startPoint;

        return $this;
    } // function
}
