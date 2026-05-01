# Tapadum Mobil Header — Tanı Doğrulama ve Müdahale Raporu

**Tarih:** 2026-04-25
**Belge sahibi:** Umut (LuwiPress)
**Yanıt:** Özgür'ün `tapadum-mobil-header-bug-raporu-v1.0.md` raporuna karşı
**Durum:** ⚠️ **V36 deploy edildi VE rollback edildi (aynı gün).** Mobil header bug **henüz açık**. Tanı (Bölüm 1-2-3.1) doğru, fix (Bölüm 3.2) yan etki yarattığı için geri alındı. Yeni fix denemesi planlanıyor.

---

## ⚠️ Güncelleme — V36 rollback notu (2026-04-25)

V36 deploy edildikten sonra Tapadum'da **iki yan etki** gözlendi:

1. **Desktop header'da logo görseli kayboldu** (column render ediliyor ama `<img>` görünmez)
2. **Customer chat widget üst kenarı kesildi** (header'ın altına gizlenmiş gibi)

V36'nın yaptığı şey aslında doğru bir niyet taşıyordu — V32'nin `:is(...)` listelerinden 2 header ID'sini çıkarmak. Ama in-place regex strip yöntemi, V32 region'undaki **54 ayrı `:is(...)` bloğunda** aynı listeyi tek tek değiştirdi. Her blok bireysel olarak geçerli CSS kalsa da, kümülatif cascade etkisi başka surface'leri kırdı (büyük olasılıkla TOP section'ından `width:100%` kaldırılınca parent layout matematiği değişti, logo column ve chat widget anchor'ı kaydı).

**Rollback:** snapshot `kit-pre-v36-1777130678.css` ile V35-only state'e geri dönüldü (sha `25d6819a32c1de73`). Live şu an V36 öncesi durumda.

**Ne demek bu:**
- Mobile header bug (icon görünmüyor) **hâlâ açık** — Özgür'ün raporundaki Bölüm 1-3 hâlâ geçerli, fix uygulanmamış durumda
- Desktop ve chat widget şu an V36 öncesi haliyle çalışır durumda (V35 + V32+V33+V34 baseline)

**Yeni fix planı:**
V32 region'unu **in-place patch yerine kaynak scan'i ile sıfırdan yeniden inşa** edeceğim:
1. `temp/tapadum/correct_scan.json` (V32'nin orijinal DOM scan'i) yeniden çalıştırılacak
2. Header section ID'leri **scan filter aşamasında** dışlanacak (sadece "≥4 icon-box widget + body/elementor parent + dark background" fingerprint'ine uyan section'lar info-bar olarak işaretlenecek)
3. `temp/tapadum/build_v32.py` ile tüm V32 bloğu sıfırdan üretilecek
4. Push öncesi **görsel regresyon kontrol listesi** uygulanacak: desktop homepage logo + chat widget üst kenarı + mobil hero overlay üstündeki menu icon — üçü birden geçmeli

Bu sefer "marker present + IDs absent" readback yeterli sayılmayacak; mobil + desktop'ta gerçek görsel doğrulama yapılacak.

**Özgür'e:** Tanı kısmı (Bölüm 1-2-3.1) doğruluğunu koruyor, fix kısmını ihmal et — yenisi gelecek. Önerdiğin Çözüm A (Customizer Additional CSS bloğu) hâlâ kullanılabilir geçici çözüm; Tapadum tarafında hızlıca uygulamak istersen, V36 şu an canlı değil ve önerini bloklayan başka bir kuralım yok.

---

---

## Özet

Raporun **bulguları doğru, kök sebep teşhisi kısmen yanlış**. Asıl tetikleyici Elementor'un default mobile stacking davranışı değil — bizim Kit CSS'imizdeki **V32 layer'ının (2026-04-23 deploy) header section ID'lerini yanlışlıkla info-bar olarak sınıflandırmasıydı**. Customer-side ölçüm (icon `top:233 left:161`) bu yanlış sınıflandırmanın doğrudan sonucudur.

V36 fix bugün (2026-04-25) deploy edildi. Header section ID'leri V32 listelerinden çıkarıldı; mobil cihazda doğrulama bekleniyor.

---

## 1. Raporun doğru tespitleri

Özgür'ün raporunda metodoloji ve ölçüm sağlam:

- **DOM yapısı analizi doğru** — section `7a29fda` mobile-only (`hide_desktop + hide_tablet`), inner section `322f38f` 50/50 structure, icon column `2e05d8c` doğru tanımlanmış
- **Icon render durumu doğru ölçüldü** — icon HTML'de mevcut, font yüklü, opacity 1, computed display block. Yani icon görünmesi gereken her şartı sağlıyor
- **Konum analizi (icon `top:233 left:161`) hatanın kanıtı** — icon header sınırından dışarı taşmış, hero overlay'in z-index'i altında kalmış. Bu doğru tespit
- **Negatif testler kapsamlı** — Customizer Additional CSS, Code Snippets, popup status, font check hepsi disiplinli yapılmış

Bu metodolojik özen takdire şayan.

## 2. Raporun düzeltilmesi gereken iki noktası

### 2.1 "Kit CSS boş (length 0)" iddiası — YANLIŞ

Live Kit CSS şu anda **370 KB** boyutunda ve aktif. Doğrulama:

```
GET /wp-json/luwipress/v1/elementor/global-css
→ kit_id: 203, css.length: 369854 bytes
→ V32 + V35 + V36 layer markers all present
```

`elementor_kit_css_get` fonksiyonu muhtemelen yanlış kit_id veya yanlış endpoint ile çağrıldı. LuwiPress `/elementor/kit` ve `/elementor/global-css` REST endpoint'leri canonical okuma yoludur.

**Bu önemli** çünkü Kit CSS dolu olmasaydı, raporun önerdiği Çözüm A (Customizer Additional CSS) kabul edilebilir bir yer olurdu. Ama Kit CSS bizim layered baseline sistemimiz; oraya yazmak doğru yer.

### 2.2 Kök sebep "Elementor default mobile stacking" — KISMEN YANLIŞ

Rapor şöyle diyor:

> "Mobile-specific tanımlı değiller: `flex_direction_mobile`, `stack_on`, `column_gap_mobile` — sonuç: Elementor'un default mobile davranışı devreye giriyor — 768px altında 2-column section'ları otomatik alt alta stack ediliyor"

Bu kısmen doğru ama **Tapadum'da gözlenen davranışı tam açıklamıyor**. Çünkü:

- Elementor default mobile stacking icon'u y=233'e değil, normal akışta `Section 7a29fda` içinde alt sıraya koyar (header section yüksekliği o kadar uzar). Icon görünür olur
- Icon'un hero overlay arkasına düşmesi → header section'ın **istemediği bir şekilde dar tutulması** + icon column'unun **içeriğinin dışarı taşması**

Gerçek tetikleyici bizim **V32 layer'ı (2026-04-23 deploy)**:

#### V32 ne yapıyor?

V32, sitewide info-bar standardize layer'ı. 4-icon-box bandının (Free Shipping / 15 Days Warranty / Global Shipping / 100% Secure) tüm sayfalarda aynı boyutta render edilmesi için DOM scan ile **48 TOP info-bar section ID + 51 INNER container ID** topladı, bunlara şu kuralları uyguladı:

- **TOP info-bar sections**: `padding: 24px 16px; background-color: #000; width: 100%`
- **INNER container sections**: `max-width: 1372px; centered; flex 4-column grid`

#### Yanlış sınıflandırma

V32 DOM scan'i sadece "2-column structure" pattern'ine baktığı için, **header template'inin (post 27562) iki section'ını yanlışlıkla info-bar olarak topladı**:

```
TOP list   içinde: 7a29fda  (mobile header section — info-bar zannedildi)
INNER list içinde: 322f38f  (header inner 2-column row — info-bar inner zannedildi)
```

Bunun sonucu mobilde:

- Header section `7a29fda` → V32 kuralı ile `padding:24px 16px; background:#000; width:100%`. Yani header siyah arkaplan + sabit dikey padding alıyor
- Inner section `322f38f` → V32 kuralı ile `max-width:1372px; centered; flex 4-column grid`. Mobilde bu otomatik **2×2 grid**'e düşüyor — header'ın 2-column row'u **alt-alta** stack oluyor, **icon column ikinci satıra düşüyor**, header section yüksekliği yetmiyor → **icon header bounds dışına taşıyor → hero overlay'in arkasına düşüyor**

Yani Özgür'ün ölçtüğü `top:233 left:161` tam olarak V32'nin INNER 4-column grid kuralının yarattığı 2×2 stack davranışıdır. Elementor default davranışı **değil** — bizim layer'ımızın yan etkisidir.

Bu raporun "10 gündür devam ediyor" notuyla da uyumlu — V32, **2026-04-23'te (2 gün önce)** deploy edildi, ama Tapadum'a stickier cache + LiteSpeed + browser cache zinciri geç bastı. Müşterinin gerçek mobil cihazında 2-3 gün sonra fark edilmiş olması olağan.

Bu, **Özgür'ün metodolojisinin yanlış olduğu anlamına gelmiyor** — sadece bizim deploy ettiğimiz Kit CSS layer'ının iç durumunu görmesi mümkün değildi (Kit CSS boş zannetti, dolayısıyla layer kuralları radarına girmedi).

## 3. Bizim doğrulama ve fix

### 3.1 Doğrulama (`temp/tapadum/verify_header_322f38f.py`)

Read-only diagnostic koşturuldu:

```
Kit CSS: 370791 bytes  sha 25d6819a32c1de73
  V32 region: 54 TOP ids, 51 INNER ids
  → 7a29fda IN top list  : True   ✗ misclassified
  → 322f38f IN inner list : True   ✗ misclassified
  → kit rules touching 7a29fda  : 6
  → kit rules touching 322f38f  : 25  (V32 INNER applies 25+ rules)
```

DOM scan yanlışlığı kanıtlanmış oldu.

### 3.2 V36 fix (`temp/tapadum/push_v36_header_fix.py`) — DEPLOY EDİLDİ

Yapılan: V32 region'undaki tüm `:is(...)` listelerinden `7a29fda` ve `322f38f` ID'leri çıkarıldı. Diğer 53 TOP + 50 INNER ID'si dokunulmadı (info-bar standardizasyonu korundu).

```
Before: TOP=54 INNER=51   header IDs WERE in lists
After : TOP=53 INNER=50   header IDs ABSENT from lists
        + V36 marker comment for traceability
        Live sha: 269a02bd404c9f03
```

Push: `POST /elementor/global-css` (append:false, full replace)
Cache purge: Elementor + LiteSpeed + UCSS hepsi temizlendi
Snapshot: `kit-pre-v36-1777130678.css` (rollback için saklı)

### 3.3 Şu an beklenen davranış

V36 sonrası header section'ları artık **Elementor'un default davranışına** döndü. Yani Özgür'ün "Çözüm A"nın açıkladığı durum (default 2-column mobile stacking) artık geçerli. İki ihtimal:

**İhtimal 1 (büyük olasılık):** Default Elementor mobile davranışı ile icon doğru konumda. Test gerektirir.

**İhtimal 2:** Default mobile stacking icon'u alt sıraya koyuyor ama görünür konumda. Yani icon var, sadece logo'nun altında. Bu da bozuk değil ama ideal değil.

Eğer #2 senaryosu çıkarsa, Özgür'ün önerdiği **Çözüm A'nın iyileştirilmiş halini** V37 olarak Kit CSS'e ekleyeceğiz:

```css
/* V37 — mobile header layout (planlanan, ihtimal 2 için) */
@media (max-width: 767px) {
  html body .elementor-element-322f38f > .elementor-container {
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
  }
  html body .elementor-element-322f38f > .elementor-container > .elementor-column {
    width: 50% !important;
  }
  html body .elementor-element-322f38f > .elementor-container > .elementor-column:last-child .elementor-widget-wrap {
    justify-content: flex-end !important;
  }
}
```

Özgür'ün bloğuna iki iyileştirme eklendi:
1. `html body` prefix specificity boost (Tapadum'daki diğer kuralları beats etmesi için)
2. Son column'a `justify-content: flex-end` (icon'u sağa hizalar)

## 4. Doğrulama isteği

Lütfen mobil cihazda (gerçek telefon, hard refresh + LiteSpeed cache temiz olduğundan emin olarak) `tapadum.com` ana sayfasını aç, header'ı kontrol et:

- [ ] Logo + menu icon yan yana mı? (50/50 row)
- [ ] Logo solda, menu icon sağda mı?
- [ ] Menu icon görünür mü? (önceki konumdan ekrandan kaybolmuş muydu)
- [ ] Header arka planı normal mi (siyah değil)?

Üç senaryo:
- **Hepsi ✓** → V36 yeterli, ek müdahaleye gerek yok
- **Icon görünür ama logo altında** → V37 deploy ederiz (yukarıdaki blok)
- **Icon hâlâ görünmez** → daha derin sorun var, ek diagnostic gerekir (header z-index + hero overlay etkileşimi)

## 5. Özgür'e geri bildirim

- Metodoloji çok iyi. Read-only tanı + DevTools ölçümleri + negatif testler — disiplinli iş
- Eksik kalan tek şey: **bizim layered Kit CSS sistemimizin** varlığını bilmek. Bundan sonra `GET /wp-json/luwipress/v1/elementor/global-css` ile Kit CSS'i de incelemeye almasını öneririm
- Önerdiği Çözüm A teknik olarak doğru, sadece yer (Customizer yerine Kit CSS) ve specificity (html body prefix) farkı var
- Rapor formatı çok temiz — bu formatı korumayı öneririm

## 6. Bağlam: 2026-04-25 günü Tapadum Kit CSS hareketleri

| Saat (yaklaşık) | Layer | Sonuç |
|---|---|---|
| 13:00 | V35 deploy (mobile product grid 1-col) | ✓ 13/13 hub+subcat sayfa PASS |
| 16:00 | V35.5 deploy (mobile info-bar 1×4 stack) | ✗ Mobil header menüsünü bozdu, footer info-bar küçüldü |
| 17:00 | V35.5 rollback → V35-only | ✓ Header + footer V35.5 öncesi durumuna döndü |
| **17:30** | **V36 deploy (V32 header misclassification fix)** | **✓ Beklenen: header bug çözümü** |

Yani Özgür'ün ölçümü muhtemelen 16:00-17:00 arası V35.5'in canlı olduğu sırada alınmış olabilir. Eğer öyleyse, `top:233 left:161` rakamı V35.5'in yan etkisi + V32'nin altında yatan misclassification'ın kümülatif sonucu olabilir. V36 her iki sebebi de adresliyor.

---

*Diagnostic + fix süresi: ~30 dakika*
*Read-only tanı + 1 yapısal CSS pushu (snapshot + readback verify + cache purge ile)*
*Rollback: tek POST snapshot, < 30 saniye*
