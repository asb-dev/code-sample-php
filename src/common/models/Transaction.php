<?php

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Class Transaction
 * @package common\models
 *
 * @property integer $id
 * @property integer $status
 * @property integer $type
 * @property integer $account_from
 * @property integer $account_to
 * @property double $amount
 * @property integer $created_at
 * @property integer $updated_at
 */
class Transaction extends ActiveRecord
{
    const TYPE_INTERNAL = 1;   // внутренняя транзакция
    const TYPE_INCOME = 2;     // пополнение баланса пользователя
    const TYPE_WITHDRAWAL = 3; // вывод средств с баланса пользователя
    const TYPE_COMMISSION = 4; // выплата комиссии за игру реферала
    const TYPE_REWARD = 5;     // выплата награды за действие реферала
    const TYPE_REFILL = 6;     // Пополнения счета пользователя

    const STATUS_EXECUTED = 1;
    const STATUS_DECLINED = 2;

    /**
     * @return array
     */
    public static function getTypes()
    {
        return [
            self::TYPE_INTERNAL,
            self::TYPE_COMMISSION,
            self::TYPE_INCOME,
            self::TYPE_REWARD,
            self::TYPE_WITHDRAWAL,
            self::TYPE_REFILL,
        ];
    }

    /**
     * @return array
     */
    public static function getStatuses()
    {
        return [
            self::STATUS_DECLINED,
            self::STATUS_EXECUTED,
        ];
    }

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'transactions';
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [[
            'class' => TimestampBehavior::class,
            'createdAtAttribute' => 'created_at',
            'updatedAtAttribute' => 'updated_at',
            'value' => new \yii\db\Expression('now()')
        ]];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['type'], 'in', 'range' => self::getTypes()],
            [['status'], 'in', 'range' => self::getStatuses()],
        ];
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountTo()
    {
        return $this->hasOne(Account::class, ['id' => 'account_to']);
    }

    /**
     * @return ActiveQuery
     */
    public function getAccountFrom()
    {
        return $this->hasOne(Account::class, ['id' => 'account_from']);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function setParentId($id)
    {
        $this->parent_id = $id;
        return $this->save();
    }
}
