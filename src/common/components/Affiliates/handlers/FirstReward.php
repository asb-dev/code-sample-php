<?php

namespace common\components\Affiliates\handlers;

use common\components\Affiliates\Event;
use common\services\FinancesService;
use common\models\Account;
use common\models\Currency;
use common\models\Transaction;
use common\models\Notification;
use common\services\UserCounterService;
use common\models\UserCounter;

/**
 * Class FirstReward
 * @package common\components\Affiliates\handlers
 */
class FirstReward extends BaseHandler
{

    const CURRENCY_CODE = 'DEMOCOIN';
    const REWARD = 1000; // минимальное вознаграждение

    /**
     * @param Event $event
     * @return bool
     * @throws \Exception
     */
    public function run(Event $event): bool
    {

        if (!parent::run($event)) {
            return false;
        }

        if (!isset($event->extData['affiliate'])) {
            return false;
        }

        $user = $event->extData['affiliate'];

        $currency = Currency::getByCode(self::CURRENCY_CODE);

        $finService = new FinancesService;
        $systemInternalAccount = Account::getSystemAccount($currency->id);

        $transaction = $finService->transfer($systemInternalAccount,
            $user->getAccount($currency), self::REWARD * $currency->denominator, Transaction::TYPE_REWARD);

        if (!$transaction) {
            throw new \Exception('Could not transfer money to user account');
        }

        $counterService = new UserCounterService;
        $counterService->updateCounter($user->id,
            UserCounter::COUNTER_FREE_COINS_ATTEMPT, 1);

        \Yii::$app->notifications->add($user->id,
            'Your reward for registration: ' . \common\helpers\MoneyHelper::format(self::REWARD) . " " . $currency->code,
            Notification::TYPE_REWARD);

        return true;
    }
}
