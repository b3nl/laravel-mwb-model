<?php
namespace b3nl\MWBModel\Models;

use b3nl\MWBModel\Models\Migration\ForeignKey;
use LogicException;
use ReflectionClass;
use ReflectionException;

/**
 * Saves content in the model stub.
 * @method ModelContent setCasts() setCasts(array $casts) Sets the casting fields.
 * @method ModelContent setDates() setDates(array $dates) Sets the dates fields.
 * @method ModelContent setFillable() setFillable(array $fillable) Sets the fillable fields.
 * @method ModelContent setTable() setTable(string $table) Sets the table name.
 * @package b3nl\MWBModel
 * @subpackage Models
 * @version $id$
 */
class ModelContent
{
    /**
     * Strings which should be added additionally.
     * @var ForeignKey[]
     */
    protected $foreignKeys = [];

    /**
     * Caches the used properties.
     * @var array
     */
    protected $properties = [
        'casts' => [],
        'dates' => [],
        'fillable' => [],
        'table' => ''
    ];

    /**
     * The extended model.
     * @var object|string
     */
    protected $targetModel = null;

    /**
     * The array of used traits for the model.
     * @var array
     */
    protected $traits = [];

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
    } // function

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
     * @param ReflectionClass $toModel
     * @return array
     */
    protected function getMethodCallsForForeignKeys(ReflectionClass $toModel)
    {
        $methods = [];

        if ($keys = $this->getForeignKeys()) {
            /** @var ForeignKey $key */
            foreach ($keys as $key) {
                $relatedTable = $key->getRelatedTable();

                if ($key->isSource()) {
                    $methodName = strtolower(
                        $key->isForMany() ? $relatedTable->getName() : $relatedTable->getModelName()
                    );

                    $methodReturnSuffix = 'has' . ($key->isForMany() ? 'Many' : 'One');
                    $methodContent = "return \$this->{$methodReturnSuffix}(" .
                        "'{$toModel->getNamespaceName()}\\{$relatedTable->getModelName()}'" .
                        ');';
                } // if
                elseif ($key->isForPivotTable()) {
                    $methodName = strtolower($key->isForMany() && $key->isForPivotTable()
                        ? $relatedTable->getName()
                        : $relatedTable->getModelName());

                    $methodReturnSuffix = 'belongsToMany';

                    $methodContent = "return \$this->{$methodReturnSuffix}(" .
                        "'{$toModel->getNamespaceName()}\\{$relatedTable->getModelName()}'" .
                        ');';
                } // elseif
                else {
                    $methodName = strtolower($key->isForMany() && $key->isForPivotTable()
                        ? $relatedTable->getName()
                        : $relatedTable->getModelName());

                    $methodReturnSuffix = 'belongsTo';

                    $methodContent = "return \$this->{$methodReturnSuffix}(" .
                        "'{$toModel->getNamespaceName()}\\{$relatedTable->getModelName()}', '{$key->foreign}'" .
                        ');';
                } // else

                $methodName = preg_replace_callback(
                    '/(_[a-z])/',
                    function ($matches) {
                        return strtoupper(substr($matches[0], 1));
                    },
                    $methodName
                );

                $method = "\t/**\n\t * Getter for {$relatedTable->getName()}.\n\t " .
                    "* @return \\Illuminate\\Database\\Eloquent\\Relations\\" .
                    ucfirst($methodReturnSuffix) . " \n\t */\n\t" .
                    "public function {$methodName}()\n\t{\n\t\t{$methodContent}\n\t} // function";

                $methods[] = $method;
            } // foreach
        } // if

        return array_unique($methods);
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
     * Returns the extended object.
     * @return object|string
     */
    public function getTargetModel()
    {
        return $this->targetModel;
    }

    /**
     * Returns the special traits for the model.
     * @return array
     */
    public function getTraits()
    {
        return $this->traits;
    } // function

    /**
     * Parses the given value to an exportable php code.
     * @param string|array $value
     * @return string
     */
    protected function parsePropertyValue($value)
    {
        $return = '';

        if (is_array($value)) {
            $withStringIndex = (bool) array_filter(array_keys($value), 'is_string');

            if ($withStringIndex) {
                $return = preg_replace(
                    ['/^(array ?\( *)/', '/\)$/'],
                    ['[', ']'],
                    str_replace("\n", '', var_export($value, true))
                );
            } else {
                $return =
                    '[' .
                    rtrim(
                        array_reduce(
                            $value,
                            function ($combined, $single) {
                                return $combined . var_export($single, true) . ', ';
                            },
                            ''
                        ),
                        ', '
                    ) .
                    ']';
            } // else
        } else {
            $return = var_export($value, true);
        } // else

        return $return;
    } // function

    /**
     * Saves the properties in the placeholder.
     * @param string $inPlaceholder
     * @return bool
     * @throws LogicException
     */
    public function save($inPlaceholder = "    //")
    {
        try {
            $reflection = new ReflectionClass($target = $this->getTargetModel());

            $this->writeToModel($reflection, $inPlaceholder);
        } catch (ReflectionException $exc) {
            throw new LogicException(sprintf(
                'Model %s not found',
                is_object($target) ? get_class($target) : $target
            ));
        } // catch

        return true;
    } // function

    /**
     * Sets the names of the traits.
     * @param array $traits
     * @return ModelContent
     */
    public function setTraits($traits)
    {
        $this->traits = $traits;

        return $this;
    } // function

    /**
     * Writes the stub properties to the target model.
     * @param \ReflectionClass $toModel The reflection of the target model.
     * @param string $inPlaceholder
     * @return int
     */
    protected function writeToModel(ReflectionClass $toModel, $inPlaceholder = "    //")
    {
        $modelFile = $toModel->getFileName();
        $replaces = [];
        $searches = [];

        foreach ($this->getProperties() as $property => $content) {
            $replaces[] = $this->parsePropertyValue($content);
            $searches[] = '{{' . $property . '}}';
        } // foreach

        $methodContent = '';
        $newContent = str_replace(
            $searches,
            $replaces,
            file_get_contents(realpath(__DIR__ . '/stubs/model-content.stub'))
        );

        if ($keys = $this->getForeignKeys()) {
            $methodContent = implode("\n\n", $this->getMethodCallsForForeignKeys($toModel));
        } // if

        $newContent = str_replace(
            ["    // {{relations}}", '// {{traits}}'],
            [$methodContent, ($traits = $this->getTraits()) ? 'use ' . implode(', ', $traits) . ';' : ''],
            $newContent
        );

        $written = file_put_contents(
            $modelFile,
            str_replace(
                $inPlaceholder,
                $newContent,
                file_get_contents($modelFile)
            )
        );

        if ($written) {
            // Success of formatting is optional.
            @exec("vendor/bin/phpcbf {$modelFile} --standard=PSR2", $output, $return);
        } // if

        return $written;
    } // function
}
