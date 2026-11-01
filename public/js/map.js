/* global MMP_Public, L */
'use strict';

// Robust gegen Cache-/Optimierungs-Plugins (Cloudflare Rocket Loader,
// Autoptimize, WP Rocket JS-Verzögerung etc.), die WordPress' eigene
// Skript-Ladereihenfolge ignorieren und Skripte asynchron nachladen.
// Statt uns auf "leaflet lädt garantiert zuerst" zu verlassen, warten
// wir aktiv bis window.L existiert, bevor der Kartencode startet.
(function waitForLeaflet(retries) {
  const domReady = document.readyState === 'interactive' || document.readyState === 'complete';

  if (typeof window.L !== 'undefined' && domReady) {
    initMap();
    return;
  }
  if (retries <= 0) {
    console.error('MMP: Leaflet (window.L) oder DOM wurde nicht rechtzeitig geladen — Karte kann nicht initialisiert werden. Prüfe ob ein Cache-/Optimierungs-Plugin die Skript-Reihenfolge verändert.');
    return;
  }
  setTimeout(() => waitForLeaflet(retries - 1), 100);
})(150); // max. 15 Sekunden warten (langsame Mobilverbindungen)

function initMap() {

  const cfg = window.MMP_Public;
  if (!cfg) return;

  const api = {
    get: (path) =>
      fetch(cfg.restBase + path).then((r) => r.json()),

    // Ohne X-WP-Nonce: der Endpunkt ist öffentlich, ein abgelaufener Nonce führte zu 403.
    post: (path, body) =>
      fetch(cfg.restBase + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }).then((r) => r.json().catch(() => ({ success: false, message: 'Server-Antwort ' + r.status }))),
  };

  // ─── Karte initialisieren ──────────────────────────────────────────

  // iOS erkennen (alle iOS-Browser sind WebKit, siehe oben).
  // iOS hat ein Limit gleichzeitiger GPU-Kompositierungsebenen; Zoom-
  // und Fade-Animationen erzeugen kurzzeitig viele zusätzliche
  // transformierte Ebenen (Kacheln + Marker). Das Limit wird bei
  // größeren Karten leicht überschritten → WebKit rendert dann
  // teilweise gar nichts mehr. Auf iOS deaktivieren wir daher die
  // Animationen — die Karte bleibt zuverlässiger, wirkt nur minimal
  // weniger "flüssig" beim Zoomen.
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); // iPadOS meldet sich als Mac

  const map = L.map('mmp-public-map', {
    fadeAnimation: !isIOS,
    zoomAnimation: !isIOS,
    markerZoomAnimation: !isIOS,
    // Mausrad/Trackpad zoomt erst nach einem Klick in die Karte – sonst bleibt man beim
    // Scrollen der Seite in der Karte hängen (v. a. Mac-Trackpads).
    scrollWheelZoom: false,
  }).setView(
    [cfg.centerLat, cfg.centerLng],
    cfg.defaultZoom
  );

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution:
      '© <a href="https://openstreetmap.org">OpenStreetMap</a>-Mitwirkende',
  }).addTo(map);

  map.on('click focus', () => map.scrollWheelZoom.enable());
  map.on('mouseout', () => map.scrollWheelZoom.disable());

  // Weißer-Karte-Fix: invalidateSize zu mehreren Zeitpunkten aufrufen
  // — DOMContentLoaded reicht nicht, CSS/Fonts können noch laden
  function fixMapSize() { map.invalidateSize(); }
  setTimeout(fixMapSize, 100);
  setTimeout(fixMapSize, 500);
  window.addEventListener('load', fixMapSize);

  // ResizeObserver: reagiert wenn der Container seine Größe ändert
  if (typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(fixMapSize).observe(
      document.getElementById('mmp-public-map')
    );
  }

  // ─── WebKit/iOS-spezifische Fixes ──────────────────────────────────
  // Alle iOS-Browser (Safari, Chrome-iOS, Firefox-iOS …) nutzen intern
  // dieselbe WebKit-Engine (Apple-Vorgabe). Drei bekannte Bugs dort:

  // 1) Lazy-Loading-Plugins/Themes hängen sich per MutationObserver an
  //    JEDES neu eingefügte <img> — auch an Leaflets Kartenkacheln.
  //    WebKit berechnet die Sichtbarkeit von transform-positionierten
  //    Bildern falsch, die Kachel gilt als "nicht sichtbar" und lädt nie.
  //    Fix: jedes Bild im Karten-Container sofort auf eager zwingen.
  const mapEl = document.getElementById('mmp-public-map');
  if (mapEl && typeof MutationObserver !== 'undefined') {
    const forceEagerLoad = (node) => {
      if (node.nodeType !== 1) return;
      const imgs = node.tagName === 'IMG' ? [node] : node.querySelectorAll?.('img') || [];
      imgs.forEach((img) => {
        img.loading = 'eager';
        img.decoding = 'sync';
        img.removeAttribute('data-src'); // manche Lazy-Load-Skripte nutzen data-src statt src
      });
    };
    new MutationObserver((mutations) => {
      mutations.forEach((m) => m.addedNodes.forEach(forceEagerLoad));
    }).observe(mapEl, { childList: true, subtree: true });
  }

  // 2) iOS-Zurück/Vorwärts-Cache (bfcache): beim Zurücknavigieren wird
  //    die Seite aus einem eingefrorenen Snapshot wiederhergestellt.
  //    WebKit verwirft dabei manchmal die GPU-Ebenen der Karte — sie
  //    bleibt weiß bis man sie berührt. Fix: bei Wiederherstellung neu
  //    validieren.
  window.addEventListener('pageshow', (e) => {
    if (e.persisted) {
      setTimeout(fixMapSize, 50);
    }
  });

  // 3) iOS reduziert bei Tab-/App-Wechsel aggressiv den Grafikspeicher
  //    von Hintergrund-Tabs. Beim Zurückkehren bleiben Kacheln teils
  //    weiß. Fix: bei Sichtbarkeitswechsel neu validieren.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      setTimeout(fixMapSize, 50);
    }
  });

  // ─── Zustand ──────────────────────────────────────────────────────

  let categories        = [];
  let allMarkers        = [];
  let activeCategories  = new Set();
  let clusterGroup      = null;
  let suggestionPinMarker = null;
  let isSuggesting      = false;

  // ─── Daten laden ──────────────────────────────────────────────────

  async function loadMap() {
    [categories, allMarkers] = await Promise.all([
      api.get('/categories'),
      api.get('/markers'),
    ]);

    // IDs als Strings: Kategorien anderer Plugins (Filter mmp_categories) haben Text-IDs.
    categories.forEach((c) => activeCategories.add(String(c.id)));

    renderMarkers(allMarkers);
    renderLegend(categories);

    if (cfg.allowSuggestions) {
      // Nur echte Kartenkategorien zur Auswahl, keine angezeigten Termine o. Ä.
      initSuggestionForm(categories.filter((c) => !c.virtual));
    }
  }

  // ─── Icon ─────────────────────────────────────────────────────────

  function makeIcon(color) {
    return L.divIcon({
      className: 'mmp-leaflet-icon',
      html: `<div style="
        background:${color};
        width:32px; height:32px;
        border-radius:50% 50% 50% 0;
        transform:rotate(-45deg);
        border:3px solid #fff;
        box-shadow:0 2px 8px rgba(0,0,0,.3);
      "></div>`,
      iconSize: [32, 32],
      iconAnchor: [16, 32],
      popupAnchor: [0, -34],
    });
  }

  // Termine (type "event"): Quadrat in der Kategoriefarbe mit weißem Kalender-Symbol.
  // Kein Emoji – das überdeckte die Farbe, die man in der Legende wiederfinden soll.
  function makeEventIcon(color) {
    const kalender = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>';
    return L.divIcon({
      className: 'mmp-leaflet-icon mmp-event-icon',
      html: `<div style="
        background:${color};
        width:30px; height:30px;
        border-radius:7px;
        border:3px solid #fff;
        box-shadow:0 2px 8px rgba(0,0,0,.3);
        display:flex; align-items:center; justify-content:center;
      ">${kalender}</div>`,
      iconSize: [30, 30],
      iconAnchor: [15, 15],
      popupAnchor: [0, -18],
    });
  }

  // ─── Marker rendern (mit Clustering falls verfügbar) ─────────────

  const hasCluster = typeof L.markerClusterGroup === 'function';

  function renderMarkers(markerData) {
    if (clusterGroup) {
      map.removeLayer(clusterGroup);
    }

    // MarkerClusterGroup falls Bibliothek geladen, sonst normaler LayerGroup
    if (hasCluster) {
      clusterGroup = L.markerClusterGroup({
        maxClusterRadius: 50,
        iconCreateFunction(cluster) {
          const count = cluster.getChildCount();
          const size  = count < 10 ? 36 : count < 50 ? 44 : 52;
          return L.divIcon({
            html: `<div class="mmp-cluster" style="width:${size}px;height:${size}px">${count}</div>`,
            className: '',
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2],
          });
        },
      });
    } else {
      clusterGroup = L.layerGroup();
    }

    const visible = markerData.filter((m) => activeCategories.has(String(m.category_id)));

    visible.forEach((m) => {
      const icon = m.type === 'event' ? makeEventIcon(m.color) : makeIcon(m.color);

      let imagesHtml = '';
      if (m.images && m.images.length > 0) {
        imagesHtml = `<div class="mmp-popup-images">` +
          m.images.map((url) =>
            `<img src="${escHtml(url)}" class="mmp-popup-img" alt="" loading="lazy">`
          ).join('') +
          `</div>`;
      }

      const popup = `
        <div class="mmp-popup-inner">
          ${imagesHtml}
          <strong>${escHtml(m.title)}</strong>
          <span class="mmp-popup-cat" style="background:${m.color}">${escHtml(m.category_name)}</span>
          <div class="mmp-popup-text">${linkify(m.popup_html)}</div>
        </div>`;

      const lm = L.marker([m.lat, m.lng], { icon }).bindPopup(popup, {
        maxWidth: 280,
        maxHeight: 320, // lange Popups scrollen statt oben abgeschnitten zu werden
      });

      // Links in neuem Tab öffnen. Kein eigener window.open-Handler mehr: der öffnete
      // zusammen mit target=_blank zwei Tabs (Safari blockierte dann einen davon).
      lm.on('popupopen', () => {
        const popupEl = lm.getPopup().getElement();
        if (!popupEl) return;
        popupEl.querySelectorAll('a').forEach((a) => {
          a.setAttribute('target', '_blank');
          a.setAttribute('rel', 'noopener noreferrer');
        });
      });
      clusterGroup.addLayer(lm);
    });

    map.addLayer(clusterGroup);
  }

  // ─── URLs in Text automatisch verlinken ──────────────────────────

  // Nur Text außerhalb von HTML-Tags und außerhalb bestehender <a>…</a> wird verlinkt.
  // Bewusst ohne Lookbehind-Regex (gibt es in Safari erst ab 16.4 – davor brach die ganze
  // Datei mit einem Syntaxfehler ab) und ohne Entities zu dekodieren (das öffnete eine
  // XSS-Lücke: &#60;script&#62; wurde zu <script>).
  function linkify(html) {
    const urlRe = /(https?:\/\/[^\s<>"']+|www\.[a-zA-Z0-9._~:/?#[\]@!$&'()*+,;=%-]+)/gi;
    let inLink = 0;
    return String(html || '').split(/(<[^>]*>)/).map((part) => {
      if (part.charAt(0) === '<') {
        if (/^<a[\s>]/i.test(part)) inLink++;
        else if (/^<\/a>/i.test(part)) inLink = Math.max(0, inLink - 1);
        return part;
      }
      if (inLink) return part;
      return part.replace(urlRe, (url) => {
        // Satzzeichen am Ende nicht mitverlinken (Punkt, Komma, Klammer)
        const clean = url.replace(/[.,;:!?)]+$/, '');
        const rest  = url.slice(clean.length);
        const href  = clean.startsWith('http') ? clean : 'https://' + clean;
        return `<a href="${escHtml(href)}">${clean}</a>${rest}`;
      });
    }).join('');
  }



  function renderLegend(cats) {
    if (!cats.length) return;

    const Legend = L.Control.extend({
      onAdd() {
        const div = L.DomUtil.create('div', 'mmp-legend');
        L.DomEvent.disableClickPropagation(div);
        L.DomEvent.disableScrollPropagation(div);

        // Kategorien anderer Plugins (z. B. Termine) mit eckigem Kästchen – passend zum Marker.
        const eintrag = (c) =>
          `<label class="mmp-legend-item mmp-legend-active${c.group ? ' mmp-legend-group-item' : ''}" data-cat-id="${escHtml(String(c.id))}">
            <span class="mmp-legend-checkbox checked"
                  style="border-color:${c.color}; background:${c.color};">
              <svg viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                <polyline points="2,6 5,9 10,3" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </span>
            <span>${escHtml(c.name)}</span>
          </label>`;

        // Kategorien anderer Plugins erscheinen unter ihrer eigenen Überschrift (Feld "group").
        const gruppen = {};
        cats.forEach((c) => {
          const g = c.group || 'Kategorien';
          (gruppen[g] = gruppen[g] || []).push(c);
        });
        div.innerHTML = Object.keys(gruppen).map((g) =>
          `<h4>${escHtml(g)}</h4>` + gruppen[g].map(eintrag).join('')
        ).join('');

        div.querySelectorAll('.mmp-legend-item').forEach((el) => {
          const catId = el.dataset.catId;
          const cat   = cats.find((c) => String(c.id) === catId);
          const box   = el.querySelector('.mmp-legend-checkbox');

          el.addEventListener('click', () => {
            if (activeCategories.has(catId)) {
              activeCategories.delete(catId);
              el.classList.remove('mmp-legend-active');
              el.classList.add('mmp-legend-inactive');
              box.classList.remove('checked');
              box.style.background    = 'transparent';
              box.style.borderColor   = cat ? cat.color : '#999';
            } else {
              activeCategories.add(catId);
              el.classList.remove('mmp-legend-inactive');
              el.classList.add('mmp-legend-active');
              box.classList.add('checked');
              box.style.background    = cat ? cat.color : '#999';
              box.style.borderColor   = cat ? cat.color : '#999';
            }

            renderMarkers(allMarkers);
          });
        });

        return div;
      },
    });

    new Legend({ position: 'bottomright' }).addTo(map);
  }

  // ─── Vorschlagsformular ───────────────────────────────────────────

  function initSuggestionForm(cats) {
    const toggleBtn  = document.getElementById('mmp-suggest-toggle');
    const form       = document.getElementById('mmp-suggest-form');
    const cancelBtn  = document.getElementById('mmp-suggest-cancel');
    const submitBtn  = document.getElementById('mmp-suggest-submit');
    const feedback   = document.getElementById('mmp-suggest-feedback');
    const coordsDisp = document.getElementById('mmp-s-coords-display');
    const catSel     = document.getElementById('mmp-s-category');

    if (!toggleBtn || !form) return;

    catSel.innerHTML = cats
      .map((c) => `<option value="${c.id}">${escHtml(c.name)}</option>`)
      .join('');

    // Toolbar: Link & Fett
    document.getElementById('mmp-s-insert-link')?.addEventListener('click', () => {
      const ta    = document.getElementById('mmp-s-content');
      const start = ta.selectionStart;
      const end   = ta.selectionEnd;
      const sel   = ta.value.substring(start, end);
      const url   = prompt('URL eingeben:', 'https://');
      if (!url || url === 'https://') return;
      const text  = sel || prompt('Linktext:', url) || url;
      const tag   = `<a href="${url}">${text}</a>`;
      ta.value    = ta.value.substring(0, start) + tag + ta.value.substring(end);
      ta.setSelectionRange(start + tag.length, start + tag.length);
      ta.focus();
    });

    document.getElementById('mmp-s-insert-bold')?.addEventListener('click', () => {
      const ta    = document.getElementById('mmp-s-content');
      const start = ta.selectionStart;
      const end   = ta.selectionEnd;
      const sel   = ta.value.substring(start, end) || 'Fetter Text';
      const tag   = `<b>${sel}</b>`;
      ta.value    = ta.value.substring(0, start) + tag + ta.value.substring(end);
      ta.setSelectionRange(start + tag.length, start + tag.length);
      ta.focus();
    });

    toggleBtn.addEventListener('click', () => {
      form.classList.toggle('hidden');
      isSuggesting = !form.classList.contains('hidden');
      toggleBtn.textContent = isSuggesting ? '✕ Schließen' : '📍 Festen Ort vorschlagen';

      if (isSuggesting) {
        coordsDisp.textContent = '📍 Klicke auf die Karte um den Ort zu markieren';
      } else {
        if (suggestionPinMarker) { suggestionPinMarker.remove(); suggestionPinMarker = null; }
        clearCoords();
      }
    });

    cancelBtn?.addEventListener('click', () => {
      form.classList.add('hidden');
      isSuggesting = false;
      toggleBtn.textContent = '📍 Festen Ort vorschlagen';
      if (suggestionPinMarker) { suggestionPinMarker.remove(); suggestionPinMarker = null; }
      clearCoords();
    });

    map.on('click', (e) => {
      if (!isSuggesting) return;
      const { lat, lng } = e.latlng;
      document.getElementById('mmp-s-lat').value = lat.toFixed(6);
      document.getElementById('mmp-s-lng').value = lng.toFixed(6);
      coordsDisp.textContent = `✅ Koordinaten gesetzt: ${lat.toFixed(4)}, ${lng.toFixed(4)}`;

      if (suggestionPinMarker) suggestionPinMarker.remove();
      suggestionPinMarker = L.circleMarker([lat, lng], {
        radius: 10, color: '#2563eb', fillColor: '#2563eb', fillOpacity: 0.8,
      }).addTo(map).bindPopup('Dein Vorschlag').openPopup();
    });

    submitBtn?.addEventListener('click', async () => {
      feedback.className = 'hidden';

      const body = {
        title:           document.getElementById('mmp-s-title').value,
        content:         document.getElementById('mmp-s-content').value,
        lat:             document.getElementById('mmp-s-lat').value,
        lng:             document.getElementById('mmp-s-lng').value,
        category_id:     document.getElementById('mmp-s-category').value,
        submitter_name:  document.getElementById('mmp-s-name').value,
        submitter_email: document.getElementById('mmp-s-email').value,
        website:         document.getElementById('mmp-hp').value,
      };

      if (!body.title)   { showFeedback('Bitte gib einen Namen ein.', 'error'); return; }
      if (!body.content) { showFeedback('Bitte füge eine Beschreibung hinzu.', 'error'); return; }
      if (!body.lat || !body.lng) { showFeedback('Bitte klicke auf die Karte um einen Ort zu wählen.', 'error'); return; }

      submitBtn.textContent = 'Wird eingereicht…';
      submitBtn.disabled = true;

      // Bei Netzabbruch (z. B. WLAN → Mobilfunk) darf der Knopf nicht gesperrt bleiben.
      let res;
      try {
        res = await api.post('/suggest', body);
      } catch (err) {
        res = { success: false, message: 'Keine Verbindung zum Server. Bitte erneut versuchen.' };
      } finally {
        submitBtn.textContent = 'Vorschlag einreichen';
        submitBtn.disabled = false;
      }

      if (res.success) {
        showFeedback('✅ Danke! Dein Vorschlag wird von den Admins vor Veröffentlichung geprüft.', 'success');
        ['mmp-s-title', 'mmp-s-content', 'mmp-s-name', 'mmp-s-email'].forEach(
          (id) => { document.getElementById(id).value = ''; }
        );
        clearCoords();
        coordsDisp.textContent = '📍 Klicke auf die Karte um den Ort zu markieren';
      } else {
        showFeedback('Fehler: ' + (res.message || 'Bitte versuche es erneut.'), 'error');
      }
    });

    function showFeedback(msg, type) {
      feedback.textContent = msg;
      feedback.className = type;
    }
  }

  function clearCoords() {
    document.getElementById('mmp-s-lat').value = '';
    document.getElementById('mmp-s-lng').value = '';
  }

  loadMap().catch(console.error);
}

function escHtml(str) {
  return String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
