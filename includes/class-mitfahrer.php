<?php
defined('ABSPATH') || exit;

/**
 * „Mitfahrer finden“: Tretrollerfahrer tragen sich selbst auf der Karte ein und lassen sich über
 * ein Formular kontaktieren, ohne dass ihre E-Mail-Adresse sichtbar wird.
 *
 * - Lage nur unscharf: Mittelpunkt der Postleitzahl (OpenStreetMap) plus fester Zufallsversatz.
 * - Nur Eingetragene sehen Namen, Interessen und Text; alle anderen anonyme Punkte.
 * - Anmeldung per Cookie mit Sitzungs-Token (12 Monate); neue Geräte über einen Anmeldelink per Mail.
 * - Links aus Mails zeigen erst eine Seite mit Knopf (POST): Mail-Scanner rufen Links automatisch
 *   auf und würden einmalige Tokens sonst verbrauchen.
 *
 * Status: draft = noch nicht bestätigt, publish = sichtbar, private = pausiert.
 */
class MMP_Mitfahrer {

    public const TYP       = 'mmp_person';
    public const KATEGORIE = 'mitfahrer';
    public const LAENDER   = ['DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz'];
    public const TAGS_STANDARD = "Feierabendrunden\nTouren/Ausflüge\nTraining\nWettkampf\nGelände\nLangstrecke\nmit Kindern\nmit Hund\nEinsteiger willkommen";

    private const COOKIE = 'mmp_mitfahrer';
    private const CRON   = 'mmp_mitfahrer_taeglich';

    public static function init(): void {
        add_action('init', [self::class, 'registrieren']);
        add_action('rest_api_init', [self::class, 'routen']);
        add_action('template_redirect', [self::class, 'link_aufruf'], 1);
        add_action(self::CRON, [self::class, 'aufraeumen']);
        add_action('admin_init', [self::class, 'datenschutz_text']);
        add_filter('mmp_categories', [self::class, 'kategorie']);
    }

    public static function aktiv(): bool {
        return (bool) get_option('mmp_mitfahrer_aktiv', 0);
    }

    public static function farbe(): string {
        return sanitize_hex_color((string) get_option('mmp_mitfahrer_farbe', '')) ?: '#0284c7';
    }

    /** @return string[] */
    public static function tags(): array {
        $roh = (string) get_option('mmp_mitfahrer_tags', self::TAGS_STANDARD);
        return array_values(array_unique(array_filter(array_map('sanitize_text_field', explode("\n", $roh)))));
    }

    public static function mindestalter(): int {
        return (int) get_option('mmp_mitfahrer_alter', 18) === 16 ? 16 : 18;
    }

    public static function registrieren(): void {
        register_post_type(self::TYP, [
            'label'    => 'Mitfahrer',
            'public'   => false,
            'show_ui'  => false,
            'supports' => ['title'],
        ]);
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON);
        }
    }

    /** Eigene Kategorie in der Legende, standardmäßig ausgeblendet. */
    public static function kategorie(array $kategorien): array {
        if (!self::aktiv()) {
            return $kategorien;
        }
        $kategorien[] = [
            'id'             => self::KATEGORIE,
            'name'           => 'Mitfahrer finden',
            'color'          => self::farbe(),
            'icon'           => '🛴',
            'group'          => 'Mitfahrer',
            'virtual'        => true,
            'hidden_default' => true,
        ];
        return $kategorien;
    }

    // ── Hilfen ────────────────────────────────────────────────────────

    private static function meta_array(int $id, string $key): array {
        $wert = get_post_meta($id, $key, true);
        return is_array($wert) ? $wert : [];
    }

    /** Anzeigename ohne HTML-Entities (WordPress speichert „&“ als „&amp;“). */
    private static function name(int $id): string {
        return wp_specialchars_decode((string) get_post_field('post_title', $id), ENT_QUOTES);
    }

    private static function hash(string $token): string {
        return hash_hmac('sha256', $token, wp_salt('auth'));
    }

    private static function signatur(string $text): string {
        return substr(hash_hmac('sha256', $text, wp_salt('nonce') . '|mmp-mitfahrer'), 0, 32);
    }

    private static function ip(): string {
        return md5((string) ($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt('nonce'));
    }

    /** Ratenbegrenzung: noch unter dem Limit? */
    private static function limit_frei(string $key, int $max): bool {
        return (int) get_transient('mmp_mf_' . $key) < $max;
    }

    private static function limit_zaehlen(string $key, int $dauer): void {
        $key = 'mmp_mf_' . $key;
        set_transient($key, (int) get_transient($key) + 1, $dauer);
    }

    private static function antwort(array $daten, int $status = 200): \WP_REST_Response {
        $antwort = new \WP_REST_Response($daten, $status);
        $antwort->header('Cache-Control', 'no-store, private');
        return $antwort;
    }

    private static function fehler(string $text, int $status = 400): \WP_Error {
        return new \WP_Error('mmp_mitfahrer', $text, ['status' => $status]);
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

    /** Seite, von der aus sich jemand eingetragen hat (nur eigene Domain). */
    private static function seite(int $id): string {
        $seite = (string) get_post_meta($id, '_mmp_seite', true);
        $seite = $seite !== '' ? wp_validate_redirect($seite, '') : '';
        return $seite !== '' ? $seite : self::karten_url();
    }

    private static function finden(string $email): int {
        $ids = get_posts([
            'post_type'      => self::TYP,
            'post_status'    => ['draft', 'publish', 'private'],
            'meta_key'       => 'email', // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value'     => $email,  // phpcs:ignore WordPress.DB.SlowDBQuery
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);
        return $ids ? (int) $ids[0] : 0;
    }

    private static function eingetragen(int $id): bool {
        return get_post_type($id) === self::TYP && in_array(get_post_status($id), ['publish', 'private'], true);
    }

    // ── Anmeldung (Cookie) ────────────────────────────────────────────

    /** Angemeldeter Eintrag aus dem Cookie, sonst 0. */
    public static function ich(): int {
        static $ich = null;
        if ($ich !== null) {
            return $ich;
        }
        $ich  = 0;
        $wert = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^(\d+)\.([A-Za-z0-9]{40})$/', $wert, $m)) {
            return 0;
        }
        $id = (int) $m[1];
        if (!self::eingetragen($id) || !isset(self::meta_array($id, '_mmp_sitzungen')[self::hash($m[2])])) {
            return 0;
        }
        $ich = $id;
        if ((int) get_post_meta($id, '_mmp_zuletzt', true) < time() - DAY_IN_SECONDS) {
            update_post_meta($id, '_mmp_zuletzt', time());
            delete_post_meta($id, '_mmp_erinnert');
        }
        return $ich;
    }

    private static function cookie(string $wert, int $ablauf): void {
        setcookie(self::COOKIE, $wert, [
            'expires'  => $ablauf,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function anmelden(int $id): void {
        $token     = wp_generate_password(40, false);
        $sitzungen = self::meta_array($id, '_mmp_sitzungen');
        $sitzungen[self::hash($token)] = time();
        arsort($sitzungen);
        update_post_meta($id, '_mmp_sitzungen', array_slice($sitzungen, 0, 10, true)); // max. 10 Geräte
        update_post_meta($id, '_mmp_zuletzt', time());
        delete_post_meta($id, '_mmp_erinnert');
        self::cookie($id . '.' . $token, time() + YEAR_IN_SECONDS);
    }

    /** Dieses Gerät abmelden; mit $alle alle Geräte und offenen Anmeldelinks. */
    private static function abmelden(int $id, bool $alle): void {
        if ($alle) {
            delete_post_meta($id, '_mmp_sitzungen');
            delete_post_meta($id, '_mmp_links');
        } elseif (preg_match('/^\d+\.([A-Za-z0-9]{40})$/', (string) ($_COOKIE[self::COOKIE] ?? ''), $m)) {
            $sitzungen = self::meta_array($id, '_mmp_sitzungen');
            unset($sitzungen[self::hash($m[1])]);
            update_post_meta($id, '_mmp_sitzungen', $sitzungen);
        }
        self::cookie('', time() - YEAR_IN_SECONDS);
    }

    // ── Links in Mails ────────────────────────────────────────────────

    /** Einmaliger Link (Bestätigen, Anmelden). Gespeichert wird nur der Hash. */
    private static function einmal_link(int $id, string $zweck, int $dauer): string {
        $token = wp_generate_password(40, false);
        $links = array_filter(self::meta_array($id, '_mmp_links'), fn($l) => ($l['bis'] ?? 0) > time());
        $links[self::hash($token)] = ['zweck' => $zweck, 'bis' => time() + $dauer];
        update_post_meta($id, '_mmp_links', $links);
        return add_query_arg(['mmp_mitfahrer' => $id, 'zweck' => $zweck, 'token' => $token], home_url('/'));
    }

    private static function einmal_pruefen(int $id, string $zweck, string $token, bool $verbrauchen): bool {
        $links = self::meta_array($id, '_mmp_links');
        $hash  = self::hash($token);
        if (!isset($links[$hash]) || ($links[$hash]['zweck'] ?? '') !== $zweck || ($links[$hash]['bis'] ?? 0) < time()) {
            return false;
        }
        if ($verbrauchen) {
            unset($links[$hash]);
            update_post_meta($id, '_mmp_links', $links);
        }
        return true;
    }

    /** Dauerhafter, signierter Link (Verwalten, Melden, Alle abmelden, Noch dabei). */
    private static function signiert_link(int $id, string $zweck, int $von = 0): string {
        return add_query_arg([
            'mmp_mitfahrer' => $id,
            'zweck'         => $zweck,
            'von'           => $von,
            'sig'           => self::signatur($zweck . '|' . $id . '|' . $von),
        ], home_url('/'));
    }

    /**
     * Aufruf eines Links aus einer Mail. GET zeigt eine Seite mit Knopf, erst POST ändert etwas.
     */
    public static function link_aufruf(): void {
        if (!isset($_GET['mmp_mitfahrer'], $_GET['zweck'])) {
            return;
        }
        $id    = absint($_GET['mmp_mitfahrer']);
        $zweck = sanitize_key($_GET['zweck']);
        $post  = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $titel = 'Mitfahrer finden';
        $ungueltig = fn() => wp_die('Dieser Link ist ungültig oder abgelaufen. Auf der Tretrollerkarte kannst du unter „Mitfahrer finden“ einen neuen anfordern.', $titel, ['response' => 403]);

        if (get_post_type($id) !== self::TYP) {
            $ungueltig();
        }
        $name = esc_html(self::name($id));

        // Einmalige Links: Eintrag bestätigen, Gerät anmelden.
        if (in_array($zweck, ['bestaetigen', 'anmelden'], true)) {
            $token = (string) wp_unslash($_GET['token'] ?? '');
            if (!self::einmal_pruefen($id, $zweck, $token, $post)) {
                $ungueltig();
            }
            if (!$post) {
                $text = $zweck === 'bestaetigen'
                    ? '<h1>Eintrag bestätigen</h1><p>Hallo ' . $name . ', mit einem Klick erscheinst du auf der Tretrollerkarte und wirst auf diesem Gerät angemeldet.</p>'
                    : '<h1>Anmelden</h1><p>Hallo ' . $name . ', mit einem Klick bist du auf diesem Gerät bei „Mitfahrer finden“ angemeldet.</p>';
                $knopf = $zweck === 'bestaetigen' ? 'Eintrag bestätigen' : 'Anmelden';
                wp_die($text . '<form method="post"><p><button type="submit" class="button">' . $knopf . '</button></p></form>', $titel, ['response' => 200]);
            }
            if ($zweck === 'bestaetigen' && get_post_status($id) === 'draft') {
                wp_update_post(['ID' => $id, 'post_status' => 'publish']);
                update_post_meta($id, '_mmp_bestaetigt', time());
                self::info_an_verband($id);
            }
            self::anmelden($id);
            wp_safe_redirect(add_query_arg('mitfahrer', $zweck === 'bestaetigen' ? 'bestaetigt' : 'angemeldet', self::seite($id)) . '#mmp-public-map');
            exit;
        }

        // Signierte Links.
        $von = absint($_GET['von'] ?? 0);
        $sig = (string) wp_unslash($_GET['sig'] ?? '');
        if (!in_array($zweck, ['verwalten', 'melden', 'abmelden_alle', 'noch_dabei'], true) || !hash_equals(self::signatur($zweck . '|' . $id . '|' . $von), $sig)) {
            $ungueltig();
        }
        if ($zweck !== 'melden' && !self::eingetragen($id)) {
            wp_die('Diesen Eintrag gibt es nicht mehr.', $titel, ['response' => 404]);
        }

        if ($zweck === 'abmelden_alle') {
            if ($post) {
                self::abmelden($id, true);
                wp_die('<h1>Abgemeldet</h1><p>Alle Geräte sind abgemeldet, offene Anmeldelinks gelten nicht mehr.</p>', $titel, ['response' => 200]);
            }
            wp_die('<h1>Alle Geräte abmelden?</h1><p>Hallo ' . $name . ', damit werden alle Geräte abgemeldet, auf denen du bei „Mitfahrer finden“ angemeldet bist. Dein Eintrag bleibt auf der Karte.</p><form method="post"><p><button type="submit" class="button">Alle Geräte abmelden</button></p></form>', $titel, ['response' => 200]);
        }

        if ($zweck === 'noch_dabei') {
            if ($post) {
                update_post_meta($id, '_mmp_zuletzt', time());
                delete_post_meta($id, '_mmp_erinnert');
                wp_die('<h1>Danke!</h1><p>Dein Eintrag bleibt für weitere 12 Monate auf der Karte.</p>', $titel, ['response' => 200]);
            }
            wp_die('<h1>Weiter dabei bleiben?</h1><p>Hallo ' . $name . ', dein Eintrag bleibt dann für weitere 12 Monate auf der Karte.</p><form method="post"><p><button type="submit" class="button">Ja, ich bleibe dabei</button></p></form>', $titel, ['response' => 200]);
        }

        if ($zweck === 'verwalten') {
            if ($post) {
                $tun = sanitize_key($_POST['tun'] ?? '');
                if ($tun === 'loeschen') {
                    wp_delete_post($id, true);
                    wp_die('<h1>Gelöscht</h1><p>Dein Eintrag und alle Daten dazu sind gelöscht.</p>', $titel, ['response' => 200]);
                }
                $pausiert = $tun === 'pausieren';
                wp_update_post(['ID' => $id, 'post_status' => $pausiert ? 'private' : 'publish']);
                wp_die('<h1>' . ($pausiert ? 'Pausiert' : 'Wieder sichtbar') . '</h1><p>' . ($pausiert ? 'Dein Eintrag ist auf der Karte nicht mehr zu sehen, bis du ihn wieder einschaltest.' : 'Dein Eintrag ist wieder auf der Karte zu sehen.') . '</p>', $titel, ['response' => 200]);
            }
            $pausiert = get_post_status($id) === 'private';
            wp_die(
                '<h1>Dein Eintrag</h1><p>Hallo ' . $name . ', dein Eintrag ist ' . ($pausiert ? 'gerade <strong>pausiert</strong>' : 'auf der Karte <strong>sichtbar</strong>') . '.</p>'
                . '<form method="post"><p><button type="submit" name="tun" value="' . ($pausiert ? 'fortsetzen' : 'pausieren') . '" class="button">' . ($pausiert ? 'Wieder sichtbar machen' : 'Pausieren') . '</button></p></form>'
                . '<form method="post" onsubmit="return confirm(\'Eintrag wirklich endgültig löschen?\')"><p><button type="submit" name="tun" value="loeschen" class="button">Eintrag löschen</button></p></form>',
                $titel,
                ['response' => 200]
            );
        }

        // melden: $id = Absender der Nachricht, $von = Empfänger, der meldet.
        if ($post) {
            if (self::eingetragen($id) && add_post_meta($id, '_mmp_gemeldet_' . $von, time(), true)) {
                update_post_meta($id, '_mmp_gemeldet', (int) get_post_meta($id, '_mmp_gemeldet', true) + 1);
                wp_mail(
                    MMP_Review::empfaenger(),
                    '[DTRV] Mitfahrer finden: Eintrag gemeldet',
                    sprintf(
                        "„%s“ (PLZ %s) wurde nach einer Kontaktanfrage von „%s“ gemeldet.\n\nBitte prüfen und den Eintrag bei Bedarf pausieren oder löschen:\n%s",
                        self::name($id),
                        get_post_meta($id, 'plz', true),
                        self::name($von) ?: 'unbekannt',
                        admin_url('admin.php?page=mmp-mitfahrer')
                    )
                );
            }
            wp_die('<h1>Danke für die Meldung</h1><p>Der Verband sieht sich das an. Du musst nicht antworten.</p>', $titel, ['response' => 200]);
        }
        wp_die('<h1>Kontaktanfrage melden?</h1><p>Melde die Nachricht von „' . $name . '“, wenn sie belästigend, beleidigend oder Werbung war. Der Verband bekommt eine Info und kann den Eintrag sperren.</p><form method="post"><p><button type="submit" class="button">Melden</button></p></form>', $titel, ['response' => 200]);
    }

    // ── Mails ─────────────────────────────────────────────────────────

    private static function gruss(): string {
        return "\n\nSportliche Grüße\nDeutscher Tretroller Verband e.V.";
    }

    private static function mail_bestaetigen(int $id): bool {
        return wp_mail(
            (string) get_post_meta($id, 'email', true),
            'Bitte bestätige deinen Eintrag bei „Mitfahrer finden“',
            sprintf(
                "Hallo %s,\n\ndu hast dich auf der Tretrollerkarte bei „Mitfahrer finden“ eingetragen. Bitte bestätige das mit diesem Link (7 Tage gültig):\n\n%s\n\nErst danach erscheinst du auf der Karte. Warst du das nicht? Dann ignoriere diese Mail, der Eintrag wird nach 7 Tagen gelöscht.%s",
                self::name($id),
                self::einmal_link($id, 'bestaetigen', 7 * DAY_IN_SECONDS),
                self::gruss()
            )
        );
    }

    private static function mail_anmelden(int $id): bool {
        return wp_mail(
            (string) get_post_meta($id, 'email', true),
            'Dein Anmeldelink für „Mitfahrer finden“',
            sprintf(
                "Hallo %s,\n\nmit diesem Link meldest du dich auf deinem Gerät bei „Mitfahrer finden“ an (30 Minuten gültig, nur einmal nutzbar):\n\n%s\n\nHast du keinen Link angefordert? Dann ignoriere diese Mail. Wenn du befürchtest, dass jemand anderes Zugriff hat, melde hier alle Geräte ab:\n%s%s",
                self::name($id),
                self::einmal_link($id, 'anmelden', 30 * MINUTE_IN_SECONDS),
                self::signiert_link($id, 'abmelden_alle'),
                self::gruss()
            )
        );
    }

    private static function info_an_verband(int $id): void {
        wp_mail(
            MMP_Review::empfaenger(),
            '[DTRV] Mitfahrer finden: neuer Eintrag',
            sprintf(
                "Neuer Eintrag bei „Mitfahrer finden“ (bereits sichtbar):\n\nName:      %s\nPLZ:       %s %s\nInteressen: %s\nText:      %s\n\nPausieren oder löschen:\n%s",
                self::name($id),
                get_post_meta($id, 'plz', true),
                get_post_meta($id, 'land', true),
                implode(', ', self::meta_array($id, 'tags')),
                get_post_meta($id, 'text', true) ?: '–',
                admin_url('admin.php?page=mmp-mitfahrer')
            )
        );
    }

    // ── Lage aus der PLZ ──────────────────────────────────────────────

    /**
     * Mittelpunkt der Postleitzahl über Nominatim (OpenStreetMap). Regeln: eigener User-Agent,
     * max. 1 Anfrage pro Sekunde, Ergebnisse zwischenspeichern.
     *
     * @return array{0: float, 1: float}|\WP_Error
     */
    private static function plz_mitte(string $plz, string $land): array|\WP_Error {
        $cache = 'mmp_plz_' . strtolower($land) . '_' . $plz;
        $treffer = get_transient($cache);
        if (is_array($treffer)) {
            return $treffer;
        }
        $warten = (float) get_transient('mmp_nominatim_zuletzt') + 1.1 - microtime(true);
        if ($warten > 0) {
            usleep((int) (min($warten, 1.1) * 1000000));
        }
        set_transient('mmp_nominatim_zuletzt', (string) microtime(true), MINUTE_IN_SECONDS);

        $antwort = wp_remote_get(
            add_query_arg([
                'postalcode'   => $plz,
                'countrycodes' => strtolower($land),
                'format'       => 'jsonv2',
                'limit'        => 1,
            ], 'https://nominatim.openstreetmap.org/search'),
            [
                'timeout'    => 8,
                'user-agent' => 'DTRV-Tretrollerkarte/' . MMP_VERSION . ' (' . home_url('/') . ')',
                'headers'    => ['Accept-Language' => 'de'],
            ]
        );
        if (is_wp_error($antwort) || wp_remote_retrieve_response_code($antwort) !== 200) {
            return self::fehler('Die Ortssuche ist gerade nicht erreichbar. Bitte versuche es in ein paar Minuten noch einmal.', 503);
        }
        $daten = json_decode(wp_remote_retrieve_body($antwort), true);
        if (empty($daten[0]['lat']) || empty($daten[0]['lon'])) {
            return self::fehler('Diese Postleitzahl haben wir nicht gefunden. Bitte prüfe sie und das Land.');
        }
        $treffer = [(float) $daten[0]['lat'], (float) $daten[0]['lon']];
        set_transient($cache, $treffer, YEAR_IN_SECONDS);
        return $treffer;
    }

    /** Fester Zufallsversatz von 1–2 km, damit sich keine Adresse ableiten lässt. */
    private static function versetzt(float $lat, float $lng): array {
        $meter  = random_int(1000, 2000);
        $winkel = random_int(0, 359) * M_PI / 180;
        return [
            round($lat + $meter * cos($winkel) / 111320, 5),
            round($lng + $meter * sin($winkel) / (111320 * max(0.2, cos(deg2rad($lat)))), 5),
        ];
    }

    // ── Eingaben prüfen ───────────────────────────────────────────────

    /** @return array<string, mixed>|\WP_Error */
    private static function eingaben(array $d, bool $neu): array|\WP_Error {
        $name = trim(sanitize_text_field((string) ($d['name'] ?? '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) {
            return self::fehler('Bitte gib einen Anzeigenamen mit 2 bis 40 Zeichen an.');
        }
        $land = strtoupper(sanitize_key((string) ($d['land'] ?? 'DE')));
        if (!isset(self::LAENDER[$land])) {
            $land = 'DE';
        }
        $plz = preg_replace('/\D/', '', (string) ($d['plz'] ?? ''));
        if (!preg_match($land === 'DE' ? '/^\d{5}$/' : '/^\d{4}$/', $plz)) {
            return self::fehler($land === 'DE' ? 'Bitte gib eine fünfstellige Postleitzahl an.' : 'Bitte gib eine vierstellige Postleitzahl an.');
        }
        $tags = array_values(array_intersect(self::tags(), array_map('sanitize_text_field', (array) ($d['tags'] ?? []))));
        if (!$tags) {
            return self::fehler('Bitte wähle mindestens ein Interesse aus.');
        }
        $text = mb_substr(trim(sanitize_textarea_field((string) ($d['text'] ?? ''))), 0, 300);

        $daten = compact('name', 'land', 'plz', 'tags', 'text');
        if ($neu) {
            $email = strtolower(sanitize_email((string) ($d['email'] ?? '')));
            if (!is_email($email)) {
                return self::fehler('Bitte gib eine gültige E-Mail-Adresse an.');
            }
            if (empty($d['alter']) || empty($d['einwilligung'])) {
                return self::fehler('Bitte bestätige dein Alter und die Einwilligung zum Datenschutz.');
            }
            $daten['email'] = $email;
        }
        return $daten;
    }

    private static function speichern(int $id, array $daten): void {
        foreach (['plz', 'land', 'tags', 'text'] as $key) {
            update_post_meta($id, $key, $daten[$key]);
        }
    }

    /** Lage neu berechnen, wenn sich PLZ oder Land geändert haben. */
    private static function lage_setzen(int $id, string $plz, string $land): bool|\WP_Error {
        if (get_post_meta($id, 'lat', true) !== '' && get_post_meta($id, 'plz', true) === $plz && get_post_meta($id, 'land', true) === $land) {
            return true;
        }
        $mitte = self::plz_mitte($plz, $land);
        if (is_wp_error($mitte)) {
            return $mitte;
        }
        [$lat, $lng] = self::versetzt($mitte[0], $mitte[1]);
        update_post_meta($id, 'lat', $lat);
        update_post_meta($id, 'lng', $lng);
        return true;
    }

    // ── REST ──────────────────────────────────────────────────────────

    public static function routen(): void {
        $ns = 'my-map/v1';
        $offen = fn() => true;
        register_rest_route($ns, '/mitfahrer', ['methods' => 'GET', 'callback' => [self::class, 'liste'], 'permission_callback' => $offen]);
        register_rest_route($ns, '/mitfahrer/eintragen', ['methods' => 'POST', 'callback' => [self::class, 'eintragen'], 'permission_callback' => $offen]);
        register_rest_route($ns, '/mitfahrer/anmeldelink', ['methods' => 'POST', 'callback' => [self::class, 'anmeldelink'], 'permission_callback' => $offen]);
        register_rest_route($ns, '/mitfahrer/ich', ['methods' => 'POST', 'callback' => [self::class, 'ich_aendern'], 'permission_callback' => $offen]);
        register_rest_route($ns, '/mitfahrer/ich/loeschen', ['methods' => 'POST', 'callback' => [self::class, 'ich_loeschen'], 'permission_callback' => $offen]);
        register_rest_route($ns, '/mitfahrer/abmelden', ['methods' => 'POST', 'callback' => [self::class, 'abmelden_rest'], 'permission_callback' => $offen]);
        register_rest_route($ns, '/mitfahrer/(?P<id>\d+)/kontakt', ['methods' => 'POST', 'callback' => [self::class, 'kontakt'], 'permission_callback' => $offen]);
    }

    private static function eigene_daten(int $id): array {
        return [
            'id'       => $id,
            'name'     => self::name($id),
            'email'    => (string) get_post_meta($id, 'email', true),
            'plz'      => (string) get_post_meta($id, 'plz', true),
            'land'     => (string) get_post_meta($id, 'land', true) ?: 'DE',
            'tags'     => self::meta_array($id, 'tags'),
            'text'     => (string) get_post_meta($id, 'text', true),
            'pausiert' => get_post_status($id) === 'private',
        ];
    }

    /** Punkte für die Karte: anonym für Besucher, mit Details für Eingetragene. */
    public static function liste(): \WP_REST_Response|\WP_Error {
        if (!self::aktiv()) {
            return self::fehler('Nicht verfügbar.', 404);
        }
        $ich   = self::ich();
        $posts = get_posts([
            'post_type'      => self::TYP,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);
        $personen = array_map(function ($p) use ($ich) {
            $punkt = [
                'lat' => (float) get_post_meta($p->ID, 'lat', true),
                'lng' => (float) get_post_meta($p->ID, 'lng', true),
            ];
            if (!$ich) {
                return $punkt;
            }
            return $punkt + [
                'id'   => $p->ID,
                'name' => self::name($p->ID),
                'tags' => self::meta_array($p->ID, 'tags'),
                'text' => (string) get_post_meta($p->ID, 'text', true),
                'ich'  => $p->ID === $ich,
            ];
        }, $posts);
        if (!$ich) {
            shuffle($personen); // Reihenfolge verrät sonst, wer sich zuerst eingetragen hat
        }

        return self::antwort([
            'angemeldet'   => (bool) $ich,
            'ich'          => $ich ? self::eigene_daten($ich) : null,
            'personen'     => $personen,
            'tags'         => self::tags(),
            'laender'      => self::LAENDER,
            'mindestalter' => self::mindestalter(),
            'datenschutz'  => (string) get_privacy_policy_url(),
        ]);
    }

    public static function eintragen(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        if (!self::aktiv()) {
            return self::fehler('Nicht verfügbar.', 404);
        }
        $d       = (array) $request->get_json_params();
        $meldung = 'Fast geschafft: Wir haben dir eine E-Mail geschickt. Klicke auf den Link darin, dann bist du dabei. Schau auch im Spam-Ordner nach.';
        if (!empty($d['website'])) {
            return self::antwort(['ok' => true, 'meldung' => $meldung]); // Honeypot: Bots bekommen dieselbe Antwort
        }
        $daten = self::eingaben($d, true);
        if (is_wp_error($daten)) {
            return $daten;
        }
        if (!self::limit_frei('ein_' . self::ip(), 5) || !self::limit_frei('mail_' . md5($daten['email']), 3)) {
            return self::fehler('Zu viele Versuche. Bitte probiere es in einer Stunde noch einmal.', 429);
        }

        $id = self::finden($daten['email']);
        if ($id && self::eingetragen($id)) {
            // Schon dabei: Anmeldelink statt neuem Eintrag. Gleiche Antwort, damit sich nicht
            // herausfinden lässt, welche Adressen eingetragen sind.
            self::limit_zaehlen('ein_' . self::ip(), HOUR_IN_SECONDS);
            self::limit_zaehlen('mail_' . md5($daten['email']), HOUR_IN_SECONDS);
            self::mail_anmelden($id);
            return self::antwort(['ok' => true, 'meldung' => $meldung]);
        }

        if (!$id) {
            $id = wp_insert_post([
                'post_type'   => self::TYP,
                'post_title'  => $daten['name'],
                'post_status' => 'draft',
            ]);
            if (!$id || is_wp_error($id)) {
                return self::fehler('Speichern fehlgeschlagen. Bitte versuche es noch einmal.', 500);
            }
            update_post_meta($id, 'email', $daten['email']);
        } else {
            wp_update_post(['ID' => $id, 'post_title' => $daten['name']]);
        }
        $lage = self::lage_setzen($id, $daten['plz'], $daten['land']);
        if (is_wp_error($lage)) {
            return $lage;
        }
        self::speichern($id, $daten);
        update_post_meta($id, '_mmp_seite', esc_url_raw((string) ($d['seite'] ?? '')));
        update_post_meta($id, '_mmp_einwilligung', [
            'zeit'  => time(),
            'alter' => self::mindestalter(),
        ]);

        self::limit_zaehlen('ein_' . self::ip(), HOUR_IN_SECONDS);
        self::limit_zaehlen('mail_' . md5($daten['email']), HOUR_IN_SECONDS);
        if (!self::mail_bestaetigen($id)) {
            return self::fehler('Die Bestätigungsmail konnte nicht verschickt werden. Bitte versuche es später noch einmal.', 500);
        }
        return self::antwort(['ok' => true, 'meldung' => $meldung]);
    }

    /** Anmeldelink anfordern. Die Antwort ist immer gleich, ob die Adresse eingetragen ist oder nicht. */
    public static function anmeldelink(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        if (!self::aktiv()) {
            return self::fehler('Nicht verfügbar.', 404);
        }
        $d     = (array) $request->get_json_params();
        $email = strtolower(sanitize_email((string) ($d['email'] ?? '')));
        if (!is_email($email)) {
            return self::fehler('Bitte gib eine gültige E-Mail-Adresse an.');
        }
        if (!self::limit_frei('link_' . self::ip(), 10)) {
            return self::fehler('Zu viele Versuche. Bitte probiere es in einer Stunde noch einmal.', 429);
        }
        self::limit_zaehlen('link_' . self::ip(), HOUR_IN_SECONDS);

        $id = self::finden($email);
        if ($id && self::eingetragen($id) && self::limit_frei('mail_' . md5($email), 3)) {
            self::limit_zaehlen('mail_' . md5($email), HOUR_IN_SECONDS);
            if (!empty($d['seite'])) {
                update_post_meta($id, '_mmp_seite', esc_url_raw((string) $d['seite']));
            }
            self::mail_anmelden($id);
        }
        return self::antwort(['ok' => true, 'meldung' => 'Falls die Adresse eingetragen ist, haben wir dir einen Anmeldelink geschickt. Er gilt 30 Minuten.']);
    }

    public static function ich_aendern(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $ich = self::ich();
        if (!$ich) {
            return self::fehler('Du bist nicht angemeldet.', 401);
        }
        $d     = (array) $request->get_json_params();
        $daten = self::eingaben($d, false);
        if (is_wp_error($daten)) {
            return $daten;
        }
        $lage = self::lage_setzen($ich, $daten['plz'], $daten['land']);
        if (is_wp_error($lage)) {
            return $lage;
        }
        self::speichern($ich, $daten);
        wp_update_post([
            'ID'          => $ich,
            'post_title'  => $daten['name'],
            'post_status' => empty($d['pausiert']) ? 'publish' : 'private',
        ]);
        return self::antwort(['ok' => true, 'meldung' => 'Gespeichert.', 'ich' => self::eigene_daten($ich)]);
    }

    public static function ich_loeschen(): \WP_REST_Response|\WP_Error {
        $ich = self::ich();
        if (!$ich) {
            return self::fehler('Du bist nicht angemeldet.', 401);
        }
        self::cookie('', time() - YEAR_IN_SECONDS);
        wp_delete_post($ich, true);
        return self::antwort(['ok' => true, 'meldung' => 'Dein Eintrag und alle Daten dazu sind gelöscht.']);
    }

    public static function abmelden_rest(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $ich = self::ich();
        if (!$ich) {
            return self::fehler('Du bist nicht angemeldet.', 401);
        }
        $alle = !empty($request->get_json_params()['alle']);
        self::abmelden($ich, $alle);
        return self::antwort(['ok' => true, 'meldung' => $alle ? 'Alle Geräte sind abgemeldet.' : 'Du bist auf diesem Gerät abgemeldet.']);
    }

    /** Nachricht an einen Eintrag. Die Antwort-Adresse ist die des Absenders. */
    public static function kontakt(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $ich = self::ich();
        if (!$ich || get_post_status($ich) !== 'publish') {
            return self::fehler('Kontaktieren können nur Eingetragene, deren Eintrag sichtbar ist.', 401);
        }
        $an = (int) $request['id'];
        if ($an === $ich || get_post_type($an) !== self::TYP || get_post_status($an) !== 'publish') {
            return self::fehler('Diesen Eintrag gibt es nicht (mehr).', 404);
        }
        $nachricht = trim(sanitize_textarea_field((string) ($request->get_json_params()['nachricht'] ?? '')));
        if (mb_strlen($nachricht) < 10 || mb_strlen($nachricht) > 1500) {
            return self::fehler('Bitte schreibe eine Nachricht mit 10 bis 1500 Zeichen.');
        }
        if (!self::limit_frei('kontakt_' . $ich . '_' . $an, 1)) {
            return self::fehler('Du hast dieser Person heute schon geschrieben. Warte auf eine Antwort oder versuche es morgen noch einmal.', 429);
        }
        if (!self::limit_frei('kontakt_' . $ich, 5)) {
            return self::fehler('Du hast heute schon 5 Nachrichten geschickt. Morgen geht es weiter.', 429);
        }

        $absender = self::name($ich);
        $tags     = self::meta_array($ich, 'tags');
        $text     = sprintf(
            "Hallo %s,\n\n%s%s möchte über „Mitfahrer finden“ auf der Tretrollerkarte Kontakt mit dir aufnehmen:\n\n----------\n%s\n----------\n\nWenn du antworten möchtest, antworte einfach auf diese E-Mail. Deine Antwort geht direkt an %s, dabei erfährt %s auch deine E-Mail-Adresse. Wenn du nicht antworten möchtest, musst du nichts tun.\n\nTipp: Trefft euch beim ersten Mal an einem öffentlichen Ort.\n\nNachricht unangenehm oder Werbung? Hier melden:\n%s\n\nDeinen Eintrag pausieren oder löschen:\n%s%s",
            self::name($an),
            $absender,
            $tags ? ' (' . implode(', ', $tags) . ')' : '',
            $nachricht,
            $absender,
            $absender,
            self::signiert_link($ich, 'melden', $an),
            self::signiert_link($an, 'verwalten'),
            self::gruss()
        );
        $antwort_an = 'Reply-To: ' . str_replace(["\r", "\n", '<', '>', '"', ','], '', $absender) . ' <' . get_post_meta($ich, 'email', true) . '>';
        if (!wp_mail((string) get_post_meta($an, 'email', true), 'Nachricht von ' . $absender . ' (Mitfahrer finden)', $text, [$antwort_an])) {
            return self::fehler('Die Nachricht konnte nicht verschickt werden. Bitte versuche es später noch einmal.', 500);
        }
        self::limit_zaehlen('kontakt_' . $ich . '_' . $an, DAY_IN_SECONDS);
        self::limit_zaehlen('kontakt_' . $ich, DAY_IN_SECONDS);
        update_post_meta($ich, '_mmp_kontakte', (int) get_post_meta($ich, '_mmp_kontakte', true) + 1);

        $empfaenger = self::name($an);
        return self::antwort(['ok' => true, 'meldung' => 'Deine Nachricht an ' . $empfaenger . ' ist unterwegs. Wenn ' . $empfaenger . ' antwortet, bekommst du die Antwort per E-Mail.']);
    }

    // ── Täglich: unbestätigte löschen, nach 12 Monaten erinnern ──────

    public static function aufraeumen(): void {
        // Unbestätigte Einträge nach 7 Tagen löschen.
        foreach (get_posts(['post_type' => self::TYP, 'post_status' => 'draft', 'posts_per_page' => 200, 'fields' => 'ids', 'date_query' => [['before' => '7 days ago']]]) as $id) {
            wp_delete_post($id, true);
        }

        $ids = get_posts(['post_type' => self::TYP, 'post_status' => ['publish', 'private'], 'posts_per_page' => -1, 'fields' => 'ids']);
        foreach ($ids as $id) {
            $zuletzt  = (int) get_post_meta($id, '_mmp_zuletzt', true);
            $erinnert = (int) get_post_meta($id, '_mmp_erinnert', true);
            if ($erinnert && $erinnert < time() - 28 * DAY_IN_SECONDS) {
                wp_delete_post($id, true);
                continue;
            }
            if (!$erinnert && $zuletzt && $zuletzt < time() - YEAR_IN_SECONDS) {
                $ok = wp_mail(
                    (string) get_post_meta($id, 'email', true),
                    'Bist du noch bei „Mitfahrer finden“ dabei?',
                    sprintf(
                        "Hallo %s,\n\ndu bist seit einem Jahr bei „Mitfahrer finden“ auf der Tretrollerkarte eingetragen. Damit die Karte aktuell bleibt, fragen wir einmal im Jahr nach.\n\nWeiter dabei bleiben:\n%s\n\nOhne Klick löschen wir deinen Eintrag in 4 Wochen automatisch. Sofort pausieren oder löschen:\n%s%s",
                        self::name($id),
                        self::signiert_link($id, 'noch_dabei'),
                        self::signiert_link($id, 'verwalten'),
                        self::gruss()
                    )
                );
                if ($ok) {
                    update_post_meta($id, '_mmp_erinnert', time());
                }
            }
        }
    }

    // ── Datenschutz ───────────────────────────────────────────────────

    /** Textvorschlag unter Einstellungen › Datenschutz („Anleitung für Datenschutzerklärung“). */
    public static function datenschutz_text(): void {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content('Tretrollerkarte – Mitfahrer finden', wp_kses_post(
            '<p>Auf der Tretrollerkarte können sich Tretrollerfahrerinnen und -fahrer unter „Mitfahrer finden“ freiwillig eintragen, damit andere in der Nähe sie kontaktieren können. Dabei speichern wir den angegebenen Anzeigenamen, die Postleitzahl, die E-Mail-Adresse, die gewählten Interessen, einen optionalen kurzen Text, eine aus der Postleitzahl berechnete, absichtlich um 1–2 km verschobene Position sowie Zeitpunkte von Eintrag, Bestätigung und letzter Nutzung. Rechtsgrundlage ist die Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), die beim Eintragen erteilt und jederzeit durch Löschen des Eintrags widerrufen werden kann.</p>'
            . '<p>Anzeigename, Interessen und Text sind für alle sichtbar, die sich ebenfalls eingetragen haben; Besucher ohne Eintrag sehen nur anonyme Punkte. Die E-Mail-Adresse wird nicht angezeigt. Kontaktanfragen leiten wir per E-Mail weiter; dabei erfährt die angeschriebene Person die E-Mail-Adresse der anfragenden Person, damit sie antworten kann.</p>'
            . '<p>Zur Umrechnung der Postleitzahl in eine Position senden wir die Postleitzahl einmalig an den Dienst Nominatim der OpenStreetMap Foundation. Zur Anmeldung setzen wir ein technisch notwendiges Cookie („mmp_mitfahrer“, 12 Monate). Unbestätigte Einträge löschen wir nach 7 Tagen. Einträge, die 12 Monate nicht genutzt wurden, löschen wir 4 Wochen nach einer Erinnerungsmail.</p>'
        ));
    }

    // ── Backend ───────────────────────────────────────────────────────

    public static function admin_seite(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (isset($_POST['mmp_mf_einstellungen'])) {
            check_admin_referer('mmp_mitfahrer');
            update_option('mmp_mitfahrer_aktiv', isset($_POST['aktiv']) ? 1 : 0);
            update_option('mmp_mitfahrer_farbe', sanitize_hex_color(wp_unslash($_POST['farbe'] ?? '')) ?: '#0284c7', false);
            update_option('mmp_mitfahrer_tags', sanitize_textarea_field(wp_unslash($_POST['tags'] ?? '')) ?: self::TAGS_STANDARD, false);
            update_option('mmp_mitfahrer_alter', (int) ($_POST['alter'] ?? 18) === 16 ? 16 : 18, false);
            echo '<div class="notice notice-success"><p>Einstellungen gespeichert.</p></div>';
        }
        if (isset($_POST['mmp_mf_tun'], $_POST['id'])) {
            check_admin_referer('mmp_mitfahrer');
            $id  = absint($_POST['id']);
            $tun = sanitize_key($_POST['mmp_mf_tun']);
            if (get_post_type($id) === self::TYP) {
                if ($tun === 'loeschen') {
                    wp_delete_post($id, true);
                } elseif (in_array($tun, ['pausieren', 'fortsetzen'], true) && self::eingetragen($id)) {
                    wp_update_post(['ID' => $id, 'post_status' => $tun === 'pausieren' ? 'private' : 'publish']);
                }
                echo '<div class="notice notice-success"><p>Erledigt.</p></div>';
            }
        }

        $eintraege = get_posts(['post_type' => self::TYP, 'post_status' => ['draft', 'publish', 'private'], 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC']);
        $status    = ['draft' => 'unbestätigt', 'publish' => 'sichtbar', 'private' => 'pausiert'];
        $datum     = fn($t) => $t ? wp_date('j.n.Y', (int) $t) : '–';
        ?>
        <div class="mmp-wrap">
            <div class="mmp-header"><h1>🛴 Mitfahrer finden</h1></div>

            <div class="mmp-card">
                <h2>Einträge (<?= count($eintraege) ?>)</h2>
                <?php if (!$eintraege): ?>
                    <p class="mmp-empty-small">Noch niemand eingetragen.</p>
                <?php else: ?>
                <table class="mmp-table">
                    <thead><tr><th>Name</th><th>PLZ</th><th>E-Mail</th><th>Interessen</th><th>Status</th><th>Bestätigt</th><th>Zuletzt aktiv</th><th>Kontakte</th><th>Gemeldet</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($eintraege as $p):
                        $gemeldet = (int) get_post_meta($p->ID, '_mmp_gemeldet', true);
                    ?>
                    <tr>
                        <td><strong><?= esc_html(self::name($p->ID)) ?></strong><?php $t = (string) get_post_meta($p->ID, 'text', true); if ($t !== ''): ?><br><small><?= esc_html($t) ?></small><?php endif; ?></td>
                        <td><?= esc_html(get_post_meta($p->ID, 'plz', true) . ' ' . get_post_meta($p->ID, 'land', true)) ?></td>
                        <td><?= esc_html((string) get_post_meta($p->ID, 'email', true)) ?></td>
                        <td><?= esc_html(implode(', ', self::meta_array($p->ID, 'tags'))) ?></td>
                        <td><?= esc_html($status[$p->post_status] ?? $p->post_status) ?></td>
                        <td><?= esc_html($datum(get_post_meta($p->ID, '_mmp_bestaetigt', true))) ?></td>
                        <td><?= esc_html($datum(get_post_meta($p->ID, '_mmp_zuletzt', true))) ?></td>
                        <td><?= (int) get_post_meta($p->ID, '_mmp_kontakte', true) ?></td>
                        <td><?= $gemeldet ? '<strong style="color:#dc2626">' . $gemeldet . '×</strong>' : '–' ?></td>
                        <td style="white-space:nowrap">
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field('mmp_mitfahrer'); ?>
                                <input type="hidden" name="id" value="<?= (int) $p->ID ?>">
                                <?php if ($p->post_status === 'publish'): ?>
                                    <button type="submit" name="mmp_mf_tun" value="pausieren" class="mmp-btn mmp-btn-sm">Pausieren</button>
                                <?php elseif ($p->post_status === 'private'): ?>
                                    <button type="submit" name="mmp_mf_tun" value="fortsetzen" class="mmp-btn mmp-btn-sm">Sichtbar machen</button>
                                <?php endif; ?>
                                <button type="submit" name="mmp_mf_tun" value="loeschen" class="mmp-btn mmp-btn-danger mmp-btn-sm" onclick="return confirm('Eintrag endgültig löschen?')">Löschen</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <div class="mmp-card mmp-card-narrow">
                <h2>Einstellungen</h2>
                <form method="post" class="mmp-form">
                    <?php wp_nonce_field('mmp_mitfahrer'); ?>
                    <div class="mmp-field mmp-field-checkbox">
                        <label>
                            <input type="checkbox" name="aktiv" value="1" <?= checked(self::aktiv(), true, false) ?>>
                            „Mitfahrer finden“ auf der Karte anbieten
                        </label>
                        <p class="description">Vorher den Abschnitt zur Datenschutzerklärung ergänzen (Vorschlag unter Einstellungen › Datenschutz › „Anleitung“). Die Kategorie ist auf der Karte zunächst ausgeblendet; auf einer Seite einblenden mit <code>[my_map einblenden="mitfahrer-finden"]</code>.</p>
                    </div>
                    <div class="mmp-field">
                        <label>Farbe</label>
                        <input type="color" name="farbe" value="<?= esc_attr(self::farbe()) ?>">
                    </div>
                    <div class="mmp-field">
                        <label>Interessen (eine pro Zeile)</label>
                        <textarea name="tags" rows="9"><?= esc_textarea(implode("\n", self::tags())) ?></textarea>
                    </div>
                    <div class="mmp-field">
                        <label>Mindestalter</label>
                        <select name="alter">
                            <option value="18" <?= selected(self::mindestalter(), 18, false) ?>>18 Jahre</option>
                            <option value="16" <?= selected(self::mindestalter(), 16, false) ?>>16 Jahre</option>
                        </select>
                    </div>
                    <button type="submit" name="mmp_mf_einstellungen" class="mmp-btn mmp-btn-primary">Speichern</button>
                </form>
            </div>
        </div>
        <?php
    }
}
