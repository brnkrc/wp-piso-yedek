<?php
/**
 * Ana eklenti sınıfı.
 */
class Wp_Piso_Yedek_Plugin {
    /**
     * Eklenti sürümü.
     */
    const VERSION = '1.0.0';

    /**
     * Özel yetenek.
     */
    const CAPABILITY = 'manage_piso_yedek';

    /**
     * Eklenti seçenek anahtarları.
     */
    const OPTION_MAX_BACKUPS = 'wp_piso_yedek_max_backups';
    const OPTION_SCHEDULE = 'wp_piso_yedek_schedule';
    const OPTION_LOGS = 'wp_piso_yedek_logs';

    /**
     * Yedek dizini.
     *
     * @var string
     */
    private $backup_dir;

    /**
     * Admin sayfası slug listesi.
     *
     * @var array<string>
     */
    private $page_slugs = [];

    /**
     * Kurucu.
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->backup_dir = trailingslashit($upload_dir['basedir']) . 'wp-piso-yedek/';
    }

    /**
     * Eklentiyi çalıştır.
     */
    public function run() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_post_wp_piso_yedek_download', [$this, 'handle_download']);
        add_action('admin_post_wp_piso_yedek_delete', [$this, 'handle_delete']);
        add_action('admin_post_wp_piso_yedek_restore', [$this, 'handle_restore']);
        add_action('plugins_loaded', [$this, 'load_textdomain']);
    }

    /**
     * Aktivasyon işlemleri.
     */
    public static function activate() {
        $instance = new self();
        $instance->ensure_backup_directory();
        $instance->add_capability();
        add_option(self::OPTION_MAX_BACKUPS, 10);
        add_option(self::OPTION_SCHEDULE, '');
        add_option(self::OPTION_LOGS, []);
    }

    /**
     * Deaktivasyon işlemleri.
     */
    public static function deactivate() {
        // Şimdilik özel bir işlem yok, ileride cron vb. temizlenebilir.
    }

    /**
     * Yeteneği yöneticilere ekle.
     */
    private function add_capability() {
        $role = get_role('administrator');
        if ($role && !$role->has_cap(self::CAPABILITY)) {
            $role->add_cap(self::CAPABILITY);
        }
    }

    /**
     * Dil dosyalarını yükle.
     */
    public function load_textdomain() {
        load_plugin_textdomain('wp-piso-yedek', false, dirname(plugin_basename(__FILE__)) . '/../languages');
    }

    /**
     * Admin menülerini kaydet.
     */
    public function register_menu() {
        $this->page_slugs[] = add_menu_page(
            __('Wp Piso Yedek', 'wp-piso-yedek'),
            __('Wp Piso Yedek', 'wp-piso-yedek'),
            self::CAPABILITY,
            'wp-piso-yedek',
            [$this, 'render_backups_page'],
            'dashicons-backup',
            26
        );

        $this->page_slugs[] = add_submenu_page(
            'wp-piso-yedek',
            __('Yedekler', 'wp-piso-yedek'),
            __('Yedekler', 'wp-piso-yedek'),
            self::CAPABILITY,
            'wp-piso-yedek',
            [$this, 'render_backups_page']
        );

        $this->page_slugs[] = add_submenu_page(
            'wp-piso-yedek',
            __('Yeni Yedek', 'wp-piso-yedek'),
            __('Yeni Yedek', 'wp-piso-yedek'),
            self::CAPABILITY,
            'wp-piso-yedek-new',
            [$this, 'render_new_backup_page']
        );

        $this->page_slugs[] = add_submenu_page(
            'wp-piso-yedek',
            __('Ayarlar', 'wp-piso-yedek'),
            __('Ayarlar', 'wp-piso-yedek'),
            self::CAPABILITY,
            'wp-piso-yedek-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Sadece gerekli sayfalarda varlıkları yükle.
     *
     * @param string $hook Hook adı.
     */
    public function enqueue_assets($hook) {
        if (!in_array($hook, $this->page_slugs, true)) {
            return;
        }

        wp_enqueue_style(
            'wp-piso-yedek-admin',
            plugin_dir_url(__FILE__) . '../assets/admin.css',
            [],
            self::VERSION
        );
    }

    /**
     * Eylem işlemleri (form gönderimleri).
     */
    public function handle_actions() {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        if (isset($_POST['wp_piso_yedek_action'])) {
            $action = sanitize_text_field(wp_unslash($_POST['wp_piso_yedek_action']));
            switch ($action) {
                case 'create_backup':
                    $this->handle_create_backup();
                    break;
                case 'save_settings':
                    $this->handle_save_settings();
                    break;
            }
        }
    }

    /**
     * Yeni yedek oluşturma işlemi.
     */
    private function handle_create_backup() {
        check_admin_referer('wp_piso_yedek_create_backup');

        $type = isset($_POST['backup_type']) ? sanitize_text_field(wp_unslash($_POST['backup_type'])) : 'full';
        if (!in_array($type, ['full', 'database', 'files'], true)) {
            $type = 'full';
        }

        $result = $this->create_backup($type);

        if ($result) {
            $this->add_log(sprintf(__('Yedek oluşturuldu: %s', 'wp-piso-yedek'), esc_html($result['file_name'])));
            wp_safe_redirect(add_query_arg('wp_piso_yedek_success', 1, menu_page_url('wp-piso-yedek', false)));
            exit;
        }

        wp_safe_redirect(add_query_arg('wp_piso_yedek_error', 1, menu_page_url('wp-piso-yedek', false)));
        exit;
    }

    /**
     * Ayarları kaydet.
     */
    private function handle_save_settings() {
        check_admin_referer('wp_piso_yedek_save_settings');

        $max_backups = isset($_POST['max_backups']) ? intval($_POST['max_backups']) : 10;
        $schedule = isset($_POST['backup_schedule']) ? sanitize_text_field(wp_unslash($_POST['backup_schedule'])) : '';

        update_option(self::OPTION_MAX_BACKUPS, max(1, $max_backups));
        update_option(self::OPTION_SCHEDULE, $schedule);

        $this->add_log(__('Ayarlar güncellendi', 'wp-piso-yedek'));

        wp_safe_redirect(add_query_arg('wp_piso_yedek_settings_saved', 1, menu_page_url('wp-piso-yedek-settings', false)));
        exit;
    }

    /**
     * Yedek dizinini oluştur ve koru.
     */
    private function ensure_backup_directory() {
        if (!wp_mkdir_p($this->backup_dir)) {
            return false;
        }

        $htaccess = $this->backup_dir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        $index = $this->backup_dir . 'index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php // Sessizce ayrılıyoruz.\n");
        }

        return is_writable($this->backup_dir);
    }

    /**
     * Yedek oluştur.
     *
     * @param string $type Yedek tipi.
     *
     * @return array{file_name:string,file_path:string}|false
     */
    private function create_backup($type = 'full') {
        if (!$this->ensure_backup_directory()) {
            return false;
        }

        $rand = wp_generate_password(12, false, false);
        $timestamp = time();
        $file_name = sprintf('%s-backup-%s-%s.zip', $type, $timestamp, $rand);
        $file_path = $this->backup_dir . sanitize_file_name($file_name);

        $zip = new ZipArchive();
        if ($zip->open($file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        if (in_array($type, ['full', 'database'], true)) {
            $db_file = $this->backup_dir . 'database-' . $timestamp . '.sql';
            $this->export_database($db_file);
            if (file_exists($db_file)) {
                $zip->addFile($db_file, 'database.sql');
            }
        }

        if (in_array($type, ['full', 'files'], true)) {
            $this->add_files_to_zip($zip);
        }

        $zip->close();

        if (isset($db_file) && file_exists($db_file)) {
            unlink($db_file);
        }

        $this->clean_old_backups();

        return [
            'file_name' => basename($file_path),
            'file_path' => $file_path,
        ];
    }

    /**
     * Veritabanını SQL olarak dışa aktar.
     *
     * @param string $target_file Hedef dosya yolu.
     */
    private function export_database($target_file) {
        global $wpdb;
        $tables = $wpdb->get_col('SHOW TABLES');
        $handle = fopen($target_file, 'w');

        if (!$handle) {
            return;
        }

        foreach ($tables as $table) {
            $table_name = esc_sql($table);
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table_name}`", ARRAY_N);
            if (!empty($create[1])) {
                fwrite($handle, "DROP TABLE IF EXISTS `{$table_name}`;\n");
                fwrite($handle, $create[1] . ";\n\n");
            }

            $offset = 0;
            $limit = 500;
            do {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table_name}` LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A);
                foreach ($rows as $row) {
                    $values = array_map(function ($value) use ($wpdb) {
                        if ($value === null) {
                            return 'NULL';
                        }
                        return "'" . esc_sql($value) . "'";
                    }, $row);

                    $columns = array_map(static function ($col) {
                        return "`{$col}`";
                    }, array_keys($row));

                    $sql = sprintf(
                        "INSERT INTO `%s` (%s) VALUES (%s);\n",
                        $table_name,
                        implode(', ', $columns),
                        implode(', ', $values)
                    );
                    fwrite($handle, $sql);
                }
                $offset += $limit;
            } while (!empty($rows));
            fwrite($handle, "\n\n");
        }

        fclose($handle);
    }

    /**
     * Dosyaları zip içine ekle.
     *
     * @param ZipArchive $zip Zip nesnesi.
     */
    private function add_files_to_zip(ZipArchive $zip) {
        $root = ABSPATH;
        $excludes = [
            'wp-content/cache',
            'wp-content/uploads/cache',
            'wp-content/wflogs',
            'wp-content/advanced-cache.php',
            'wp-content/uploads/wp-piso-yedek',
        ];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            $path = str_replace($root, '', $file->getPathname());
            $normalized = str_replace('\\', '/', $path);

            $skip = false;
            foreach ($excludes as $exclude) {
                if (strpos($normalized, $exclude) === 0) {
                    $skip = true;
                    break;
                }
            }

            if ($skip) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($normalized);
            } else {
                $zip->addFile($file->getPathname(), $normalized);
            }
        }
    }

    /**
     * Yedek listesini getir.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_backups() {
        if (!is_dir($this->backup_dir)) {
            return [];
        }

        $files = glob($this->backup_dir . '*.zip');
        $backups = [];

        if ($files) {
            foreach ($files as $file) {
                $backups[] = [
                    'name' => basename($file),
                    'path' => $file,
                    'size' => size_format(filesize($file), 2),
                    'date' => date_i18n(get_option('date_format') . ' H:i', filemtime($file)),
                    'type' => $this->parse_type_from_name(basename($file)),
                ];
            }
        }

        usort($backups, function ($a, $b) {
            return strcmp($b['name'], $a['name']);
        });

        return $backups;
    }

    /**
     * Dosya adından yedek tipini ayrıştır.
     *
     * @param string $file_name Dosya adı.
     *
     * @return string
     */
    private function parse_type_from_name($file_name) {
        if (strpos($file_name, 'database-backup') === 0) {
            return __('Veritabanı', 'wp-piso-yedek');
        }
        if (strpos($file_name, 'files-backup') === 0) {
            return __('Dosya', 'wp-piso-yedek');
        }
        return __('Tam', 'wp-piso-yedek');
    }

    /**
     * Eski yedekleri silerek maksimum sayıyı koru.
     */
    private function clean_old_backups() {
        $max = intval(get_option(self::OPTION_MAX_BACKUPS, 10));
        $backups = $this->get_backups();

        if (count($backups) <= $max) {
            return;
        }

        $to_delete = array_slice($backups, $max);
        foreach ($to_delete as $backup) {
            $this->delete_backup_file($backup['path']);
        }
    }

    /**
     * Yedek indirme bağlantısını oluştur.
     *
     * @param string $file_name Dosya adı.
     *
     * @return string
     */
    private function get_download_url($file_name) {
        $timestamp = time();
        $token = wp_hash($file_name . '|' . $timestamp);
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'wp_piso_yedek_download',
                    'file' => rawurlencode($file_name),
                    't' => $timestamp,
                    'token' => $token,
                ],
                admin_url('admin-post.php')
            ),
            'wp_piso_yedek_download'
        );
    }

    /**
     * Yedek indir.
     */
    public function handle_download() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Bu işlem için yetkiniz yok.', 'wp-piso-yedek'));
        }

        check_admin_referer('wp_piso_yedek_download');

        $file = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';
        $timestamp = isset($_GET['t']) ? intval($_GET['t']) : 0;
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

        if (!$file || !$timestamp || !$token) {
            wp_die(__('Geçersiz istek.', 'wp-piso-yedek'));
        }

        if ((time() - $timestamp) > HOUR_IN_SECONDS) {
            wp_die(__('Bağlantı süresi doldu.', 'wp-piso-yedek'));
        }

        $expected = wp_hash($file . '|' . $timestamp);
        if (!hash_equals($expected, $token)) {
            wp_die(__('Geçersiz token.', 'wp-piso-yedek'));
        }

        $path = $this->backup_dir . $file;
        if (!file_exists($path)) {
            wp_die(__('Dosya bulunamadı.', 'wp-piso-yedek'));
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /**
     * Yedek silme işlemi.
     */
    public function handle_delete() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Bu işlem için yetkiniz yok.', 'wp-piso-yedek'));
        }

        check_admin_referer('wp_piso_yedek_delete');

        $file = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';
        if ($file) {
            $path = $this->backup_dir . $file;
            $this->delete_backup_file($path);
            $this->add_log(sprintf(__('Yedek silindi: %s', 'wp-piso-yedek'), esc_html($file)));
        }

        wp_safe_redirect(remove_query_arg(['action', 'file', '_wpnonce'], wp_get_referer() ?: menu_page_url('wp-piso-yedek', false)));
        exit;
    }

    /**
     * Tek bir yedek dosyasını sil.
     *
     * @param string $path Dosya yolu.
     */
    private function delete_backup_file($path) {
        if (file_exists($path) && strpos($path, $this->backup_dir) === 0) {
            unlink($path);
        }
    }

    /**
     * Geri yükleme işlemi.
     */
    public function handle_restore() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Bu işlem için yetkiniz yok.', 'wp-piso-yedek'));
        }

        check_admin_referer('wp_piso_yedek_restore');

        $file = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';
        if (!$file) {
            wp_die(__('Geçersiz dosya.', 'wp-piso-yedek'));
        }

        $path = $this->backup_dir . $file;
        if (!file_exists($path)) {
            wp_die(__('Dosya bulunamadı.', 'wp-piso-yedek'));
        }

        $result = $this->restore_database_from_backup($path);
        if ($result) {
            $this->add_log(sprintf(__('Veritabanı geri yüklendi: %s', 'wp-piso-yedek'), esc_html($file)));
            wp_safe_redirect(add_query_arg('wp_piso_yedek_restored', 1, menu_page_url('wp-piso-yedek', false)));
            exit;
        }

        wp_safe_redirect(add_query_arg('wp_piso_yedek_restore_error', 1, menu_page_url('wp-piso-yedek', false)));
        exit;
    }

    /**
     * Zip içindeki database.sql dosyasını çalıştır.
     *
     * @param string $zip_path Zip yolu.
     */
    private function restore_database_from_backup($zip_path) {
        global $wpdb;
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return false;
        }

        $index = $zip->locateName('database.sql');
        if ($index === false) {
            $zip->close();
            return false;
        }

        $sql_content = $zip->getFromIndex($index);
        $zip->close();

        if (!$sql_content) {
            return false;
        }

        $queries = array_filter(array_map('trim', explode(';', $sql_content)));
        foreach ($queries as $query) {
            $wpdb->query($query);
        }

        return true;
    }

    /**
     * Log ekle.
     *
     * @param string $message Mesaj.
     */
    private function add_log($message) {
        $logs = get_option(self::OPTION_LOGS, []);
        $logs[] = date_i18n('Y-m-d H:i:s') . ' - ' . sanitize_text_field($message);
        $logs = array_slice($logs, -50);
        update_option(self::OPTION_LOGS, $logs);
    }

    /**
     * Logları al.
     *
     * @return array<int, string>
     */
    private function get_logs() {
        return (array) get_option(self::OPTION_LOGS, []);
    }

    /**
     * Yedekler sayfasını oluştur.
     */
    public function render_backups_page() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Bu sayfaya erişim yetkiniz yok.', 'wp-piso-yedek'));
        }

        $backups = $this->get_backups();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('Yedekler', 'wp-piso-yedek')); ?></h1>

            <?php if (isset($_GET['wp_piso_yedek_success'])) : ?>
                <div class="notice notice-success"><p><?php echo esc_html(__('Yedek başarıyla oluşturuldu.', 'wp-piso-yedek')); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['wp_piso_yedek_error'])) : ?>
                <div class="notice notice-error"><p><?php echo esc_html(__('Yedek oluşturulurken bir hata oluştu.', 'wp-piso-yedek')); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['wp_piso_yedek_restored'])) : ?>
                <div class="notice notice-success"><p><?php echo esc_html(__('Veritabanı geri yüklendi.', 'wp-piso-yedek')); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['wp_piso_yedek_restore_error'])) : ?>
                <div class="notice notice-error"><p><?php echo esc_html(__('Geri yükleme sırasında hata oluştu.', 'wp-piso-yedek')); ?></p></div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                <tr>
                    <th><?php echo esc_html(__('Dosya Adı', 'wp-piso-yedek')); ?></th>
                    <th><?php echo esc_html(__('Tarih', 'wp-piso-yedek')); ?></th>
                    <th><?php echo esc_html(__('Tür', 'wp-piso-yedek')); ?></th>
                    <th><?php echo esc_html(__('Boyut', 'wp-piso-yedek')); ?></th>
                    <th><?php echo esc_html(__('İşlemler', 'wp-piso-yedek')); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($backups)) : ?>
                    <tr><td colspan="5"><?php echo esc_html(__('Henüz yedek bulunmuyor.', 'wp-piso-yedek')); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($backups as $backup) : ?>
                        <tr>
                            <td><?php echo esc_html($backup['name']); ?></td>
                            <td><?php echo esc_html($backup['date']); ?></td>
                            <td><?php echo esc_html($backup['type']); ?></td>
                            <td><?php echo esc_html($backup['size']); ?></td>
                            <td>
                                <a class="button" href="<?php echo esc_url($this->get_download_url($backup['name'])); ?>"><?php echo esc_html(__('İndir', 'wp-piso-yedek')); ?></a>
                                <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg([
                                    'action' => 'wp_piso_yedek_delete',
                                    'file' => rawurlencode($backup['name']),
                                ], admin_url('admin-post.php')), 'wp_piso_yedek_delete')); ?>">
                                    <?php echo esc_html(__('Sil', 'wp-piso-yedek')); ?>
                                </a>
                                <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg([
                                    'action' => 'wp_piso_yedek_restore',
                                    'file' => rawurlencode($backup['name']),
                                ], admin_url('admin-post.php')), 'wp_piso_yedek_restore')); ?>" onclick="return confirm('<?php echo esc_js(__('Bu işlem geri alınamaz, lütfen devam etmeden önce emin olun.', 'wp-piso-yedek')); ?>');">
                                    <?php echo esc_html(__('Veritabanını Geri Yükle', 'wp-piso-yedek')); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h2><?php echo esc_html(__('Log Kayıtları', 'wp-piso-yedek')); ?></h2>
            <ul>
                <?php foreach ($this->get_logs() as $log) : ?>
                    <li><?php echo esc_html($log); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Yeni yedek sayfası.
     */
    public function render_new_backup_page() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Bu sayfaya erişim yetkiniz yok.', 'wp-piso-yedek'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('Yeni Yedek', 'wp-piso-yedek')); ?></h1>
            <form method="post">
                <?php wp_nonce_field('wp_piso_yedek_create_backup'); ?>
                <input type="hidden" name="wp_piso_yedek_action" value="create_backup" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="backup_type"><?php echo esc_html(__('Yedek Türü', 'wp-piso-yedek')); ?></label></th>
                        <td>
                            <select id="backup_type" name="backup_type">
                                <option value="full"><?php echo esc_html(__('Tam Yedek (Dosya + Veritabanı)', 'wp-piso-yedek')); ?></option>
                                <option value="database"><?php echo esc_html(__('Sadece Veritabanı', 'wp-piso-yedek')); ?></option>
                                <option value="files"><?php echo esc_html(__('Sadece Dosya', 'wp-piso-yedek')); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p class="description"><?php echo esc_html(__('Büyük siteler için işlem parçalı ilerler, PHP zaman aşımını önlemek üzere dosyalar akış olarak eklenir.', 'wp-piso-yedek')); ?></p>

                <p><button type="submit" class="button button-primary"><?php echo esc_html(__('Şimdi Yedek Al', 'wp-piso-yedek')); ?></button></p>
            </form>
        </div>
        <?php
    }

    /**
     * Ayarlar sayfası.
     */
    public function render_settings_page() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Bu sayfaya erişim yetkiniz yok.', 'wp-piso-yedek'));
        }

        $max = intval(get_option(self::OPTION_MAX_BACKUPS, 10));
        $schedule = get_option(self::OPTION_SCHEDULE, '');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('Ayarlar', 'wp-piso-yedek')); ?></h1>
            <form method="post">
                <?php wp_nonce_field('wp_piso_yedek_save_settings'); ?>
                <input type="hidden" name="wp_piso_yedek_action" value="save_settings" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="max_backups"><?php echo esc_html(__('Maksimum Yedek Sayısı', 'wp-piso-yedek')); ?></label></th>
                        <td><input name="max_backups" id="max_backups" type="number" min="1" value="<?php echo esc_attr($max); ?>" />
                            <p class="description"><?php echo esc_html(__('Bu sayı aşıldığında en eski yedek otomatik silinir.', 'wp-piso-yedek')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="backup_schedule"><?php echo esc_html(__('Otomatik Yedekleme Zamanı (isteğe bağlı)', 'wp-piso-yedek')); ?></label></th>
                        <td><input name="backup_schedule" id="backup_schedule" type="text" value="<?php echo esc_attr($schedule); ?>" />
                            <p class="description"><?php echo esc_html(__('Saat veya cron ifadesi gibi bir bilgi saklayabilirsiniz. Cron ayarlaması manuel yapılmalıdır.', 'wp-piso-yedek')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html(__('Yedek Dizini', 'wp-piso-yedek')); ?></th>
                        <td><code><?php echo esc_html($this->backup_dir); ?></code></td>
                    </tr>
                </table>

                <p><button type="submit" class="button button-primary"><?php echo esc_html(__('Ayarları Kaydet', 'wp-piso-yedek')); ?></button></p>
            </form>
        </div>
        <?php
    }
}
