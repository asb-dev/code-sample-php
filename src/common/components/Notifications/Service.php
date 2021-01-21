<?php

namespace common\components\Notifications;

use common\models\Notification;
use common\models\User;
use yii\base\Component;

/**
 * Class Service
 * @package common\components\Notifications
 */
class Service extends Component
{

    public $mockMode = false;

    const LIMIT = 10;
    const TIMEOUT = 10;

    /**
     * @param $userId
     * @param $message
     * @param int $type
     * @param bool $send
     */
    public function add($userId, $message, $type = Notification::TYPE_INFO, $send = true)
    {
        $model = new Notification;
        $model->message = $message;
        $model->user_id = $userId;
        $model->type = $type;
        $model->status = Notification::STATUS_NEW;

        if ($model->save() && $send) {
            $this->sendToUser($userId);
        }
    }

    /**
     * @param $userId
     */
    public function sendToUser($userId)
    {
        $user = User::findOne(['id' => $userId]);
        if (!$user) {
            return;
        }

        $notifications = array_map(function ($el) {
            return [
                "message" => $el->message,
                "type" => $el->type,
                "id" => $el->id,
            ];
        }, $this->getByUser($user->id));

        $message = [
            "message" => \json_encode(["notifications" => $notifications]),
            "uuid" => $user->uuid,
        ];

        $this->send($message);
    }

    /**
     * @param $message
     * @return bool
     */
    public function send($message)
    {
        if ($this->mockMode) {
            return true;
        }

        $localsocket = \Yii::$app->websockets->internalServer;
        try {
            // соединяемся с локальным tcp-сервером
            $instance = stream_socket_client($localsocket, $errorno, $errorstr,
                self::TIMEOUT, STREAM_CLIENT_ASYNC_CONNECT);
            // отправляем сообщение
            fwrite($instance, \json_encode($message));
        } catch (\Exception $e) {

        }

        if (!empty($instance)) {
            fclose($instance);
        }
    }

    /**
     * @param $userId
     * @param bool $markAsRead
     * @param int $limit
     * @return array|\yii\db\ActiveRecord[]
     */
    public static function getByUser($userId, $markAsRead = false, $limit = self::LIMIT): array
    {
        if (!Notification::find()->where(['status' => Notification::STATUS_NEW, 'user_id' => $userId])->exists()) {
            return [];
        }

        $notifications = Notification::find()
            ->where(['status' => Notification::STATUS_NEW, 'user_id' => $userId])
            ->limit($limit)
            ->orderBy('created_at DESC')
            ->all();

        if ($markAsRead) {
            $ids = array_map(function ($el) {
                return $el->id;
            }, $notifications);

            Notification::updateAll(['status' => Notification::STATUS_READ], ['in', 'id', $ids]);
        }

        return $notifications;
    }

}
