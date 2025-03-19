<?php

/**
 * Validacija podataka prema D Express API specifikaciji
 */
class D_Express_Validator
{

    /**
     * Validira telefonski broj
     * 
     * Format: 381XXXXXXXXX (381 + broj bez prve nule)
     */
    public static function validate_phone($phone)
    {
        $pattern = '/^(381[1-9][0-9]{7,8}|38167[0-9]{6,8})$/';
        return preg_match($pattern, $phone);
    }

    /**
     * Formatira telefonski broj u D Express format
     */
    public static function format_phone($phone)
    {
        // Ukloni sve osim brojeva
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Dodaj prefiks 381 ako ne postoji
        if (substr($phone, 0, 3) !== '381') {
            // Ako počinje sa 0, zameniti 0 sa 381
            if (substr($phone, 0, 1) === '0') {
                $phone = '381' . substr($phone, 1);
            } else {
                // Inače samo dodati 381 na početak
                $phone = '381' . $phone;
            }
        }

        return $phone;
    }

    /**
     * Validira naziv (ime, naziv ulice itd.)
     */
    public static function validate_name($name)
    {
        $pattern = '/^([\-a-zžćčđšA-ZĐŠĆŽČ_0-9\.]+)( [\-a-zžćčđšA-ZĐŠĆŽČ_0-9\.]+)*$/';
        return preg_match($pattern, $name) && strlen($name) <= 50;
    }

    /**
     * Validira broj kuće/zgrade
     */
    public static function validate_address_number($number)
    {
        $pattern = '/^((bb|BB|b\.b\.|B\.B\.)(\/[-a-zžćčđšA-ZĐŠĆŽČ_0-9]+)*|(\d(-\d){0,1}[a-zžćčđšA-ZĐŠĆŽČ_0-9]{0,2})+(\/[-a-zžćčđšA-ZĐŠĆŽČ_0-9]+)*)$/';
        return preg_match($pattern, $number) && strlen($number) <= 10;
    }

    /**
     * Validira opis sadržaja pošiljke
     */
    public static function validate_content($content)
    {
        $pattern = '/^([\-,\(\)\/a-zžćčđšA-ZĐŠĆŽČ_0-9]+\.?)( [\-,\(\)\/a-zžćčđšA-ZĐŠĆŽČ_0-9]+\.?)*$/';
        return preg_match($pattern, $content) && strlen($content) <= 50;
    }

    /**
     * Validira referencu
     */
    public static function validate_reference($reference)
    {
        $pattern = '/^([\-\#\$a-zžćčđšA-ZĐŠĆŽČ_0-9,:;\+\(\)\/\.]+)( [\-\#\$a-zžćčđšA-ZĐŠĆŽČ_0-9,:;\+\(\)\/\.]+)*$/';
        return preg_match($pattern, $reference) && strlen($reference) <= 50;
    }

    /**
     * Validira bankovni račun
     */
    public static function validate_bank_account($account)
    {
        // Format: XXX-XXXXXXXXXX-XX 
        $pattern = '/^\d{3}-\d{10}-\d{2}$/';
        return preg_match($pattern, $account) && strlen($account) <= 20;
    }

    /**
     * Validira Town ID
     */
    public static function validate_town_id($town_id)
    {
        return is_numeric($town_id) && $town_id >= 100000 && $town_id <= 10000000;
    }

    /**
     * Validira kompletne podatke shipment-a
     */
    public static function validate_shipment_data($data)
    {
        $errors = [];

        // Validacija osnovnih obaveznih polja
        if (empty($data['CClientID']) || !preg_match('/^UK[0-9]{5}$/', $data['CClientID'])) {
            $errors[] = "Neispravan ClientID format (treba biti u formatu UK12345)";
        }

        // Validacija imena
        foreach (['CName', 'PuName', 'RName'] as $field) {
            if (empty($data[$field]) || !self::validate_name($data[$field])) {
                $errors[] = "Neispravan format za polje {$field}";
            }
        }

        // Validacija adresa
        foreach (['CAddress', 'PuAddress', 'RAddress'] as $field) {
            if (empty($data[$field]) || !self::validate_name($data[$field])) {
                $errors[] = "Neispravan format za polje {$field}";
            }
        }

        // Validacija brojeva adresa
        foreach (['CAddressNum', 'PuAddressNum', 'RAddressNum'] as $field) {
            if (empty($data[$field]) || !self::validate_address_number($data[$field])) {
                $errors[] = "Neispravan format za polje {$field}";
            }
        }

        // Validacija ID-jeva gradova
        foreach (['CTownID', 'PuTownID', 'RTownID'] as $field) {
            if (empty($data[$field]) || !self::validate_town_id($data[$field])) {
                $errors[] = "Neispravan format za polje {$field}";
            }
        }

        // Validacija kontakt telefona
        foreach (['CCPhone', 'PuCPhone', 'RCPhone'] as $field) {
            if (!empty($data[$field]) && !self::validate_phone($data[$field])) {
                $errors[] = "Neispravan format telefona za polje {$field}";
            }
        }

        // Validacija reference
        if (empty($data['ReferenceID']) || !self::validate_reference($data['ReferenceID'])) {
            $errors[] = "Neispravan format za ReferenceID";
        }

        // Validacija sadržaja
        if (empty($data['Content']) || !self::validate_content($data['Content'])) {
            $errors[] = "Neispravan format za Content";
        }

        // Validacija mase
        if (empty($data['Mass']) || !is_numeric($data['Mass']) || $data['Mass'] <= 0 || $data['Mass'] > 10000000) {
            $errors[] = "Neispravan format za Mass";
        }

        // Validacija otkupnine i bankovnog računa
        if (!empty($data['BuyOut']) && $data['BuyOut'] > 0) {
            if (empty($data['BuyOutAccount']) || !self::validate_bank_account($data['BuyOutAccount'])) {
                $errors[] = "Ako je definisana otkupnina, BuyOutAccount mora biti ispravnog formata";
            }
        }

        return empty($errors) ? true : $errors;
    }
}
