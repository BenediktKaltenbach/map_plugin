# My Map Plugin (Tretrollerkarte)

WordPress plugin for [dtrv.net](https://dtrv.net), the website of the Deutscher Tretroller Verband e.V. (German kick scooter association). It shows an interactive [Leaflet](https://leafletjs.com/) map with colour-coded marker categories, marker clustering and a public form where visitors can suggest new places.

The plugin's admin and front-end UI is in German, because the site is German.

## Installation

1. Download `my-map-plugin.zip` from [Releases](https://github.com/BenediktKaltenbach/map_plugin/releases). Do not use "Source code", it lacks `vendor/` (the update checker).
2. WordPress › Plugins › Add New › Upload Plugin. If an older copy is installed, choose **"Replace current with uploaded"**: markers, categories and settings are kept.
3. From then on, updates appear in the regular WordPress plugin list (via [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)) and install with one click.

## Usage

Put the shortcode on any page or post:

```
[my_map]
```

Optional parameters:

```
[my_map height="600" zoom="12" lat="48.775" lng="9.182"]
```

Which categories are visible when the map loads is set under **Kategorien › Beim Laden der Karte** (unticked = hidden at first; visitors can switch them on in the legend). A page can override this:

```
[my_map einblenden="termine"]                     show the "Termine" group here
[my_map ausblenden="alle" einblenden="wettkampf"] start with only "Wettkampf" switched on
[my_map nur="wettkampf"]                          show nothing but "Wettkampf"
```

Values are comma-separated and can be a category's short name (listed in the admin), its ID, a group name such as `termine`, or `alle`.

On phones the map is limited to 65% of the screen height, so visitors can still scroll past it. Mouse-wheel/trackpad zoom only starts after clicking into the map.

## Admin

After activation the WordPress menu has:

- **Marker** – manage markers on the map, add new ones (with images from the media library)
- **Vorschläge** – review visitor suggestions, approve or reject them
- **Kategorien** – categories with colours and icons, plus which categories are visible when the map loads
- **Mitfahrer finden** – entries of people who want to be found by other riders, plus settings (on/off, colour, interests)
- **Einstellungen** – default zoom, map centre, map height, suggestions on/off

## Mitfahrer finden (find riders nearby)

Off by default; switch it on under **Mitfahrer finden › Einstellungen** once the privacy policy covers it (WordPress shows a suggested section under Settings › Privacy › Policy Guide).

- **Sign-up:** clicking "Mitfahrer finden" in the legend opens a dialog. People enter a display name, postcode (DE/AT/CH), email, interests and an optional short text, give their consent, and get a confirmation link (double opt-in). Only after confirming do they appear on the map.
- **Rough position only:** the postcode is turned into its centre via OpenStreetMap Nominatim (own user agent, max. one request per second, cached for a year), then shifted by a fixed random 1–2 km. No address is stored.
- **Visibility:** visitors without an entry see anonymous points and a count; people with an entry see names, interests, text and a "Kontaktieren" button. The category starts hidden; show it on a page with `[my_map einblenden="mitfahrer-finden"]`.
- **Contact without revealing addresses:** messages are mailed by the site with `Reply-To` set to the sender, so the recipient's address stays hidden until they reply. Limits: 5 messages per day per sender, 1 per recipient per day. Each mail has links to report the sender and to pause or delete one's own entry.
- **Login:** an HttpOnly, SameSite=Lax cookie (12 months) holds a session token; only its hash is stored. On another device, people request a one-time login link (valid 30 minutes). The response is always the same, so nobody can find out which addresses are registered. Links from mails open a page with a button first, so mail scanners cannot use them up.
- **Self-service:** "Mein Eintrag" lets people edit, pause, delete, log out on this device or on all devices.
- **Housekeeping (daily WP-Cron):** unconfirmed entries are deleted after 7 days; after 12 months without use people get a reminder, and the entry is deleted 4 weeks later unless they click "Ich bleibe dabei".
- New entries and reports are mailed to the review address.

## Security and robustness

- All input is sanitised server-side (`sanitize_text_field()`, `wp_kses()`, `sanitize_hex_color()` …). Popup text is never entity-decoded in the browser.
- Admin REST routes require `manage_options` and only act on this plugin's own post types.
- Visitor suggestions are stored as `pending` and stay invisible until approved.
- **Two ways to contribute:** below the map, "📍 Festen Ort vorschlagen" opens the place suggestion form (permanent places such as tracks, meeting points, clubs) and "📅 Termin einreichen" leads to the event suggestion form. The address is set under Einstellungen, or filled in automatically via the `mmp_termin_url` filter, which DTRV-Termine uses to point to the page with its form.
- **Review by mail:** every suggestion is mailed to the address under Einstellungen › "E-Mail für neue Vorschläge" (empty: the WordPress admin email). The mail has **Freigeben** / **Ablehnen** links that work without logging in (secret token per suggestion). They open a confirmation page with a button first, so email security scanners cannot trigger them. Rejected suggestions go to the trash. After approval (by mail or in the admin), the submitter gets a thank-you mail if they left an address, and the address is then deleted.
- Honeypot field against simple bots; at most 5 valid suggestions per IP per hour.
- The public suggestion endpoint does not use a REST nonce, so tabs kept open for days (common on iOS) do not fail with "Cookie check failed".
- Leaflet 1.9.4 and Leaflet.markercluster 1.5.3 are bundled and loaded under their own handles (`mmp-leaflet`, `mmp-leaflet-cluster`). This avoids picking up another plugin's Leaflet (e.g. Otter Blocks registers `leaflet` with version 1.8.0).
- The front-end script avoids JavaScript features older Safari versions cannot parse (CI fails on regex lookbehind).

## Extension point for other plugins

Other plugins can add categories and markers to the map without creating copies as `mmp_marker` posts. DTRV-Termine uses this to show upcoming events.

```php
add_filter( 'mmp_categories', function ( array $categories ) {
    $categories[] = array(
        'id'      => 'termine-wettkampf', // string IDs are fine
        'name'    => 'Wettkampf',
        'color'   => '#006666',
        'icon'    => '📅',
        'group'   => 'Termine',  // own heading in the legend
        'virtual' => true,       // not offered in the suggestion form
    );
    return $categories;
} );

add_filter( 'mmp_markers', function ( array $markers ) {
    $markers[] = array(
        'id'            => 'termin-123',
        'title'         => 'Deutsche Meisterschaft 2027',
        'popup_html'    => '…', // must already be escaped/safe HTML
        'lat'           => 50.7151,
        'lng'           => 7.1393,
        'color'         => '#006666',
        'icon'          => '📅',
        'type'          => 'event', // square marker in the category colour with a calendar symbol
        'category_id'   => 'termine-wettkampf',
        'category_name' => 'Wettkampf',
        'images'        => array(),
    );
    return $markers;
} );
```

## Release process

1. Test new versions on a copy of the site first.
2. Bump the version in `my-map-plugin.php` (plugin header `Version:` **and** `MMP_VERSION`).
3. Commit, push, then tag:
   ```
   git tag v1.2.0
   git push origin v1.2.0
   ```
4. The "Release" GitHub Action checks that tag and version match, builds `my-map-plugin.zip` and attaches it to the release. Shortly after, WordPress shows "update available".

The "Check" action runs on every push: PHP syntax (8.1 and 8.4), JavaScript syntax and the Safari lookbehind check.

## File structure

```
my-map-plugin/
├── my-map-plugin.php          # main file, update checker
├── includes/
│   ├── class-cpt.php          # custom post types (mmp_marker, mmp_category)
│   ├── class-sanitizer.php    # input sanitising
│   ├── class-rest-api.php     # REST endpoints (my-map/v1)
│   ├── class-review.php       # review suggestions by mail
│   └── class-mitfahrer.php    # Mitfahrer finden (entries, login, contact, admin page)
├── admin/                     # admin pages, admin map and marker picker
├── public/                    # shortcode, front-end map, suggestion form, Mitfahrer finden (mitfahrer.js)
└── assets/leaflet/            # Leaflet 1.9.4, MarkerCluster 1.5.3
```
