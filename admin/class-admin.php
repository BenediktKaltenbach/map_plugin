<?php
defined('ABSPATH') || exit;

class MMP_Admin {

    public static function add_menu(): void {

        // Anzahl ausstehender Vorschläge für Badge
        $pending = wp_count_posts('mmp_marker')->pending ?? 0;
        $badge   = $pending ? ' <span class="awaiting-mod count-' . $pending . '"><span class="pending-count">' . $pending . '</span></span>' : '';

        add_menu_page(
            'Kartenplugín',
            'Karten-Plugin' . $badge,
            'manage_options',
            'mmp-map',
            [self::class, 'render_map_page'],
            'dashicons-location-alt',
            30
        );

        add_submenu_page('mmp-map', 'Marker verwalten',  'Marker',                'manage_options', 'mmp-map',         [self::class, 'render_map_page']);
        add_submenu_page('mmp-map', 'Vorschläge',        'Vorschläge' . $badge,  'manage_options', 'mmp-suggestions', [self::class, 'render_suggestions_page']);
        add_submenu_page('mmp-map', 'Kategorien',        'Kategorien',            'manage_options', 'mmp-categories',  [self::class, 'render_categories_page']);
        add_submenu_page('mmp-map', 'Einstellungen',     'Einstellungen',         'manage_options', 'mmp-settings',    [self::class, 'render_settings_page']);
    }

    public static function enqueue_assets(string $hook): void {
        $page = sanitize_key($_GET['page'] ?? '');
        $plugin_pages = ['mmp-map', 'mmp-suggestions', 'mmp-categories', 'mmp-settings'];

        if (!in_array($page, $plugin_pages, true)) return;

        wp_enqueue_style('leaflet',   MMP_URL . 'assets/leaflet/leaflet.css', [], '1.9.4');
        wp_enqueue_script('leaflet',  MMP_URL . 'assets/leaflet/leaflet.js',  [], '1.9.4', true);
        wp_enqueue_style('mmp-admin', MMP_URL . 'admin/admin.css', [], MMP_VERSION);
        wp_enqueue_script('mmp-admin',MMP_URL . 'admin/admin.js',  ['leaflet', 'wp-api-fetch'], MMP_VERSION, true);

        // WordPress Media Library für Bildauswahl
        wp_enqueue_media();

        wp_localize_script('mmp-admin', 'MMP', [
            'restBase' => rest_url('my-map/v1'),
            'nonce'    => wp_create_nonce('wp_rest'),
            'adminUrl' => admin_url(),
        ]);
    }

    // ── Marker-Verwaltung ─────────────────────────────────────────────

    public static function render_map_page(): void { ?>
        <div class="mmp-wrap">
            <div class="mmp-header">
                <h1>🗺 Marker verwalten</h1>
                <button class="mmp-btn mmp-btn-primary" id="mmp-add-marker-btn">+ Neuer Marker</button>
            </div>

            <div class="mmp-layout">
                <div class="mmp-sidebar">
                    <div class="mmp-search-box">
                        <input type="text" id="mmp-search" placeholder="Marker suchen…">
                    </div>
                    <div id="mmp-marker-list" class="mmp-marker-list">
                        <div class="mmp-loading">Lade Marker…</div>
                    </div>
                </div>
                <div class="mmp-map-area">
                    <div id="mmp-admin-map"></div>
                </div>
            </div>

            <!-- Modal: Marker hinzufügen/bearbeiten -->
            <div id="mmp-modal" class="mmp-modal hidden">
                <div class="mmp-modal-content">
                    <div class="mmp-modal-header">
                        <h2 id="mmp-modal-title">Neuer Marker</h2>
                        <button class="mmp-modal-close" id="mmp-modal-close">✕</button>
                    </div>
                    <div class="mmp-modal-body">
                        <input type="hidden" id="mmp-m-id" value=""><!-- 0 = neu, >0 = bearbeiten -->
                        <div class="mmp-field">
                            <label>Name <span class="required">*</span></label>
                            <input type="text" id="mmp-m-title" maxlength="100">
                        </div>
                        <div class="mmp-field">
                            <label>Kategorie <span class="required">*</span></label>
                            <select id="mmp-m-category"></select>
                        </div>
                        <div class="mmp-field">
                            <label>Beschreibung <span class="required">*</span></label>
                            <div class="mmp-toolbar">
                                <button type="button" class="mmp-toolbar-btn" id="mmp-insert-link" title="Link einfügen">🔗 Link</button>
                                <button type="button" class="mmp-toolbar-btn" id="mmp-insert-bold" title="Fett">B</button>
                            </div>
                            <textarea id="mmp-m-content" rows="4" placeholder="Beschreibung des Ortes…"></textarea>
                        </div>
                        <div class="mmp-field">
                            <label>Bilder</label>
                            <div id="mmp-image-preview" class="mmp-image-preview"></div>
                            <button type="button" class="mmp-btn mmp-btn-secondary" id="mmp-image-pick">🖼 Bild aus Mediathek wählen</button>
                            <small>Bis zu 5 Bilder möglich</small>
                            <input type="hidden" id="mmp-m-images" value="[]">
                        </div>
                        <div class="mmp-coords-row">
                            <div class="mmp-field">
                                <label>Breitengrad (lat)</label>
                                <input type="number" id="mmp-m-lat" step="0.000001">
                            </div>
                            <div class="mmp-field">
                                <label>Längengrad (lng)</label>
                                <input type="number" id="mmp-m-lng" step="0.000001">
                            </div>
                        </div>
                        <p class="mmp-hint">💡 Klicke auf die Karte unten um Koordinaten zu setzen.</p>
                        <div id="mmp-picker-map"></div>
                    </div>
                    <div class="mmp-modal-footer">
                        <button class="mmp-btn mmp-btn-secondary" id="mmp-modal-cancel">Abbrechen</button>
                        <button class="mmp-btn mmp-btn-primary" id="mmp-modal-save">Speichern</button>
                    </div>
                </div>
            </div>
        </div>
    <?php }

    // ── Vorschläge ────────────────────────────────────────────────────

    public static function render_suggestions_page(): void {
        $suggestions = get_posts([
            'post_type'      => 'mmp_marker',
            'post_status'    => 'pending',
            'posts_per_page' => -1,
        ]); ?>
        <div class="mmp-wrap">
            <div class="mmp-header">
                <h1>📬 Vorschläge prüfen</h1>
                <span class="mmp-badge"><?= count($suggestions) ?> ausstehend</span>
            </div>

            <?php if (empty($suggestions)): ?>
                <div class="mmp-empty">✅ Keine ausstehenden Vorschläge</div>
            <?php else: ?>
                <div class="mmp-suggestions-grid">
                    <?php foreach ($suggestions as $s):
                        $cat_id   = (int) get_post_meta($s->ID, 'category_id', true);
                        $cat      = $cat_id ? get_post($cat_id) : null;
                        $color    = $cat_id ? get_post_meta($cat_id, 'color', true) : '#999';
                        $name     = get_post_meta($s->ID, 'submitter_name', true);
                        $email    = get_post_meta($s->ID, 'submitter_email', true);
                        $lat      = get_post_meta($s->ID, 'lat', true);
                        $lng      = get_post_meta($s->ID, 'lng', true);
                    ?>
                    <div class="mmp-suggestion-card" data-id="<?= $s->ID ?>">
                        <div class="mmp-card-header" style="border-left: 4px solid <?= esc_attr($color) ?>">
                            <strong><?= esc_html($s->post_title) ?></strong>
                            <span class="mmp-cat-badge" style="background:<?= esc_attr($color) ?>">
                                <?= esc_html($cat ? $cat->post_title : '–') ?>
                            </span>
                        </div>
                        <div class="mmp-card-body">
                            <div class="mmp-card-content"><?= wp_kses_post($s->post_content) ?></div>
                            <div class="mmp-card-meta">
                                <?php if ($name): ?><span>👤 <?= esc_html($name) ?></span><?php endif; ?>
                                <?php if ($email): ?><span>✉️ <?= esc_html($email) ?></span><?php endif; ?>
                                <?php if ($lat && $lng): ?><span>📍 <?= esc_html($lat) ?>, <?= esc_html($lng) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="mmp-card-actions">
                            <button class="mmp-btn mmp-btn-success mmp-approve" data-id="<?= $s->ID ?>">✓ Freigeben</button>
                            <button class="mmp-btn mmp-btn-danger mmp-reject"  data-id="<?= $s->ID ?>">✕ Ablehnen</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php }

    // ── Kategorien ────────────────────────────────────────────────────

    public static function render_categories_page(): void {
        // Speichern
        if (isset($_POST['mmp_action']) && $_POST['mmp_action'] === 'add_category') {
            check_admin_referer('mmp_categories_nonce');

            $data = MMP_Sanitizer::category_input($_POST);
            if (!is_wp_error($data)) {
                $post_id = wp_insert_post([
                    'post_type'   => 'mmp_category',
                    'post_title'  => $data['name'],
                    'post_status' => 'publish',
                ]);
                update_post_meta($post_id, 'color', $data['color']);
                update_post_meta($post_id, 'icon',  $data['icon']);
                echo '<div class="notice notice-success"><p>Kategorie gespeichert.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html($data->get_error_message()) . '</p></div>';
            }
        }

        if (isset($_POST['mmp_action']) && $_POST['mmp_action'] === 'delete_category') {
            check_admin_referer('mmp_categories_nonce');
            wp_delete_post((int) $_POST['cat_id'], true);
            echo '<div class="notice notice-success"><p>Kategorie gelöscht.</p></div>';
        }

        $categories = get_posts(['post_type' => 'mmp_category', 'posts_per_page' => -1, 'post_status' => 'publish']);
        ?>
        <div class="mmp-wrap">
            <div class="mmp-header">
                <h1>🎨 Kategorien</h1>
            </div>

            <div class="mmp-two-col">
                <!-- Liste -->
                <div class="mmp-card">
                    <h2>Vorhandene Kategorien</h2>
                    <?php if (empty($categories)): ?>
                        <p class="mmp-empty-small">Noch keine Kategorien angelegt.</p>
                    <?php else: ?>
                        <table class="mmp-table">
                            <thead><tr><th>Icon</th><th>Name</th><th>Farbe</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($categories as $cat):
                                $color = get_post_meta($cat->ID, 'color', true);
                                $icon  = get_post_meta($cat->ID, 'icon', true) ?: '📍';
                            ?>
                            <tr>
                                <td><?= esc_html($icon) ?></td>
                                <td><?= esc_html($cat->post_title) ?></td>
                                <td>
                                    <span class="mmp-color-chip" style="background:<?= esc_attr($color) ?>"></span>
                                    <code><?= esc_html($color) ?></code>
                                </td>
                                <td>
                                    <form method="post" style="display:inline">
                                        <?php wp_nonce_field('mmp_categories_nonce'); ?>
                                        <input type="hidden" name="mmp_action" value="delete_category">
                                        <input type="hidden" name="cat_id" value="<?= $cat->ID ?>">
                                        <button type="submit" class="mmp-btn mmp-btn-danger mmp-btn-sm"
                                                onclick="return confirm('Wirklich löschen?')">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Neu anlegen -->
                <div class="mmp-card">
                    <h2>Neue Kategorie</h2>
                    <form method="post" class="mmp-form">
                        <?php wp_nonce_field('mmp_categories_nonce'); ?>
                        <input type="hidden" name="mmp_action" value="add_category">

                        <div class="mmp-field">
                            <label>Name</label>
                            <input type="text" name="name" maxlength="50" required placeholder="z.B. Restaurant">
                        </div>
                        <div class="mmp-field">
                            <label>Farbe</label>
                            <div class="mmp-color-row">
                                <input type="color" name="color" value="#3498DB">
                                <div class="mmp-preset-colors">
                                    <?php foreach (['#E74C3C','#E67E22','#F1C40F','#2ECC71','#3498DB','#9B59B6','#1ABC9C','#34495E'] as $c): ?>
                                        <button type="button" class="mmp-preset-dot" style="background:<?= $c ?>" data-color="<?= $c ?>"></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="mmp-field">
                            <label>Icon (Emoji)</label>
                            <input type="text" name="icon" value="📍" maxlength="2" placeholder="📍">
                            <small>Ein einzelnes Emoji als Marker-Icon</small>
                        </div>

                        <button type="submit" class="mmp-btn mmp-btn-primary">Kategorie anlegen</button>
                    </form>
                </div>
            </div>
        </div>
    <?php }

    // ── Einstellungen ─────────────────────────────────────────────────

    public static function render_settings_page(): void {
        if (isset($_POST['mmp_save_settings'])) {
            check_admin_referer('mmp_settings_nonce');
            update_option('mmp_center_lat',  floatval($_POST['center_lat']));
            update_option('mmp_center_lng',  floatval($_POST['center_lng']));
            update_option('mmp_default_zoom', absint($_POST['default_zoom']));
            update_option('mmp_allow_suggestions', isset($_POST['allow_suggestions']) ? 1 : 0);
            update_option('mmp_map_height',  absint($_POST['map_height']));
            echo '<div class="notice notice-success"><p>Einstellungen gespeichert.</p></div>';
        }
        ?>
        <div class="mmp-wrap">
            <div class="mmp-header"><h1>⚙️ Einstellungen</h1></div>
            <div class="mmp-card mmp-card-narrow">
                <form method="post" class="mmp-form">
                    <?php wp_nonce_field('mmp_settings_nonce'); ?>

                    <h2>Karten-Standardansicht</h2>
                    <div class="mmp-coords-row">
                        <div class="mmp-field">
                            <label>Mittelpunkt Lat</label>
                            <input type="number" name="center_lat" step="0.000001"
                                   value="<?= esc_attr(get_option('mmp_center_lat', 51.163)) ?>">
                        </div>
                        <div class="mmp-field">
                            <label>Mittelpunkt Lng</label>
                            <input type="number" name="center_lng" step="0.000001"
                                   value="<?= esc_attr(get_option('mmp_center_lng', 10.447)) ?>">
                        </div>
                    </div>
                    <div class="mmp-field">
                        <label>Standard-Zoom (1–18)</label>
                        <input type="number" name="default_zoom" min="1" max="18"
                               value="<?= esc_attr(get_option('mmp_default_zoom', 6)) ?>">
                    </div>
                    <div class="mmp-field">
                        <label>Kartenhöhe (px)</label>
                        <input type="number" name="map_height" min="200" max="1200"
                               value="<?= esc_attr(get_option('mmp_map_height', 500)) ?>">
                    </div>

                    <h2>Nutzer-Vorschläge</h2>
                    <div class="mmp-field mmp-field-checkbox">
                        <label>
                            <input type="checkbox" name="allow_suggestions" value="1"
                                   <?= checked(1, get_option('mmp_allow_suggestions', 1), false) ?>>
                            Vorschläge von Besuchern erlauben
                        </label>
                    </div>

                    <button type="submit" name="mmp_save_settings" class="mmp-btn mmp-btn-primary">
                        Speichern
                    </button>
                </form>
            </div>
        </div>
    <?php }
}
