<?php

namespace Controllers;

use GuzzleHttp\Client;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\Admin\Settings;
use Tracy\ILogger;

class Logger implements ILogger {

    public static function logIfCli($log) {
        if (php_sapi_name() !== 'cli') {
            return;
        }
        echo $log . PHP_EOL;
    }

    public function __construct() {
    }

    public function log($value, $priority = ILogger::INFO) {

        $guzzle = new Client();

        $res = $guzzle->post(
            'https://bennetgallein.de/api/error-handler',
            [
                'form_params' => [
                    'productId' => Panel::getModule('BaseModule')->getMeta()->productId

                ]
            ]
        )->getBody();
        $res = json_decode($res);

        if ($res->has) {
            \Sentry\init([
                'dsn'     => $res->sdn,
                'release' => Panel::getModule('BaseModule')->getMeta()->version
            ]);

            \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($res): void {
                $scope->setTag('server.ip', $res->ip);
            });

            \Sentry\captureException($value);

            $user = BaseModule::getUser();
            $id   = \Sentry\SentrySdk::getCurrentHub()->getLastEventId();

            if ($user->getPermission() > 2) {
                echo <<<html
                    <h1>Error encountered.</h1>
                    <p>The Panel has encountered an error. Since you are Admin, we show you this message. Since you have setup error handling, the error has already been reported to the team. When you reach out to us, give us the estimate time so we can look the error up.</p>
                    <p>Reference ID: $id</p>
                html;

                die();
                // Panel::compile('_views/_blank.html');
            }
        }
    }
}
