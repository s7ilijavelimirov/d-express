<?php
// Ako WordPress nije pozvao ovu skriptu, izađi
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Učitaj uninstaller klasu
require_once plugin_dir_path(__FILE__) . 'includes/class-dexpress-uninstaller.php';

// Pokreni uninstaller
D_Express_Uninstaller::uninstall();
