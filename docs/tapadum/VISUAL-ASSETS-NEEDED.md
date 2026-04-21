# Tapadum — Modernizasyon İçin Eksik Görsel Listesi

**Tarih:** 2026-04-19
**Bağlam:** [TAPADUM-UI-MODERNIZATION.md](TAPADUM-UI-MODERNIZATION.md) — Faz 1-3 görsel envanteri
**Mevcut durum:** 2511 medya öğesi + 131 ürün var (ürün fotoğrafları büyük oranda mevcut), ancak **brand storytelling, lifestyle, hero, ambient** görselleri yok.

---

## TL;DR

| Kategori | Eksik adet | Aciliyet | Bütçe tahmini (€) |
|---|---|---|---|
| **A. Hero & Anasayfa** | 8-10 | 🔴 Kritik | 800-1500 (1 fotoğraf çekimi günü) |
| **B. Kategori banner'ları** | 11 ana + 30+ alt | 🔴 Kritik | 1500-2500 (2 çekim günü) |
| **C. Atölye / Brand Story** | 15-20 | 🟠 Yüksek | 1500-2500 (1 çekim günü) |
| **D. Sanatçı / Lifestyle** | 10-15 | 🟠 Yüksek | 2000-4000 (model + 1 çekim günü) |
| **E. Trust & Sertifika** | 6-8 ikon | 🟡 Orta | 100-200 (icon set) |
| **F. Video içerik** | 5-8 video | 🟢 Faz 3 | 3000-6000 (videocu + edit) |
| **G. Ürün gallery iyileştirme** | ~131 ürün × 2-3 ek | 🟡 Orta | 2000-4000 (mevcut ürünleri yeniden çek) |
| **H. UI ikon seti** | ~30 SVG | 🟢 Düşük | Ücretsiz (Phosphor/Lucide) |
| **TOPLAM** | — | — | **~10,000-20,000 €** |

---

## A. Hero & Anasayfa Görselleri (🔴 Kritik — Faz 1)

Anasayfa şu an "Tapadum Birthday Discounts" promo banner'ıyla açılıyor — duygusal hero yok.

| # | Görsel | Boyut | Kullanım | Brief |
|---|---|---|---|---|
| A1 | **Ana hero** — yakın çekim oud + sanatçının elleri, sıcak ışık | 2400×1200 (desktop) + 1080×1350 (mobile crop) | Hero section, CTA: "Discover handpans crafted by master luthiers" | Şallak değil, "boutique premium" hissi. Doğal ışık, nötr arkaplan, sığ alan derinliği |
| A2 | **Hero alternatif 2** — atölye tezgahında yarım bitmiş bir handpan + araçlar | 2400×1200 | Rotating hero / kampanya değişimi için | Hammer + skala notları + ahşap masa. Storytelling: "Hand-crafted in our Italian workshop" |
| A3 | **Hero alternatif 3** — sahnede live performans (silüet, spotlight) | 2400×1200 | "Tapadum at festivals" hikaye akışı | Konser/festival çekimi. Hak/lisans önemli — kendi etkinlik fotoğrafı tercih |
| A4 | **Kategori showcase 6'lı grid** — Percussions, Strings, Bowed, Winds, Ouds, Accessories | 6 × 800×1000 | Anasayfa kategori cards bölümü | Her kategori için: o kategorinin temsilcisi enstrüman, **siyah/koyu arkaplan + drama ışık**, tek ton renk paleti |
| A5 | **Promo banner — "Tapadum Customs"** | 1920×600 | Custom sipariş CTA bölümü | Sanatçının özel sipariş bir oud çizimini incelediği veya luthier'in kalem-kağıt eskizi |
| A6 | **Promo banner — "Music Academy"** | 1920×600 | Akademi CTA | Online ders ekran görüntüsü VEYA sınıfta enstrüman çalan öğrenci |
| A7 | **Promo banner — "50% OFF Oud Cases"** | 1920×600 | İndirim banner'ı (mevcut placeholder yerine) | Açık bir oud case'inin içinde oud + aksesuar düzeni — flat lay |
| A8 | **Newsletter signup arkaplan** | 1920×500 | Footer üstü email yakalama | Çalan parmaklar + yumuşak bokeh — "Stay tuned" mesajına uygun atmosferik |

---

## B. Kategori Banner'ları (🔴 Kritik — Faz 1-2)

Her kategori sayfası şu an banner'sız açılıyor. **11 ana kategori + 30+ alt kategori** var.

### B.1 Ana kategoriler (her biri 1920×500 hero banner)

| # | Kategori | Tema önerisi |
|---|---|---|
| B1 | Percussions | Çoklu darbuka + handpan grid'i, üstten çekim, sıcak ahşap zemin |
| B2 | String Instruments | Oud + saz + setar yan yana, ışık enstrümanların oyma detaylarını vurguluyor |
| B3 | Bowed | Kemençe + kamancheh + tanbur — ahşap ve yay birlikte |
| B4 | Winds | Ney + duduk + zurna — siyah arkaplan, dikey kompozisyon |
| B5 | Ouds (Arabic & Turkish) | Tek bir master oud, yakın çekim, oyma detay |
| B6 | Accessories | Mızrap + tel + case + tuner — flat lay, organize |

### B.2 Yüksek-trafik alt kategoriler (her biri 1600×400)

| # | Slug (mevcut sayfa) | Görsel önerisi |
|---|---|---|
| B7 | `/cajon/` (sayfa: 9311) | Cajon + perküsyonist oturumu |
| B8 | `/shaman-drums/` (9318) | Frame drum + ritüel atmosfer |
| B9 | `/handpan/` | Handpan + parmaklar + drama ışık |
| B10 | `/santur-accessories/` (9267) | Santur tokmakları + santur teli detay |
| B11 | `/oud-accessories/` (9260) | Mızrap + oud teli + bilgili düzenleme |
| B12 | `/saz-baglama-accessories/` (9274) | Saz tezene + perde + tel |
| B13 | `/ney/` (9138) | Ney + nefes detayı (mum dumanı vb. atmosferik) |
| B14 | `/duduk/` (9166) | Ermeni duduk + dramatik ışık |
| B15 | `/mey/` (9173) | Mey + ahşap zemin |
| B16 | `/kaval/` (9244) | Kaval + manzara (Anadolu hissi) |
| B17 | `/zurna/` (9253) | Zurna + halay/düğün atmosferi |
| B18 | `/tanbur/` (9116) | Tanbur + uzun yatay kompozisyon |
| B19 | `/bowed-tanbur/` (9123) | Yaylı tanbur + yay detay |
| B20 | `/persian-kamancheh/` (9131) | Kamancheh + İran motif arkaplan |
| B21 | `/black-sea-kemence/` (9109) | Karadeniz kemence + deniz/dağ atmosferi |

**Not:** Her banner'ın 4 dile uygun **boş metin alanı** olmalı (CSS overlay ile başlık eklenecek — görsele text gömme).

---

## C. Atölye / Brand Story (🟠 Yüksek — Faz 3)

About sayfasında 4 ekip üyesi (Özgür Yalçın, Gürkan Özkan, Volkan İncüvez, Serkan Sarıoğlu) **placeholder** olarak duruyor.

| # | Görsel | Boyut | Kullanım |
|---|---|---|---|
| C1 | **Özgür Yalçın portrait** (founder) | 800×1000 (4:5) | About hero + footer "founder note" |
| C2 | **Gürkan Özkan** (Percussion Manager) — perküsyonla | 800×1000 | About team grid |
| C3 | **Volkan İncüvez** (Woodwinds Manager) — ney/duduk çalarken | 800×1000 | About team grid |
| C4 | **Serkan Sarıoğlu** (String Manager) — oud/saz tezgahında | 800×1000 | About team grid |
| C5 | **Atölye genel çekim** — uzun pano | 2400×1000 | About hero + brand video poster |
| C6 | **Luthier elleri çalışırken** | 1600×1000 | Storytelling section |
| C7 | **Ahşap kesim / oyma close-up** | 1200×1200 | "Our craft" bölümü |
| C8 | **Tezgahta araç düzeni** (flat lay — keski, eğe, ölçü) | 1600×1000 | Atölye bölümü |
| C9 | **Yarım bitmiş enstrüman serisi** | 1600×1000 | "Process" anlatımı |
| C10 | **Boya / cila aşaması** | 1200×1500 | Üretim süreci infographic |
| C11 | **Mağaza/showroom giriş** (İtalya) | 1600×1000 | "Visit us" bölümü |
| C12 | **Showroom iç mekan** — duvarda asılı enstrümanlar | 2400×1000 | Atölye + harita yanı |
| C13 | **Founder + ekip grup fotoğrafı** | 2400×1200 | About hero alternatif |
| C14 | **Atölyede günlük yaşam** (3-5 candid) | 1200×1500 | Instagram feed + about scrollytelling |

---

## D. Sanatçı / Lifestyle Çekimleri (🟠 Yüksek — Faz 3)

Premium algı için "enstrüman + insan" görselleri kritik. Stüdyo flat lay yetmez.

| # | Görsel | Boyut | Kullanım |
|---|---|---|---|
| D1 | **Müzisyen oud çalıyor** (oturmuş, doğal ışık) | 1600×2000 | Oud kategori + ürün lifestyle |
| D2 | **Müzisyen handpan çalıyor** (açık hava VEYA stüdyo) | 1600×2000 | Handpan kategori + featured product |
| D3 | **Müzisyen darbuka, ritim halinde** | 1600×2000 | Percussions kategori |
| D4 | **Ney çalan müzisyen** (silüet + ışık) | 1600×2000 | Winds kategori (sufistik atmosfer) |
| D5 | **Kamancheh sanatçısı** (oturmuş, klasik İran ortamı) | 1600×2000 | Bowed kategori |
| D6 | **Sahne — solo** | 2400×1600 | Hero alternatif + booking sayfası |
| D7 | **Stüdyo kayıt** — mikrofon + enstrüman | 1600×2000 | "Recording artists love us" testimonial |
| D8 | **Çocuk + öğretmen** (ders sahnesi) | 1600×2000 | Music Academy sayfa |
| D9 | **Festival kalabalığı** (silüet) | 2400×1200 | "Tapadum at events" booking page |
| D10 | **Ürün el detay** — mızrap teli çekiyor | 1200×1500 | Product page lifestyle eki |

**Not:** Model freelance fotoğrafçı + müzisyen anlaşması gerekli (model release sözleşmesi).

---

## E. Trust & Sertifika İkonları (🟡 Orta — Faz 1)

Mevcut 4 trust badge text-only render ediliyor ("Free Shipping" vb. — ikon yok).

| # | İkon | Format | Renk | Kullanım |
|---|---|---|---|---|
| E1 | **Free Shipping Europe** ikonu (kamyon + Avrupa) | SVG, 64×64 | Brand primary | Trust strip |
| E2 | **15-Day Warranty** (kalkan + 15) | SVG, 64×64 | Brand primary | Trust strip |
| E3 | **Global Shipping** (dünya + ok) | SVG, 64×64 | Brand primary | Trust strip |
| E4 | **100% Secure Checkout** (kilit + ✓) | SVG, 64×64 | Brand primary | Trust strip + checkout |
| E5 | **Handcrafted in Italy** (made-in-italy bayrak veya el) | SVG, 64×64 | Brand primary | Product page + about |
| E6 | **Master Luthier** (alet + el) | SVG, 64×64 | Brand primary | Product page |
| E7 | **Authentic Materials** (ahşap doku) | SVG, 64×64 | Brand primary | Product page |
| E8 | **24/7 WhatsApp Support** | SVG, 64×64 | WhatsApp green | Sticky chat + footer |

**Kaynak önerisi:** Phosphor Icons veya Lucide (ücretsiz, 1000+ ikon, MIT lisans). Custom 4 ikon için designer brief.

---

## F. Video İçerikler (🟢 Faz 3 — Premium katman)

| # | Video | Süre | Kullanım |
|---|---|---|---|
| F1 | **Brand video** — atölye + ekip + enstrüman + sanatçı (montaj) | 60-90s | Anasayfa hero (autoplay muted loop) |
| F2 | **Atölye işleyiş** — handpan tuning süreci | 30-45s | Handpan kategori sayfası |
| F3 | **Master luthier interview** — Özgür Yalçın hikayesi | 90-120s | About sayfası |
| F4 | **Ürün demo** — handpan + oud + saz × 3 video | 15-20s × 3 | Ürün galerisinde "Hear it" tab |
| F5 | **"How to choose your first oud"** | 3-5dk | Blog video makale eki |
| F6 | **Music Academy preview** — ders kesiti | 60s | Academy sayfa |
| F7 | **Customer testimonial** — sanatçı × 2 | 30-60s × 2 | Anasayfa social proof |
| F8 | **360° ürün rotation** — top 10 ürün | 8-10s × 10 | Ürün galerisinde |

**Not:** Brand video (F1) en kritik. Diğerleri Faz 3 ilerlemesine göre.

---

## G. Ürün Galerisi İyileştirmesi (🟡 Orta — Faz 2)

Mevcut 131 ürünün çoğunda 1 ana görsel var. Modern e-ticaret beklentisi: **3-5 görsel + 1 video + 360°** (en az top sellers için).

| Öncelik | Ürün grubu | Görsel ihtiyacı / ürün |
|---|---|---|
| **Top 20 bestseller** | Tapadum Customs, Pro Handpan, Master Oud | 5 foto + 1 video + 360° |
| **Top 50** | Tüm ana kategori başı | 4 foto (ön/yan/arka/detay) + 1 lifestyle |
| **Geri kalan 81** | Standart ürünler | Mevcudu koruma, alt text iyileştirme |

**Yaklaşım:** Mevcut 2511 medyada belki bazı ürünlerin ek fotoğrafları zaten var ama ürüne attach edilmemiş. Önce **medya envanter audit** (LuwiPress AI ile orphan media + ürün eşleştirme) önerilir.

---

## H. UI Icon Seti (🟢 Düşük — Faz 1)

Header/footer/UI ikonları için tutarlı bir set:

| Kullanım | Adet | Set önerisi |
|---|---|---|
| Search, cart, user, wishlist, hamburger, close | 6 | Phosphor (regular weight) |
| Sosyal medya — IG, FB, YT, WhatsApp, TikTok, Spotify | 6 | Phosphor (fill weight) |
| Ürün kartı — quick view, add to cart, wishlist, compare | 4 | Phosphor (regular) |
| Filtre — fiyat, kategori, marka, materyal, rating | 5 | Phosphor (regular) |
| Sayfa içi — chevron, arrow, check, star, info, warning | 6 | Phosphor (regular) |
| Premium custom — luthier hand, instrument family, etc. | 3-5 | Custom designer |

**Toplam:** ~30 SVG, hepsi `--lp-icon-color` ile renklendirilebilir tek dosya.

---

## Üretim Stratejisi

### Faz 1 başlangıcı için minimum (1 hafta içi)

Sıfır bütçe + hızlı çıkmak için:

1. **A1 hero** → Mevcut 2511 medyadan en kaliteli oud/handpan close-up'ını seç, hero olarak yeniden tasarla
2. **A4 kategori 6'lı** → Mevcut ürün fotoğraflarından 6 temsilci seç + Photoshop'ta tutarlı arkaplan/ışık
3. **B.1 ana kategori banner'ları** → Aynı yaklaşım, mevcut görsellerden derleme
4. **E.1-E.4 trust ikonları** → Phosphor Icons'tan SVG indir + brand renge boya
5. **H. UI ikon seti** → Phosphor full set, hepsi tek SVG sprite

**Sonuç:** ~3-4 günlük design work, 0 € fotoğraf çekim maliyeti, anında modern görünüm.

### Faz 2-3 için profesyonel çekim (3-6 hafta içi)

Bütçe açıldıktan sonra:

1. **1 atölye günü** (C1-C14) → Founder + ekip + atölye + ürün process → 1500-2500 €
2. **1 lifestyle günü** (D1-D10) → Stüdyoda model + enstrüman çekimleri → 2000-4000 €
3. **1 video günü** (F1, F3, F4) → Brand video + atölye + 3 ürün demo → 3000-5000 €

**Toplam profesyonel çekim:** 6500-11500 €

### Süreç önerisi

1. **Brand brief** (1 gün) — Müşteriyle: tonalite, renk, stil moodboard
2. **Photographer/videographer brief** (1-2 gün) — Shot list, locations
3. **Çekim** (3 gün, dağıtık) — Atölye, lifestyle, video
4. **Post-production** (1-2 hafta) — Renk düzenleme, retuş, video edit
5. **Asset upload + WP medya organizasyonu** (2-3 gün) — alt text, organize folders, attach to products/categories
6. **LuwiPress AI** ile alt text + caption batch generation (otomatik)

---

## Müşteri Onayı Gereken Stratejik Kararlar

1. **Bütçe** — Profesyonel çekim için 6500-11500 € ayrılabilir mi? Yoksa Faz 1 minimum (mevcut görsel + Photoshop) ile mi devam edelim?
2. **Tonalite** — "Boutique premium" (sıcak ahşap, doğal ışık) mı, "Modern global" (siyah arkaplan, dramatik) mı, "Authentic ethnic" (kültürel motif) mi? Bu seçim tüm asset'lerin moodboard'unu belirler
3. **Atölye erişimi** — İtalya'daki atölye/showroom çekim yapılabilir mi, yoksa stok/AI üretim mi?
4. **Modeller** — Müzisyen modelleri için Tapadum'un mevcut sanatçı ağı kullanılabilir mi, freelance mi tutulacak?
5. **AI üretim** — Mid-journey/DALL-E ile **moodboard ve geçici asset** üretilsin mi? (Hızlı ama "AI look" riski var, premium algıyla çakışabilir)

---

## Sonraki Adım

Bu liste onaylanırsa:

1. Faz 1 minimum üretim için **mevcut medya envanter audit** çalıştır → kullanılabilir ham görselleri belirle
2. Müşteriden brand brief al (tonalite + renk + 5-10 referans görsel)
3. Eksik kategorileri AI moodboard'la doldur (geçici, müşteriye sunum için)
4. Bütçe onayı sonrası fotoğrafçı brief'i hazırla
5. Çekim takvimi ve Faz 2-3 başlangıç tarihi belirle

**Hazırlayan:** Claude (Opus 4.7) — LuwiPress geliştirme ortağı
