<?php

namespace Objects;

use Controllers\Panel;
use DateTime;
use IntlDateFormatter;
use Module\BaseModule\Controllers\Admin;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\BaseModule\Controllers\Invoice;

class Formatters {

    public static function getRank($permission) {
        $ranks = [
            Panel::getLanguage()->get('global', 'm_customer'),
            Panel::getLanguage()->get('global', 'm_supporter'),
            Panel::getLanguage()->get('global', 'm_admin')
        ];

        return $ranks[$permission - 1];
    }

    public static function formatBalance($input) {
        // format in correct locale.
        $currency         = Invoice::getActiveCurrency();
        $currencyPosition = Settings::getConfigEntry('CURRENCY_POSITION', 'BEHIND');

        $currencySymbol = $currency['symbol'];

        $fmt = number_format(
            (float) preg_replace(
                '/,/',
                '.',
                $input),
            $currency['decimals'],
            $currency['decimal_separator'],
            $currency['thousand_separator']
        );

        return $currencyPosition == 'BEHIND' ? "$fmt $currencySymbol" : "$currencySymbol $fmt";
    }

    public static function formatPercentage($input) {
        $lang = Panel::getLanguage()->getCurrentLanguage(true);

        if ($lang == "en") {
            return number_format($input, floatval($input) == intval($input) ? 0 : 2, '.', ',') . " %";
        } else {
            return number_format($input, floatval($input) == intval($input) ? 0 : 2, ',', '') . " %";
        }
    }



    public static function getPaymentMethod($method, $_method) {
        $lang = Panel::getLanguage()->getCurrentLanguage(true);
        switch ($method) {
            case "mollie":
                if (is_array(Balance::$mollieMethods[$_method])) {
                    return Balance::$mollieMethods[$_method][$lang];
                }
                return Balance::$mollieMethods[$_method];
        }
    }

    public static function formatPriority($priority) {
        return Panel::getLanguage()->get('support', 'm_priority_' . Constants::PRIORITIES[$priority]);
    }
    public static function getTicketStatus($status) {
        if ($status == 0) {
            return Panel::getLanguage()->get('ticket', 'm_staus_open');
        } else {
            return Panel::getLanguage()->get('ticket', 'm_status_closed');
        }
    }

    public static function formatDateAbsolute($input) {
        $lang = Panel::getLanguage()->getCurrentLanguage(true);
        $fmt  = new IntlDateFormatter($lang, 0, 0);
        $fmt->setPattern("d MMMM yyyy HH:mm");
        return $fmt->format(new DateTime($input));
    }

    public static function formatTicketDate($input) {
        return self::time_elapsed_string($input);
    }

    public static function formatTimeInSeconds($input, $decimals = 2) {
        $lang = Panel::getLanguage()->getCurrentLanguage(true);
        if ($lang == "de") {
            return number_format($input, $decimals, ',', ' ');
        } else {
            return number_format($input, $decimals, '.', ' ');
        }
    }

    /**
     * format a date into an elapsed string
     *
     * @suppress PHP0416
     * @param [type] $datetime
     * @param boolean $full
     * @return string
     */
    private static function time_elapsed_string($datetime, $full = false) {
        $now  = new DateTime();
        $ago  = new DateTime($datetime);
        $diff = $now->diff($ago);

        $w     = floor($diff->d / 7);
        $diffs = [
            'y' => $diff->y,
            'm' => $diff->m,
            'w' => $w,
            'd' => $diff->d,
            'h' => $diff->h,
            'i' => $diff->i,
            's' => $diff->s
        ];

        $lang = Panel::getLanguage()->getCurrentLanguage(true);

        if ($lang == "de") {
            $string = array(
                'y' => 'Jahr',
                'm' => 'Monat',
                'w' => 'Woche',
                'd' => 'Tag',
                'h' => 'Stunde',
                'i' => 'Minute',
                's' => 'Sekunde',
            );

            $multipliers = [
                'y' => 'en',
                'm' => 'en',
                'w' => 'en',
                'd' => 'en',
                'h' => 'n',
                'i' => 'n',
                's' => 'n'
            ];

            $prefix = "vor ";
            $suffix = "";
            $just   = "Gerade eben";
        } else {
            $string = array(
                'y' => 'year',
                'm' => 'month',
                'w' => 'week',
                'd' => 'day',
                'h' => 'hour',
                'i' => 'minute',
                's' => 'second',
            );

            $multipliers = [
                'y' => 's',
                'm' => 's',
                'w' => 's',
                'd' => 's',
                'h' => 's',
                'i' => 's',
                's' => 's'
            ];
            $prefix      = "";
            $suffix      = " ago";
            $just        = "Just now";
        }


        foreach ($string as $k => &$v) {
            if ($diffs[$k]) {
                $v = $diffs[$k] . ' ' . $v . ($diffs[$k] > 1 ? $multipliers[$k] : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full)
            $string = array_slice($string, 0, 1);
        return $string ? $prefix . implode(', ', $string) . $suffix : $just;
    }

    /**
     * format a big number into something more readable
     * 
     * @param mixed $input
     * @return float|string
     */
    public static function shortBigNumber($input) {
        $lang     = Panel::getLanguage()->getCurrentLanguage(true);
        $suffixes = [
            'en' => ['', 'K', 'M', 'B', 'T'],
            'de' => ['', 'Tsd', 'Mio', 'Mrd', 'Bio'],
        ];

        if (isset($suffixes[$lang])) {
            $suffix = $suffixes[$lang];
        } else {
            $suffix = $suffixes['en'];
        }

        $number        = floatval($input);
        $abbreviations = count($suffix);

        for ($i = $abbreviations - 1; $i > 0; $i--) {
            $size = pow(1000, $i);
            if ($number >= $size) {
                $short = $number / $size;
                return number_format($short, 1, '.', '') . $suffix[$i];
            }
        }

        return (string) intval($number);
    }
}