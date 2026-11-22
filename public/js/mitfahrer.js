/* global L */
'use strict';

// „Mitfahrer finden“: eigene Punkte auf der Karte, Fenster zum Eintragen/Anmelden/Verwalten und
// Kontaktformular. Gestartet von map.js (window.MMP_Mitfahrer.start), sobald die Karte steht.
// Anmeldung läuft über ein HttpOnly-Cookie, das der Server setzt; hier wird nichts gespeichert.

window.MMP_Mitfahrer = { start: startMitfahrer };

function startMitfahrer(k) {
  const { map, api, escHtml } = k;
  const kat = k.kategorie;

  let daten    = null;   // Antwort von GET /mitfahrer
  let geladen  = null;   // laufendes Laden (Promise)
  let gefragt  = false;  // Fenster beim ersten Einblenden schon gezeigt?

  // ─── Symbole ──────────────────────────────────────────────────────

  const person = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c1-4 4-6 7-6s6 2 7 6"/></svg>';
  const symbol = (rand) => L.divIcon({
    className: 'mmp-leaflet-icon mmp-person-icon',
    html: `<div style="background:${kat.color};width:30px;height:30px;border-radius:50%;border:3px solid ${rand};` +
      `box-shadow:0 2px 8px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;">${person}</div>`,
    iconSize: [30, 30],
    iconAnchor: [15, 15],
    popupAnchor: [0, -18],
  });
  const symbolAndere = symbol('#fff');
  const symbolIch    = symbol('#facc15'); // eigener Punkt mit gelbem Rand

  // ─── Daten ────────────────────────────────────────────────────────

  function laden() {
    geladen = api.get('/mitfahrer').then((d) => {
      if (!d || !Array.isArray(d.personen)) throw new Error((d && d.message) || 'Keine Daten');
      daten = d;
      k.setzeMarker(d.personen.map(zuMarker));
      zaehlerAktualisieren();
      return d;
    }).catch((e) => {
      geladen = null;
      console.error('MMP Mitfahrer:', e);
    });
    return geladen;
  }

  function zuMarker(p) {
    return {
      type: 'person',
      category_id: kat.id,
      lat: p.lat,
      lng: p.lng,
      color: kat.color,
      _icon: p.ich ? symbolIch : symbolAndere,
      _popup: popupHtml(p),
      _beimOeffnen: (el) => {
        el.querySelector('.mmp-mf-mitmachen')?.addEventListener('click', () => zeigeStart());
        el.querySelector('.mmp-mf-kontakt')?.addEventListener('click', () => zeigeKontakt(p));
        el.querySelector('.mmp-mf-mein')?.addEventListener('click', () => zeigeIch());
      },
    };
  }

  function popupHtml(p) {
    if (!daten.angemeldet) {
      return `<div class="mmp-popup-inner">
        <strong>Hier fährt jemand Tretroller</strong>
        <div class="mmp-popup-text">Trag dich ein, um zu sehen, wer hier fährt, und Kontakt aufzunehmen.</div>
        <button type="button" class="mmp-pub-btn mmp-pub-btn-primary mmp-mf-mitmachen">Mitmachen</button>
      </div>`;
    }
    const tags = (p.tags || []).map((t) => `<span class="mmp-mf-tag">${escHtml(t)}</span>`).join('');
    const text = p.text ? `<div class="mmp-popup-text">${escHtml(p.text).replace(/\n/g, '<br>')}</div>` : '';
    const knopf = p.ich
      ? '<p class="mmp-mf-klein">Das bist du.</p><button type="button" class="mmp-pub-btn mmp-pub-btn-secondary mmp-mf-mein">Mein Eintrag</button>'
      : '<button type="button" class="mmp-pub-btn mmp-pub-btn-primary mmp-mf-kontakt">Kontaktieren</button>';
    return `<div class="mmp-popup-inner">
      <strong>${escHtml(p.name)}</strong>
      <div class="mmp-mf-tags">${tags}</div>
      ${text}
      ${knopf}
    </div>`;
  }

  // ─── Zähler oben rechts (nur wenn die Kategorie eingeblendet ist) ─

  const Zaehler = L.Control.extend({
    onAdd() {
      const div = L.DomUtil.create('div', 'mmp-mf-zaehler');
      L.DomEvent.disableClickPropagation(div);
      return div;
    },
  });
  const zaehler = new Zaehler({ position: 'topright' });
  let zaehlerDa = false;

  function zaehlerAktualisieren() {
    if (!k.istAn() || !daten) {
      if (zaehlerDa) { zaehler.remove(); zaehlerDa = false; }
      return;
    }
    if (!zaehlerDa) { zaehler.addTo(map); zaehlerDa = true; }
    const grenzen = map.getBounds();
    const anzahl  = daten.personen.filter((p) => grenzen.contains([p.lat, p.lng])).length;
    const el = zaehler.getContainer();
    if (daten.angemeldet) {
      el.innerHTML = `<span><b>${anzahl}</b> im Ausschnitt</span><button type="button">Mein Eintrag</button>`;
      el.querySelector('button').onclick = () => zeigeIch();
    } else {
      el.innerHTML = `<span><b>${anzahl}</b> Tretrollerfahrer</span><button type="button">Mitmachen</button>`;
      el.querySelector('button').onclick = () => zeigeStart();
    }
  }
  map.on('moveend', zaehlerAktualisieren);

  kat.beimSchalten = (an) => {
    if (!an) { zaehlerAktualisieren(); return; }
    (daten ? Promise.resolve(daten) : (geladen || laden())).then(() => {
      zaehlerAktualisieren();
      // Beim ersten Einblenden ohne Eintrag gleich fragen, ob man mitmachen will.
      if (daten && !daten.angemeldet && !gefragt) {
        gefragt = true;
        zeigeStart();
      }
    });
  };

  // ─── Fenster ──────────────────────────────────────────────────────

  const overlay = document.createElement('div');
  overlay.className = 'mmp-mf-overlay hidden';
  overlay.innerHTML = '<div class="mmp-mf-dialog" role="dialog" aria-modal="true" aria-labelledby="mmp-mf-titel">' +
    '<button type="button" class="mmp-mf-zu" aria-label="Schließen">×</button><div class="mmp-mf-inhalt"></div></div>';
  document.body.appendChild(overlay);
  const inhalt = overlay.querySelector('.mmp-mf-inhalt');
  let zuvor = null;

  function oeffnen(html) {
    if (overlay.classList.contains('hidden')) zuvor = document.activeElement;
    inhalt.innerHTML = html;
    overlay.classList.remove('hidden');
    const erstes = inhalt.querySelector('input:not([type=hidden]):not([tabindex="-1"]), textarea, button');
    if (erstes) erstes.focus({ preventScroll: true });
    overlay.scrollTop = 0;
  }
  function schliessen() {
    overlay.classList.add('hidden');
    inhalt.innerHTML = '';
    if (zuvor && zuvor.focus) zuvor.focus({ preventScroll: true });
  }
  overlay.querySelector('.mmp-mf-zu').addEventListener('click', schliessen);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) schliessen(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !overlay.classList.contains('hidden')) schliessen();
  });

  function meldung(titel, text, weiter) {
    oeffnen(`<h3 id="mmp-mf-titel">${escHtml(titel)}</h3><p>${escHtml(text)}</p>
      <div class="mmp-form-actions"><button type="button" class="mmp-pub-btn mmp-pub-btn-primary mmp-mf-ok">OK</button></div>`);
    inhalt.querySelector('.mmp-mf-ok').addEventListener('click', () => (weiter ? weiter() : schliessen()));
  }

  function fehlerText(res) {
    return (res && (res.message || res.meldung)) || 'Das hat nicht geklappt. Bitte versuche es noch einmal.';
  }

  // Formular absenden: Knopf sperren, bei Netzabbruch wieder freigeben, Fehler im Fenster zeigen.
  async function senden(knopf, pfad, body) {
    const fehler = inhalt.querySelector('.mmp-mf-fehler');
    const text   = knopf.textContent;
    fehler.textContent = '';
    knopf.disabled = true;
    knopf.textContent = 'Einen Moment …';
    let res;
    try {
      res = await api.post(pfad, body);
    } catch (e) {
      res = { message: 'Keine Verbindung zum Server. Bitte erneut versuchen.' };
    } finally {
      knopf.disabled = false;
      knopf.textContent = text;
    }
    if (!res || !res.ok) {
      fehler.textContent = fehlerText(res);
      return null;
    }
    return res;
  }

  function sicher() {
    return daten ? Promise.resolve(daten) : (geladen || laden());
  }

  // Start: eintragen oder anmelden
  function zeigeStart() {
    sicher().then(() => {
      if (daten && daten.angemeldet) { zeigeIch(); return; }
      oeffnen(`<h3 id="mmp-mf-titel">Mitfahrer finden</h3>
        <p>Trag dich auf der Tretrollerkarte ein und finde Leute in deiner Nähe, die auch Tretroller fahren. Deine E-Mail-Adresse bleibt verborgen, Kontakt läuft über die Website.</p>
        <div class="mmp-mf-wahl">
          <button type="button" class="mmp-pub-btn mmp-pub-btn-primary mmp-mf-neu">Noch nicht dabei? Trag dich ein</button>
          <button type="button" class="mmp-pub-btn mmp-pub-btn-secondary mmp-mf-login">Schon dabei? Anmelden</button>
        </div>`);
      inhalt.querySelector('.mmp-mf-neu').addEventListener('click', zeigeEintragen);
      inhalt.querySelector('.mmp-mf-login').addEventListener('click', zeigeAnmelden);
    });
  }

  function felderHtml(werte) {
    const laender = Object.keys(daten.laender || { DE: 'Deutschland' });
    const tags = (daten.tags || []).map((t) =>
      `<label class="mmp-mf-chip"><input type="checkbox" name="tags" value="${escHtml(t)}"${(werte.tags || []).indexOf(t) >= 0 ? ' checked' : ''}> ${escHtml(t)}</label>`
    ).join('');
    return `
      <div class="mmp-pub-field">
        <label for="mmp-mf-name">Anzeigename <span class="mmp-req">*</span></label>
        <input type="text" id="mmp-mf-name" maxlength="40" autocomplete="nickname" placeholder="Vorname oder Spitzname" value="${escHtml(werte.name || '')}">
      </div>
      <div class="mmp-mf-reihe">
        <div class="mmp-pub-field">
          <label for="mmp-mf-plz">Postleitzahl <span class="mmp-req">*</span></label>
          <input type="text" id="mmp-mf-plz" inputmode="numeric" maxlength="5" autocomplete="postal-code" value="${escHtml(werte.plz || '')}">
        </div>
        <div class="mmp-pub-field">
          <label for="mmp-mf-land">Land</label>
          <select id="mmp-mf-land">${laender.map((l) => `<option value="${l}"${(werte.land || 'DE') === l ? ' selected' : ''}>${escHtml(daten.laender[l])}</option>`).join('')}</select>
        </div>
      </div>
      <p class="mmp-mf-klein">Auf der Karte erscheint nur die ungefähre Lage (1–2 km verschoben), nie deine Adresse.</p>
      <fieldset class="mmp-pub-field mmp-mf-tagwahl">
        <legend>Interessen <span class="mmp-req">*</span></legend>
        <div>${tags}</div>
      </fieldset>
      <div class="mmp-pub-field">
        <label for="mmp-mf-text">Kurzer Text (optional)</label>
        <textarea id="mmp-mf-text" rows="3" maxlength="300" placeholder="z. B. Fahre meist sonntags an der Ruhr">${escHtml(werte.text || '')}</textarea>
      </div>`;
  }

  function felderLesen() {
    return {
      name: inhalt.querySelector('#mmp-mf-name').value,
      plz:  inhalt.querySelector('#mmp-mf-plz').value,
      land: inhalt.querySelector('#mmp-mf-land').value,
      tags: Array.prototype.map.call(inhalt.querySelectorAll('input[name=tags]:checked'), (el) => el.value),
      text: inhalt.querySelector('#mmp-mf-text').value,
    };
  }

  function zeigeEintragen() {
    const ds = daten.datenschutz
      ? `<a href="${escHtml(daten.datenschutz)}" target="_blank" rel="noopener">Datenschutzerklärung</a>`
      : 'Datenschutzerklärung';
    oeffnen(`<h3 id="mmp-mf-titel">Trag dich ein</h3>
      ${felderHtml({})}
      <div class="mmp-pub-field">
        <label for="mmp-mf-email">E-Mail <span class="mmp-req">*</span></label>
        <input type="email" id="mmp-mf-email" autocomplete="email">
        <p class="mmp-mf-klein">Wird nie angezeigt. Du bekommst einen Bestätigungslink.</p>
      </div>
      <div class="mmp-hp-field" aria-hidden="true"><label>Website</label><input type="text" id="mmp-mf-hp" tabindex="-1" autocomplete="off"></div>
      <p class="mmp-mf-hinweis">Anzeigename, Interessen und Text sehen alle, die sich ebenfalls eingetragen haben. Da sich jede Person eintragen kann, ist das praktisch öffentlich: Trag nichts ein, was nicht öffentlich sein soll.</p>
      <label class="mmp-mf-check"><input type="checkbox" id="mmp-mf-ok"> Ich bin einverstanden, dass meine Angaben wie beschrieben gespeichert und angezeigt werden (${ds}). Ich kann meinen Eintrag jederzeit löschen.</label>
      <div class="mmp-mf-fehler" role="alert"></div>
      <div class="mmp-form-actions">
        <button type="button" class="mmp-pub-btn mmp-pub-btn-secondary mmp-mf-zurueck">Zurück</button>
        <button type="button" class="mmp-pub-btn mmp-pub-btn-primary mmp-mf-senden">Eintragen</button>
      </div>`);
    inhalt.querySelector('.mmp-mf-zurueck').addEventListener('click', zeigeStart);
    const knopf = inhalt.querySelector('.mmp-mf-senden');
    knopf.addEventListener('click', async () => {
      const res = await senden(knopf, '/mitfahrer/eintragen', Object.assign(felderLesen(), {
        email:        inhalt.querySelector('#mmp-mf-email').value,
        einwilligung: inhalt.querySelector('#mmp-mf-ok').checked,
        website:      inhalt.querySelector('#mmp-mf-hp').value,
        seite:        seitenAdresse(),
      }));
      if (res) meldung('Fast geschafft', res.meldung);
    });
  }

  function zeigeAnmelden() {
    oeffnen(`<h3 id="mmp-mf-titel">Anmelden</h3>
      <p>Gib die E-Mail-Adresse deines Eintrags ein. Wir schicken dir einen Link, mit dem du dich auf diesem Gerät anmeldest.</p>
      <div class="mmp-pub-field">
        <label for="mmp-mf-email">E-Mail</label>
        <input type="email" id="mmp-mf-email" autocomplete="email">
      </div>
      <div class="mmp-mf-fehler" role="alert"></div>
      <div class="mmp-form-actions">
        <button type="button" class="mmp-pub-btn mmp-pub-btn-secondary mmp-mf-zurueck">Zurück</button>
        <button type="button" class="mmp-pub-btn mmp-pub-btn-primary mmp-mf-senden">Link schicken</button>
      </div>`);
    inhalt.querySelector('.mmp-mf-zurueck').addEventListener('click', zeigeStart);
    const knopf = inhalt.querySelector('.mmp-mf-senden');
    knopf.addEventListener('click', async () => {
      const res = await senden(knopf, '/mitfahrer/anmeldelink', {
        email: inhalt.querySelector('#mmp-mf-email').value,
        seite: seitenAdresse(),
      });
      if (res) meldung('Schau in dein Postfach', res.meldung);
    });
  }

  function zeigeIch() {
    sicher().then(() => {
      if (!daten || !daten.ich) { zeigeStart(); return; }
      const ich = daten.ich;
      oeffnen(`<h3 id="mmp-mf-titel">Mein Eintrag</h3>
        <p class="mmp-mf-klein">Angemeldet mit ${escHtml(ich.email)}</p>
        ${felderHtml(ich)}
        <label class="mmp-mf-check"><input type="checkbox" id="mmp-mf-pause"${ich.pausiert ? ' checked' : ''}> Pausieren (auf der Karte unsichtbar)</label>
        <div class="mmp-mf-fehler" role="alert"></div>
        <div class="mmp-form-actions">
          <button type="button" class="mmp-pub-btn mmp-pub-btn-primary mmp-mf-senden">Speichern</button>
        </div>
        <div class="mmp-mf-links">
          <button type="button" class="mmp-mf-link mmp-mf-ab">Auf diesem Gerät abmelden</button>
          <button type="button" class="mmp-mf-link mmp-mf-ab-alle">Alle Geräte abmelden</button>
          <button type="button" class="mmp-mf-link mmp-mf-weg">Eintrag löschen</button>
        </div>`);
      const knopf = inhalt.querySelector('.mmp-mf-senden');
      knopf.addEventListener('click', async () => {
        const res = await senden(knopf, '/mitfahrer/ich', Object.assign(felderLesen(), {
          pausiert: inhalt.querySelector('#mmp-mf-pause').checked,
        }));
        if (res) { await laden(); meldung('Gespeichert', 'Deine Änderungen sind gespeichert.'); }
      });
      const aktion = (sel, pfad, body, frage) => {
        const b = inhalt.querySelector(sel);
        b.addEventListener('click', async () => {
          if (frage && !window.confirm(frage)) return;
          const res = await senden(b, pfad, body);
          if (res) { await laden(); meldung('Erledigt', res.meldung); }
        });
      };
      aktion('.mmp-mf-ab', '/mitfahrer/abmelden', { alle: false });
      aktion('.mmp-mf-ab-alle', '/mitfahrer/abmelden', { alle: true }, 'Alle Geräte abmelden, auch dieses?');
      aktion('.mmp-mf-weg', '/mitfahrer/ich/loeschen', {}, 'Eintrag endgültig löschen? Das lässt sich nicht rückgängig machen.');
    });
  }

  function zeigeKontakt(p) {
    if (daten.ich && daten.ich.pausiert) {
      meldung('Eintrag pausiert', 'Solange dein Eintrag pausiert ist, kannst du niemanden kontaktieren. Unter „Mein Eintrag“ kannst du ihn wieder sichtbar machen.');
      return;
    }
    oeffnen(`<h3 id="mmp-mf-titel">Nachricht an ${escHtml(p.name)}</h3>
      <div class="mmp-pub-field">
        <label for="mmp-mf-nachricht">Deine Nachricht</label>
        <textarea id="mmp-mf-nachricht" rows="5" maxlength="1500" placeholder="Hallo, ich fahre auch in der Gegend …"></textarea>
      </div>
      <p class="mmp-mf-hinweis">Deine E-Mail-Adresse wird an ${escHtml(p.name)} weitergegeben, damit ${escHtml(p.name)} dir antworten kann.</p>
      <div class="mmp-mf-fehler" role="alert"></div>
      <div class="mmp-form-actions">
        <button type="button" class="mmp-pub-btn mmp-pub-btn-secondary mmp-mf-abbrechen">Abbrechen</button>
        <button type="button" class="mmp-pub-btn mmp-pub-btn-primary mmp-mf-senden">Senden</button>
      </div>`);
    inhalt.querySelector('.mmp-mf-abbrechen').addEventListener('click', schliessen);
    const knopf = inhalt.querySelector('.mmp-mf-senden');
    knopf.addEventListener('click', async () => {
      const res = await senden(knopf, `/mitfahrer/${encodeURIComponent(p.id)}/kontakt`, {
        nachricht: inhalt.querySelector('#mmp-mf-nachricht').value,
      });
      if (res) meldung('Gesendet', res.meldung);
    });
  }

  // Seite ohne Rückmelde-Parameter, damit Links aus Mails hierher zurückführen.
  function seitenAdresse() {
    const url = new URL(window.location.href);
    url.searchParams.delete('mitfahrer');
    url.hash = '';
    return url.toString();
  }

  // ─── Start ────────────────────────────────────────────────────────

  // Zurück aus einem Mail-Link (?mitfahrer=bestaetigt|angemeldet): Kategorie einblenden, begrüßen.
  const zurueck = new URLSearchParams(window.location.search).get('mitfahrer');
  if (zurueck) {
    if (window.history.replaceState) window.history.replaceState(null, '', seitenAdresse() + window.location.hash);
    gefragt = true;
    laden().then(() => {
      if (!k.istAn()) k.einschalten(); else zaehlerAktualisieren();
      meldung(
        zurueck === 'bestaetigt' ? 'Willkommen!' : 'Angemeldet',
        zurueck === 'bestaetigt'
          ? 'Dein Eintrag ist bestätigt und jetzt auf der Karte. Klick auf andere Punkte, um zu sehen, wer in deiner Nähe fährt.'
          : 'Du bist auf diesem Gerät angemeldet.'
      );
    });
  } else if (k.istAn()) {
    laden();
  }
}
