<?php
defined('ABSPATH') || exit;

/**
 * Prüfung von Ortsvorschlägen per Mail.
 *
 * Jeder Vorschlag geht an die Adresse aus den Einstellungen, mit Links „Freigeben“ und
 * „Ablehnen“. Die Links funktionieren ohne Anmeldung (geheimer Token je Vorschlag) und
 * öffnen erst eine Bestätigungsseite mit Knopf – Mail-Scanner rufen Links automatisch auf.
 * Nach der Freigabe bekommt der Einsender eine Dankes-Mail (falls er eine Adresse angab);
 * danach wird seine Adresse gelöscht.
 */
class MMP_Review {

    public static function init(): void {
        add_action('template_redirect', [self::class, 'link_aufruf'], 1);
    }

    public static function empfaenger(): string {
        $adresse = (string) get_option('mmp_review_email', '');
        return $adresse !== '' ? $adresse : (string) get_option('admin_email');
    }

    /** Neuer Vorschlag: Token anlegen und Mail an den Vorstand schicken. */
    public static function neu(int $id): void {
        update_post_meta($id, '_mmp_token', wp_generate_password(32, false));

        $cat_id = (int) get_post_meta($id, 'category_id', true);
        $lat    = get_post_meta($id, 'lat', true);
        $lng    = get_post_meta($id, 'lng', true);
        $name   = get_post_meta($id, 'submitter_name', true);
        $email  = get_post_meta($id, 'submitter_email', true);

        $zeilen = [
            'Neuer Kartenvorschlag über ' . wp_parse_url(home_url(), PHP_URL_HOST),
            '',
            'Ort:        ' . get_post_field('post_title', $id),
            'Kategorie:  ' . ($cat_id ? get_post_field('post_title', $cat_id) : '–'),
            'Position:   ' . sprintf('https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=16/%1$s/%2$s', $lat, $lng),
            '',
            'Beschreibung:',
            trim(wp_strip_all_tags(str_replace(['<br>', '<br />', '</p>'], "\n", get_post_field('post_content', $id)))),
            '',
            'Eingereicht von: ' . ($name ?: '–') . ($email ? ' <' . $email . '>' : ''),
            '',
            'Freigeben: ' . self::link($id, 'freigeben'),
            'Ablehnen:  ' . self::link($id, 'ablehnen'),
            '',
            'Im WordPress-Admin: ' . admin_url('admin.php?page=mmp-suggestions'),
        ];

        $kopf = [];
        if (is_email($email)) {
            $kopf[] = 'Reply-To: ' . str_replace(["\r", "\n", '<', '>', '"'], '', $name ?: $email) . ' <' . $email . '>';
        }
        $ok = wp_mail(self::empfaenger(), '[DTRV] Neuer Kartenvorschlag: ' . get_post_field('post_title', $id), implode("\n", $zeilen), $kopf);
        update_post_meta($id, '_mmp_mail', $ok ? 'ok' : 'fehler');
    }

    public static function link(int $id, string $aktion): string {
        return add_query_arg([
            'mmp_vorschlag' => $id,
            'aktion'        => $aktion,
            'token'         => get_post_meta($id, '_mmp_token', true),
        ], home_url('/'));
    }

    /** Nach der Freigabe (per Mail-Link oder im Admin): Dankes-Mail, dann Adresse löschen. */
    public static function nach_freigabe(int $id): void {
        $email = get_post_meta($id, 'submitter_email', true);
        if (is_email($email) && add_post_meta($id, '_mmp_dank_gesendet', time(), true)) {
            $name = get_post_meta($id, 'submitter_name', true);
            $text = sprintf(
                "Hallo %s,\n\nvielen Dank für deinen Vorschlag „%s“. Er ist jetzt auf der Tretrollerkarte zu sehen:\n%s\n\nSportliche Grüße\nDeutscher Tretroller Verband e.V.",
                $name ?: 'zusammen',
                get_post_field('post_title', $id),
                self::karten_url()
            );
            if (!wp_mail($email, 'Dein Kartenvorschlag ist online', $text)) {
                delete_post_meta($id, '_mmp_dank_gesendet'); // erneut versuchbar
                return;
            }
        }
        delete_post_meta($id, 'submitter_email');
        delete_post_meta($id, '_mmp_token');
    }

    /** Seite mit der Karte (erste veröffentlichte Seite mit [my_map]), sonst Startseite. */
    private static function karten_url(): string {
        $seiten = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            's'              => '[my_map',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        return $seiten ? (string) get_permalink($seiten[0]) : home_url('/');
    }

    /**
     * Aufruf eines Links aus der Mail. GET zeigt nur eine Bestätigungsseite,
     * erst der Knopf (POST) ändert etwas.
     */
    public static function link_aufruf(): void {
        if (!isset($_GET['mmp_vorschlag'], $_GET['aktion'], $_GET['token'])) {
            return;
        }
        $id     = absint($_GET['mmp_vorschlag']);
        $aktion = sanitize_key($_GET['aktion']);
        $token  = (string) wp_unslash($_GET['token']);
        $soll   = (string) get_post_meta($id, '_mmp_token', true);

        if (get_post_type($id) !== 'mmp_marker' || $soll === '' || !hash_equals($soll, $token) || !in_array($aktion, ['freigeben', 'ablehnen'], true)) {
            wp_die('Dieser Link ist ungültig oder der Vorschlag wurde bereits bearbeitet.', 'Kartenvorschlag', ['response' => 403]);
        }

        $titel  = esc_html(get_post_field('post_title', $id));
        $status = get_post_status($id);

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            if ($aktion === 'freigeben') {
                wp_update_post(['ID' => $id, 'post_status' => 'publish']);
                self::nach_freigabe($id);
                $html = '<h1>Freigegeben</h1><p>„' . $titel . '“ ist jetzt auf der Karte zu sehen.</p>'
                    . '<p><a class="button" href="' . esc_url(self::karten_url()) . '">Zur Karte</a></p>';
            } else {
                // In den Papierkorb statt endgültig löschen: lässt sich im Admin wiederherstellen.
                delete_post_meta($id, 'submitter_email');
                delete_post_meta($id, '_mmp_token');
                wp_trash_post($id);
                $html = '<h1>Abgelehnt</h1><p>„' . $titel . '“ liegt im Papierkorb. Die E-Mail-Adresse des Einsenders wurde gelöscht.</p>';
            }
            wp_die($html, 'Kartenvorschlag', ['response' => 200]);
        }

        $cat_id = (int) get_post_meta($id, 'category_id', true);
        $lat    = get_post_meta($id, 'lat', true);
        $lng    = get_post_meta($id, 'lng', true);
        $frage  = $aktion === 'freigeben' ? 'Vorschlag freigeben?' : 'Vorschlag ablehnen?';
        $knopf  = $aktion === 'freigeben' ? 'Ja, freigeben' : 'Ja, ablehnen';
        $html   = '<h1>' . esc_html($frage) . '</h1>'
            . ($status === 'publish' ? '<p><em>Dieser Vorschlag ist bereits freigegeben.</em></p>' : '')
            . '<table class="widefat striped"><tbody>'
            . '<tr><th style="text-align:left">Ort</th><td>' . $titel . '</td></tr>'
            . '<tr><th style="text-align:left">Kategorie</th><td>' . esc_html($cat_id ? get_post_field('post_title', $cat_id) : '–') . '</td></tr>'
            . '<tr><th style="text-align:left">Position</th><td><a href="' . esc_url(sprintf('https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=16/%1$s/%2$s', $lat, $lng)) . '" target="_blank" rel="noopener">auf der Karte ansehen</a></td></tr>'
            . '<tr><th style="text-align:left;vertical-align:top">Beschreibung</th><td>' . wp_kses_post(get_post_field('post_content', $id)) . '</td></tr>'
            . '</tbody></table>'
            . '<form method="post"><p><button type="submit" class="button">' . esc_html($knopf) . '</button></p></form>';
        wp_die($html, 'Kartenvorschlag', ['response' => 200]);
    }
}
