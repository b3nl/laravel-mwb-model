<?php

namespace b3nl\MWBModel\Models;

use Artisan;
use b3nl\MWBModel\Models\Migration\Base;
use b3nl\MWBModel\Models\Migration\ForeignKey;
use Countable;
use Doctrine\Common\Inflector\Inflector;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Console\AppNamespaceDetectorTrait;

/**
 * Class to prepare the table for the laravel migrations.
 * @package b3nl\MWBModel
 * @subpackage Models
 * @version $id$
 */
class TableMigration implements Countable
{
    use AppNamespaceDetectorTrait;

    /**
     * The guarded fields.
     * @var array
     */
    protected $blacklist = [];

    /**
     * Maps field names to casting types.
     * @var array
     */
    protected $castedFields = [];

    /**
     * The issueing command.
     * @var Command|void
     */
    protected $command = null;

    /**
     * Caching of the database fields.
     * @var MigrationField[]
     */
    protected $fields = [];

    /**
     * General columns with unspeficied/amobiguous rows.
     * @var Base[]
     */
    protected $genericCalls = [];

    /**
     * The id of the field in the model.
     * @var string
     */
    protected $id = '';

    /**
     * Is this table a m:n pivot table?
     * @var bool
     */
    protected $isPivotTable = false;

    /**
     * The cached model name.
     * @var string
     */
    protected $modelName = '';

    /**
     * The used model node.
     * @var null|\DOMNode
     */
    protected $modelNode = null;

    /**
     * Name of this table.
     * @var string
     */
    protected $name = '';

    /**
     * The foreign keys, where this table is the source.
     * @var ForeignKey[]
     */
    protected $relationSources = [];

    /**
     * Should this table write the timestamps call?
     * @var bool
     */
    protected $withoutTimestamps = false;

    /**
     * The constructor.
     * @param Command $command
     */
    public function __construct(Command $command)
    {
        $this->setCommand($command);
    }

    /**
     * Adds a foreign key, where this table is the source.
     * @param ForeignKey $foreignKey
     * @return TableMigration
     */
    public function addAsRelationSource(ForeignKey $foreignKey)
    {
        $foreignKey->isSource(true);
        $this->relationSources[] = $foreignKey;

        return $this;
    } // function

    protected function addCallsToMigrationFile($file)
    {
        $name = $this->getName();
        $written = false;

        // filter every "foreign call" (for m:n tables) to itself.
        $calls = array_filter($this->getGenericCalls(), function ($call) use ($name) {
            return !$call->foreign || $call->on !== $name;
        });

        if ($calls) {
            $search = '            $table->timestamps();' . "\n";
            $replace = "\t\t\t" . implode("\n\t\t\t", $calls);

            $written = (bool)file_put_contents($file, str_replace($search, $replace . "\n", file_get_contents($file)));

            if ($written) {
                if (DIRECTORY_SEPARATOR === '\\') {
                    $exec = ".\\vendor\\bin\\phpcbf.bat {$file} --standard=PSR2";
                } else {
                    $exec = "vendor/bin/phpcbf {$file} --standard=PSR2";
                }

                // Success of formatting is optional.
                @exec($exec, $output, $return);
            }
        }

        return $written;
    }

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
     * @param DOMXPath $rootPath
     * @return TableMigration
     */
    protected function addForeignKeys(DOMXPath $rootPath)
    {
        $fields = $this->getFields();
        $idMap = array_flip(array_map(function (MigrationField $field) {
            return $field->getId();
        }, $fields));

        /** @var MigrationField $field */
        foreach ($fields as $name => $field) {
            $usedId = $field->getId();
            $indexNodes = $this->getForeignKeysForField($field, $rootPath);

            if ($indexNodes && $indexNodes->length) {
                /** @var \DOMNode $indexNode */
                foreach ($indexNodes as $indexNode) {
                    $call = new ForeignKey();

                    $call
                        ->foreign($field->getName())
                        ->references('id')
                        ->on(
                            $rootPath->evaluate(
                                'string(.//link[@type="object" and @struct-name="db.mysql.Table" ' .
                                'and @key="referencedTable"])',
                                $indexNode
                            )
                        );

                    if ($rule = $rootPath->evaluate('string(./value[@key="deleteRule"])', $indexNode)) {
                        $call->onDelete(strtolower($rule));
                    } // if

                    if ($rule = $rootPath->evaluate('string(./value[@key="updateRule"])', $indexNode)) {
                        $call->onUpdate(strtolower($rule));
                    } // if

                    $call->isForMany((int)$rootPath->evaluate('number(./value[@key="many"])', $indexNode) === 1);
                } // foreach

                $this->addGenericCall($call);
            } // if
        } // foreach

        return $this;
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
     * @param DOMXPath $rootPath
     * @return array
     * @todo   Primary missing; Problems with m:ns.
     */
    protected function addIndicesToFields(DOMXPath $rootPath)
    {
        $fetchedNodes = [];
        $fields = $this->getFields();
        $multipleIndices = [];
        $idMap = array_flip(
            array_map(
                function (MigrationField $field) {
                    return $field->getId();
                },
                $fields
            )
        );

        /** @var MigrationField $field */
        foreach ($fields as $name => $field) {
            $usedId = $field->getId();
            $indexNodes = $rootPath->query(
                './value[@content-struct-name="db.mysql.Index" and @key="indices"]/' .
                'value[@type="object" and @struct-name="db.mysql.Index"]//' .
                'value[@type="object" and @struct-name="db.mysql.IndexColumn"]//' .
                'link[@type="object" and @struct-name="db.Column" and @key="referencedColumn" ' .
                'and text() = "' . $usedId . '"]/' .
                '../' .
                '../' .
                '..'
            );

            if ($indexNodes && $indexNodes->length) {
                $fks = $this->getForeignKeysForField($field, $rootPath);

                /** @var \DOMNode $indexNode */
                foreach ($indexNodes as $indexNode) {
                    if (in_array($nodeId = $indexNode->attributes->getNamedItem('id')->nodeValue, $fetchedNodes)) {
                        continue;
                    } // if

                    $fetchedNodes[] = $nodeId;

                    $indexColumns = $rootPath->query(
                        './/link[@type="object" and @struct-name="db.Column" and @key="referencedColumn"]',
                        $indexNode
                    );

                    $isSingleColumn = $indexColumns && $indexColumns->length <= 1;
                    $genericCall = !$isSingleColumn ? new Base() : null;

                    if ($rootPath->evaluate(
                        'boolean(./value[@type="int" and @key="unique" and number() = 1])',
                        $indexNode
                    )
                    ) {
                        if ($isSingleColumn) {
                            $field->addAdditionalOption('unique');
                        } // if
                        else {
                            $genericMethod = 'unique';
                        } // else
                    } // if

                    $hasIndex = $rootPath->evaluate(
                        'boolean(./value[@type="string" and @key="indexType" and text() = "INDEX"])',
                        $indexNode
                    );

                    if ($hasIndex && (!$fks || !$fks->length)) {
                        if ($isSingleColumn) {
                            $field->addAdditionalOption('index');
                        } // if
                        else {
                            $genericMethod = 'index';
                        } // else
                    } // if

                    if ($rootPath->evaluate(
                        'boolean(./value[@type="string" and @key="indexType" and text() = "PRIMARY"])',
                        $indexNode
                    )
                    ) {
                        if ($isSingleColumn) {
                            $field->addAdditionalOption('primary');
                        } // if
                        else {
                            $genericMethod = 'primary';
                        } // else
                    } // if

                    if ($genericCall && $genericMethod) {
                        $genericParams = [];
                        /** @var DOMNode $column */
                        foreach ($indexColumns as $column) {
                            $genericParams[] = $idMap[$column->nodeValue];
                        } // foreach

                        $this->addGenericCall(call_user_func_array(
                            [$genericCall, $genericMethod],
                            $genericParams ? [$genericParams] : []
                        ));
                    }
                }
            }
        }

        return array_unique($multipleIndices);
    }

    /**
     * Returns the count of fields.
     * @return int
     */
    public function count()
    {
        return count($this->fields);
    } // function

    /**
     * Creates the migration file for the given table.
     * @return mixed|string
     * @todo softDeletes, timestamps
     */
    protected function createMigrationFile()
    {
        $fieldObjects = $this->getFields();

        if (@$fieldObjects['id']) {
            unset($fieldObjects['id']);
        }

        if (@$fieldObjects['created_at'] && @$fieldObjects['updated_at']) {
            $this->addGenericCall((new Base())->timestamps());

            unset($fieldObjects['created_at'], $fieldObjects['updated_at']);
        } // if

        if (@$fieldObjects['deleted_at']) {
            $this->addGenericCall((new Base())->softDeletes());

            unset($fieldObjects['deleted_at']);
        } // if

        $schema = implode(', ', $fieldObjects);

        if (strpos($schema, 'enum') !== false) {
            $this->getCommand()->info(sprintf('Please change the enum field of table "%s" manually.',
                $this->getName()));
        }

        if ($this->isPivotTable()) {
            $tables = array_keys($this->getForeignKeys());

            Artisan::call(
                'make:migration:pivot',
                [
                    'tableOne' => $tables[0],
                    'tableTwo' => $tables[1]
                ]
            );
        } else {
            Artisan::call(
                'make:migration:schema',
                [
                    'name' => "create_{$this->getName()}_table",
                    '--model' => $this->needsLaravelModel(),
                    '--schema' => $fieldObjects ? $schema : ''
                ]
            );

            $migrationFiles = glob(
                database_path('migrations') . DIRECTORY_SEPARATOR . "*_create_{$this->getName()}_table.php"
            );
        }

        return @$migrationFiles ? end($migrationFiles) : '';
    } // function

    /**
     * Returns the guarded fields.
     * @return array
     */
    public function getBlacklist()
    {
        return $this->blacklist;
    }

    /**
     * Returns the casted fields.
     * @return array
     */
    public function getCastedFields()
    {
        return $this->castedFields;
    }

    /**
     * Returns Sets the issueing command.
     * @return Command
     */
    public function getCommand()
    {
        return $this->command;
    }

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
     * Returns the relations for foreign keys.
     * @return Base[]
     */
    public function getForeignKeys()
    {
        $return = [];
        $table = $this->getName();

        /** @var Base $call */
        foreach ($this->getGenericCalls() as $call) {
            if (isset($call->on) && $call->on !== $table) {
                $return[(string)$call->on] = $call;
            } // if
        } // foreach

        return $return;
    } // function

    /**
     * Returns the foreign key fields for a field.
     * @param MigrationField $field
     * @param DOMXPath $rootPath
     * @return DOMNodeList
     */
    protected function getForeignKeysForField(MigrationField $field, DOMXPath $rootPath)
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
     * Returns the id of this field in the model.
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Returns the model name.
     * @return string
     */
    public function getModelName()
    {
        if (!$name = $this->modelName) {
            $tableName = $this->getName();

            $modelNames = [];
            foreach (explode('_', $tableName) as $word) {
                $modelNames[] = ucfirst(Inflector::singularize($word));
            } //foreach
            $modelName = implode('', $modelNames);

            $this->setModelName($name = ucfirst($this->isReservedPHPWord($modelName) ? $tableName : $modelName));
        } // if

        return $name;
    } // function

    /**
     * Returns the name of the table.
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Returns the Relation-Sources.
     * @return Migration\ForeignKey[]
     */
    public function getRelationSources()
    {
        return $this->relationSources;
    } // function

    /**
     * Returns true if there is a field with the given name.
     * @param string $name
     * @return bool
     */
    public function hasField($name)
    {
        return array_key_exists($name, $this->fields);
    } // function

    /**
     * Is this table a m:n pivot table?
     * @param bool $newStatus The new status.
     * @return bool The old status.
     */
    public function isPivotTable($newStatus = false)
    {
        $oldStatus = $this->isPivotTable;

        if (func_num_args()) {
            $this->isPivotTable = $newStatus;
        } // if

        return $oldStatus;
    } // function

    /**
     * Returns true if the given word is a php keyword.
     * @param string $word
     * @return bool
     */
    protected function isReservedPHPWord($word)
    {
        $word = strtolower($word);

        $keywords = [
            '__halt_compiler',
            'abstract',
            'and',
            'array',
            'as',
            'break',
            'callable',
            'case',
            'catch',
            'class',
            'clone',
            'const',
            'continue',
            'declare',
            'default',
            'die',
            'do',
            'echo',
            'else',
            'elseif',
            'empty',
            'enddeclare',
            'endfor',
            'endforeach',
            'endif',
            'endswitch',
            'endwhile',
            'eval',
            'exit',
            'extends',
            'final',
            'for',
            'foreach',
            'function',
            'global',
            'goto',
            'if',
            'implements',
            'include',
            'include_once',
            'instanceof',
            'insteadof',
            'interface',
            'isset',
            'list',
            'namespace',
            'new',
            'or',
            'print',
            'private',
            'protected',
            'public',
            'require',
            'require_once',
            'return',
            'static',
            'switch',
            'throw',
            'trait',
            'try',
            'unset',
            'use',
            'var',
            'while',
            'xor'
        ];

        $predefined_constants = [
            '__CLASS__',
            '__DIR__',
            '__FILE__',
            '__FUNCTION__',
            '__LINE__',
            '__METHOD__',
            '__NAMESPACE__',
            '__TRAIT__'
        ];

        return in_array($word, $keywords) || in_array($word, $predefined_constants);
    } // function

    /**
     * Load this table with its dom node.
     * @param DOMNode $node
     * @return TableMigration|bool Returns false if the table should be ignored.
     */
    public function load(DOMNode $node)
    {
        $return = true;

        $dom = new \DOMDocument('1.0');
        $dom->importNode($node, true);
        $dom->appendChild($node);

        $path = new \DOMXPath($dom);

        $this->setName($tableName = $path->evaluate('string((./value[@key="name"])[1])'));

        $comment = $path->evaluate('string((./value[@key="comment"])[1])');

        if ($comment) {
            $return = $this->loadAnnotations($comment);
        } // if

        if ($return) {
            $this->setId($tableId = $node->attributes->getNamedItem('id')->nodeValue);

            if (!$tableId || !$tableName) {
                throw new \LogicException('Table name or id could not be fond.');
            } // if

            $return = $this->loadMigrationFields($path);
        } // if

        return $return;
    } // function

    /**
     * Loads the annotations for this table.
     * @param string $annotations
     * @return bool Returns false, if this table should be ignored.
     */
    public function loadAnnotations($annotations)
    {
        $moreSettings = parse_ini_string($annotations, true) ?: [];

        if (@$guardedFields = $moreSettings['blacklist']) {
            $this->setBlacklist(explode(',', $guardedFields));
        } // if

        if (@$moreSettings['casting'] && is_array($moreSettings['casting'])) {
            $this->setCastedFields($moreSettings['casting']);
        } // if

        if (@$modelName = $moreSettings['model']) {
            $this->setModelName($modelName);
        } // if

        $this->isPivotTable(@ (bool)$moreSettings['isPivot']);
        $this->withoutTimestamps(@ (bool)$moreSettings['withoutTimestamps']);

        return !@$moreSettings['ignore'];
    }

    /**
     * Returns the migration fields of the model.
     * @param DOMXPath $rootPath
     * @return TableMigration
     */
    protected function loadMigrationFields(DOMXPath $rootPath)
    {
        $fields = $rootPath->query(
            './value[@type="list" and @key="columns"]/value[@type="object" and @struct-name="db.mysql.Column"]'
        );

        if ($fields && $fields->length) {
            /** @var \DOMNode $field */
            foreach ($fields as $field) {
                $fieldName = $rootPath->query('./value[@key="name"]', $field)->item(0)->nodeValue;

                $this->addField($fieldName)->load($field, $rootPath);
            } // foreach
        } // if

        $this->addIndicesToFields($rootPath);

        return $this->addForeignKeys($rootPath);
    } // function

    /**
     * Returns true, if this table needs a laravel model aswell.
     * @return bool
     */
    public function needsLaravelModel()
    {
        return !$this->isPivotTable() || count($this->fields) > 2;
    } // function

    /**
     * Relates this table to others.
     * @param TableMigration[] $otherTables
     * @return TableMigration
     */
    public function relateToOtherTables(array $otherTables)
    {
        if ($calls = $this->getGenericCalls()) {
            $tablesByModelName = [];

            /** @var TableMigration $tableObject */
            foreach ($otherTables as $tableObject) {
                $tablesByModelName[$tableObject->getModelName()] = $tableObject;
            } // foreach

            /** @var ForeignKey $call */
            foreach ($calls as $call) {
                if ($on = $call->on) {
                    /** @var TableMigration $otherTable */
                    $otherTable = @$otherTables[$on];
                    $call->on = $otherTable->getName();

                    $otherCall = clone $call;

                    if ($this->isPivotTable()) {
                        $modelNames = array_map('ucfirst', explode('_', $tableName = $this->getName()));

                        $matchedModelTables = array_intersect_key($tablesByModelName, array_flip($modelNames));
                        unset($matchedModelTables[$otherTable->getModelName()]);

                        $otherCall->isForMany(true);
                        $otherCall->isForPivotTable(true);

                        $call->on = current($matchedModelTables)->getName();
                        $otherTable->addGenericCall($otherCall->setRelatedTable(current($matchedModelTables)));
                    } // if
                    else {
                        $call->setRelatedTable($otherTable);
                        $otherTable->addAsRelationSource($otherCall->setRelatedTable($this));
                    } // else
                } // if
            } // foreach
        } // if

        return $this;
    } // function

    /**
     * Saves the table in the given migration file.
     * @return bool
     */
    public function save()
    {
        $file = $this->createMigrationFile();

        if (!$written = !$file) {
            $written = $this->addCallsToMigrationFile($file);
        }

        if ($this->needsLaravelModel()) {
            $this->saveModelForTable();
        }

        return $written;
    } // function

    /**
     * Saves the model content for a table.
     * @return MakeMWBModel
     */
    protected function saveModelForTable()
    {
        $table = $this;
        $dates = [];
        $fields = $table->getFields();
        // TODO Work with the namespace again. The FQN does not work since switching to the generator.s
        $modelContent = (new ModelContent($table->getModelName()))->setTable($table->getName());

        if (array_key_exists($field = 'deleted_at', $fields)) {
            unset($fields[$field]);

            $dates[] = $field;
            $modelContent->setTraits(['\Illuminate\Database\Eloquent\SoftDeletes']);
        } // if

        if (array_key_exists($field = 'created_at', $fields)) {
            unset($fields[$field]);

            $dates[] = $field;
        } // if

        if (array_key_exists($field = 'updated_at', $fields)) {
            unset($fields[$field]);

            $dates[] = $field;
        } // if

        if ($dates) {
            $modelContent->setDates($dates);
        } // if

        unset($fields['id']);
        $modelContent->setFillable(array_diff(array_keys($fields), $table->getBlacklist()));
        $modelContent->setCasts($table->getCastedFields());

        if ($genericCalls = $table->getGenericCalls()) {
            foreach ($genericCalls as $call) {
                if ($call instanceof ForeignKey) {
                    $modelContent->addForeignKey($call);
                } // if
            } // foreach
        } // if

        if ($sources = $table->getRelationSources()) {
            foreach ($sources as $call) {
                if ($call instanceof ForeignKey) {
                    $modelContent->addForeignKey($call);
                } // if
            } // foreach
        } // if

        $modelContent->save();

        return $this;
    } // function

    /**
     * Sets the guarded fields.
     * @param array $blacklist
     * @return TableMigration.
     */
    public function setBlacklist(array $blacklist)
    {
        $this->blacklist = $blacklist;

        return $this;
    } // function

    /**
     * Sets the casted fields for this table.
     * @param array $castedFields
     * @return TableMigration
     */
    public function setCastedFields(array $castedFields)
    {
        $this->castedFields = $castedFields;

        return $this;
    } // function

    /**
     * Sets the issueing command.
     * @param Command|void $command
     * @return TableMigration
     */
    public function setCommand($command)
    {
        $this->command = $command;

        return $this;
    }

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
     * @return TableMigration
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    } // function

    /**
     * Sets the model name.
     * @param string $name
     * @return TableMigration
     */
    public function setModelName($name)
    {
        $this->modelName = $name;

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

    /**
     * Should this table be rendered without the timestamps?
     * @param bool $newStatus
     * @return bool
     */
    public function withoutTimestamps($newStatus = false)
    {
        $oldStatus = $this->withoutTimestamps;

        if (func_num_args()) {
            $this->withoutTimestamps = $newStatus;
        } // if

        return $oldStatus;
    }
}
