<?php
/**
 * Created by PhpStorm.
 * User: Vitaly Voskobovich
 * Date: 13.12.14
 * Time: 13:05
 */
namespace app\components\behaviors;

use app\components\ActiveRecord;
use yii\base\Exception;

class DynamicPropertiesBehavior extends \yii\base\Behavior
{
    /**
     * Атрибуты формы
     * @var array
     */
    public $attributes = [];

    /**
     * Attributes value
     * @var array
     */
    private $_values = array();

    /**
     * Events list
     * @return array
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_VALIDATE => 'validateProperties',
            ActiveRecord::EVENT_AFTER_INSERT => 'saveProperties',
            ActiveRecord::EVENT_AFTER_UPDATE => 'saveProperties',
        ];
    }

    /**
     * Validate properties models
     */
    public function validateProperties()
    {
        foreach ($this->attributes as $name => $relationName) {
            $models = $this->getPropertyModels($name);

            $validate = true;
            foreach ($models as $model) {
                if ($model->hasErrors()) {
                    $validate = false;
                }
            }

            if (!$validate) {
                $this->owner->addError($name, 'Invalid one is models');
            }
        }
    }

    /**
     * Save properties data
     */
    public function saveProperties()
    {
        $connection = \Yii::$app->db;

        foreach ($this->attributes as $name => $relationName) {

            $relation = $this->getRelation($name);
            $model = new $relation->modelClass();
            $tableName = $model::tableName();
            $tableColumns = $model->attributes();

            list($bindingColumn) = array_keys($relation->link);

            // Remove relations
            $connection->createCommand()
                ->delete($tableName, "{$bindingColumn} = {$this->owner->getPrimaryKey()}")
                ->execute();

            $models = $this->getPropertyModels($name);

            if (!empty($models)) {
                $insertRows = array();
                foreach ($models as $model) {
                    array_push($insertRows, array_values($model->getAttributes()));
                }

                $connection->createCommand()
                    ->batchInsert($tableName, $tableColumns, $insertRows)
                    ->execute();
            }
        }
    }

    /**
     * Get params
     * @param $attributeName
     * @return mixed
     * @throws Exception
     */
    private function getRelationParams($attributeName)
    {
        if (empty($this->attributes[$attributeName])) {
            throw new Exception("Item \"{$attributeName}\" must be configured");
        }

        return $this->attributes[$attributeName];
    }

    /**
     * Get source attribute name
     * @param $attributeName
     * @return null
     */
    private function getRelationName($attributeName)
    {
        $params = $this->getRelationParams($attributeName);

        if (is_string($params)) {
            return $params;
        } elseif (is_array($params) && !empty($params[0])) {
            return $params[0];
        }
        return NULL;
    }

    /**
     * Get relation object
     * @param $name
     * @return mixed
     */
    public function getRelation($name)
    {
        $relationName = $this->getRelationName($name);
        return $this->owner->getRelation($relationName);
    }

    /**
     * Get property new models
     * @param $name
     * @return null
     */
    private function getPropertyModels($name)
    {
        if ($this->hasPropertyModels($name)) {
            return $this->_values[$name];
        }
        return [];
    }

    /**
     * Check has set property models
     * @param $name
     * @return null
     */
    private function hasPropertyModels($name)
    {
        return isset($this->_values[$name]);
    }

    /**
     * Returns a value indicating whether a property can be read.
     *
     * @param string $name the property name
     * @param boolean $checkVars whether to treat member variables as properties
     * @return boolean whether the property can be read
     * @see canSetProperty()
     */
    public function canGetProperty($name, $checkVars = true)
    {
        return array_key_exists($name, $this->attributes) ?
            true : parent::canGetProperty($name, $checkVars);
    }

    /**
     * Returns a value indicating whether a property can be set.
     *
     * @param string $name the property name
     * @param boolean $checkVars whether to treat member variables as properties
     * @param boolean $checkBehaviors whether to treat behaviors' properties as properties of this component
     * @return boolean whether the property can be written
     * @see canGetProperty()
     */
    public function canSetProperty($name, $checkVars = true, $checkBehaviors = true)
    {
        return array_key_exists($name, $this->attributes) ?
            true : parent::canSetProperty($name, $checkVars, $checkBehaviors);
    }

    /**
     * Returns the value of an object property.
     *
     * @param string $name the property name
     * @return mixed the property value
     * @see __set()
     */
    public function __get($name)
    {
        $relation = $this->getRelation($name);

        $value = $this->hasPropertyModels($name) ?
            $this->getPropertyModels($name) : $relation->all();

        if (empty($value)) {
            $value[] = new $relation->modelClass();
        }

        return $value;
    }

    /**
     * Sets the value of a component property.
     *
     * @param string $name the property name or the event name
     * @param mixed $value the property value
     * @see __get()
     */
    public function __set($name, $value)
    {
        if (is_array($value)) {
            $relation = $this->getRelation($name);
            $ownerPK = $this->owner->getPrimaryKey();

            list($bindingColumn) = array_keys($relation->link);

            foreach ($value as $data) {
                $model = new $relation->modelClass();
                $model->setAttributes($data);
                $model->$bindingColumn = $ownerPK;
                $model->validate();

                $this->_values[$name][] = $model;
            }
        }
    }
}