<?php

namespace Module\BaseModule\Controllers;

use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Objects\Formatters;

class News {

    public static function adminList() {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) {
            die('401');
        }
        $news = Panel::getDatabase()->custom_query("SELECT news.*, users.username FROM news LEFT JOIN users ON news.authorId = users.id ORDER BY id DESC")->fetchAll(\PDO::FETCH_ASSOC);
        // hier aus DB laden
        Panel::compile('_views/_pages/admin/news.html', array_merge([
            'news' => $news
        ], Panel::getLanguage()->getPage('admin_news')));
    }

    public static function archive() {
        $news = Panel::getDatabase()->custom_query("SELECT news.*, users.username FROM news LEFT JOIN users ON news.authorId = users.id WHERE news.public = 1 ORDER BY id DESC")->fetchAll(\PDO::FETCH_ASSOC);
        Panel::compile('_views/_pages/news_archive.html', array_merge([
            'news' => $news
        ], Panel::getLanguage()->getPage('news_archive')));
    }

    public static function add() {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) {
            die('401');
        }

        $title = $_POST['title'];
        $content = $_POST['content'];
        $author = $user->getId();

        if (isset($_POST['id']) && $_POST['id'] != "") {
            Panel::getDatabase()->update('news', [
                'title' => $title,
                'content' => $content,
                'authorId' => $author
            ], 'id', $_POST['id']);
            die(print_r(isset($_POST['id']), true));
        } else {
            Panel::getDatabase()->insert('news', [
                'title' => $title,
                'content' => $content,
                'authorId' => $author,
                'public' => 0
            ]);
            header('Content-Type: application/json');
            die(json_encode(['id' => Panel::getDatabase()->get_last_id()]));
        }
    }

    public static function togglePublic($id) {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) die('401');

        Panel::getDatabase()->custom_query("UPDATE news SET public = NOT public WHERE id=?", ['id' => $id]);
        die('ok');
    }

    public static function getSingleNews($id) {
        header('Content-Type: application/json');
        $item = Panel::getDatabase()->custom_query("SELECT news.*, users.username FROM news LEFT JOIN users ON news.authorId = users.id WHERE news.id=? ORDER BY id DESC", ['id' => $id])->fetchAll(\PDO::FETCH_ASSOC)[0];
        if ($item) {
            $item['createdAt'] = Formatters::formatDateAbsolute($item['createdAt']);
        }
        die(json_encode($item));
    }

    public static function deleteEntry($id) {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) die('401');

        Panel::getDatabase()->delete('news', 'id', $id);
        die();
    }

    public static function apiGetLast() {
        $item = Panel::getDatabase()->custom_query("SELECT news.*, users.username FROM news LEFT JOIN users ON news.authorId = users.id WHERE news.public = 1 ORDER BY id DESC LIMIT 1")->fetchAll();

        if (sizeof($item) > 0) {
            $item[0]->createdAt = Formatters::formatDateAbsolute($item[0]->createdAt);
        }

        return $item;
    }
}
