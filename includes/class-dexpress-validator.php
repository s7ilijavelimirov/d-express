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
        $pattern = '/^(3816[0-9][0-9]{6,8}|381[1-9][0-9]{7,8})$/';
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
        $name = trim($name);
        $pattern = '/^[\-\#\$a-zžćčđšA-ZĐŠĆŽČ_0-9,:;\+\(\)\/\.]+( [\-\#\$a-zžćčđšA-ZĐŠĆŽČ_0-9,:;\+\(\)\/\.]+)*$/';
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
     * 
     * Poboljšana validacija koja podržava različite formate računa, ali obezbeđuje 
     * da račun ima osnovni format XXX-XXXXXXXXX-XX sa fleksibilnim brojem cifara u srednjem delu
     */
    public static function validate_bank_account($account)
    {
        // Uklonimo sve razmake prvo
        $account = trim($account);

        // Proverimo osnovni format (cifre i crtice)
        if (!preg_match('/^[\d\-]+$/', $account)) {
            return false;
        }

        // Proverimo dužinu (prema API dokumentaciji, maksimalna dužina je 20)
        if (strlen($account) > 20) {
            return false;
        }

        // Proverimo osnovni format (X-X-X) gde je X niz cifara
        $parts = explode('-', $account);
        if (count($parts) !== 3) {
            return false;
        }

        // Prvi deo bi trebao biti 3 cifre (npr. 170, 160, 200...)
        if (!preg_match('/^\d{1,3}$/', $parts[0])) {
            return false;
        }

        // Poslednji deo bi trebao biti 2 cifre (kontrolni broj)
        if (!preg_match('/^\d{1,2}$/', $parts[2])) {
            return false;
        }

        // Srednji deo može biti različite dužine, ali mora biti samo cifre
        if (!preg_match('/^\d+$/', $parts[1])) {
            return false;
        }

        // Sve provere su prošle
        return true;
    }

    /**
     * Validira Town ID
     */
    public static function validate_town_id($town_id)
    {
        return is_numeric($town_id) && $town_id >= 100000 && $town_id <= 10000000;
    }
    public static function validate_note($note)
    {
        $pattern = '/^([\-a-zžćčđšA-ZĐŠĆŽČ:,._0-9]+\.?)( [\-a-zžćčđšA-ZĐŠĆŽČ:,._0-9]+\.?)*$/u';
        return empty($note) || preg_match($pattern, $note);
    }
    /**
     * Validira kompletne podatke shipment-a
     * 
     * @param array $data Podaci za validaciju
     * @return true|array True ako su podaci validni, niz grešaka ako nisu
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
                $errors[] = "Neispravan format imena za polje {$field}";
            }
        }

        // Validacija adresa
        foreach (['CAddress', 'PuAddress', 'RAddress'] as $field) {
            if (empty($data[$field]) || !self::validate_name($data[$field])) {
                $errors[] = "Neispravan format adrese za polje {$field}";
            }
        }

        // Validacija brojeva adresa
        foreach (['CAddressNum', 'PuAddressNum', 'RAddressNum'] as $field) {
            if (empty($data[$field]) || !self::validate_address_number($data[$field])) {
                $errors[] = "Neispravan format broja adrese za polje {$field}";
            }
        }

        // Validacija ID-jeva gradova
        foreach (['CTownID', 'PuTownID', 'RTownID'] as $field) {
            if (empty($data[$field]) || !self::validate_town_id($data[$field])) {
                $errors[] = "Neispravan format ID-a grada za polje {$field}";
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
        if (!empty($data['BuyOut']) && (!is_numeric($data['BuyOut']) || $data['BuyOut'] <= 0)) {
            $errors[] = "Neispravan format za BuyOut";
        }
        if (!empty($data['BuyOut']) && $data['BuyOut'] > 0) {
            if (empty($data['BuyOutAccount'])) {
                $errors[] = "Za otkupninu (BuyOut) je obavezan bankovni račun";
            } elseif (!self::validate_bank_account($data['BuyOutAccount'])) {
                $errors[] = "Neispravan format bankovnog računa. Format treba biti XXX-YYYYYYYYY-ZZ gde je X poziv na broj, Y broj računa, Z kontrolni broj.";
            }
        }

        return empty($errors) ? true : $errors;
    }
}
