<?php
defined('ABSPATH') || exit;

class MMP_MapShortcode {

    public static function init(): void {
        add_shortcode('my_map', [self::class, 'render']);
    }

    public static function render(array $atts = []): string {

        $atts = shortcode_atts([
            'height' => get_option('mmp_map_height', 500),
            'zoom'   => get_option('mmp_default_zoom', 6),
            'lat'    => get_option('mmp_center_lat', 51.163),
            'lng'    => get_option('mmp_center_lng', 10.447),
        ], $atts, 'my_map');

        // Assets nur auf Seiten laden, die den Shortcode haben
        wp_enqueue_style('leaflet',     MMP_URL . 'assets/leaflet/leaflet.css', [], '1.9.4');
        wp_enqueue_style('leaflet-cluster',         MMP_URL . 'assets/leaflet/MarkerCluster.css',         [], '1.5.3');
        wp_enqueue_style('leaflet-cluster-default', MMP_URL . 'assets/leaflet/MarkerCluster.Default.css', [], '1.5.3');

        // Leaflet + MarkerCluster als ein Bundle — vermeidet Ladereihenfolge-Probleme
        wp_enqueue_script('leaflet', MMP_URL . 'assets/leaflet/leaflet-bundle.js', [], '1.9.4', true);

        wp_enqueue_style('mmp-public', MMP_URL . 'public/css/map.css', [], MMP_VERSION);
        wp_enqueue_script('mmp-public', MMP_URL . 'public/js/map.js', ['leaflet'], MMP_VERSION, true);

        wp_localize_script('mmp-public', 'MMP_Public', [
            'restBase'        => rest_url('my-map/v1'),
            'nonce'           => wp_create_nonce('wp_rest'),
            'centerLat'       => floatval($atts['lat']),
            'centerLng'       => floatval($atts['lng']),
            'defaultZoom'     => intval($atts['zoom']),
            'allowSuggestions'=> (bool) get_option('mmp_allow_suggestions', 1),
        ]);

        $height = intval($atts['height']);

        ob_start(); ?>
        <div class="mmp-public-wrap">
            <div class="mmp-map-wrapper">
                <div id="mmp-public-map" style="height: <?= $height ?>px;"></div>
            </div>

            <?php if (get_option('mmp_allow_suggestions', 1)): ?>
            <div class="mmp-suggest-toggle">
                <button class="mmp-suggest-btn" id="mmp-suggest-toggle">
                    📍 Ort vorschlagen
                </button>
            </div>

            <div class="mmp-suggest-form hidden" id="mmp-suggest-form">
                <h3>Neuen Ort vorschlagen</h3>
                <p class="mmp-form-hint">Dein Vorschlag wird vor der Veröffentlichung geprüft.</p>

                <!-- Honeypot-Feld (versteckt für Bots, sichtbar im HTML) -->
                <div class="mmp-hp-field" aria-hidden="true">
                    <label>Website</label>
                    <input type="text" name="website" id="mmp-hp" tabindex="-1" autocomplete="off">
                </div>

                <div class="mmp-pub-field">
                    <label>Name des Ortes <span class="mmp-req">*</span></label>
                    <input type="text" id="mmp-s-title" maxlength="100">
                </div>
                <div class="mmp-pub-field">
                    <label>Kategorie <span class="mmp-req">*</span></label>
                    <select id="mmp-s-category"></select>
                </div>
                <div class="mmp-pub-field">
                    <label>Beschreibung <span class="mmp-req">*</span></label>
                    <div class="mmp-pub-toolbar">
                        <button type="button" class="mmp-pub-toolbar-btn" id="mmp-s-insert-link">🔗 Link</button>
                        <button type="button" class="mmp-pub-toolbar-btn mmp-pub-toolbar-bold" id="mmp-s-insert-bold">B</button>
                    </div>
                    <textarea id="mmp-s-content" rows="3" placeholder="Was ist besonders an diesem Ort?"></textarea>
                </div>
                <div class="mmp-coords-hint">
                    <span id="mmp-s-coords-display">📍 Klicke auf die Karte um den Ort zu markieren</span>
                </div>
                <input type="hidden" id="mmp-s-lat">
                <input type="hidden" id="mmp-s-lng">

                <div class="mmp-pub-field">
                    <label>Dein Name (optional)</label>
                    <input type="text" id="mmp-s-name" maxlength="80">
                </div>
                <div class="mmp-pub-field">
                    <label>E-Mail (optional, wird nicht veröffentlicht)</label>
                    <input type="email" id="mmp-s-email">
                </div>

                <div class="mmp-form-actions">
                    <button class="mmp-pub-btn mmp-pub-btn-secondary" id="mmp-suggest-cancel">Abbrechen</button>
                    <button class="mmp-pub-btn mmp-pub-btn-primary" id="mmp-suggest-submit">Vorschlag einreichen</button>
                </div>
                <div id="mmp-suggest-feedback" class="hidden"></div>
            </div>
            <?php endif; ?>
        </div><!-- .mmp-public-wrap -->
        <?php
        return ob_get_clean();
    }
}
