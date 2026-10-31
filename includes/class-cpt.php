<?php
defined('ABSPATH') || exit;

class MMP_CPT {

    public static function register(): void {

        // ── Kategorie CPT ──────────────────────────────────────────────
        register_post_type('mmp_category', [
            'label'    => 'Kartenkategorien',
            'public'   => false,
            'show_ui'  => false,
            'supports' => ['title'],
        ]);

        register_post_meta('mmp_category', 'color', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'single'            => true,
            'show_in_rest'      => true,
        ]);

        register_post_meta('mmp_category', 'icon', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'single'            => true,
            'show_in_rest'      => true,
        ]);

        // ── Marker CPT ─────────────────────────────────────────────────
        register_post_type('mmp_marker', [
            'label'    => 'Kartenmarker',
            'public'   => false,
            'show_ui'  => false,
            'supports' => ['title', 'editor'],
        ]);

        foreach (['lat', 'lng', 'category_id', 'submitter_name', 'submitter_email'] as $key) {
            register_post_meta('mmp_marker', $key, [
                'type'         => 'string',
                'single'       => true,
                'show_in_rest' => true,
            ]);
        }

        // Bilder als JSON-Array von URLs
        register_post_meta('mmp_marker', 'images', [
            'type'         => 'string',
            'single'       => true,
            'show_in_rest' => true,
        ]);
    }
}
