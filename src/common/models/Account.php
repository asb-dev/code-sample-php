<?php

namespace common\models;

use common\helpers\MoneyHelper;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use common\models\User;
use common\models\WithdrawalRequest;
use common\models\Transaction;

/**
 * Class Account
 * @package common\models
 *
 * @property integer $id
 * @property integer $user_id
 * @property integer $currency_id
 * @property integer $type
 * @property double $balance
 * @property Currency $currency
 * @property Wallet $wallet
 * @property integer $created_at
 * @property integer $updated_at
 */
class Account extends ActiveRecord
{
    const TYPE_DEFAULT = 1;           // обычный счёт
    const TYPE_SYSTEM_WITHDRAWAL = 2; // счёт вывода денег из системы
    const TYPE_SYSTEM_PROFIT = 3;     // прибыль системы
    const TYPE_SYSTEM = 4;            // Общий счет системы

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'accounts';
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
     * @return ActiveQuery
     */
    public function getCurrency()
    {
        return $this->hasOne(Currency::class, ['id' => 'currency_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getWallet()
    {
        return $this->hasOne(Wallet::class, ['account_id' => 'id'])
            ->orderBy(['id' => SORT_DESC])
            ->limit(1);
    }

    /**
     * @return bool
     */
    public function isSystemAccount()
    {
        return in_array($this->type, [self::TYPE_SYSTEM_PROFIT, self::TYPE_SYSTEM_WITHDRAWAL, self::TYPE_SYSTEM]);
    }

    /**
     * @return ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getWithdraw()
    {
        return $this->hasOne(WithdrawalRequest::class, ['account_id' => 'id'])
            ->where(['OR',
                ['status' => WithdrawalRequest::STATUS_WAITING_APPROVAL],
                ['status' => WithdrawalRequest::STATUS_WAIT_APPROVAL_PROCCESS],
            ]);
    }

    /**
     * @return ActiveQuery
     */
    public function getDeposits()
    {
        return Transaction::find()
            ->where(['account_to' => $this->id, 'account_from' => 0, 'status' => Transaction::STATUS_EXECUTED])
            ->orderBy('created_at ASC')
            ->all();
    }

    /**
     * @return ActiveQuery
     */
    public function getWithdrawals()
    {
        return Transaction::find()
            ->where(['account_to' => 0, 'account_from' => $this->id, 'status' => Transaction::STATUS_EXECUTED])
            ->orderBy('created_at ASC')
            ->all();
    }

    /**
     * @return string
     */
    public function getFormattedBalance(): string
    {
        return MoneyHelper::format($this->balance / $this->currency->denominator, 8) . ' ' . $this->currency->code;
    }

    /**
     * Возвращает системный аккаунт по типу валюты
     *
     * @param int $currencyId
     * @return Account | null
     */
    public static function getSystemAccount(int $currencyId)
    {
        return self::find()->where(['currency_id' => $currencyId, 'type' => self::TYPE_SYSTEM])->one();
    }
}
