<?php

namespace Module\BaseModule\Controllers;

use GuzzleHttp\Client;
use Module\BaseModule\Controllers\Admin\Settings;

class Captcha {

    static $PROVIDERS = [
        'google_v3'            => 'Google reCAPTCHA v3',
        'google_enterprise'    => 'Google reCAPTCHA Enterprise',
        'cloudflare_turnstile' => 'Cloudflare Turnstile'
    ];

    public static function verify($token) {
        $tokenProvider = Settings::getConfigEntry('CAPTCHA_PROVIDER', false);
        if (!$tokenProvider)
            return true;

        $client = new Client();
        switch ($tokenProvider) {
            case 'google_enterprise':
            case 'google_v3':
                $result = $client->post('https://www.google.com/recaptcha/api/siteverify', [
                    \GuzzleHttp\RequestOptions::FORM_PARAMS => [
                        'secret'   => Settings::getConfigEntry('CAPTCHA_PRIVATE'),
                        'response' => $token
                    ]
                ]);
                $r = json_decode($result->getBody());
                return $r->success && $r->score >= Settings::getConfigEntry('CAPTCHA_THRESHOLD', 0.5);

            case 'cloudflare_turnstile':
                $result = $client->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    \GuzzleHttp\RequestOptions::FORM_PARAMS => [
                        'secret'   => Settings::getConfigEntry('CAPTCHA_PRIVATE'),
                        'response' => $token
                    ]
                ]);
                $r = json_decode($result->getBody());
                return $r->success;
        }
    }
}