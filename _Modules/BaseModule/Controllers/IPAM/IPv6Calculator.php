<?php

namespace Module\BaseModule\Controllers\IPAM;

class IPv6Calculator {

    public function getInformation($superNet, $subNetCdr) {

        $result = [];

        $charHost = inet_pton(strtok($superNet, '/'));
        $charMask = $this->_cdr2Char(strtok('/'), strlen($charHost));

        $charHostMask = substr($this->_cdr2Char(127), -strlen($charHost));

        $charWC      = ~$charMask; // Supernet wildcard mask
        $charNet     = $charHost & $charMask; // Supernet network address
        $charBcst    = $charNet | ~$charMask; // Supernet broadcast
        $charHostMin = $charNet | ~$charHostMask; // Minimum host
        $charHostMax = $charBcst & $charHostMask; // Maximum host



        $result['network']              = [];
        $result['network']['network']   = inet_ntop($charNet) . "/" . $this->_char2Cdr($charMask);
        $result['network']['netmask']   = inet_ntop($charMask) . " = /" . $this->_char2Cdr($charMask);
        $result['network']['wildcard']  = inet_ntop($charWC);
        $result['network']['broadcast'] = inet_ntop($charBcst);
        $result['network']['hostmin']   = inet_ntop($charHostMin);
        $result['network']['hostmax']   = inet_ntop($charHostMax);

        if ($subNetCdr) {
            preg_match('/\d+/', $subNetCdr, $cdr);
            $subNetCdr    = $cdr[0];
            $charSubMask  = $this->_cdr2Char($subNetCdr, strlen($charHost));
            $charSubWC    = ~$charSubMask; // Subnet wildcard mask
            $superNetMask = inet_ntop($charSubMask);
        } else {
            exit - 2;
        }

        $intNet   = $this->_unpackBytes($charNet);
        $intSubWC = $this->_unpackBytes($charSubWC);
        $n        = 0;
        $intSub   = $intNet;
        $charSub  = $charNet;
        $charSubs = array();
        while ((($charSub & $charMask) == $charNet) && $n < 4096) {
            array_push($charSubs, $charSub);
            $intSub  = $this->_addBytes($intSub, $intSubWC);
            $charSub = $this->_packBytes($intSub);
            $n++;
        }
        foreach ($charSubs as $charSub) {
            $hex = unpack("H*hex", $charSub);
            $ip  = substr(preg_replace("/([A-f0-9]{4})/", "$1:", $hex['hex']), 0, -1);

            $result['networks'][] = [
                'network'  => inet_ntop($charSub),
                'expanded' => $ip,
                'prefix'   => $this->_char2Cdr($charSubMask)
            ];
        }

        return $result;
    }


    function _packBytes($array) {
        $chars = "";
        foreach ($array as $byte) {
            $chars .= pack('C', $byte);
        }
        return $chars;
    }

    // Convert binary to array of short integers
    function _unpackBytes($string) {
        return unpack('C*', $string);
    }

    // Add array of short unsigned integers
    function _addBytes($array1, $array2) {
        $result = array();
        $carry  = 0;
        foreach (array_reverse($array1, true) as $value1) {
            $value2 = array_pop($array2);
            if (empty($result)) {
                $value2++;
            }
            $newValue = $value1 + $value2 + $carry;
            if ($newValue > 255) {
                $newValue = $newValue - 256;
                $carry    = 1;
            } else {
                $carry = 0;
            }
            array_unshift($result, $newValue);
        }
        return $result;
    }

    /* Useful Functions */

    function _cdr2Bin($cdrin, $len = 4) {
        if ($len > 4 || $cdrin > 32) { // Are we ipv6?
            return str_pad(str_pad("", $cdrin, "1"), 128, "0");
        } else {
            return str_pad(str_pad("", $cdrin, "1"), 32, "0");
        }
    }

    function _bin2Cdr($binin) {
        return strlen(rtrim($binin, "0"));
    }

    function _cdr2Char($cdrin, $len = 4) {
        $hex = $this->_bin2Hex($this->_cdr2Bin($cdrin, $len));
        return $this->_hex2Char($hex);
    }

    function _char2Cdr($char) {
        $bin = $this->_hex2Bin($this->_char2Hex($char));
        return $this->_bin2Cdr($bin);
    }

    function _hex2Char($hex) {
        return pack('H*', $hex);
    }

    function _char2Hex($char) {
        $hex = unpack('H*', $char);
        return array_pop($hex);
    }

    function _hex2Bin($hex) {
        $bin = '';
        for ($i = 0; $i < strlen($hex); $i++)
            $bin .= str_pad(decbin(hexdec($hex[$i])), 4, '0', STR_PAD_LEFT);
        return $bin;
    }

    function _bin2Hex($bin) {
        $hex = '';
        for ($i = strlen($bin) - 4; $i >= 0; $i -= 4)
            $hex .= dechex(bindec(substr($bin, $i, 4)));
        return strrev($hex);
    }
}
