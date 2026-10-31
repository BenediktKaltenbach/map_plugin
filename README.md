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

On phones the map is limited to 65% of the screen height, so visitors can still scroll past it. Mouse-wheel/trackpad zoom only starts after clicking into the map.

## Admin

After activation the WordPress menu has:

- **Marker** – manage markers on the map, add new ones (with images from the media library)
- **Vorschläge** – review visitor suggestions, approve or reject them
- **Kategorien** – categories with colours and icons
- **Einstellungen** – default zoom, map centre, map height, suggestions on/off

## Security and robustness

- All input is sanitised server-side (`sanitize_text_field()`, `wp_kses()`, `sanitize_hex_color()` …). Popup text is never entity-decoded in the browser.
- Admin REST routes require `manage_options` and only act on this plugin's own post types.
- Visitor suggestions are stored as `pending` and stay invisible until approved.
- Honeypot field against simple bots; at most 5 valid suggestions per IP per hour.
- The public suggestion endpoint does not use a REST nonce, so tabs kept open for days (common on iOS) do not fail with "Cookie check failed".
- Leaflet 1.9.4 and Leaflet.markercluster 1.5.3 are bundled and loaded under their own handles (`mmp-leaflet`, `mmp-leaflet-cluster`). This avoids picking up another plugin's Leaflet (e.g. Otter Blocks registers `leaflet` with version 1.8.0).
- The front-end script avoids JavaScript features older Safari versions cannot parse (CI fails on regex lookbehind).

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
│   └── class-rest-api.php     # REST endpoints (my-map/v1)
├── admin/                     # admin pages, admin map and marker picker
├── public/                    # shortcode, front-end map, suggestion form
└── assets/leaflet/            # Leaflet 1.9.4, MarkerCluster 1.5.3
```
