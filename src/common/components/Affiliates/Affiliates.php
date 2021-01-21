<?php

namespace common\components\Affiliates;

use common\models\User;
use yii\base\Component;
use common\models\AffiliateStats;
use common\components\Affiliates\Event;

/**
 * Class Affiliates
 * @package common\components\Affiliates
 */
class Affiliates extends Component
{
    const ON_REGISTRATION = 'on_registration';
    const ON_GAME = 'on_game';
    const COOKIE_LIFETIME = 3600 * 24 * 30;

    public $handlers = [];

    /**
     *
     */
    public function init()
    {
        foreach ($this->handlers as $type => $handlers) {
            foreach ($handlers as $handler) {
                $hnd = new $handler['class'];
                if (isset($handler['enabled'])) {
                    $hnd->enabled = $handler['enabled'];
                }
                $this->on($type, [$hnd, 'run']);
            }
        }
    }

    /**
     * Устанавливет куку
     *
     * @param string $link
     * @return bool
     */
    public function addVisitor(string $link = '')
    {
        if (!\Yii::$app->user->isGuest || isset($_COOKIE[User::AFFILIATE_COOKIE_NAME])) {
            return;
        }

        setcookie(User::AFFILIATE_COOKIE_NAME, $link, time() + static::COOKIE_LIFETIME, "/");

        $externalId = \Yii::$app->request->get('click_id', null);
        if ($externalId) {
            setcookie(User::AFFILIATE_EXTERNAL_ID_COOKIE_NAME, $externalId, time() + static::COOKIE_LIFETIME, "/");
        }

        $affUser = User::find()->where(['aff_link' => $link])->one();
        if (!$affUser) {
            return;
        }
        $affStat = AffiliateStats::find()
            ->where(['user_id' => $affUser->id])
            ->one();

        if (!$affStat) {
            $affStat = new AffiliateStats();
            $affStat->user_id = $affUser->id;
            $affStat->clicks = 1;
        } else {
            $affStat->clicks++;
        }

        $affStat->save();
    }

    /**
     * Устанавливает aff_id пользователю
     *
     * @param User $user
     * @return boolean
     */
    public function setAffId(User $user)
    {
        if ($user->aff_id) {
            return true;
        }

        if (!isset($_COOKIE[User::AFFILIATE_COOKIE_NAME])) {
            return false;
        }

        $affUser = User::find()
            ->where(['aff_link' => $_COOKIE[User::AFFILIATE_COOKIE_NAME]])
            ->one();

        if (!$affUser) {
            return false;
        }

        $user->aff_id = $affUser->id;

        if (isset($_COOKIE[User::AFFILIATE_EXTERNAL_ID_COOKIE_NAME])) {
            $user->external_id = $_COOKIE[User::AFFILIATE_EXTERNAL_ID_COOKIE_NAME];
        }

        return $user->save();
    }

    /**
     * Событие регистрации пользователя
     *
     * @param User $user
     * @return bool
     */
    public function onAffiliateRegistration(User $user): bool
    {
        if ($this->setAffId($user)) {
            $aff = $user->affiliate;

            $affStat = $aff->affiliateStats;

            if (!$affStat) {
                $affStat = new AffiliateStats();
                $affStat->user_id = $aff->id;
                $affStat->users = 1;
            } else {
                $affStat->users++;
            }

            if (!$affStat->save()) {
                return false;
            }
        }

        $event = new Event;
        $event->sender = $this;
        $event->name = self::ON_REGISTRATION;
        $event->extData = [
            'affiliate' => $user,
        ];

        $this->trigger(self::ON_REGISTRATION, $event);

        return true;
    }

    /**
     * @param $user
     * @param $houseEdge
     * @param $currency
     */
    public function onPlay($user, $houseEdge, $currency)
    {
        if (!$this->setAffId($user)) {
            return;
        }

        $event = new Event;
        $event->sender = $this;
        $event->name = self::ON_GAME;
        $event->extData = [
            'user' => $user,
            'houseedge' => $houseEdge,
            'currency' => $currency,
        ];

        $this->trigger(self::ON_GAME, $event);
    }
}
