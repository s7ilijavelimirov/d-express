<?php

/**
 * D Express Plugin Updater
 * 
 * Omogućava automatsko ažuriranje plugina direktno iz GitHub repozitorijuma
 */

defined('ABSPATH') || exit;

class D_Express_Plugin_Updater
{
    private $slug;
    private $plugin_file;
    private $version;
    private $repo_url;
    private $plugin_name;
    private $username;
    private $repository;
    private $requires;
    private $tested;
    private $cache_key;
    private $cache_allowed;
    private $readme_data;

    /**
     * Konstruktor klase
     *
     * @param string $plugin_file Putanja do glavnog fajla plugina
     * @param string $repo_url URL do GitHub repozitorijuma
     */
    public function __construct($plugin_file, $repo_url)
    {
        $this->plugin_file = $plugin_file;

        $plugin_data = get_plugin_data($plugin_file);
        $this->version = $plugin_data['Version'];
        $this->plugin_name = $plugin_data['Name'];
        $this->slug = plugin_basename($plugin_file);

        // Parsiranje GitHub URL-a
        $repo_parts = parse_url($repo_url);
        $repo_path = ltrim($repo_parts['path'], '/');
        $repo_parts = explode('/', $repo_path);

        $this->username = $repo_parts[0];
        $this->repository = $repo_parts[1];
        $this->repo_url = 'https://github.com/' . $this->username . '/' . $this->repository;

        $this->requires = '6.0';  // Minimalna verzija WP-a koja je potrebna
        $this->tested = '6.7.2';  // Verzija WP-a na kojoj je plugin testiran

        $this->cache_key = 'dexpress_updater_cache';
        $this->cache_allowed = true;

        // Dodavanje filtera za proveravanje ažuriranja
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));

        // Filteri za informacije o plugin-u
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);

        // Postavka ikonica za plugin i bannera
        add_filter('plugin_row_meta', array($this, 'add_plugin_meta_links'), 10, 2);

        // Filter za poruku nakon ažuriranja
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);

        // Dodavanje akcije za proveru licence
        add_action('admin_init', array($this, 'check_license'));

        // Filtera za message posle updatea
        add_action('in_plugin_update_message-' . $this->slug, array($this, 'in_plugin_update_message'), 10, 2);
    }

    /**
     * Dodaje prilagođene linkove na stranicu sa plugin-ima
     */
    public function add_plugin_meta_links($links, $file)
    {
        if ($file == $this->slug) {
            $links[] = '<a href="' . $this->repo_url . '/issues" target="_blank">' . __('Prijavi problem', 'd-express-woo') . '</a>';
            $links[] = '<a href="' . $this->repo_url . '" target="_blank">' . __('GitHub Repo', 'd-express-woo') . '</a>';
        }
        return $links;
    }

    /**
     * Provera da li postoji ažuriranje
     */
    public function check_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Dobijanje podataka o poslednjoj verziji
        $remote_version = $this->get_latest_version();

        if (false !== $remote_version && version_compare($this->version, $remote_version, '<')) {
            $plugin_data = get_plugin_data($this->plugin_file);

            $response = new stdClass();
            $response->slug = $this->slug;
            $response->plugin = $this->slug;
            $response->new_version = $remote_version;
            $response->tested = $this->tested;
            $response->package = $this->get_download_url($remote_version);
            $response->url = $this->repo_url;
            $response->icons = array(
                '1x' => 'https://example.com/icon-128x128.png',  // Zameni sa URL-om tvoje ikone
                '2x' => 'https://example.com/icon-256x256.png',  // Zameni sa URL-om tvoje ikone
            );

            $transient->response[$this->slug] = $response;
        }

        return $transient;
    }

    /**
     * Dobijanje podataka o plugin-u za prikaz u dijalogu za ažuriranje
     */
    public function plugin_info($result, $action, $args)
    {
        // Proverava da li se upit odnosi na naš plugin
        if ('plugin_information' != $action || !isset($args->slug) || $args->slug != dirname($this->slug)) {
            return $result;
        }

        $remote_info = $this->get_remote_info();

        if (!$remote_info) {
            return $result;
        }

        $info = new stdClass();
        $info->name = $this->plugin_name;
        $info->slug = dirname($this->slug);
        $info->version = $this->get_latest_version();
        $info->tested = $this->tested;
        $info->requires = $this->requires;
        $info->author = '<a href="https://github.com/' . $this->username . '">' . $this->username . '</a>';
        $info->download_link = $this->get_download_url();
        $info->trunk = $this->get_download_url();
        $info->requires_php = '7.2';
        $info->last_updated = $remote_info['updated_at'];

        // Readme podaci
        $readme_data = $this->get_readme_data();
        if ($readme_data) {
            $info->sections = array(
                'description' => isset($readme_data['Description']) ? $readme_data['Description'] : '',
                'installation' => isset($readme_data['Installation']) ? $readme_data['Installation'] : '',
                'faq' => isset($readme_data['FAQ']) ? $readme_data['FAQ'] : '',
                'changelog' => isset($readme_data['Changelog']) ? $readme_data['Changelog'] : '',
                'screenshots' => isset($readme_data['Screenshots']) ? $readme_data['Screenshots'] : ''
            );

            // Banner
            if (isset($readme_data['Banner'])) {
                $info->banners = array(
                    'low' => $readme_data['Banner']['low'],
                    'high' => $readme_data['Banner']['high']
                );
            }
        }

        return $info;
    }

    /**
     * Dobijanje URL-a za preuzimanje ZIP fajla poslednje verzije
     */
    private function get_download_url($version = null)
    {
        $version = $version ?: $this->get_latest_version();

        if ($version) {
            // Link za preuzimanje ZIP arhive sa GitHub-a
            return "https://github.com/{$this->username}/{$this->repository}/archive/refs/tags/{$version}.zip";
        }

        return false;
    }

    /**
     * Dobijanje poslednje verzije plugin-a
     */
    private function get_latest_version()
    {
        $remote_info = $this->get_remote_info();

        if ($remote_info && isset($remote_info['tag_name'])) {
            // GitHub često u tagovima koristi "v" prefiks, pa ga uklanjamo
            $version = $remote_info['tag_name'];
            if (substr($version, 0, 1) === 'v') {
                $version = substr($remote_info['tag_name'], 1);
            }

            return $version;
        }

        return false;
    }

    /**
     * Dobijanje podataka sa GitHub-a o poslednjoj verziji
     */
    private function get_remote_info()
    {
        // Provera cache-a
        if ($this->cache_allowed) {
            $cached = get_transient($this->cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        // API URL za latest release
        $url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest";

        // Dodavanje auth tokena ako postoji
        $token = defined('GITHUB_ACCESS_TOKEN') ? GITHUB_ACCESS_TOKEN : '';
        $headers = array();

        if (!empty($token)) {
            $headers['Authorization'] = 'token ' . $token;
        }

        // Slanje zahteva
        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 10
        ));

        // Provera odgovora
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return false;
        }

        $remote_info = json_decode(wp_remote_retrieve_body($response), true);

        // Čuvanje u cache-u
        if ($this->cache_allowed) {
            set_transient($this->cache_key, $remote_info, 6 * HOUR_IN_SECONDS);
        }

        return $remote_info;
    }

    /**
     * Dobijanje podataka iz README.md fajla
     */
    private function get_readme_data()
    {
        if ($this->readme_data !== null) {
            return $this->readme_data;
        }

        // URL README fajla u repozitorijumu
        $url = "https://raw.githubusercontent.com/{$this->username}/{$this->repository}/master/README.md";

        $response = wp_remote_get($url);

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return false;
        }

        $content = wp_remote_retrieve_body($response);

        // Parsiranje README.md fajla (ovo je pojednostavljena implementacija)
        $sections = array(
            'Description' => '',
            'Installation' => '',
            'FAQ' => '',
            'Changelog' => '',
            'Screenshots' => '',
            'Banner' => array(
                'low' => '',
                'high' => ''
            )
        );

        // Izdvajanje sekcija (ovo bi trebalo unaprediti za pravi parser)
        $lines = explode("\n", $content);
        $current_section = 'Description';

        foreach ($lines as $line) {
            $line = trim($line);

            // Detektovanje headinga (## naslov)
            if (preg_match('/^#+\s+(.+)$/', $line, $matches)) {
                $heading = $matches[1];

                switch (strtolower($heading)) {
                    case 'installation':
                    case 'install':
                        $current_section = 'Installation';
                        break;
                    case 'faq':
                    case 'frequently asked questions':
                        $current_section = 'FAQ';
                        break;
                    case 'changelog':
                    case 'change log':
                        $current_section = 'Changelog';
                        break;
                    case 'screenshots':
                        $current_section = 'Screenshots';
                        break;
                    case 'banner':
                        $current_section = 'Banner';
                        break;
                    default:
                        // Ostaje u trenutnoj sekciji
                        break;
                }

                continue;
            }

            // Dodavanje linije u trenutnu sekciju
            if ($current_section === 'Banner') {
                // Parsiranje URL-ova za banner
                if (preg_match('/low[:\s]+(.+)/i', $line, $matches)) {
                    $sections['Banner']['low'] = trim($matches[1]);
                } elseif (preg_match('/high[:\s]+(.+)/i', $line, $matches)) {
                    $sections['Banner']['high'] = trim($matches[1]);
                }
            } else {
                $sections[$current_section] .= $line . "\n";
            }
        }

        // Konverzija Markdown-a u HTML (pojednostavljeno)
        foreach ($sections as $key => $value) {
            if ($key !== 'Banner' && is_string($value)) {
                // Osnovna konverzija Markdown u HTML (ovo bi trebalo unaprediti)
                $sections[$key] = $this->markdown_to_html($value);
            }
        }

        $this->readme_data = $sections;
        return $sections;
    }

    /**
     * Pojednostavljena konverzija Markdown-a u HTML
     */
    private function markdown_to_html($text)
    {
        // Headings
        $text = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $text);
        $text = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $text);

        // Bold i Italic
        $text = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text);

        // Lists
        $text = preg_replace('/^\s*\*\s+(.+)$/m', '<li>$1</li>', $text);
        $text = preg_replace('/^\s*\d+\.\s+(.+)$/m', '<li>$1</li>', $text);
        $text = preg_replace('/<\/li>\n<li>/s', "</li>\n<li>", $text);
        $text = preg_replace('/<li>(.+?)(?=(\n<\/li>|\n<li>|$))/s', '<ul><li>$1</li></ul>', $text);

        // Links
        $text = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $text);

        // Paragraphs
        $text = preg_replace('/(?<=\n)\n(?=\n)/', "\n<p>\n", $text);

        return $text;
    }

    /**
     * Provera licence (za premium plugin)
     */
    public function check_license()
    {
        // Ovde možeš implementirati proveru licence ako je plugin premium
    }

    /**
     * Poruka koja se prikazuje u dijalogu za ažuriranje
     */
    public function in_plugin_update_message($plugin_data, $response)
    {
        // Dobijanje detaljnijih informacija o ažuriranju
        $remote_info = $this->get_remote_info();

        if ($remote_info && isset($remote_info['body'])) {
            echo '<br /><span style="color:#7a80dd;">' . __('Ažuriranje donosi sledeće promene:', 'd-express-woo') . '</span><br />';

            // Označi detaljne promene u opisnoj fusnoti
            echo '<ul class="dexpress-update-details" style="list-style: disc; margin-left: 20px; color: #50575e;">';

            // Izdvoji bullet-e
            $changes = explode("\n", $remote_info['body']);
            foreach ($changes as $change) {
                $change = trim($change);
                if (!empty($change) && $change[0] === '-') {
                    echo '<li>' . esc_html(ltrim($change, '- ')) . '</li>';
                }
            }

            echo '</ul>';
        }
    }

    /**
     * Akcije nakon instalacije ažuriranja
     */
    public function post_install($response, $hook_extra, $result)
    {
        global $wp_filesystem;

        $plugin_folder = WP_PLUGIN_DIR . '/' . dirname($this->slug);
        $wp_filesystem->move($result['destination'], $plugin_folder);
        $result['destination'] = $plugin_folder;

        // Aktiviraj plugin
        activate_plugin($this->slug);

        return $result;
    }
}

/**
 * Inicijalizacija D Express Updatera
 */
function dexpress_initialize_updater()
{
    // Putanja do glavnog fajla plugin-a
    $plugin_file = DEXPRESS_WOO_PLUGIN_DIR . 'd-express-woocommerce-integration.php';

    // GitHub repo URL
    $repo_url = 'https://github.com/s7ilijavelimirov/d-express.git';

    // Kreiraj instancu updater-a
    $updater = new D_Express_Plugin_Updater($plugin_file, $repo_url);
}

// Inicijalizacija updatera nakon što je plugin učitan
add_action('plugins_loaded', 'dexpress_initialize_updater', 20);
