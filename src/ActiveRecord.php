<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\mongodb;

use MongoDB\BSON\Binary;
use MongoDB\BSON\Type;
use MongoDB\BSON\ObjectId;
use Yii;
use yii\base\InvalidConfigException;
use yii\db\BaseActiveRecord;
use yii\db\StaleObjectException;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;
use yii\helpers\StringHelper;

/**
 * ActiveRecord is the base class for classes representing Mongo documents in terms of objects.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 2.0
 */
abstract class ActiveRecord extends BaseActiveRecord
{

    private static $batchInsertCommand;
    private static $batchInsertQueue = 0;
    private static $batchInsertDocuments = [];
    private static $batchInsertInit = false;
    public  static $batchInsertSize = 500;

    private static $batchUpdateCommand;
    private static $batchUpdateQueue = 0;
    private static $batchUpdateDocuments = [];
    private static $batchUpdateInit = false;
    public  static $batchUpdateSize = 500;

    private static $batchDeleteCommand;
    private static $batchDeleteQueue = 0;
    private static $batchDeleteDocuments = [];
    private static $batchDeleteInit = false;
    public  static $batchDeleteSize = 500;

    /**
     * Returns the Mongo connection used by this AR class.
     * By default, the "mongodb" application component is used as the Mongo connection.
     * You may override this method if you want to use a different database connection.
     * @return Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return Yii::$app->get('mongodb');
    }

    /**
     * Updates all documents in the collection using the provided attribute values and conditions.
     * For example, to change the status to be 1 for all customers whose status is 2:
     *
     * ```php
     * Customer::updateAll(['status' => 1], ['status' => 2]);
     * ```
     *
     * @param array $attributes attribute values (name-value pairs) to be saved into the collection
     * @param array $condition description of the objects to update.
     * Please refer to [[Query::where()]] on how to specify this parameter.
     * @param array $options list of options in format: optionName => optionValue.
     * @return int the number of documents updated.
     */
    public static function updateAll($attributes, $condition = [], $options = [])
    {
        return static::getCollection()->update($condition, $attributes, $options);
    }

    /**
     * Updates all documents in the collection using the provided counter changes and conditions.
     * For example, to increment all customers' age by 1,
     *
     * ```php
     * Customer::updateAllCounters(['age' => 1]);
     * ```
     *
     * @param array $counters the counters to be updated (attribute name => increment value).
     * Use negative values if you want to decrement the counters.
     * @param array $condition description of the objects to update.
     * Please refer to [[Query::where()]] on how to specify this parameter.
     * @param array $options list of options in format: optionName => optionValue.
     * @return int the number of documents updated.
     */
    public static function updateAllCounters($counters, $condition = [], $options = [])
    {
        return static::getCollection()->update($condition, ['$inc' => $counters], $options);
    }

    /**
     * Deletes documents in the collection using the provided conditions.
     * WARNING: If you do not specify any condition, this method will delete documents rows in the collection.
     *
     * For example, to delete all customers whose status is 3:
     *
     * ```php
     * Customer::deleteAll(['status' => 3]);
     * ```
     *
     * @param array $condition description of the objects to delete.
     * Please refer to [[Query::where()]] on how to specify this parameter.
     * @param array $options list of options in format: optionName => optionValue.
     * @return int the number of documents deleted.
     */
    public static function deleteAll($condition = [], $options = [])
    {
        return static::getCollection()->remove($condition, $options);
    }

    /**
     * {@inheritdoc}
     * @return ActiveQuery the newly created [[ActiveQuery]] instance.
     */
    public static function find()
    {
        return Yii::createObject(ActiveQuery::className(), [get_called_class()]);
    }

    /**
     * Declares the name of the Mongo collection associated with this AR class.
     *
     * Collection name can be either a string or array:
     *  - if string considered as the name of the collection inside the default database.
     *  - if array - first element considered as the name of the database, second - as
     *    name of collection inside that database
     *
     * By default this method returns the class name as the collection name by calling [[Inflector::camel2id()]].
     * For example, 'Customer' becomes 'customer', and 'OrderItem' becomes
     * 'order_item'. You may override this method if the collection is not named after this convention.
     * @return string|array the collection name
     */
    public static function collectionName()
    {
        return Inflector::camel2id(StringHelper::basename(get_called_class()), '_');
    }

    /**
     * Return the Mongo collection instance for this AR class.
     * @return Collection collection instance.
     */
    public static function getCollection()
    {
        return static::getDb()->getCollection(static::collectionName());
    }

    /**
     * Returns the primary key name(s) for this AR class.
     * The default implementation will return ['_id'].
     *
     * Note that an array should be returned even for a collection with single primary key.
     *
     * @return string[] the primary keys of the associated Mongo collection.
     */
    public static function primaryKey()
    {
        return ['_id'];
    }

    /**
     * Returns the list of all attribute names of the model.
     * This method must be overridden by child classes to define available attributes.
     * Note: primary key attribute "_id" should be always present in returned array.
     * For example:
     *
     * ```php
     * public function attributes()
     * {
     *     return ['_id', 'name', 'address', 'status'];
     * }
     * ```
     *
     * @throws \yii\base\InvalidConfigException if not implemented
     * @return array list of attribute names.
     */
    public function attributes()
    {
        throw new InvalidConfigException('The attributes() method of mongodb ActiveRecord has to be implemented by child classes.');
    }

    /**
     * Inserts a row into the associated Mongo collection using the attribute values of this record.
     *
     * This method performs the following steps in order:
     *
     * 1. call [[beforeValidate()]] when `$runValidation` is true. If validation
     *    fails, it will skip the rest of the steps;
     * 2. call [[afterValidate()]] when `$runValidation` is true.
     * 3. call [[beforeSave()]]. If the method returns false, it will skip the
     *    rest of the steps;
     * 4. insert the record into collection. If this fails, it will skip the rest of the steps;
     * 5. call [[afterSave()]];
     *
     * In the above step 1, 2, 3 and 5, events [[EVENT_BEFORE_VALIDATE]],
     * [[EVENT_BEFORE_INSERT]], [[EVENT_AFTER_INSERT]] and [[EVENT_AFTER_VALIDATE]]
     * will be raised by the corresponding methods.
     *
     * Only the [[dirtyAttributes|changed attribute values]] will be inserted into database.
     *
     * If the primary key  is null during insertion, it will be populated with the actual
     * value after insertion.
     *
     * For example, to insert a customer record:
     *
     * ```php
     * $customer = new Customer();
     * $customer->name = $name;
     * $customer->email = $email;
     * $customer->insert();
     * ```
     *
     * @param bool $runValidation whether to perform validation before saving the record.
     * If the validation fails, the record will not be inserted into the collection.
     * @param array $attributes list of attributes that need to be saved. Defaults to null,
     * meaning all attributes that are loaded will be saved.
     * @return bool whether the attributes are valid and the record is inserted successfully.
     * @throws \Exception in case insert failed.
     */
    public function insert($runValidation = true, $attributes = null)
    {
        if ($runValidation && !$this->validate($attributes)) {
            return false;
        }

        if(!$this->isTransactional(self::OP_INSERT))
            return $this->insertInternal($attributes);

        $result = null;
        static::getDb()->transaction(function()use($attribute,&$result){
            $result = $this->insertInternal($attributes);
        });
        return $result;
    }

    /**
     * @see ActiveRecord::insert()
     */
    protected function insertInternal($attributes = null)
    {
        if (!$this->beforeSave(true)) {
            return false;
        }
        $values = $this->getDirtyAttributes($attributes);
        if (empty($values)) {
            $currentAttributes = $this->getAttributes();
            foreach ($this->primaryKey() as $key) {
                if (isset($currentAttributes[$key])) {
                    $values[$key] = $currentAttributes[$key];
                }
            }
        }
        $newId = static::getCollection()->insert($values);
        if ($newId !== null) {
            $this->setAttribute('_id', $newId);
            $values['_id'] = $newId;
        }

        $changedAttributes = array_fill_keys(array_keys($values), null);
        $this->setOldAttributes($values);
        $this->afterSave(true, $changedAttributes);

        return true;
    }

    /**
     * Saves the changes to this active record into the associated database table.
     *
     * This method performs the following steps in order:
     *
     * 1. call [[beforeValidate()]] when `$runValidation` is `true`. If [[beforeValidate()]]
     *    returns `false`, the rest of the steps will be skipped;
     * 2. call [[afterValidate()]] when `$runValidation` is `true`. If validation
     *    failed, the rest of the steps will be skipped;
     * 3. call [[beforeSave()]]. If [[beforeSave()]] returns `false`,
     *    the rest of the steps will be skipped;
     * 4. save the record into database. If this fails, it will skip the rest of the steps;
     * 5. call [[afterSave()]];
     *
     * In the above step 1, 2, 3 and 5, events [[EVENT_BEFORE_VALIDATE]],
     * [[EVENT_AFTER_VALIDATE]], [[EVENT_BEFORE_UPDATE]], and [[EVENT_AFTER_UPDATE]]
     * will be raised by the corresponding methods.
     *
     * Only the [[dirtyAttributes|changed attribute values]] will be saved into database.
     *
     * For example, to update a customer record:
     *
     * ```php
     * $customer = Customer::findOne($id);
     * $customer->name = $name;
     * $customer->email = $email;
     * $customer->update();
     * ```
     *
     * Note that it is possible the update does not affect any row in the table.
     * In this case, this method will return 0. For this reason, you should use the following
     * code to check if update() is successful or not:
     *
     * ```php
     * if ($customer->update() !== false) {
     *     // update successful
     * } else {
     *     // update failed
     * }
     * ```
     *
     * @param bool $runValidation whether to perform validation (calling [[validate()]])
     * before saving the record. Defaults to `true`. If the validation fails, the record
     * will not be saved to the database and this method will return `false`.
     * @param array $attributeNames list of attributes that need to be saved. Defaults to `null`,
     * meaning all attributes that are loaded from DB will be saved.
     * @return int|false the number of rows affected, or false if validation fails
     * or [[beforeSave()]] stops the updating process.
     * @throws StaleObjectException if [[optimisticLock|optimistic locking]] is enabled and the data
     * being updated is outdated.
     * @throws \Exception|\Throwable in case update failed.
     */
    public function update($runValidation = true, $attributeNames = null)
    {
        if ($runValidation && !$this->validate($attributeNames)) {
            Yii::info('Model not updated due to validation error.', __METHOD__);
            return false;
        }

        if(!$this->isTransactional(self::OP_UPDATE))
            return $this->updateInternal($attributeNames);

        $result = null;
        static::getDb()->transaction(function()use($attributeNames,&$result){
            $result = $this->updateInternal($attributeNames);
        });
        return $result;
    }

    /**
     * @see ActiveRecord::update()
     * @throws StaleObjectException
     */
    protected function updateInternal($attributes = null)
    {
        if (!$this->beforeSave(false)) {
            return false;
        }
        $values = $this->getDirtyAttributes($attributes);
        if (empty($values)) {
            $this->afterSave(false, $values);
            return 0;
        }
        $condition = $this->getOldPrimaryKey(true);
        $lock = $this->optimisticLock();
        if ($lock !== null) {
            if (!isset($values[$lock])) {
                $values[$lock] = $this->$lock + 1;
            }
            $condition[$lock] = $this->$lock;
        }
        // We do not check the return value of update() because it's possible
        // that it doesn't change anything and thus returns 0.
        $rows = static::getCollection()->update($condition, $values);

        if ($lock !== null && !$rows) {
            throw new StaleObjectException('The object being updated is outdated.');
        }

        if (isset($values[$lock])) {
            $this->$lock = $values[$lock];
        }

        $changedAttributes = [];
        foreach ($values as $name => $value) {
            $changedAttributes[$name] = $this->getOldAttribute($name);
            $this->setOldAttribute($name, $value);
        }
        $this->afterSave(false, $changedAttributes);

        return $rows;
    }

    /**
     * Deletes the document corresponding to this active record from the collection.
     *
     * This method performs the following steps in order:
     *
     * 1. call [[beforeDelete()]]. If the method returns false, it will skip the
     *    rest of the steps;
     * 2. delete the document from the collection;
     * 3. call [[afterDelete()]].
     *
     * In the above step 1 and 3, events named [[EVENT_BEFORE_DELETE]] and [[EVENT_AFTER_DELETE]]
     * will be raised by the corresponding methods.
     *
     * @return int|bool the number of documents deleted, or false if the deletion is unsuccessful for some reason.
     * Note that it is possible the number of documents deleted is 0, even though the deletion execution is successful.
     * @throws StaleObjectException if [[optimisticLock|optimistic locking]] is enabled and the data
     * being deleted is outdated.
     * @throws \Exception in case delete failed.
     */
    public function delete()
    {
        if(!$this->isTransactional(self::OP_DELETE))
            return $this->deleteInternal();

        $result = null;
        static::getDb()->transaction(function()use(&$result){
            $result = $this->deleteInternal();
        });
        return $result;
    }

    /**
     * @see ActiveRecord::delete()
     * @throws StaleObjectException
     */
    protected function deleteInternal()
    {
        if(!$this->beforeDelete())
            return false;
        // we do not check the return value of deleteAll() because it's possible
        // the record is already deleted in the database and thus the method will return 0
        $condition = $this->getOldPrimaryKey(true);
        $lock = $this->optimisticLock();
        if ($lock !== null) {
            $condition[$lock] = $this->$lock;
        }
        $result = static::getCollection()->remove($condition);
        if ($lock !== null && !$result) {
            throw new StaleObjectException('The object being deleted is outdated.');
        }
        $this->setOldAttributes(null);
        $this->afterDelete()

        return $result;
    }

    /**
     * Returns a value indicating whether the given active record is the same as the current one.
     * The comparison is made by comparing the collection names and the primary key values of the two active records.
     * If one of the records [[isNewRecord|is new]] they are also considered not equal.
     * @param ActiveRecord $record record to compare to
     * @return bool whether the two active records refer to the same row in the same Mongo collection.
     */
    public function equals($record)
    {
        if ($this->isNewRecord || $record->isNewRecord) {
            return false;
        }

        return $this->collectionName() === $record->collectionName() && (string) $this->getPrimaryKey() === (string) $record->getPrimaryKey();
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true)
    {
        $data = parent::toArray($fields, $expand, false);
        if (!$recursive) {
            return $data;
        }
        return $this->toArrayInternal($data);
    }

    /**
     * Converts data to array recursively, converting MongoDB BSON objects to readable values.
     * @param mixed $data the data to be converted into an array.
     * @return array the array representation of the data.
     * @since 2.1
     */
    private function toArrayInternal($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = $this->toArrayInternal($value);
                }
                if (is_object($value)) {
                    if ($value instanceof Type) {
                        $data[$key] = $this->dumpBsonObject($value);
                    } else {
                        $data[$key] = ArrayHelper::toArray($value);
                    }
                }
            }
            return $data;
        } elseif (is_object($data)) {
            return ArrayHelper::toArray($data);
        }
        return [$data];
    }

    /**
     * Converts MongoDB BSON object to readable value.
     * @param Type $object MongoDB BSON object.
     * @return array|string object dump value.
     * @since 2.1
     */
    private function dumpBsonObject(Type $object)
    {
        if ($object instanceof Binary) {
            return $object->getData();
        }
        if (method_exists($object, '__toString')) {
            return $object->__toString();
        }
        return ArrayHelper::toArray($object);
    }

    public function batchSave():void{
        if($this->getIsNewRecord())
            return $this->batchInsert();
        return $this->batchUpdate();
    }

    public static function hasBatchInsert(){
        return self::$batchInsertQueue > 0;
    }

    private static function batchInsertInit():void{
        if(self::$batchInsertInit)
            return;
        self::$batchInsertInit = true;
        $db = static::getDb();
        self::$batchInsertCommand = ($db ? $db : yii::$app->mongodb)->createCommand();
        register_shutdown_function(function(){
            if(self::hasBatchInsert())
                yii::warning(self::className().' : batch insert mode not completed!');
        });
    }

    public function batchInsert():void{
        self::batchInsertInit();
        $values = $this->getDirtyAttributes($attributes);
        if (empty($values)) {
            $currentAttributes = $this->getAttributes();
            foreach ($this->primaryKey() as $key) {
                if (isset($currentAttributes[$key])) {
                    $values[$key] = $currentAttributes[$key];
                }
            }
        }
        self::$batchInsertCommand->AddInsert($values);
        self::$batchInsertQueue++;
        if(self::$batchInsertQueue >= self::$batchInsertSize)
            self::flushBatchInsert();
    }

    public static function flushBatchInsert(){
        if(self::$batchInsertQueue === 0)
            return;
        self::$batchInsertQueue = 0;
        $result = self::$batchInsertCommand->executeBatch(self::collectionName());
        self::$batchInsertCommand->document = [];
        return $result;
    }

    public static function hasBatchUpdate(){
        return self::$batchUpdateQueue > 0;
    }

    private static function batchUpdateInit():void{
        if(self::$batchUpdateInit)
            return;
        self::$batchUpdateInit = true;
        $db = static::getDb();
        self::$batchUpdateCommand = ($db ? $db : yii::$app->mongodb)->createCommand();
        register_shutdown_function(function(){
            if(self::hasBatchUpdate())
                yii::warning(self::className().' : batch update mode not completed!');
        });
    }

    public function batchUpdate():void{
        self::batchUpdateInit();
        $values = $this->getDirtyAttributes($attributes);
        if (empty($values))
           return;
        $condition = $this->getOldPrimaryKey(true);
        self::$batchUpdateCommand->AddUpdate($condition, $values);
        self::$batchUpdateQueue++;
        if(self::$batchUpdateQueue >= self::$batchUpdateSize)
            self::flushBatchUpdate();
    }

    public static function flushBatchUpdate(){
        if(self::$batchUpdateQueue === 0)
            return;
        self::$batchUpdateQueue = 0;
        $result = self::$batchUpdateCommand->executeBatch(self::collectionName());
        self::$batchUpdateCommand->document = [];
        return $result;
    }

    public static function hasBatchDelete(){
        return self::$batchDeleteQueue > 0;
    }

    private static function batchDeleteInit():void{
        if(self::$batchDeleteInit)
            return;
        self::$batchDeleteInit = true;
        $db = static::getDb();
        self::$batchDeleteCommand = ($db ? $db : yii::$app->mongodb)->createCommand();
        register_shutdown_function(function(){
            if(self::hasBatchDelete())
                yii::warning(self::className().' : batch update mode not completed!');
        });
    }

    public function batchDelete():void{
        self::batchDeleteInit();
        self::$batchDeleteCommand->AddDelete($this->getOldPrimaryKey(true));
        self::$batchDeleteQueue++;
        if(self::$batchDeleteQueue >= self::$batchDeleteSize)
            self::flushBatchDelete();
    }

    public static function flushBatchDelete(){
        if(self::$batchDeleteQueue === 0)
            return;
        self::$batchDeleteQueue = 0;
        $result = self::$batchDeleteCommand->executeBatch(self::collectionName());
        self::$batchDeleteCommand->document = [];
        return $result;
    }

    /**
     * Lock a document in a transaction(like `select for update` feature in mysql)
     * @see https://www.mongodb.com/blog/post/how-to-select--for-update-inside-mongodb-transactions
     * @param mixed $id a document id(primary key > _id)
     * @param array $options list of options in format: optionName => optionValue.
     * @param Connection $db the Mongo connection used to execute the query.
     * @return ActiveRecord|array|null the original document, or the modified document when $options['new'] is set.
     * Depending on the setting of [[asArray]], the query result may be either an array or an ActiveRecord object.
     * Null will be returned if the query results in nothing.
    */
    public static function LockDocument($id, $options = [], $db = null){
        ($db ? $db : static::getDb())->transactionReady('lock document');
        return
            self::find()
                ->where(['_id' => $id])
            ->modify(['_lock' => new ObjectId], $options, $db)
        ;
    }
}
