<?php

namespace common\components\Affiliates\handlers;

use common\components\Affiliates\Event;
use common\models\User;

/**
 * Class Notify
 * @package common\components\Affiliates\handlers
 */
class Notify extends BaseHandler
{

    /**
     * @param Event $event
     * @return bool
     */
    public function run(Event $event): bool
    {
        if (!parent::run($event)) {
            return false;
        }

        if (!isset($event->extData['affiliate'])) {
            return false;
        }

        if (!$event->extData['affiliate']->id) {
            return false;
        }

        $user = User::findOne(['id' => $event->extData['affiliate']->id]);
        if (!$user) {
            return false;
        }

        if (!\Yii::$app->params['TGNotifyEnabled']) {
            return true;
        }
        
        try {
            $url = 'https://api.telegram.org/' . getenv('TG_BOT_CREDENTIALS') . '/sendMessage?chat_id=' . getenv('TG_BOT_CHAT_ID') . '&text=' . urlencode('New user #' . $user->id . ' just registered');

            $client = new \GuzzleHttp\Client();
            $response = $client->get($url);
        } catch (\Exception $e) {
            \Yii::warning("Error on send telegram notify: " . $e->getMessage());
        }

        return true;
    }
}
