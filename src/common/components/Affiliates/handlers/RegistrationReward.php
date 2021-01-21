<?php

namespace common\components\Affiliates\handlers;

use common\components\Affiliates\Event;
use common\helpers\MoneyHelper;
use common\services\FinancesService;
use common\models\Account;
use common\models\Currency;
use common\models\User;
use common\models\Transaction;
use common\models\Notification;

/**
 * Class RegistrationReward
 * @package common\components\Affiliates\handlers
 */
class RegistrationReward extends BaseHandler
{

    const CURRENCY_CODE = 'DEMOCOIN';
    const REWARD = 200;

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

        if (!$event->extData['affiliate']->aff_id) {
            return false;
        }

        $user = User::findOne(['id' => $event->extData['affiliate']->aff_id]);
        if (!$user) {
            return false;
        }

        $currency = Currency::getByCode(self::CURRENCY_CODE);

        $finService = new FinancesService;
        $systemInternalAccount = Account::getSystemAccount($currency->id);

        $transaction = $finService->transfer($systemInternalAccount,
            $user->getAccount($currency), self::REWARD * $currency->denominator, Transaction::TYPE_REWARD);

        if (!$transaction) {
            throw new \Exception('Could not transfer bet money from user account');
        }

        \Yii::$app->notifications->add($user->id,
            'Your reward for new affiliate user registration: ' . MoneyHelper::format(self::REWARD) . " " . $currency->code,
            Notification::TYPE_REWARD);
        
        $this->sendCallback($user, $event->extData['affiliate']);

        return true;
    }

    /**
     * @param User $aff
     * @param User $user
     * @return boolean
     * @throws \Exception
     */
    private function sendCallback(User $aff, User $user)
    {
        if (!$aff->is_partner) {
            return true;
        }

        $callbacksUrls = $aff->callbacksUrls;
        if (!$callbacksUrls) {
            return true;
        }

        $url = $callbacksUrls->getPreparedLeadLink($user);
        if (!$url) {
            return true;
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->request('GET', $url);
            if ($response->getStatusCode() != 200) {
                throw new \Exception($response->getBody());
            }
        } catch (\Exception $e) {
            \Yii::error($e->getMessage() . PHP_EOL . 'In file ' . $e->getFile() . ' at line ' . $e->getLine());
            return false;
        }

        return true;
    }
}
