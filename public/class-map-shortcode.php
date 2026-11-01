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

        // Assets nur auf Seiten laden, die den Shortcode haben.
        // Eigene Handles (mmp-…): Otter Blocks registriert bereits „leaflet“ (1.8.0, async);
        // mit demselben Handle würde WordPress dessen Datei laden statt unserer.
        wp_enqueue_style('mmp-leaflet',                 MMP_URL . 'assets/leaflet/leaflet.css',                [], '1.9.4');
        wp_enqueue_style('mmp-leaflet-cluster',         MMP_URL . 'assets/leaflet/MarkerCluster.css',         ['mmp-leaflet'], '1.5.3');
        wp_enqueue_style('mmp-leaflet-cluster-default', MMP_URL . 'assets/leaflet/MarkerCluster.Default.css', ['mmp-leaflet-cluster'], '1.5.3');

        wp_enqueue_script('mmp-leaflet',         MMP_URL . 'assets/leaflet/leaflet.js',               [], '1.9.4', true);
        wp_enqueue_script('mmp-leaflet-cluster', MMP_URL . 'assets/leaflet/leaflet.markercluster.js', ['mmp-leaflet'], '1.5.3', true);

        wp_enqueue_style('mmp-public', MMP_URL . 'public/css/map.css', ['mmp-leaflet'], MMP_VERSION);
        wp_enqueue_script('mmp-public', MMP_URL . 'public/js/map.js', ['mmp-leaflet', 'mmp-leaflet-cluster'], MMP_VERSION, true);

        // Kein REST-Nonce: Vorschläge sind öffentlich, und ein veralteter Nonce (iOS hält Tabs
        // tagelang offen) würde jede Anfrage mit 403 „Cookie check failed“ scheitern lassen.
        wp_localize_script('mmp-public', 'MMP_Public', [
            'restBase'        => rest_url('my-map/v1'),
            'centerLat'       => floatval($atts['lat']),
            'centerLng'       => floatval($atts['lng']),
            'defaultZoom'     => intval($atts['zoom']),
            'allowSuggestions'=> (bool) get_option('mmp_allow_suggestions', 1),
        ]);

        $height = intval($atts['height']);

        // Zweiter Weg neben „Ort vorschlagen“: Termin einreichen (Seite mit dem Termin-Formular).
        // Adresse aus den Einstellungen; andere Plugins (DTRV-Termine) liefern sie über den Filter.
        $termin_url = (string) apply_filters('mmp_termin_url', (string) get_option('mmp_termin_url', ''));
        $vorschlaege = (bool) get_option('mmp_allow_suggestions', 1);

        ob_start(); ?>
        <div class="mmp-public-wrap">
            <div class="mmp-map-wrapper">
                <div id="mmp-public-map" style="height: <?= $height ?>px;"></div>
            </div>

            <?php if ($vorschlaege || $termin_url !== ''): ?>
            <div class="mmp-suggest-toggle">
                <?php if ($termin_url !== ''): ?>
                <a class="mmp-suggest-btn mmp-termin-btn" href="<?= esc_url($termin_url) ?>">
                    📅 Termin einreichen
                </a>
                <?php endif; ?>
                <?php if ($vorschlaege): ?>
                <button class="mmp-suggest-btn" id="mmp-suggest-toggle">
                    📍 Festen Ort vorschlagen
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($vorschlaege): ?>

            <div class="mmp-suggest-form hidden" id="mmp-suggest-form">
                <h3>Festen Ort vorschlagen</h3>
                <p class="mmp-form-hint">Für Orte, die dauerhaft da sind – z. B. Strecken, Treffpunkte, Vereine. Einmalige Veranstaltungen bitte über „Termin einreichen“. Dein Vorschlag wird vor der Veröffentlichung geprüft.</p>

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
