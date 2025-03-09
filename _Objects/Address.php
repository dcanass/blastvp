<?php

namespace Objects;

use Controllers\Panel;

class Address {

    public $gender;
    public $birthday;
    public $address1;
    public $address2;
    public $state;
    public $zipcode;
    public $country, $_country;
    public $city;
    public $countryAlpha;
    public $vatId;
    public $phone;

    public function __construct(private $userid) {
    }

    /**
     * load the address to a user. 
     * Not required for every context so it's left out in a default setting and not done in the constructor
     *
     * @return Address
     */
    public function load() {

        $data = Panel::getDatabase()->fetch_single_row('addresses', 'userid', $this->userid);

        if (!$data) {
            return $this;
        }

        $this->gender       = $data->gender;
        $this->birthday     = $data->birthday;
        $this->address1     = $data->address1;
        $this->address2     = $data->address2;
        $this->state        = $data->state;
        $this->zipcode      = $data->zipcode;
        $this->country      = $data->country;
        $this->city         = $data->city;
        $this->phone = $data->phone;
        $this->vatId = $data->vatId;
        $this->_country     = Constants::COUNTRYS[$this->country];
        $this->countryAlpha = Constants::COUNTRY_NUM_TO_ALPHA[$this->country];

        return $this;
    }

    /**
     * return wether all required fields are set or not.
     *
     * @return boolean
     */
    public function isValid() {
        return ($this->address1 && $this->zipcode && $this->country);
    }

    /**
     * save address-data in the database.
     *
     * @param [type] $data
     * @return Address
     */
    public function save($data) {
        $has = Panel::getDatabase()->fetch_single_row('addresses', 'userid', $this->userid);

        if ($has) {
            Panel::getDatabase()->update('addresses', $data, 'userid', $this->userid);
        } else {
            Panel::getDatabase()->insert('addresses', $data);
        }
        return $this;
    }

    /**
     * Get the value of gender
     */
    public function getGender() {
        return $this->gender;
    }

    /**
     * Set the value of gender
     *
     * @return  self
     */
    public function setGender($gender) {
        $this->gender = $gender;

        return $this;
    }

    /**
     * Get the value of birthday
     */
    public function getBirthday() {
        return $this->birthday;
    }

    /**
     * Set the value of birthday
     *
     * @return  self
     */
    public function setBirthday($birthday) {
        $this->birthday = $birthday;

        return $this;
    }

    /**
     * return joined address
     *
     * @return string
     */
    public function getAddress() {
        return trim($this->getAddress1() . " " . $this->getAddress2());
    }

    /**
     * Get the value of address1
     */
    public function getAddress1() {
        return $this->address1;
    }

    /**
     * Set the value of address1
     *
     * @return  self
     */
    public function setAddress1($address1) {
        $this->address1 = $address1;

        return $this;
    }

    /**
     * Get the value of address2
     */
    public function getAddress2() {
        return $this->address2;
    }

    /**
     * Set the value of address2
     *
     * @return  self
     */
    public function setAddress2($address2) {
        $this->address2 = $address2;

        return $this;
    }

    /**
     * Get the value of state
     */
    public function getState() {
        return $this->state;
    }

    /**
     * Set the value of state
     *
     * @return  self
     */
    public function setState($state) {
        $this->state = $state;

        return $this;
    }

    /**
     * Get the value of zipcode
     */
    public function getZipcode() {
        return $this->zipcode;
    }

    /**
     * Set the value of zipcode
     *
     * @return  self
     */
    public function setZipcode($zipcode) {
        $this->zipcode = $zipcode;

        return $this;
    }

    /**
     * Get the value of country
     */
    public function getCountry() {
        return $this->country;
    }

    /**
     * Set the value of country
     *
     * @return  self
     */
    public function setCountry($country) {
        $this->country = $country;

        return $this;
    }

    /**
     * Get the value of _country
     */
    public function get_country() {
        return $this->_country;
    }

    /**
     * Set the value of _country
     *
     * @return  self
     */
    public function set_country($_country) {
        $this->_country = $_country;

        return $this;
    }

    /**
     * Get the value of city
     */
    public function getCity() {
        return $this->city;
    }

    /**
     * Set the value of city
     *
     * @return  self
     */
    public function setCity($city) {
        $this->city = $city;

        return $this;
    }

    /**
     * Get the value of countryAlpha
     */
    public function getCountryAlpha() {
        return $this->countryAlpha;
    }

    /**
     * Set the value of countryAlpha
     *
     * @return  self
     */
    public function setCountryAlpha($countryAlpha) {
        $this->countryAlpha = $countryAlpha;

        return $this;
    }

    /**
     * Get the value of userid
     */
    public function getUserid() {
        return $this->userid;
    }

    /**
     * Set the value of userid
     *
     * @return  self
     */
    public function setUserid($userid) {
        $this->userid = $userid;

        return $this;
    }

    public function getPhone() {
        return $this->phone;
    }

    public function getVatId() {
        return $this->vatId;
    }

    /**
     * return wether the country is located in the EU or not
     *
     * @return boolean
     */
    public function isInEu() {
        return in_array($this->countryAlpha, Constants::EU_COUNTRYS);
    }
}
