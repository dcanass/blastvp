<?php

namespace Controllers;

class Crontab {

    private static function stringToArray($jobs = '') {
        $array = explode("\r\n", trim($jobs ?? '')); // trim() gets rid of the last \r\n
        foreach ($array as $key => $item) {
            if ($item == '') {
                unset($array[$key]);
            }
        }
        return $array;
    }

    private static function arrayToString($jobs = array()) {
        $string = implode("\r\n", $jobs);
        return $string;
    }

    public static function getJobs() {
        $output = shell_exec('crontab -l');
        return self::stringToArray($output);
    }

    public static function saveJobs($jobs = array()) {
        $output = shell_exec('echo "' . self::arrayToString($jobs) . '" | crontab -');
        return $output;
    }

    public static function doesJobExist($job = '') {
        $jobs = self::getJobs();
        if (in_array($job, $jobs)) {
            return true;
        } else {
            return false;
        }
    }

    static public function addJob($job = '') {
        if (self::doesJobExist($job)) {
            return false;
        } else {
            $jobs = self::getJobs();
            $jobs[] = $job;
            return self::saveJobs($jobs);
        }
    }

    static public function removeJob($job = '') {
        if (self::doesJobExist($job)) {
            $jobs = self::getJobs();
            unset($jobs[array_search($job, $jobs)]);
            return self::saveJobs($jobs);
        } else {
            return false;
        }
    }
}
