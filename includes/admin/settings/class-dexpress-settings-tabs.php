<?php

/**
 * D Express Settings Tabs Handler
 * 
 * Odgovoran za strukturu i logiku tabova u settings stranici
 */

defined('ABSPATH') || exit;

class D_Express_Settings_Tabs
{
    /**
     * Vraća listu dozvoljenih tabova
     */
    public static function get_allowed_tabs()
    {
        return ['api', 'codes', 'auto', 'sender', 'shipment', 'webhook', 'cron', 'uninstall'];
    }

    /**
     * Vraća nazive tabova
     */
    public static function get_tab_titles()
    {
        return [
            'api' => __('API Podešavanja', 'd-express-woo'),
            'codes' => __('Kodovi pošiljki', 'd-express-woo'),
            'auto' => __('Kreiranje pošiljki', 'd-express-woo'),
            'sender' => __('Magacini pošiljaoca', 'd-express-woo'),
            'shipment' => __('Podešavanja pošiljke', 'd-express-woo'),
            'webhook' => __('Webhook podešavanja', 'd-express-woo'),
            'cron' => __('Automatsko ažuriranje', 'd-express-woo'),
            'uninstall' => __('Clean Uninstall', 'd-express-woo')
        ];
    }

    /**
     * Vraća aktivni tab
     */
    public static function get_active_tab()
    {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api';
        $allowed_tabs = self::get_allowed_tabs();
        
        if (!in_array($active_tab, $allowed_tabs)) {
            $active_tab = 'api';
        }
        
        return $active_tab;
    }

    /**
     * Renderuje tab navigaciju
     */
    public static function render_tab_navigation($active_tab)
    {
        $tab_titles = self::get_tab_titles();
        $allowed_tabs = self::get_allowed_tabs();
        
        echo '<div class="dexpress-tab-links">';
        
        foreach ($allowed_tabs as $tab) {
            $is_active = $active_tab === $tab ? 'active' : '';
            $title = esc_html($tab_titles[$tab]);
            
            echo '<a href="#tab-' . esc_attr($tab) . '"';
            echo ' class="dexpress-tab-link ' . $is_active . '"';
            echo ' data-tab="' . esc_attr($tab) . '"';
            echo ' onclick="switchTab(event, \'' . esc_attr($tab) . '\')">';
            echo $title;
            echo '</a>';
        }
        
        echo '</div>';
    }

    /**
     * Renderuje tab sadržaj wrapper početak
     */
    public static function render_tabs_wrapper_start()
    {
        echo '<div class="dexpress-tabs">';
    }

    /**
     * Renderuje tab sadržaj wrapper kraj
     */
    public static function render_tabs_wrapper_end()
    {
        echo '</div>';
    }

    /**
     * Pomoćna funkcija za početak tab div-a
     */
    public static function render_tab_start($tab_id, $active_tab, $title = '')
    {
        $is_active = $active_tab === $tab_id ? 'active' : '';
        
        echo '<div id="tab-' . esc_attr($tab_id) . '" class="dexpress-tab ' . $is_active . '">';
        
        if (!empty($title)) {
            echo '<h2>' . esc_html($title) . '</h2>';
        }
    }

    /**
     * Pomoćna funkcija za kraj tab div-a
     */
    public static function render_tab_end()
    {
        echo '</div>';
    }

    /**
     * Renderuje dugmad za akcije (na dnu stranice)
     */
    public static function render_action_buttons()
    {
        echo '<div class="dexpress-settings-actions" style="margin-top: 20px;">';
        
        echo '<button type="submit" name="dexpress_save_settings" class="button button-primary">';
        echo __('Sačuvaj podešavanja', 'd-express-woo');
        echo '</button>';

        echo '<a href="' . esc_url(admin_url('admin.php?page=dexpress-settings&action=update_indexes')) . '" class="button button-secondary">';
        echo __('Ažuriraj šifarnike', 'd-express-woo');
        echo '</a>';

        if (dexpress_is_test_mode()) {
            echo '<a href="' . esc_url(admin_url('admin.php?page=dexpress-settings&action=test_connection')) . '" class="button button-secondary">';
            echo __('Testiraj konekciju', 'd-express-woo');
            echo '</a>';
        }
        
        echo '</div>';
    }
}