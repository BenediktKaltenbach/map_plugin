<?php
defined('ABSPATH') || exit;

class MMP_RestAPI {

    public static function register_routes(): void {

        $namespace = 'my-map/v1';

        // GET  /categories
        register_rest_route($namespace, '/categories', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_categories'],
            'permission_callback' => '__return_true',
        ]);

        // GET  /markers
        register_rest_route($namespace, '/markers', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_markers'],
            'permission_callback' => '__return_true',
        ]);

        // POST /suggest  (öffentlich, Vorschlag einreichen)
        register_rest_route($namespace, '/suggest', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'suggest_marker'],
            'permission_callback' => '__return_true',
        ]);

        // POST /markers  (nur Admins, direkt publizieren)
        register_rest_route($namespace, '/markers', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'create_marker'],
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);

        // DELETE /markers/{id}
        register_rest_route($namespace, '/markers/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [self::class, 'delete_marker'],
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);

        // POST /markers/{id}/update
        register_rest_route($namespace, '/markers/(?P<id>\d+)/update', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'update_marker'],
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);

        // POST /markers/{id}/approve
        register_rest_route($namespace, '/markers/(?P<id>\d+)/approve', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'approve_marker'],
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);
    }

    // ── GET /categories ───────────────────────────────────────────────

    public static function get_categories(): \WP_REST_Response {
        $posts = get_posts([
            'post_type'      => 'mmp_category',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $categories = array_map(fn($p) => [
            'id'    => $p->ID,
            'name'  => $p->post_title,
            'color' => get_post_meta($p->ID, 'color', true),
            'icon'  => get_post_meta($p->ID, 'icon', true) ?: '📍',
        ], $posts);

        return new \WP_REST_Response($categories, 200);
    }

    // ── GET /markers ──────────────────────────────────────────────────

    public static function get_markers(): \WP_REST_Response {
        $posts = get_posts([
            'post_type'      => 'mmp_marker',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
        ]);

        $markers = array_map(function ($p) {
            $cat_id = (int) get_post_meta($p->ID, 'category_id', true);
            $color  = $cat_id ? get_post_meta($cat_id, 'color', true) : '#3498DB';
            $icon   = $cat_id ? (get_post_meta($cat_id, 'icon', true) ?: '📍') : '📍';
            $cat    = $cat_id ? get_post($cat_id) : null;

            $images_raw = get_post_meta($p->ID, 'images', true);
            $images     = $images_raw ? json_decode($images_raw, true) : [];

            return [
                'id'           => $p->ID,
                'title'        => $p->post_title,
                'popup_html'   => $p->post_content,
                'lat'          => (float) get_post_meta($p->ID, 'lat', true),
                'lng'          => (float) get_post_meta($p->ID, 'lng', true),
                'color'        => $color,
                'icon'         => $icon,
                'category_id'  => $cat_id,
                'category_name'=> $cat ? $cat->post_title : '',
                'images'       => is_array($images) ? $images : [],
            ];
        }, $posts);

        return new \WP_REST_Response($markers, 200);
    }

    // ── POST /suggest ─────────────────────────────────────────────────

    public static function suggest_marker(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {

        // Rate-Limiting: max 5 Vorschläge pro IP pro Stunde
        $ip       = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        $rate_key = 'mmp_rate_' . md5($ip);
        $count    = (int) get_transient($rate_key);

        if ($count >= 5) {
            return new \WP_Error('rate_limit', 'Zu viele Anfragen. Bitte später erneut versuchen.', ['status' => 429]);
        }

        // Daten bereinigen
        $data = MMP_Sanitizer::marker_submission($request->get_json_params());
        if (is_wp_error($data)) {
            return $data;
        }

        // Erst gültige Vorschläge zählen, damit Tippfehler das Kontingent nicht aufbrauchen.
        set_transient($rate_key, $count + 1, HOUR_IN_SECONDS);

        // Als "pending" speichern → wartet auf Admin-Freigabe
        $post_id = wp_insert_post([
            'post_type'    => 'mmp_marker',
            'post_title'   => $data['title'],
            'post_content' => $data['content'],
            'post_status'  => 'pending',
        ]);

        if (is_wp_error($post_id)) {
            return new \WP_Error('save_failed', 'Speichern fehlgeschlagen.', ['status' => 500]);
        }

        foreach (['lat', 'lng', 'category_id', 'submitter_name', 'submitter_email'] as $key) {
            update_post_meta($post_id, $key, $data[$key] ?? '');
        }

        // Admin per E-Mail benachrichtigen
        wp_mail(
            get_option('admin_email'),
            'Neuer Kartenvorschlag: ' . $data['title'],
            "Ein neuer Marker wurde vorgeschlagen und wartet auf Freigabe:\n\n" .
            "Name: {$data['title']}\n" .
            "Von: {$data['submitter_name']} ({$data['submitter_email']})\n\n" .
            admin_url('admin.php?page=mmp-suggestions')
        );

        return new \WP_REST_Response(['success' => true, 'message' => 'Vorschlag eingereicht. Danke!'], 201);
    }

    // ── POST /markers (Admin direkt) ──────────────────────────────────

    public static function create_marker(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {

        $data = MMP_Sanitizer::marker_submission($request->get_json_params());
        if (is_wp_error($data)) {
            return $data;
        }

        $post_id = wp_insert_post([
            'post_type'    => 'mmp_marker',
            'post_title'   => $data['title'],
            'post_content' => $data['content'],
            'post_status'  => 'publish',
        ]);

        if (is_wp_error($post_id)) {
            return new \WP_Error('save_failed', 'Speichern fehlgeschlagen.', ['status' => 500]);
        }

        foreach (['lat', 'lng', 'category_id'] as $key) {
            update_post_meta($post_id, $key, $data[$key]);
        }

        // Bilder speichern (URLs aus WordPress Media Library)
        $images = array_map('esc_url_raw', (array) ($request->get_json_params()['images'] ?? []));
        $images = array_filter($images);
        update_post_meta($post_id, 'images', json_encode(array_values($images)));

        return new \WP_REST_Response(['success' => true, 'id' => $post_id], 201);
    }

    // ── POST /markers/{id}/update ─────────────────────────────────────

    public static function update_marker(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request->get_param('id');

        if (!get_post($id) || get_post_type($id) !== 'mmp_marker') {
            return new \WP_Error('not_found', 'Marker nicht gefunden.', ['status' => 404]);
        }

        $data = MMP_Sanitizer::marker_submission($request->get_json_params());
        if (is_wp_error($data)) return $data;

        wp_update_post([
            'ID'           => $id,
            'post_title'   => $data['title'],
            'post_content' => $data['content'],
        ]);

        foreach (['lat', 'lng', 'category_id'] as $key) {
            update_post_meta($id, $key, $data[$key]);
        }

        $images = array_map('esc_url_raw', (array) ($request->get_json_params()['images'] ?? []));
        update_post_meta($id, 'images', json_encode(array_values(array_filter($images))));

        return new \WP_REST_Response(['success' => true], 200);
    }

    // ── POST /markers/{id}/approve ────────────────────────────────────

    public static function approve_marker(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request->get_param('id');
        if (get_post_type($id) !== 'mmp_marker') {
            return new \WP_Error('not_found', 'Marker nicht gefunden.', ['status' => 404]);
        }

        $result = wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        if (is_wp_error($result)) {
            return new \WP_Error('approve_failed', 'Freigabe fehlgeschlagen.', ['status' => 500]);
        }

        return new \WP_REST_Response(['success' => true], 200);
    }

    // ── DELETE /markers/{id} ──────────────────────────────────────────

    public static function delete_marker(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request->get_param('id');
        if (get_post_type($id) !== 'mmp_marker') {
            return new \WP_Error('not_found', 'Marker nicht gefunden.', ['status' => 404]);
        }
        wp_delete_post($id, true);

        return new \WP_REST_Response(['success' => true], 200);
    }
}
