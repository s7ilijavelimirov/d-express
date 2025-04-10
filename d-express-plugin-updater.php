<?php
class D_Express_GitHub_Updater {
    private $plugin_file;
    private $plugin_slug;
    private $username;
    private $repository;
    private $github_response;

    public function __construct($plugin_file, $username, $repository) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->username = $username;
        $this->repository = $repository;

        // DEBUG: Log API poziva
        add_action('init', [$this, 'debug_update_check']);

        // Dodaj filtere za update proveru
        add_filter('pre_set_site_transient_update_plugins', [$this, 'modify_transient'], 10, 1);
        add_filter('plugins_api', [$this, 'plugin_popup'], 10, 3);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
    }

    // DEBUG funkcija za praćenje API poziva
    public function debug_update_check() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->get_repository_release();
        }
    }

    // Provera GitHub Release-a
    private function get_repository_release() {
        // Keš za smanjenje broja API poziva
        if (!empty($this->github_response)) {
            return $this->github_response;
        }

        $remote_url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest";
        
        $response = wp_remote_get($remote_url, [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json'
            ],
            'timeout' => 10  // Povećan timeout
        ]);

        // Logovanje greške
        if (is_wp_error($response)) {
            error_log('GitHub API Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('GitHub API HTTP Error: ' . $response_code);
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $this->github_response = json_decode($response_body, true);

        // Dodatno logovanje
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GitHub Release Data: ' . print_r($this->github_response, true));
        }

        return $this->github_response;
    }

    // Modifikacija update transient-a
    public function modify_transient($transient) {
        if (!property_exists($transient, 'checked')) {
            return $transient;
        }

        $plugin_data = get_plugin_data($this->plugin_file);
        $current_version = $plugin_data['Version'];

        $release = $this->get_repository_release();

        if (!$release) {
            return $transient;
        }

        // Ukloni 'v' prefix ako postoji
        $latest_version = ltrim($release['tag_name'], 'v');

        if (version_compare($current_version, $latest_version, '<')) {
            // Provera da li postoji ZIP asset
            $download_url = $this->get_download_url($release);

            if (!$download_url) {
                error_log('Nije pronađen download URL za plugin');
                return $transient;
            }

            $transient->response[$this->plugin_slug] = (object) [
                'slug' => $this->repository,
                'plugin' => $this->plugin_slug,
                'new_version' => $latest_version,
                'url' => $release['html_url'],
                'package' => $download_url,
                'icons' => [
                    '1x' => DEXPRESS_WOO_PLUGIN_URL . 'assets/images/icon-128x128.png',
                    '2x' => DEXPRESS_WOO_PLUGIN_URL . 'assets/images/icon-256x256.png',
                ],
            ];
        }

        return $transient;
    }

    // Pronalaženje download URL-a
    private function get_download_url($release) {
        // Pokušaj da nađeš ZIP asset
        if (isset($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (strtolower(pathinfo($asset['name'], PATHINFO_EXTENSION)) === 'zip') {
                    return $asset['browser_download_url'];
                }
            }
        }
    
        // Fallback na GitHub download archive
        return "https://github.com/{$this->username}/{$this->repository}/archive/refs/tags/{$release['tag_name']}.zip";
    }

    // Popup prozor sa informacijama o update-u
    public function plugin_popup($result, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== $this->repository) {
            return $result;
        }

        $release = $this->get_repository_release();

        if (!$release) {
            return $result;
        }

        $plugin_data = get_plugin_data($this->plugin_file);

        $popup = (object) [
            'name' => $plugin_data['Name'],
            'slug' => $this->repository,
            'version' => ltrim($release['tag_name'], 'v'),
            'last_updated' => $release['published_at'],
            'sections' => [
                'description' => $release['body'],
                'changelog' => $this->parse_changelog($release['body'])
            ],
            'download_link' => $this->get_download_url($release)
        ];

        return $popup;
    }

    // Parsiranje changelog-a iz release opisa
    private function parse_changelog($body) {
        $changelog = '';
        $lines = explode("\n", $body);
        
        foreach ($lines as $line) {
            if (strpos($line, '- ') === 0 || strpos($line, '* ') === 0) {
                $changelog .= $line . "\n";
            }
        }

        return $changelog ?: 'Nema detaljnih promena.';
    }

    // Post-instalaciona akcija
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        // Provera da li je instalacija za naš plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
            return $result;
        }

        // Logika za post-instalaciju (opciono)
        return $result;
    }
}
?>