// Arka plan yenilemesi için ortak yardımcılar (onay + hediye ekranı).
// Amaç: sayfayı ya da listeyi baştan çizmeden, sunucudan gelen veriyle SADECE değişen kartları yerinde
// güncellemek; kaydırma konumu, arama kutusu, odak ve açık seçiciler bozulmasın.
// Kural: kişi verisi DOM'a sadece textContent ile girer (burada metin yok; kartı çağıran çizer).
(function () {
  'use strict';

  var openKey = {}; // scope -> açık konum kartının id'si

  var live = {
    /**
     * Zamanlayıcı: sekme görünürken her `ms`'de tick() çağırır, gizliyken durur; görünür olunca hemen bir kez çağırır.
     * `name` başına tek zamanlayıcı (yenilemesiz gezinmede script yeniden çalışınca eskisi kapanır).
     * `root` DOM'dan çıktıysa (başka sayfaya geçildi) kendini kapatır.
     */
    start: function (name, root, tick, ms) {
      var key = '__live_' + name;
      if (window[key]) window[key]();
      var timer = null;

      function alive() { return document.body.contains(root); }
      function stop() { if (timer) clearInterval(timer); timer = null; }
      function teardown() { stop(); document.removeEventListener('visibilitychange', onVis); window[key] = null; }
      function onVis() {
        if (!alive()) { teardown(); return; }
        if (document.visibilityState === 'visible') {
          tick();
          if (!timer) timer = setInterval(onTick, ms);
        } else {
          stop();
        }
      }
      function onTick() {
        if (!alive()) { teardown(); return; }
        if (document.visibilityState === 'visible') tick();
      }

      window[key] = teardown;
      document.addEventListener('visibilitychange', onVis);
      if (document.visibilityState === 'visible') timer = setInterval(onTick, ms);
      return teardown;
    },

    /**
     * Açılır konum satırı (onay + hediye kartı): satıra dokununca kart YERİNDE aşağı açılır (modal yok),
     * aynı anda (scope başına) tek kart açık kalır. Açık kartın kimliği burada tutulur: kart arka plan
     * yenilemesiyle yeniden çizilse de açık kalır.
     * @param o.scope   'onay' | 'hediye'
     * @param o.id      kart kimliği
     * @param o.text    satırdaki kısa metin ("Kemer / Beldibi Mah."); o.title tam metin
     * @param o.lines   [[etiket, değer], ...] açılan kısımdaki ek bilgiler (yetkili, firma, sicil)
     * @param o.places  [{label, address}] adresler; boşsa "Adres kayıtlı değil" ve Yol tarifi butonu gizli
     * @return {btn, panel} ikisi de kartın içine eklenir
     */
    place: function (o) {
      var uid = 'place-' + o.scope + '-' + o.id;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'place-btn';
      btn.setAttribute('data-scope', o.scope);
      btn.setAttribute('aria-controls', uid);
      if (o.title) btn.title = o.title;
      var text = document.createElement('span');
      text.className = 'place-text';
      text.textContent = o.text;
      var caret = document.createElement('span');
      caret.className = 'place-caret';
      caret.setAttribute('aria-hidden', 'true');
      caret.textContent = '\u25BE';
      btn.appendChild(text);
      btn.appendChild(caret);

      var panel = document.createElement('div');
      panel.className = 'place-panel';
      panel.id = uid;
      (o.lines || []).forEach(function (l) {
        if (!l[1]) return;
        var d = document.createElement('div');
        d.className = 'place-line';
        var k = document.createElement('strong');
        k.textContent = l[0] + ': ';
        d.appendChild(k);
        d.appendChild(document.createTextNode(l[1]));
        panel.appendChild(d);
      });
      var places = o.places || [];
      places.forEach(function (pl) {
        var box = document.createElement('div');
        box.className = 'place-item';
        if (places.length > 1 && pl.label) {
          var lb = document.createElement('div');
          lb.className = 'place-label';
          lb.textContent = pl.label;
          box.appendChild(lb);
        }
        var ad = document.createElement('div');
        ad.className = 'place-addr';
        ad.textContent = pl.address; // seçilebilir metin
        box.appendChild(ad);
        var a = document.createElement('a');
        a.className = 'btn btn-route';
        a.href = 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(pl.address);
        a.target = '_blank';
        a.rel = 'noopener';
        a.setAttribute('aria-label', 'Yol tarifi: ' + pl.address);
        a.textContent = '\uD83D\uDCCD Yol tarifi';
        box.appendChild(a);
        panel.appendChild(box);
      });
      if (!places.length) {
        var none = document.createElement('div');
        none.className = 'place-none';
        none.textContent = 'Adres kayıtlı değil';
        panel.appendChild(none);
      }

      function setOpen(b, p, on) {
        b.setAttribute('aria-expanded', on ? 'true' : 'false');
        p.hidden = !on;
      }
      var open = openKey[o.scope] === o.id;
      setOpen(btn, panel, open);
      btn.addEventListener('click', function () {
        var on = btn.getAttribute('aria-expanded') !== 'true';
        if (on) {
          // aynı anda tek kart açık: diğerini kapat
          Array.prototype.forEach.call(document.querySelectorAll('.place-btn[data-scope="' + o.scope + '"][aria-expanded="true"]'), function (other) {
            var op = document.getElementById(other.getAttribute('aria-controls'));
            if (op) setOpen(other, op, false);
          });
        }
        setOpen(btn, panel, on);
        openKey[o.scope] = on ? o.id : null;
      });
      return { btn: btn, panel: panel };
    },

    /** Kartın içeriği değişti mi bilmek için imza (JSON). Kartı çizen fonksiyon node.__sig'i bununla doldurur. */
    sign: function (node, item) { node.__sig = JSON.stringify(item); return node; },

    /**
     * Listeyi sunucudaki sırayla eşitler. Değişmeyen kartlara (imza aynı) DOKUNMAZ.
     * @param list     kapsayıcı
     * @param items    sunucudan gelen öğeler (her birinde id)
     * @param o.build  item -> yeni kart düğümü (node.__sig'i kendisi doldurur)
     * @param o.sel    kart seçicisi, ör. '.p-card'
     * @param o.patchOnly  true: eklenme/silinme/yeniden sıralama yok, sadece var olan kartları güncelle
     * @param o.carry  (eski, yeni) -> açık <details> gibi küçük arayüz durumunu taşır
     * @param o.keep   düğüm -> true ise sunucu listesinde olmasa da SİLİNMEZ (kullanıcının az önce işlem yaptığı
     *                 kart filtreye artık uymasa bile parmağının altından kaybolmasın; sonraki aramada temizlenir)
     * Odak kartın içindeyse (açık seçici / yazılan alan) o karta dokunulmaz.
     */
    reconcile: function (list, items, o) {
      var active = document.activeElement;
      var busy = active && list.contains(active) ? active.closest(o.sel) : null;
      // Konum satırı düğmesi odakta olsa da kart güncellenebilir: açık/kapalı durum yeniden çizimde korunur
      if (busy && active.classList.contains('place-btn')) busy = null;

      var existing = {};
      Array.prototype.forEach.call(list.querySelectorAll(o.sel), function (n) { existing[n.getAttribute('data-id')] = n; });

      var seen = {};
      var prev = null;
      items.forEach(function (item) {
        var id = String(item.id);
        seen[id] = true;
        var node = existing[id];
        var sig = JSON.stringify(item);
        if (node && node.__sig !== sig && node !== busy) {
          var fresh = o.build(item);
          if (o.carry) o.carry(node, fresh);
          node.replaceWith(fresh);
          node = fresh;
        } else if (!node) {
          if (o.patchOnly) return;
          node = o.build(item);
        }
        if (!o.patchOnly && node !== busy) {
          var want = prev ? prev.nextSibling : list.firstChild;
          if (node !== want) list.insertBefore(node, want);
        }
        prev = node;
      });

      if (!o.patchOnly) {
        Object.keys(existing).forEach(function (id) {
          var n = existing[id];
          if (!seen[id] && n !== busy && n.parentNode === list && !(o.keep && o.keep(n))) n.remove();
        });
      }
    }
  };

  window.secimLive = live;
})();
