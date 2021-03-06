<?php namespace b3nl\MWBModel\Models;

use b3nl\MWBModel\Models\Migration\Base;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Illuminate\Database\Migrations\Migration;

/**
 * Class to prepare the database fields for the laravel migrations.
 * @package b3nl\MWBModel
 * @subpackage Models
 * @version $id$
 */
class MigrationField extends Base
{
    /**
     * The possible options for this field.
     * @var array
     */
    protected $additionalOptions = [];

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
     * The settings of this field.
     * @var array
     */
    protected $settings = [];

    /**
     * XPath evals for the main type of the desired database field in the migration.
     *
     * Key is the laravel call, the first array value is the xpath to check, if the call is needed and the second
     * array value are the XPaths to get the method arguments.
     * @var array
     */
    protected $typeEvals = [
        'boolean' => [
            './link[@key="userType" and text() = "com.mysql.rdbms.mysql.userdatatype.boolean"]'
        ],
        'bigInteger' => [
            './link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.bigint"]',
            [
                'boolean(./value[@type="int" and @key="autoIncrement" and number() = 1])',
                'boolean(./value[@content-type="string" and @key="flags"]/value[@type="string" ' .
                'and text() = "UNSIGNED"])'
            ]
        ],
        'dateTime' => [
            './link[@key="simpleType" and (text() = "com.mysql.rdbms.mysql.datatype.datetime" or ' .
                'text() = "com.mysql.rdbms.mysql.datatype.datetime_f")]'
        ],
        'enum' => [
            './link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.enum"]'
        ],
        'increments' => [
            './link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.int"] and ./value[@key="name" ' .
            'and text() = "id"] and ./value[@type="int" and @key="autoIncrement" and number() = 1]'
        ],
        'integer' => [
            './link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.int"]',
            [
                'boolean(./value[@type="int" and @key="autoIncrement" and number() = 1])',
                'boolean(./value[@content-type="string" and @key="flags"]/value[@type="string" ' .
                'and text() = "UNSIGNED"])'
            ]
        ],
        'mediumInteger' => [
            './link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.mediumint"]',
            [
                'boolean(./value[@type="int" and @key="autoIncrement" and number() = 1])',
                'boolean(./value[@content-type="string" and @key="flags"]/value[@type="string" ' .
                'and text() = "UNSIGNED"])'
            ]
        ],
        'text' => [
            './link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.text"]'
        ],
        'timestamp' => [
            './link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.timestamp_f"]'
        ],
        'tinyInteger' => [
            './link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.tinyint"]',
            [
                'boolean(./value[@type="int" and @key="autoIncrement" and text() = 1])',
                'boolean(./value[@content-type="string" and @key="flags"]/value[@type="string" ' .
                'and text() = "UNSIGNED"])'
            ]
        ],
        'rememberToken' => [
            './link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.varchar"]/../value[@key="name" ' .
            'and text() = "remember_token"]'
        ],
        'smallInteger' => [
            './link[@key="simpleType" and text() = "com.mysql.rdbms.mysql.datatype.smallint"]',
            [
                'boolean(./value[@type="int" and @key="autoIncrement" and text() = 1])',
                'boolean(./value[@content-type="string" and @key="flags"]/value[@type="string" ' .
                'and text() = "UNSIGNED"])'
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
     * Returns the method call, to be saved in the migration file.
     * @return string
     */
    public function __toString()
    {
        $schema = $this->getName() . ':';

        foreach ($this->getSettings() as $type => $values) {
            $schema .= $type;

            if ($values) {
                $schema .= '(' .
                    implode(',', array_map(function($value) { return var_export($value, true); }, $values)) .
                    ')';
            }
        }

        if ($options = $this->getAdditionalOptions()) {
            $schema .= ':';

            foreach ($options as $name => $values) {
                $schema .= $name;

                if ($values) {
                    $schema .= '(' .
                        implode(',', array_map(function($value) { return var_export($value, true); }, $values)) .
                        ')';
                }
            }
        }

        return $schema;
    }

    /**
     * @param $name
     * @param array $values
     * @return $this
     */
    public function addAdditionalOption($name, array $values = [])
    {
        $this->additionalOptions[$name] = $values;

        return $this;
    }

    /**
     * Returns the options for this field.
     * @return array
     */
    public function getAdditionalOptions()
    {
        return $this->additionalOptions;
    }

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
     * Returns the settings of this field.
     * @return array
     */
    public function getSettings()
    {
        return $this->settings;
    }

    /**
     * Loads the database migrations calls of the database field..
     * @param \DOMNode $field
     * @param \DOMXPath $rootPath
     * @return MigrationField
     */
    public function load(DOMNode $field, DOMXPath $rootPath)
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
    protected function loadMigrationCalls(
        DOMNode $field,
        DOMXPath $rootPath,
        array $migrationRules,
        $isOptional = false
    ) {
        $method = '';
        $params = [];

        foreach ($migrationRules as $ruleMethod => $rules) {
            $evaledXPath = $rootPath->evaluate($rules[0], $field);

            // is it a found domnodelist or evaled the xpath to a scalar value !== false.
            if ((($evaledXPath instanceof DOMNodeList) && ($evaledXPath->length)) ||
                ((!($evaledXPath instanceof DOMNodeList)) && $evaledXPath)
            ) {
                $method = $ruleMethod;
                break;
            } // if
        } // foreach

        // optional calls are only added, if the desired method is found.
        if ($method || !$isOptional) {
            if (!$method) {
                $method = 'string';
                $rules = [];
            } // if

            if (@$rules[1] && is_array($rules[1])) {
                foreach ($rules[1] as $paramPath) {
                    $paramResult = $rootPath->evaluate($paramPath, $field);
                    $isResultNodeList = $paramResult instanceof \DOMNodeList;

                    if ((($isResultNodeList) && ($paramResult->length)) || (!$isResultNodeList)) {
                        $rawParam = $isResultNodeList ? $paramResult->item(0)->nodeValue : $paramResult;
                        $params[] =  is_numeric($rawParam) ? $rawParam * 1 : $rawParam;
                    } // if
                } // foreach
            } // if

            $this->{'set' . ($isOptional ? 'AdditionalOptions' : 'Settings')}(array($method => $params));
        } // if

        return $this;
    }

    /**
     * Sets the options array.
     * @param array $options
     * @return MigrationField
     */
    public function setAdditionalOptions($options)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Sets the id of this field.
     * @param string $id
     * @return MigrationField
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Sets the fieldname.
     * @param string $name
     * @return MigrationField
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Sets the settings of this field.
     * @param array $settings
     * @return MigrationField
     */
    public function setSettings($settings)
    {
        $this->settings = $settings;

        return $this;
    }
}
