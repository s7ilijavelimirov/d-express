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

        // Dodaj filtere za update proveru
        add_filter('pre_set_site_transient_update_plugins', [$this, 'modify_transient'], 10, 1);
        add_filter('plugins_api', [$this, 'plugin_popup'], 10, 3);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
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
            ]
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $this->github_response = json_decode($response_body, true);

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
            $transient->response[$this->plugin_slug] = (object) [
                'slug' => $this->repository,
                'plugin' => $this->plugin_slug,
                'new_version' => $latest_version,
                'url' => $release['html_url'],
                'package' => $release['assets'][0]['browser_download_url'],
                'icons' => [
                    '1x' => DEXPRESS_WOO_PLUGIN_URL . 'assets/images/icon-128x128.png',
                    '2x' => DEXPRESS_WOO_PLUGIN_URL . 'assets/images/icon-256x256.png',
                ],
            ];
        }

        return $transient;
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
            'download_link' => $release['assets'][0]['browser_download_url']
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