/* global MMP, L */
'use strict';

const api = {
  get: (path) =>
    fetch(MMP.restBase + path, {
      headers: { 'X-WP-Nonce': MMP.nonce },
    }).then((r) => r.json()),

  post: (path, body) =>
    fetch(MMP.restBase + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': MMP.nonce },
      body: JSON.stringify(body),
    }).then((r) => r.json()),

  del: (path) =>
    fetch(MMP.restBase + path, {
      method: 'DELETE',
      headers: { 'X-WP-Nonce': MMP.nonce },
    }).then((r) => r.json()),
};

// ─── Vorschläge-Seite (läuft auf jeder Admin-Seite des Plugins) ──────

document.addEventListener('DOMContentLoaded', function () {

  // ── Freigeben ──────────────────────────────────────────────────────
  document.querySelectorAll('.mmp-approve').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id  = btn.dataset.id;
      btn.textContent = '…';
      btn.disabled = true;
      const res = await api.post('/markers/' + id + '/approve', {});
      if (res.success) {
        btn.closest('.mmp-suggestion-card').remove();
      } else {
        btn.textContent = '✓ Freigeben';
        btn.disabled = false;
        alert('Fehler beim Freigeben.');
      }
    });
  });

  // ── Ablehnen ───────────────────────────────────────────────────────
  document.querySelectorAll('.mmp-reject').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('Vorschlag ablehnen und löschen?')) return;
      const id  = btn.dataset.id;
      btn.textContent = '…';
      btn.disabled = true;
      const res = await api.del('/markers/' + id);
      if (res.success) {
        btn.closest('.mmp-suggestion-card').remove();
      } else {
        btn.textContent = '✕ Ablehnen';
        btn.disabled = false;
        alert('Fehler beim Ablehnen.');
      }
    });
  });

  // ── Kategorieseite: Farb-Presets ──────────────────────────────────
  document.querySelectorAll('.mmp-preset-dot').forEach((dot) => {
    dot.addEventListener('click', () => {
      const colorInput = dot.closest('.mmp-color-row')?.querySelector('input[type="color"]');
      if (colorInput) colorInput.value = dot.dataset.color;
    });
  });

});

// ─── Marker-Verwaltungsseite ──────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {

  // ── Admin-Karte ────────────────────────────────────────────────────
  const mapEl = document.getElementById('mmp-admin-map');
  if (!mapEl) return;

  let markers    = [];
  let categories = [];
  let leafletMarkers = {};
  let adminMap;

  // Karte initialisieren
  adminMap = L.map('mmp-admin-map').setView([48.137, 11.575], 10);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://openstreetmap.org">OpenStreetMap</a>',
  }).addTo(adminMap);

  // Daten laden
  async function loadData() {
    [categories, markers] = await Promise.all([
      api.get('/categories'),
      api.get('/markers'),
    ]);
    renderList(markers);
    renderMapMarkers(markers);
    populateCategoryDropdown();
  }

  // ── Marker-Liste ───────────────────────────────────────────────────

  function renderList(list) {
    const container = document.getElementById('mmp-marker-list');
    if (!container) return;

    if (!list.length) {
      container.innerHTML = '<div class="mmp-loading">Noch keine Marker vorhanden.</div>';
      return;
    }

    container.innerHTML = list.map((m) => `
      <div class="mmp-marker-item" data-id="${m.id}">
        <div class="mmp-marker-dot" style="background:${m.color}"></div>
        <div class="mmp-marker-info">
          <div class="mmp-marker-name">${escHtml(m.title)}</div>
          <div class="mmp-marker-cat">${escHtml(m.category_name)}</div>
        </div>
        <div class="mmp-marker-actions">
          <button class="mmp-btn mmp-btn-icon mmp-edit-marker" data-id="${m.id}" title="Bearbeiten">✏️</button>
          <button class="mmp-btn mmp-btn-icon mmp-delete-marker" data-id="${m.id}" title="Löschen">🗑</button>
        </div>
      </div>
    `).join('');

    container.querySelectorAll('.mmp-marker-item').forEach((el) => {
      el.addEventListener('click', (e) => {
        if (e.target.closest('.mmp-delete-marker') || e.target.closest('.mmp-edit-marker')) return;
        const id = parseInt(el.dataset.id);
        const m  = markers.find((x) => x.id === id);
        if (m) {
          adminMap.setView([m.lat, m.lng], 15);
          leafletMarkers[id]?.openPopup();
        }
      });
    });

    container.querySelectorAll('.mmp-edit-marker').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = parseInt(btn.dataset.id);
        const m  = markers.find((x) => x.id === id);
        if (!m) return;
        openModal(m);
      });
    });

    container.querySelectorAll('.mmp-delete-marker').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Marker wirklich löschen?')) return;
        const id = parseInt(btn.dataset.id);
        await api.del('/markers/' + id);
        await loadData();
      });
    });
  }

  // ── Leaflet-Marker ─────────────────────────────────────────────────

  function renderMapMarkers(list) {
    Object.values(leafletMarkers).forEach((m) => m.remove());
    leafletMarkers = {};

    list.forEach((m) => {
      const icon = L.divIcon({
        className: 'mmp-leaflet-icon',
        html: `<div style="
          background:${m.color};
          width:32px; height:32px;
          border-radius:50% 50% 50% 0;
          transform: rotate(-45deg);
          border: 3px solid #fff;
          box-shadow: 0 2px 8px rgba(0,0,0,.3);
        "></div>`,
        iconSize: [32, 32],
        iconAnchor: [16, 32],
        popupAnchor: [0, -34],
      });

      const lm = L.marker([m.lat, m.lng], { icon })
        .addTo(adminMap)
        .bindPopup(`<strong>${escHtml(m.title)}</strong><br>${m.popup_html}`);

      leafletMarkers[m.id] = lm;
    });
  }

  // ── Such-Filter ────────────────────────────────────────────────────

  document.getElementById('mmp-search')?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    const filtered = markers.filter(
      (m) => m.title.toLowerCase().includes(q) || m.category_name.toLowerCase().includes(q)
    );
    renderList(filtered);
  });

  // ── Toolbar: Link & Fett einfügen ─────────────────────────────────

  document.getElementById('mmp-insert-link')?.addEventListener('click', () => {
    const textarea = document.getElementById('mmp-m-content');
    const start    = textarea.selectionStart;
    const end      = textarea.selectionEnd;
    const selected = textarea.value.substring(start, end);

    const url  = prompt('URL eingeben:', 'https://');
    if (!url || url === 'https://') return;

    const text    = selected || prompt('Linktext eingeben:', url) || url;
    const tag     = `<a href="${url}">${text}</a>`;
    const before  = textarea.value.substring(0, start);
    const after   = textarea.value.substring(end);

    textarea.value = before + tag + after;
    // Cursor nach dem eingefügten Tag setzen
    const pos = before.length + tag.length;
    textarea.setSelectionRange(pos, pos);
    textarea.focus();
  });

  document.getElementById('mmp-insert-bold')?.addEventListener('click', () => {
    const textarea = document.getElementById('mmp-m-content');
    const start    = textarea.selectionStart;
    const end      = textarea.selectionEnd;
    const selected = textarea.value.substring(start, end) || 'Fetter Text';
    const tag      = `<b>${selected}</b>`;
    const before   = textarea.value.substring(0, start);
    const after    = textarea.value.substring(end);

    textarea.value = before + tag + after;
    const pos = before.length + tag.length;
    textarea.setSelectionRange(pos, pos);
    textarea.focus();
  });

  // ── Bild-Picker (WordPress Media Library) ─────────────────────────

  let mediaFrame = null;
  let selectedImages = [];

  function renderImagePreviews() {
    const preview = document.getElementById('mmp-image-preview');
    if (!preview) return;
    preview.innerHTML = selectedImages.map((url, i) => `
      <div class="mmp-img-thumb">
        <img src="${escHtml(url)}" alt="">
        <button type="button" class="mmp-img-remove" data-index="${i}">✕</button>
      </div>
    `).join('');
    preview.querySelectorAll('.mmp-img-remove').forEach(btn => {
      btn.addEventListener('click', () => {
        selectedImages.splice(parseInt(btn.dataset.index), 1);
        document.getElementById('mmp-m-images').value = JSON.stringify(selectedImages);
        renderImagePreviews();
      });
    });
  }

  document.getElementById('mmp-image-pick')?.addEventListener('click', () => {
    if (selectedImages.length >= 5) {
      alert('Maximal 5 Bilder pro Marker.');
      return;
    }
    if (!mediaFrame) {
      mediaFrame = wp.media({
        title: 'Bild auswählen',
        button: { text: 'Bild verwenden' },
        multiple: false,
        library: { type: 'image' },
      });
      mediaFrame.on('select', () => {
        const attachment = mediaFrame.state().get('selection').first().toJSON();
        const url = attachment.sizes?.medium?.url || attachment.url;
        if (!selectedImages.includes(url) && selectedImages.length < 5) {
          selectedImages.push(url);
          document.getElementById('mmp-m-images').value = JSON.stringify(selectedImages);
          renderImagePreviews();
        }
      });
    }
    mediaFrame.open();
  });

  // ── Neuer Marker Modal ─────────────────────────────────────────────

  const modal      = document.getElementById('mmp-modal');
  const addBtn     = document.getElementById('mmp-add-marker-btn');
  const closeBtn   = document.getElementById('mmp-modal-close');
  const cancelBtn  = document.getElementById('mmp-modal-cancel');
  const saveBtn    = document.getElementById('mmp-modal-save');
  let pickerMap    = null;
  let pickerMarker = null;

  function openModal(markerData = null) {
    document.getElementById('mmp-modal-title').textContent = markerData ? 'Marker bearbeiten' : 'Neuer Marker';
    document.getElementById('mmp-m-id').value = markerData ? markerData.id : '';

    if (markerData) {
      document.getElementById('mmp-m-title').value   = markerData.title;
      document.getElementById('mmp-m-content').value = markerData.popup_html;
      document.getElementById('mmp-m-lat').value     = markerData.lat;
      document.getElementById('mmp-m-lng').value     = markerData.lng;
      // Kategorie vorauswählen
      const sel = document.getElementById('mmp-m-category');
      if (sel) sel.value = markerData.category_id;
      // Bilder laden
      selectedImages = markerData.images || [];
      document.getElementById('mmp-m-images').value = JSON.stringify(selectedImages);
      renderImagePreviews();
    }

    modal?.classList.remove('hidden');

    setTimeout(() => {
      if (!pickerMap) {
        const startLat = markerData ? markerData.lat : 51.163;
        const startLng = markerData ? markerData.lng : 10.447;
        pickerMap = L.map('mmp-picker-map').setView([startLat, startLng], markerData ? 12 : 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(pickerMap);

        pickerMap.on('click', (e) => {
          const { lat, lng } = e.latlng;
          document.getElementById('mmp-m-lat').value = lat.toFixed(6);
          document.getElementById('mmp-m-lng').value = lng.toFixed(6);
          if (pickerMarker) pickerMarker.remove();
          pickerMarker = L.circleMarker([lat, lng], {
            radius: 8, color: '#2563eb', fillColor: '#2563eb', fillOpacity: 1,
          }).addTo(pickerMap);
        });
      } else if (markerData) {
        pickerMap.setView([markerData.lat, markerData.lng], 12);
      }

      // Vorhandenen Pin anzeigen beim Bearbeiten
      if (markerData) {
        if (pickerMarker) pickerMarker.remove();
        pickerMarker = L.circleMarker([markerData.lat, markerData.lng], {
          radius: 8, color: '#2563eb', fillColor: '#2563eb', fillOpacity: 1,
        }).addTo(pickerMap);
      }

      pickerMap.invalidateSize();
    }, 100);
  }

  function closeModal() {
    modal?.classList.add('hidden');
    document.getElementById('mmp-m-id').value      = '';
    document.getElementById('mmp-m-title').value   = '';
    document.getElementById('mmp-m-content').value = '';
    document.getElementById('mmp-m-lat').value     = '';
    document.getElementById('mmp-m-lng').value     = '';
    selectedImages = [];
    document.getElementById('mmp-m-images').value = '[]';
    renderImagePreviews();
    if (pickerMarker) { pickerMarker.remove(); pickerMarker = null; }
  }

  addBtn?.addEventListener('click', () => openModal());
  closeBtn?.addEventListener('click', closeModal);
  cancelBtn?.addEventListener('click', closeModal);
  modal?.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

  saveBtn?.addEventListener('click', async () => {
    const editId = document.getElementById('mmp-m-id').value;
    const body = {
      title:       document.getElementById('mmp-m-title').value,
      content:     document.getElementById('mmp-m-content').value,
      lat:         parseFloat(document.getElementById('mmp-m-lat').value),
      lng:         parseFloat(document.getElementById('mmp-m-lng').value),
      category_id: parseInt(document.getElementById('mmp-m-category').value),
      images:      selectedImages,
    };

    saveBtn.textContent = 'Speichert…';
    saveBtn.disabled = true;

    // Neu oder Update?
    const res = editId
      ? await api.post('/markers/' + editId + '/update', body)
      : await api.post('/markers', body);

    saveBtn.textContent = 'Speichern';
    saveBtn.disabled = false;

    if (res.success) {
      closeModal();
      await loadData();
    } else {
      alert('Fehler: ' + (res.message || 'Unbekannt'));
    }
  });

  // ── Kategorie-Dropdown befüllen ────────────────────────────────────

  function populateCategoryDropdown() {
    const sel = document.getElementById('mmp-m-category');
    if (!sel) return;
    sel.innerHTML = categories.map((c) =>
      `<option value="${c.id}">${c.icon} ${escHtml(c.name)}</option>`
    ).join('');
  }

  // ── Init ───────────────────────────────────────────────────────────

  loadData().catch(console.error);
});

function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
