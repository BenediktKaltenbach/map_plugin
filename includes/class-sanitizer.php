<?php
defined('ABSPATH') || exit;

class MMP_Sanitizer {

    /**
     * Bereinigt alle Eingaben eines Marker-Vorschlags.
     * Gibt ein sauberes Array zurück oder WP_Error bei ungültigen Daten.
     */
    public static function marker_submission(array $raw): array|\WP_Error {

        $data = [];

        // Titel (Pflichtfeld)
        $data['title'] = sanitize_text_field($raw['title'] ?? '');
        if (empty($data['title'])) {
            return new \WP_Error('missing_title', 'Name des Ortes fehlt.', ['status' => 400]);
        }
        if (mb_strlen($data['title']) > 100) {
            return new \WP_Error('title_too_long', 'Name darf max. 100 Zeichen haben.', ['status' => 400]);
        }

        // Popup-Text (Pflichtfeld, erlaubt sicheres HTML)
        $allowed_html = [
            'b'      => [],
            'strong' => [],
            'em'     => [],
            'i'      => [],
            'a'      => ['href' => [], 'title' => []],
            'br'     => [],
            'p'      => [],
        ];
        $data['content'] = wp_kses($raw['content'] ?? '', $allowed_html);
        if (empty($data['content'])) {
            return new \WP_Error('missing_content', 'Beschreibungstext fehlt.', ['status' => 400]);
        }

        // Koordinaten (Pflichtfelder)
        $lat = filter_var($raw['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($raw['lng'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($lat === false || $lng === false) {
            return new \WP_Error('invalid_coords', 'Ungültige Koordinaten.', ['status' => 400]);
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return new \WP_Error('coords_out_of_range', 'Koordinaten außerhalb des gültigen Bereichs.', ['status' => 400]);
        }

        $data['lat'] = (string) $lat;
        $data['lng'] = (string) $lng;

        // Kategorie (Pflichtfeld, muss existieren)
        $category_id = absint($raw['category_id'] ?? 0);
        if (!$category_id || get_post_type($category_id) !== 'mmp_category') {
            return new \WP_Error('invalid_category', 'Ungültige Kategorie.', ['status' => 400]);
        }
        $data['category_id'] = (string) $category_id;

        // Einsender (optional)
        $data['submitter_name']  = sanitize_text_field($raw['submitter_name'] ?? '');
        $data['submitter_email'] = sanitize_email($raw['submitter_email'] ?? '');

        // Honeypot-Feld: wenn gefüllt → Spam
        if (!empty($raw['website'])) {
            return new \WP_Error('spam', 'Spam erkannt.', ['status' => 400]);
        }

        return $data;
    }

    /**
     * Bereinigt Kategorie-Eingaben aus der Admin-UI.
     */
    public static function category_input(array $raw): array|\WP_Error {

        $name = sanitize_text_field($raw['name'] ?? '');
        if (empty($name)) {
            return new \WP_Error('missing_name', 'Kategoriename fehlt.');
        }

        $color = sanitize_hex_color($raw['color'] ?? '');
        if (empty($color)) {
            return new \WP_Error('missing_color', 'Farbe fehlt oder ungültig.');
        }

        $icon = sanitize_text_field($raw['icon'] ?? '📍');

        return compact('name', 'color', 'icon');
    }
}
