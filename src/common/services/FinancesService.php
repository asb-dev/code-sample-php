<?php

namespace common\services;

use common\models\Account;
use common\models\AtomicOperation;
use common\models\Transaction;
use common\models\Wallet;
use common\models\WithdrawalRequest;
use yii\base\BaseObject;
use common\models\AffProfit;
use common\models\User;
use common\models\Currency;
use common\models\PartnerProfit;

/**
 * Class FinancesService
 * @package common\services
 */
class FinancesService extends BaseObject
{

    /**
     *
     * @var string
     */
    public $paymentsDomain;

    /**
     *
     * @var bool
     */
    public $mockMode = false;

    /**
     *
     * @var string
     */
    protected $withdrawalURL;

    /**
     * @var string
     */
    protected $walletBalanceUrl;

    /**
     * @var null
     */
    protected $atomic = null;

    /**
     * @var bool
     */
    protected $isInternalAtomic = true;

    /**
     * @var \GuzzleHttp\Client
     */
    protected $httpClient;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->withdrawalURL = $this->paymentsDomain . '/payment/create';
        $this->walletBalanceUrl = $this->paymentsDomain . '/wallet/balance';
        $this->httpClient = new \GuzzleHttp\Client;
    }

    /**
     * @return FinancesService
     */
    public function getInstance()
    {
        return new self([
            'paymentsDomain' => $this->paymentsDomain,
            'mockMode' => $this->mockMode,
        ]);
    }

    /**
     * Пополнение баланса
     *
     * @param $address
     * @param $amount
     * @param $txid
     * @return bool
     * @throws \Exception
     */
    public function deposit($address, $amount, $txid)
    {
        $result = false;

        if (!$txid) {
            throw new \Exception('Invalid TX id!');
        }

        $wallet = Wallet::find()
            ->where(['address' => $address])
            ->one();

        if (!$wallet) {
            throw new \Exception('No wallet found!');
        }

        $account = Account::findOne($wallet->account_id);
        if (!$account) {
            throw new \Exception('No account found!');
        }

        $this->initAtomicOperation();
        $this->atomic->begin(\yii\db\Transaction::SERIALIZABLE);

        $transaction = $this->transfer(0, $account,
            $amount * $account->currency->denominator);

        if ($transaction) {
            $transaction->txid = $txid;
            $transaction->save();
            if ($this->isInternalAtomic) {
                $this->atomic->commit();
            }
            $result = $transaction;
        } else {
            if ($this->isInternalAtomic) {
                $this->atomic->rollback();
            }
            throw new \Exception('Error on save transaction!');
        }

        return $result;
    }

    /**
     * @param $request
     * @return bool|Transaction
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function withdrawal($request)
    {
        if (!$request->account) {
            throw new \Exception('No account found!');
        }

        $this->initAtomicOperation();
        $this->atomic->begin(\yii\db\Transaction::READ_COMMITTED);

        try {
            $withdrawAmount = (int)($request->amount - $request->account->currency->withdraw_comission);

            $transaction = $this->transfer($request->account, 0,
                $withdrawAmount, Transaction::TYPE_WITHDRAWAL);

            if (!$transaction) {
                throw new \Exception('Error on trasfer money!');
            }

            $request->status = WithdrawalRequest::STATUS_APPROVED;
            $request->transaction_id = $transaction->id;

            $systemAccount = Account::getSystemAccount($request->account->currency->id);

            if ($request->account->currency->withdraw_comission) {
                $comissionTransaction = $this->transfer($request->account,
                    $systemAccount,
                    $request->account->currency->withdraw_comission,
                    Transaction::TYPE_COMMISSION);

                if (!$comissionTransaction) {
                    throw new \Exception('Error on create comission transaction!');
                }

                if (!$comissionTransaction->setParentId($transaction->id)) {
                    throw new \Exception('Error on save parent id');
                }
            }

            if ($this->mockMode) {
                $request->txid = sha1(time() . uniqid() . $request->id);
                $request->commission = $request->account->currency->withdraw_comission;

                if (!$request->save()) {
                    throw new \Exception('Error on save request');
                }
                $this->atomic->commit();
                return $transaction;
            }

            $client = new \GuzzleHttp\Client();
            $response = $client->request('POST', $this->withdrawalURL,
                ['json' => [
                    'request_id' => $request->id,
                    'currency_code' => $request->account->currency->code,
                    'amount' => ($withdrawAmount / $request->account->currency->denominator),
                    'address' => $request->address,
                    'confirmation_code' => $request->confirmation_code
                ]]);

            if ($response->getStatusCode() == 200) {
                $body = json_decode($response->getBody(), true);
                if (isset($body['txid'])) {
                    $request->txid = $body['txid'];
                    $request->commission = $request->account->currency->withdraw_comission;
                    $request->save();
                    $this->atomic->commit();
                } else {
                    throw new \Exception($response->getBody());
                }
            } else {
                throw new \Exception($response->getBody());
            }

            return $transaction;
        } catch (\Exception $e) {
            if ($this->isInternalAtomic) {
                $this->atomic->rollback();
            }
            throw $e;
        }
    }

    /**
     * @param $request
     * @throws \Exception
     */
    public function refill($account, $amount)
    {
        $this->initAtomicOperation();
        $this->atomic->begin(\yii\db\Transaction::SERIALIZABLE);
        try {
            $systemInternalAccount = Account::getSystemAccount($account->currency_id);
            $transaction = $this->transfer($systemInternalAccount, $account,
                $amount, Transaction::TYPE_REFILL);

            if (!$transaction) {
                throw new \Exception("Error on save transaction");
            }
            if ($this->isInternalAtomic) {
                $this->atomic->commit();
            }
        } catch (\Exception $e) {
            if ($this->isInternalAtomic) {
                $this->atomic->rollback();
            }
            throw $e;
        }

        return $transaction;
    }

    /**
     * @param int $accountFrom
     * @param int $accountTo
     * @param $amount
     * @param int $transactionType
     * @return bool|Transaction
     * @throws \Exception
     */
    public function transfer($accountFrom = 0, $accountTo = 0, $amount, $transactionType = null)
    {
        $this->initAtomicOperation();
        if ($this->isInternalAtomic) {
            $this->atomic->begin(\yii\db\Transaction::SERIALIZABLE);
        }

        try {
            if ($amount <= 0) {
                throw new \Exception("Amount must be great 0");
            }

            if ($accountFrom instanceof Account && $accountTo instanceof Account
                && $accountFrom->currency_id !== $accountTo->currency_id) {

                throw new \Exception("Currency mismatch");
            }

            $db = \Yii::$app->getDb();
            $transaction = new Transaction();
            $transaction->account_from = ($accountFrom instanceof Account) ? $accountFrom->id : 0;
            $transaction->account_to = ($accountTo instanceof Account) ? $accountTo->id : 0;
            $transaction->amount = $amount;
            $transaction->status = Transaction::STATUS_EXECUTED;
            if (is_null($transactionType)) {
                $transactionType = Transaction::TYPE_INTERNAL;
            }
            $currencyId = 0;

            if (!($accountFrom instanceof Account) && $accountFrom == 0) {
                $transactionType = Transaction::TYPE_INCOME;
            } else {
                if (!$accountFrom->isSystemAccount() && $accountFrom->balance < $amount) {
                    throw new \Exception('Not enough funds on account');
                }
                $currencyId = $accountFrom->currency->id;
                $db->createCommand('UPDATE `accounts` SET balance = balance - :amount WHERE id = :id', [
                    ':amount' => $amount,
                    ':id' => $accountFrom->id
                ])->execute();
            }
            if (!($accountTo instanceof Account) && $accountTo == 0) {
                $transactionType = Transaction::TYPE_WITHDRAWAL;
            } else {
                $currencyId = $accountTo->currency->id;

                $db->createCommand('UPDATE `accounts` SET balance = balance + :amount WHERE id = :id',
                    [
                        ':amount' => $amount,
                        ':id' => $accountTo->id
                    ])->execute();
            }

            $transaction->currency_id = $currencyId;
            $transaction->type = $transactionType;
            if ($transaction->save()) {
                if ($this->isInternalAtomic) {
                    $this->atomic->commit();
                }
                return $transaction;
            }
            throw new \Exception("Error on save transaction");
        } catch (\Exception $e) {
            if ($this->isInternalAtomic) {
                $this->atomic->rollback();
            }
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * @param User $user
     * @param Currency $currency
     * @param int $houseedge
     * @return boolean
     * @throws \Exception
     */
    public function updateAffProfit(User $user, Currency $currency, $houseedge)
    {
        $this->initAtomicOperation();
        if ($this->isInternalAtomic) {
            $this->atomic->begin(\yii\db\Transaction::SERIALIZABLE);
        }
        try {
            $model = AffProfit::findOne(['user_id' => $user->id, 'currency_id' => $currency->id]);
            if (!$model) {
                $model = new AffProfit;
                $model->user_id = $user->id;
                $model->currency_id = $currency->id;
                $model->houseedge = (int)$houseedge;
                if (!$model->save()) {
                    throw new \Exception("Can not create aff profit: user id #{$user->id}, currency id #{$currency->id}");
                }
            } else {
                $model->updateCounters(['houseedge' => (int)$houseedge]);
            }

            if ($this->isInternalAtomic) {
                $this->atomic->commit();
            }
            return $model;
        } catch (\Exception $e) {
            if ($this->isInternalAtomic) {
                $this->atomic->rollback();
            }
            \Yii::error($e->getMessage() . PHP_EOL . ' In file ' . $e->getFile() . ' at line ' . $e->getLine());
            throw $e;
        }
    }

    /**
     * @param User $partner
     * @param User $user
     * @param Currency $currency
     * @param int $houseedge
     * @return PartnerProfit
     * @throws \Exception
     */
    public function updatePartnerProfit(User $partner, User $user, Currency $currency, $houseedge)
    {
        if ($currency->is_internal || !$partner->is_partner) {
            return true;
        }
        $this->initAtomicOperation();
        if ($this->isInternalAtomic) {
            $this->atomic->begin(\yii\db\Transaction::SERIALIZABLE);
        }
        try {
            $model = PartnerProfit::findOne(['partner_id' => $partner->id, 'user_id' => $user->id, 'currency_id' => $currency->id]);
            if (!$model) {
                $model = new PartnerProfit;
                $model->user_id = $user->id;
                $model->currency_id = $currency->id;
                $model->houseedge = (int)$houseedge;
                $model->partner_id = $partner->id;

                if (!$model->save()) {
                    throw new \Exception("Can not create partner profit: partner id #{$partner->id}, user id #{$user->id}, currency id #{$currency->id}");
                }
            } else {
                $model->updateCounters(['houseedge' => (int)$houseedge]);
            }
            if ($this->isInternalAtomic) {
                $this->atomic->commit();
            }
            return $model;
        } catch (\Exception $e) {
            if ($this->isInternalAtomic) {
                $this->atomic->rollback();
            }
            \Yii::error($e->getMessage() . PHP_EOL . 'In file ' . $e->getFile() . ' at line ' . $e->getLine());
            throw $e;
        }
    }

    /**
     * Баланс "горячего" кошелька
     *
     * @param Currency $currency
     * @return int
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getWalletBalance(Currency $currency)
    {
        $client = new \GuzzleHttp\Client();

        try {
            $response = $client->request('POST', $this->walletBalanceUrl, [
                'form_params' => [
                    'currency_code' => $currency->code,
                ]]);

            if ($response->getStatusCode() == 200) {
                $body = json_decode($response->getBody(), true);
                if (isset($body['balance'])) {
                    return str_replace(',', '', $body['balance']);
                }
            }
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {

        }

        return 0;
    }

    /**
     * @param Currency $currency
     * @param $toCode
     * @return float
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getCurrencyRate(Currency $currency, $toCode)
    {
        $avgRate = 0;
        $rowCount = 0;

        $pair = $currency->code . '_' . $toCode;
        $response = $this->httpClient->request('GET',
            'https://api.exmo.com/v1/trades/?pair=' . $pair, []);

        $data = json_decode($response->getBody()->getContents(), true);

        if (!is_array($data)) {
            throw new \Exception('Exmo send bad response');
        }

        foreach ($data[$pair] as $d) {
            if ($d['type'] == 'buy') {
                $rowCount++;
                $avgRate += $d['price'];
            }
        }

        if ($rowCount > 0) {
            $avgRate = $avgRate / $rowCount;
        } else {
            $avgRate = 0;
        }

        return (float)$avgRate;
    }

    /**
     * @param AtomicOperation $atomic
     */
    public function setAtomic(AtomicOperation $atomic)
    {
        $this->atomic = $atomic;
        $this->isInternalAtomic = false;
    }

    /**
     * Инициализация транзакции
     */
    protected function initAtomicOperation()
    {
        if (is_null($this->atomic)) {
            $this->atomic = new AtomicOperation();
            $this->isInternalAtomic = true;
        }
    }
}
