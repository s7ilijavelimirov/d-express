<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * D Express Paketomat Shipping Method
 */
function init_dexpress_dispenser_shipping_method()
{
    if (!class_exists('WC_Shipping_Method')) {
        return;
    }

    class D_Express_Dispenser_Shipping_Method extends WC_Shipping_Method
    {
        public function __construct($instance_id = 0)
        {
            $this->id = 'dexpress_dispenser';
            $this->instance_id = absint($instance_id);
            $this->method_title = __('D Express Paketomat', 'd-express-woo');
            $this->method_description = __('D Express dostava u paketomat', 'd-express-woo');

            $this->supports = array(
                'shipping-zones',
                'instance-settings',
                'instance-settings-modal',
            );

            $this->init();
        }

        public function init()
        {
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title', __('D Express Paketomat', 'd-express-woo'));
            $this->enabled = $this->get_option('enabled', 'yes');

            add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields()
        {
            $this->instance_form_fields = array(
                'title' => array(
                    'title' => __('Naziv dostave', 'd-express-woo'),
                    'type' => 'text',
                    'default' => __('D Express Paketomat', 'd-express-woo'),
                ),
                'cost' => array(
                    'title' => __('Cena dostave', 'd-express-woo'),
                    'type' => 'price',
                    'default' => '250',
                    'description' => __('Cena dostave u RSD', 'd-express-woo'),
                ),
                'free_shipping_min' => array(
                    'title' => __('Besplatna dostava od', 'd-express-woo'),
                    'type' => 'price',
                    'default' => '5000',
                    'description' => __('Besplatna dostava za porudžbine preko ovog iznosa (u RSD). Postavite 0 da isključite.', 'd-express-woo'),
                ),
                'apply_coupons' => array(
                    'title' => __('Uračunaj kupone', 'd-express-woo'),
                    'type' => 'checkbox',
                    'label' => __('Uračunaj iznose kupona u uslov za besplatnu dostavu', 'd-express-woo'),
                    'default' => 'no',
                    'description' => __('Ako je uključeno, ukupan iznos porudžbine nakon primenjenih kupona će biti korišćen za određivanje besplatne dostave.', 'd-express-woo'),
                )
            );
        }

        public function calculate_shipping($package = array())
        {
            $cost = $this->get_option('cost');
            $free_shipping_min = $this->get_option('free_shipping_min');
            $apply_coupons = $this->get_option('apply_coupons') === 'yes';

            // Ukupna vrednost porudžbine
            $cart_total = WC()->cart->get_displayed_subtotal();

            // Proveriti da li da uračunamo kupone u ukupan iznos
            if (!$apply_coupons) {
                $discount_total = WC()->cart->get_discount_total();
                $cart_total += $discount_total;
            }

            // Sačuvaj originalnu vrednost naslova
            $title = $this->title;

            // Primeni besplatnu dostavu ako je potrebno
            if ($free_shipping_min > 0 && $cart_total >= $free_shipping_min) {
                $cost = 0;
                $title = sprintf(__('%s (Besplatna dostava)', 'd-express-woo'), $this->title);
            }

            $rate = array(
                'id' => $this->get_rate_id(),
                'label' => $title,
                'cost' => $cost,
                'calc_tax' => 'per_order',
                'meta_data' => array(
                    'dispenser_selection' => 'required'
                )
            );

            $this->add_rate($rate);
        }
    }
}

// Registracija shipping metode
add_action('woocommerce_shipping_init', 'init_dexpress_dispenser_shipping_method');
add_filter('woocommerce_shipping_methods', 'add_dexpress_dispenser_shipping_method');

function add_dexpress_dispenser_shipping_method($methods)
{
    $methods['dexpress_dispenser'] = 'D_Express_Dispenser_Shipping_Method';
    return $methods;
}