<?php

/**
 * Centralizovana klasa za validaciju D Express podataka
 * 
 * Objedinjuje sve validacije na jednom mestu za lakše održavanje
 */

defined('ABSPATH') || exit;

class D_Express_Validator
{

    /**
     * Validacija kompletne narudžbine za D Express dostavu
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return bool|WP_Error True ako je validacija uspela, WP_Error ako nije
     */
    public static function validate_order($order)
    {
        try {
            // 1. Validacija adrese
            $address_validation = self::validate_order_address($order);
            if (is_wp_error($address_validation)) {
                return $address_validation;
            }

            // 2. Validacija težine
            $weight_validation = self::validate_order_weight($order);
            if (is_wp_error($weight_validation)) {
                return $weight_validation;
            }

            // 3. Validacija vrednosti i otkupnine
            $value_validation = self::validate_order_value($order);
            if (is_wp_error($value_validation)) {
                return $value_validation;
            }

            // 4. Validacija telefona
            $phone_validation = self::validate_order_phone($order);
            if (is_wp_error($phone_validation)) {
                return $phone_validation;
            }

            // 5. Provera da li je paketomat i dodatne validacije
            $is_dispenser = !empty(get_post_meta($order->get_id(), '_dexpress_dispenser_id', true));
            if ($is_dispenser) {
                $dispenser_validation = self::validate_dispenser_order($order);
                if (is_wp_error($dispenser_validation)) {
                    return $dispenser_validation;
                }
            }

            // Sve validacije su prošle
            return true;
        } catch (Exception $e) {
            return new WP_Error('validation_exception', $e->getMessage());
        }
    }

    /**
     * Validacija za checkout proces
     * Koristi se pre završetka narudžbine
     * 
     * @return bool True ako validacija prolazi
     */
    public static function validate_checkout()
    {
        // Proveravamo samo ako je checkout forma poslata
        if (empty($_POST['woocommerce-process-checkout-nonce'])) {
            return true;
        }

        // Proverava da li je odabrana D Express dostava
        $is_dexpress = false;
        $is_dispenser = false;
        if (!empty($_POST['shipping_method'])) {
            foreach ($_POST['shipping_method'] as $method) {
                if (strpos($method, 'dexpress') !== false) {
                    $is_dexpress = true;
                    if (strpos($method, 'dexpress_dispenser') !== false) {
                        $is_dispenser = true;
                    }
                    break;
                }
            }
        }

        if (!$is_dexpress) {
            return true;
        }

        // Provera obaveznih polja adrese
        $address_type = isset($_POST['ship_to_different_address']) ? 'shipping' : 'billing';
        $errors = [];

        // Provera ulice
        if (empty($_POST[$address_type . '_street']) || empty($_POST[$address_type . '_street_id'])) {
            $errors[] = __('Morate izabrati ulicu iz padajućeg menija.', 'd-express-woo');
        }

        // Provera kućnog broja
        if (empty($_POST[$address_type . '_number'])) {
            $errors[] = __('Kućni broj je obavezan.', 'd-express-woo');
        } elseif (!self::validate_address_number($_POST[$address_type . '_number'])) {
            $errors[] = __('Kućni broj nije u ispravnom formatu. Podržani formati: 15, bb, 23a, 44/2', 'd-express-woo');
        }

        // Provera grada
        if (empty($_POST[$address_type . '_city_id'])) {
            $errors[] = __('Potrebno je izabrati grad.', 'd-express-woo');
        }

        // Provera telefona
        if (empty($_POST['billing_phone'])) {
            $errors[] = __('Broj telefona je obavezan za D Express dostavu.', 'd-express-woo');
        } elseif (!self::validate_phone_format($_POST['billing_phone'])) {
            $errors[] = __('Broj telefona mora biti u formatu +3816XXXXXXXX ili +3811XXXXXXXX.', 'd-express-woo');
        }

        // Provera paketomata ako je odabran
        $is_dispenser_shipping = false;
        foreach (WC()->session->get('chosen_shipping_methods', array()) as $method) {
            if (strpos($method, 'dexpress_dispenser') !== false) {
                $is_dispenser_shipping = true;
                break;
            }
        }

        if ($is_dispenser_shipping && empty($_POST['dexpress_chosen_dispenser'])) {
            $errors[] = __('Morate izabrati paketomat za dostavu.', 'd-express-woo');
        }

        // Provera težine narudžbine
        $cart_weight = self::calculate_cart_weight();
        if ($cart_weight <= 0) {
            $errors[] = __('Težina vaše narudžbine mora biti veća od 0.', 'd-express-woo');
        } else {
            // KLJUČNA PROMENA: Provera maksimalne težine zavisno od tipa dostave
            if ($is_dispenser) {
                // Paketomat ima limit od 20kg
                if ($cart_weight > 20000) { // 20kg u gramima
                    $errors[] = __('Za dostavu u paketomat, ukupna težina narudžbine ne može biti veća od 20kg.', 'd-express-woo');
                }
            } else {
                // Standardna dostava ima limit od 10.000kg
                if ($cart_weight > 10000000) { // 10.000kg u gramima
                    $errors[] = sprintf(
                        __('Ukupna težina narudžbine ne može biti veća od %s kg za D Express dostavu.', 'd-express-woo'),
                        number_format(10000, 0, ',', '.')
                    );
                }
            }
        }

        // Provera da li je pouzeće (COD)
        $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : '';
        $is_cod = ($payment_method === 'cod' || $payment_method === 'bacs' || $payment_method === 'cheque');

        if ($is_cod) {
            // Maksimalna vrednost otkupnine za standardnu dostavu
            $max_buyout = 1000000000; // 10.000.000 RSD u para (100 para = 1 RSD)

            // Ako je paketomat, poseban limit
            if ($is_dispenser_shipping) {
                $max_buyout = 20000000; // 200.000 RSD u para
            }

            // Provera iznosa
            $cart_total_para = WC()->cart->get_total('edit') * 100;
            if ($cart_total_para > $max_buyout) {
                $errors[] = sprintf(
                    __('Vrednost porudžbine za otkupninu ne može biti veća od %s RSD za D Express dostavu.', 'd-express-woo'),
                    number_format($max_buyout / 100, 2, ',', '.')
                );
            }

            // Provera postojanja računa za otkupninu
            if (get_option('dexpress_require_buyout_account', 'no') === 'yes') {
                $buyout_account = get_option('dexpress_buyout_account', '');
                if (empty($buyout_account) || !self::validate_bank_account($buyout_account)) {
                    $errors[] = __('Za pouzeće je obavezan validan bankovni račun. Postavite ga u D Express podešavanjima.', 'd-express-woo');
                }
            }
        }

        // Dodavanje grešaka u WooCommerce
        foreach ($errors as $error) {
            wc_add_notice($error, 'error');
        }

        return empty($errors);
    }

    /**
     * Validacija adrese narudžbine
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return bool|WP_Error True ako je validna, WP_Error ako nije
     */
    private static function validate_order_address($order)
    {
        // Odredite koji tip adrese koristiti
        $address_type = $order->has_shipping_address() ? 'shipping' : 'billing';

        // Dohvatite meta podatke za adresu
        $street = $order->get_meta("_{$address_type}_street", true);
        $number = $order->get_meta("_{$address_type}_number", true);
        $city_id = $order->get_meta("_{$address_type}_city_id", true);

        // Validacija ulice
        if (empty($street) || !self::validate_name($street)) {
            return new WP_Error('invalid_street', __('Neispravan format ulice. Molimo unesite ispravnu ulicu.', 'd-express-woo'));
        }

        // Validacija kućnog broja
        if (empty($number) || !self::validate_address_number($number)) {
            return new WP_Error('invalid_address_number', __('Neispravan format kućnog broja. Prihvatljiv format: bb, 10, 15a, 23/4', 'd-express-woo'));
        }

        // Validacija grada
        if (empty($city_id) || !self::validate_town_id($city_id)) {
            return new WP_Error('invalid_town', __('Neispravan grad. Molimo izaberite grad iz liste.', 'd-express-woo'));
        }

        return true;
    }

    /**
     * Validacija težine narudžbine
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return bool|WP_Error True ako je validna, WP_Error ako nije
     */
    private static function validate_order_weight($order)
    {
        $weight_grams = self::calculate_order_weight($order); // Dobijamo težinu u gramima

        if ($weight_grams <= 0) {
            return new WP_Error('invalid_weight', __('Težina narudžbine mora biti veća od 0.', 'd-express-woo'));
        }

        // 1. Maksimalna težina za standardnu dostavu (10.000 kg = 10.000.000 g)
        $max_weight = 10000000; // 10.000 kg u gramima
        if ($weight_grams > $max_weight) {
            return new WP_Error(
                'weight_limit_exceeded',
                sprintf(__('Težina pošiljke ne može biti veća od %s kg.', 'd-express-woo'), number_format($max_weight / 1000, 0, ',', '.'))
            );
        }

        return true;
    }

    /**
     * Validacija vrednosti narudžbine i otkupnine
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return bool|WP_Error True ako je validna, WP_Error ako nije
     */
    private static function validate_order_value($order)
    {
        // Provera vrednosti - vrednost pošiljke (Value) mora biti između 0 i 1.000.000.000 para (10.000.000 RSD)
        $value_para = self::convert_price_to_para($order->get_total());
        $max_value = 1000000000; // 10.000.000 RSD

        if ($value_para > $max_value) {
            return new WP_Error(
                'value_limit_exceeded',
                sprintf(
                    __('Vrednost pošiljke ne može biti veća od %s RSD.', 'd-express-woo'),
                    number_format($max_value / 100, 2, ',', '.')
                )
            );
        }

        // Provera metode plaćanja i otkupnine
        $payment_method = $order->get_payment_method();
        $is_cod = ($payment_method === 'cod' || $payment_method === 'bacs' || $payment_method === 'cheque');

        if ($is_cod) {
            // Opšta provera maksimuma za otkupninu prema API dokumentaciji (10.000.000 RSD / 1.000.000.000 para)
            $max_buyout_api = 1000000000; // 10.000.000 RSD u para

            // Provera limita otkupnine
            if ($value_para > $max_buyout_api) {
                return new WP_Error(
                    'buyout_limit_exceeded',
                    sprintf(
                        __('Vrednost otkupnine ne može biti veća od %s RSD za D Express dostavu.', 'd-express-woo'),
                        number_format($max_buyout_api / 100, 2, ',', '.')
                    )
                );
            }

            // Provera postojanja računa za otkupninu
            if (get_option('dexpress_require_buyout_account', 'no') === 'yes') {
                $buyout_account = get_option('dexpress_buyout_account', '');
                if (empty($buyout_account) || !self::validate_bank_account($buyout_account)) {
                    return new WP_Error(
                        'missing_buyout_account',
                        __('Za pouzeće je obavezan validan bankovni račun. Podesite ga u D Express podešavanjima.', 'd-express-woo')
                    );
                }
            }
        }

        return true;
    }

    /**
     * Validacija telefona
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return bool|WP_Error True ako je validna, WP_Error ako nije
     */
    private static function validate_order_phone($order)
    {
        $phone = get_post_meta($order->get_id(), '_billing_phone_api_format', true);

        if (empty($phone)) {
            $phone = self::format_phone($order->get_billing_phone());
        }

        if (!self::validate_phone($phone)) {
            return new WP_Error('invalid_phone', __('Neispravan format telefona. Format treba biti +381XXXXXXXXX', 'd-express-woo'));
        }

        return true;
    }

    /**
     * Dodatne validacije za paketomat
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return bool|WP_Error True ako je validna, WP_Error ako nije
     */
    private static function validate_dispenser_order($order)
    {
        // 1. Provera broja paketa - mora biti samo jedan
        // Ovo je ograničenje D Express API-ja za paketomate
        // Za sada uvek prolazi jer plugin ne podržava više paketa

        // 2. Provera težine - mora biti manja od 20 kg
        $weight_grams = self::calculate_order_weight($order);
        if ($weight_grams > 20000) { // 20kg u gramima
            return new WP_Error('paketomat_validation', __('Za dostavu u paketomat, pošiljka mora biti lakša od 20kg.', 'd-express-woo'));
        }

        // 3. Provera vrednosti otkupnine - mora biti manja od 200.000 RSD
        $payment_method = $order->get_payment_method();
        $is_cod = ($payment_method === 'cod' || $payment_method === 'bacs' || $payment_method === 'cheque');

        if ($is_cod) {
            $total_para = self::convert_price_to_para($order->get_total());
            if ($total_para > 20000000) { // 200.000 RSD u para
                return new WP_Error('paketomat_validation', __('Za dostavu u paketomat, otkupnina mora biti manja od 200.000,00 RSD.', 'd-express-woo'));
            }
        }

        // 4. Provera povratnih dokumenata - ne sme biti povratka dokumenata
        $return_doc = intval(get_option('dexpress_return_doc', 0));
        if ($return_doc != 0) {
            return new WP_Error('paketomat_validation', __('Za dostavu u paketomat nije dozvoljeno vraćanje dokumenata.', 'd-express-woo'));
        }

        // 5. Provera da li je telefon mobilni
        // Mobilni brojevi su obavezni za paketomate jer se šalje SMS
        $phone = get_post_meta($order->get_id(), '_billing_phone_api_format', true);

        if (empty($phone)) {
            $phone = self::format_phone($order->get_billing_phone());
        }

        if (!self::validate_mobile_phone($phone)) {
            return new WP_Error('paketomat_validation', __('Za dostavu u paketomat, potreban je validan mobilni broj telefona (+3816XXXXXXXX).', 'd-express-woo'));
        }

        // 6. Dimenzije paketa - po API dokumentaciji ne smeju biti veće od 470 x 440 x 440mm
        $max_dimensions = array(
            'length' => 470, // mm
            'width'  => 440, // mm
            'height' => 440  // mm
        );

        // Proveri dimenzije svakog proizvoda u narudžbini
        $items = $order->get_items();
        foreach ($items as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            // Proveri da li proizvod ima dimenzije
            if ($product->has_dimensions()) {
                $length = wc_get_dimension($product->get_length(), 'mm');
                $width = wc_get_dimension($product->get_width(), 'mm');
                $height = wc_get_dimension($product->get_height(), 'mm');

                // Ako bilo koja dimenzija prelazi maksimum, vrati grešku
                if ($length > $max_dimensions['length'] || $width > $max_dimensions['width'] || $height > $max_dimensions['height']) {
                    return new WP_Error(
                        'paketomat_validation',
                        sprintf(
                            __('Proizvod "%s" je prevelikih dimenzija za paketomat. Maksimalne dozvoljene dimenzije su %d x %d x %d mm.', 'd-express-woo'),
                            $product->get_name(),
                            $max_dimensions['length'],
                            $max_dimensions['width'],
                            $max_dimensions['height']
                        )
                    );
                }
            }
        }

        // NAPOMENA: Ostala ograničenja za paketomate:
        // - Paketomat pošiljka ne može imati povrat dokumenta (ReturnDoc mora biti 0)
        // - Paketomat pošiljka modu da ima samo jedan paket

        return true;
    }
    /**
     * Izračunavanje težine narudžbine u gramima
     * 
     * @param WC_Order $order WooCommerce narudžbina
     * @return int Težina u gramima
     */
    public static function calculate_order_weight($order)
    {
        $weight = 0;

        // Provera da li je objekat narudžbine validan
        if (!($order instanceof WC_Order)) {
            return 0;
        }

        // Dohvatanje stavki narudžbine
        $items = $order->get_items();
        if (empty($items)) {
            return 0;
        }

        foreach ($items as $item) {
            try {
                if (!$item instanceof WC_Order_Item_Product) {
                    continue;
                }

                $product = $item->get_product();
                if ($product && $product->has_weight()) {
                    $product_weight_kg = floatval($product->get_weight()); // Težina u kg
                    $product_weight_g = $product_weight_kg * 1000; // Konverzija u grame
                    $weight += $product_weight_g * $item->get_quantity();
                }
            } catch (Exception $e) {
                dexpress_log("Greška pri dohvatanju težine proizvoda: " . $e->getMessage(), 'error');
                continue;
            }
        }

        // Minimalna težina 100g
        $weight = max(0.1, $weight);

        // Konverzija u grame
        return self::convert_weight_to_grams($weight);
    }

    /**
     * Izračunavanje težine korpe u gramima
     * 
     * @return int Težina u gramima
     */
    public static function calculate_cart_weight()
    {
        $weight = 0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if ($product && $product->has_weight()) {
                $product_weight_kg = floatval($product->get_weight());
                $weight += $product_weight_kg * $cart_item['quantity'];
            }
        }

        // Minimalna težina 100g (0.1kg)
        $weight = max(0.1, $weight);

        // Konverzija u grame
        return self::convert_weight_to_grams($weight);
    }
    /**
     * Validacija mobilnog telefona
     * Prihvata samo mobilne brojeve za Srbiju
     * 
     * @param string $phone Broj telefona
     * @return bool True ako je validan mobilni broj
     */
    public static function validate_mobile_phone($phone)
    {
        // Prihvata samo mobilne brojeve za Srbiju sa prefiksom 6
        // 381 + 6 + 7-8 cifara = mobilni brojevi
        $pattern = '/^(381[6][0-9]{7,8})$/';
        return preg_match($pattern, $phone);
    }


    /**
     * Konverzija kg u grame
     * 
     * @param float $weight_kg Težina u kg
     * @return int Težina u gramima (zaokružena na ceo broj)
     */
    public static function convert_weight_to_grams($weight_kg)
    {
        $grams = round($weight_kg * 1000);
        return $grams;
    }

    /**
     * Konverzija cene u para
     * 
     * @param float $price Cena u valuti prodavnice
     * @return int Cena u para (100 para = 1 RSD)
     */
    public static function convert_price_to_para($price)
    {
        // Konverzija u RSD ako je potrebno
        $price_rsd = $price; // Ovde bi trebalo implementirati konverziju ako prodavnica ne koristi RSD

        // Konverzija u para (1 RSD = 100 para)
        return intval($price_rsd * 100);
    }

    /**
     * Validira telefonski broj
     * 
     * Format: 381XXXXXXXXX (381 + broj bez prve nule)
     * 
     * @param string $phone Broj telefona
     * @return bool True ako je ispravan
     */
    public static function validate_phone($phone)
    {
        // Prihvata sve brojeve koji počinju sa 381 i imaju cifru 1-9 iza toga, pa još 7-8 cifara
        $pattern = '/^(381[1-9][0-9]{7,8})$/';
        return preg_match($pattern, $phone);
    }

    /**
     * Validacija telefonskog broja sa prefiksom +
     * 
     * @param string $phone Broj telefona
     * @return bool True ako je ispravan
     */
    public static function validate_phone_format($phone)
    {
        // Prihvata sve brojeve koji počinju sa +381 i imaju cifru 1-9 iza toga, pa još 7-8 cifara
        $pattern = '/^\+381[1-9][0-9]{7,8}$/';
        return preg_match($pattern, $phone);
    }

    /**
     * Formatira telefonski broj u D Express format
     * 
     * @param string $phone Broj telefona
     * @return string Formatiran broj
     */
    public static function format_phone($phone)
    {
        // Ukloni sve osim brojeva
        $digits_only = preg_replace('/[^0-9]/', '', $phone);

        // Ukloni +381 ili 381 sa početka ako postoji
        if (strpos($digits_only, '381') === 0) {
            // Već ima 381 prefiks
            return $digits_only;
        } elseif (strpos($digits_only, '0') === 0) {
            // Počinje sa 0, zameni sa 381
            return '381' . substr($digits_only, 1);
        } else {
            // Dodaj prefiks 381
            return '381' . $digits_only;
        }
    }

    /**
     * Validacija naziva (ime, naziv ulice itd.)
     * 
     * @param string $name Naziv
     * @return bool True ako je ispravan
     */
    public static function validate_name($name)
    {
        $name = trim($name);
        $pattern = '/^([\-a-zžćčđšA-ZĐŠĆŽČ_0-9\.]+)( [\-a-zžćčđšA-ZĐŠĆŽČ_0-9\.]+)*$/';
        return preg_match($pattern, $name) && strlen($name) <= 50;
    }

    /**
     * Validacija broja kuće/zgrade
     * 
     * @param string $number Broj
     * @return bool True ako je ispravan
     */
    public static function validate_address_number($number)
    {
        $pattern = '/^((bb|BB|b\.b\.|B\.B\.)(\/[-a-zžćčđšA-ZĐŠĆŽČ_0-9]+)*|(\d(-\d){0,1}[a-zžćčđšA-ZĐŠĆŽČ_0-9]{0,2})+(\/[-a-zžćčđšA-ZĐŠĆŽČ_0-9]+)*)$/';
        return preg_match($pattern, $number) && strlen($number) <= 10;
    }

    /**
     * Validacija TownID
     * 
     * @param int $town_id ID grada
     * @return bool True ako je ispravan
     */
    public static function validate_town_id($town_id)
    {
        return is_numeric($town_id) && $town_id >= 100000 && $town_id <= 10000000;
    }

    /**
     * Validacija bankovnog računa
     * 
     * @param string $account Broj računa
     * @return bool True ako je ispravan
     */
    public static function validate_bank_account($account)
    {
        // Proverimo osnovni format (XXX-XXXXXXXXX-XX)
        return preg_match('/^\d{3}-\d{8,13}-\d{2}$/', $account);
    }
    /**
     * Validacija opisa sadržaja pošiljke
     * 
     * @param string $content Opis sadržaja
     * @return bool True ako je ispravan
     */
    public static function validate_content($content)
    {
        $pattern = '/^([\-,\(\)\/a-zžćčđšA-ZĐŠĆŽČ_0-9]+\.?)( [\-,\(\)\/a-zžćčđšA-ZĐŠĆŽČ_0-9]+\.?)*$/';
        return preg_match($pattern, $content) && strlen($content) <= 50;
    }

    /**
     * Validacija reference
     * 
     * @param string $reference Referenca
     * @return bool True ako je ispravan
     */
    public static function validate_reference($reference)
    {
        $pattern = '/^([\-\#\$a-zžćčđšA-ZĐŠĆŽČ_0-9,:;\+\(\)\/\.]+)( [\-\#\$a-zžćčđšA-ZĐŠĆŽČ_0-9,:;\+\(\)\/\.]+)*$/';
        return preg_match($pattern, $reference) && strlen($reference) <= 50;
    }

    /**
     * Validacija kompletnih podataka shipment-a
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
