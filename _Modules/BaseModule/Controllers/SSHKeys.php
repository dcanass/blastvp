<?php

namespace Module\BaseModule\Controllers;

use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Objects\Constants;

class SSHKeys {

    public static function list() {
        $user = BaseModule::getUser();

        $keys = Panel::getDatabase()->custom_query("SELECT * FROM `ssh-keys` WHERE userId = ?", ['userId' => $user->getId()])->fetchAll(\PDO::FETCH_ASSOC);

        Panel::compile('_views/_pages/account/ssh-keys.html', array_merge([
            'keys' => $keys
        ], Panel::getLanguage()->getPage('ssh-keys')));
    }

    public static function apiFingerprint() {
        header('Content-Type: application/json');
        $key = $_POST['key'];

        $content = explode(' ', $key, 3);
        $fingerprint = join(':', str_split(md5(base64_decode($content[1])), 2));
        die(json_encode([
            'result' => $fingerprint
        ]));
    }

    public static function create() {
        header('Content-Type: application/json');
        $key = $_POST['key'];
        $name = $_POST['name'];
        $fingerprint = $_POST['fingerprint'];
        $user = BaseModule::getUser();

        if (!Constants::validateKey($key)) {
            die(json_encode(['error' => true, 'message' => Panel::getLanguage()->get('order', 'm_err_ssh')]));
        }

        Panel::getDatabase()->insert('ssh-keys', [
            'name' => $name,
            'content' => $key,
            'fingerprint' => $fingerprint,
            'userId' => $user->getId()
        ]);
        $id = Panel::getDatabase()->get_last_id();

        die(json_encode(['id' => $id, 'name' => $name, 'fingerprint' => $fingerprint]));
    }

    public static function deleteKey() {
        $id = $_POST['id'];
        $user = BaseModule::getUser();

        $key = Panel::getDatabase()->fetch_single_row('ssh-keys', 'id', $id, \PDO::FETCH_OBJ);
        if ($key->userId != $user->getId()) {
            die('401');
        }

        Panel::getDatabase()->delete('ssh-keys', 'id', $id);
        die('ok');
    }
}
