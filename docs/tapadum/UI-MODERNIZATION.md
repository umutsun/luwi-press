# Tapadum UI Modernization — Detaylı Analiz & Yol Haritası

**Tarih:** 2026-04-19
**Site:** tapadum.com (WooCommerce + Elementor + LuwiPress + WPML)
**Diller:** EN / IT / FR / ES
**Hedef segment:** Premium etnik perküsyon & telli enstrüman alıcısı (Avrupa odaklı)

---

## 1. Yönetici Özeti

Tapadum **fonksiyonel ama görsel olarak 2018-2020 dönemine takılı** bir WooCommerce mağazası. Güçlü yönleri var (çoklu dil, kategori derinliği, trust badge, WhatsApp entegrasyonu, blog), ancak modern bir e-ticaret kullanıcısının "premium enstrüman" beklentisini karşılamıyor. Üç ana sorun öne çıkıyor:

1. **Görsel anlatım eksik** — Hero alanı, kategori banner'ları, ürün galerileri zayıf veya yok. Premium enstrüman satışında video + zoom + 360° kritik.
2. **Discovery (keşif) zayıf** — Filtre yok, faceted search yok, ürün kartlarında rating/badge yok. Kullanıcı "ne aradığını bilmek zorunda".
3. **Conversion friction yüksek** — Cart sayfası boş halde demoralize edici, checkout'ta sticky CTA yok, mobilde sticky add-to-cart yok, abandonment recovery yok.

Bu üç eksen üzerinden 4 fazlı bir yol haritası öneriyorum: **Quick wins (1 hafta) → Discovery (2-3 hafta) → Premium UI (1 ay) → Conversion optimization (2-4 hafta)**.

---

## 2. Mevcut Durum — Sayfa Sayfa

### 2.1 Anasayfa (Homepage)

**Yapı (sırayla):**
1. Üst promosyon bandı ("Free Shipping Europe")
2. Header + 4 dilli switcher + hamburger
3. "Tapadum Birthday Discounts" promosyon banner'ı (zayıf hero)
4. NEW ARRIVALS — 12 ürünlük grid
5. Trust badges (4 adet: shipping, warranty, secure, global)
6. Kategori kartları (Percussions, String, vb. — 6 kart)
7. Promo bölümleri (50% Oud Cases, Clay Dabukas, Customs, Music Academy)
8. Customer Care
9. Footer

**Güçlü:**
- Trust signal'lar erken görünüyor (shipping, warranty, secure)
- Kategori derinliği menüde belli (String 13 alt, Percussions 10 alt)
- WhatsApp + Instagram + YouTube linkleri görünür
- 4 dilli switcher prominent

**Zayıf:**
- **Hero yok** — Sadece bir promo banner var. Premium bir oud/handpan satıcısı için duygusal bir hero görseli (atölye, sanatçı, yakın çekim enstrüman) zorunlu
- Placeholder SVG'ler yaygın (yavaş bağlantıda gerçek görseller geç yükleniyor → LCP kötü)
- Brand storytelling yok ("Why Tapadum?" "Our luthiers")
- Customer review/star widget yok (Trustpilot, Google Reviews entegrasyonu yok)
- "Shop the look" / curated collections yok
- Newsletter signup zayıf veya yok

### 2.2 Shop Arşivi (`/shop/`)

**Mevcut:**
- 4 sütun desktop grid
- Sort dropdown (popularity, rating, latest, price)
- Pagination (9 sayfa)
- Breadcrumb var

**Eksik (kritik):**
- **Filtre yok** — Fiyat aralığı yok, marka yok, malzeme yok, enstrüman tipi yok
- Quick view yok
- Wishlist yok
- Compare yok
- Hover state'i yok (ikinci görsel, hızlı ekle, vb.)
- Star rating ürün kartında görünmüyor
- "Beginner-friendly", "Pro-level" gibi etiket yok
- Yığınla ürün arasında **AI-powered recommendation** yok

### 2.3 Kategori Sayfaları (örn. `/product-category/percussions/`)

**Mevcut:**
- SEO açıklama metni var (10 enstrüman paragrafı — generic, AI üretimi gibi)
- Sort var
- 35 ürün için 3 sayfa

**Eksik:**
- **Kategori hero/banner yok** — Bir percussion kategorisi açan kullanıcı bir görsel çarpışmasıyla karşılaşmalı
- Subkategori chip'leri sayfada görünmüyor (sadece menüde — gereksiz tıklama)
- Filtre yok (tekrar)
- Cross-sell yok ("Looking at percussions? Customers also explored Bowed")
- Sale sayfa düzeni jenerik — "Under €100", "Best for beginners" gibi facet shortcut'ları yok

### 2.4 Ürün Sayfası (denenen URL'ler 404, ancak menüden tahmin)

**Tahmini eksikler (LuwiPress AEO'su buradan veri okur):**
- Image zoom yok ya da zayıf (lightbox eski)
- Video yok (yumuşak bir oud melodisi 30 saniye → conversion %15-30 artar)
- Sticky add-to-cart (mobil) muhtemelen yok
- FAQ schema (LuwiPress üretebiliyor ama eklenmiş mi belirsiz)
- "Bu ürünle birlikte alınanlar" var mı şüpheli
- Variation UI (eğer modeli/notası varsa) eski WC default

### 2.5 Cart / Checkout

- Boş cart sayfası "Your cart is currently empty" — **0 motivasyon, 0 öneri**
- Promo code field görünmüyor
- Shipping calculator yok
- Cross-sell yok
- Trust badge tekrar yok
- Sticky checkout button yok

### 2.6 Blog (`/blog/`)

**Mevcut:** Magazine grid, "Trending Today", kategori navigation, pagination
**Eksik:** Newsletter signup, related posts, author bio, reading time, social share, table of contents

### 2.7 Mobil

- Floating cart var (`floating_cart_xforwc` — eski plugin)
- Sticky add-to-cart yok
- Hamburger menü ile düzelttik (geçen session) ama header henüz **bottom navigation bar** yok (modern e-ticaret beklentisi)
- Touch target boyutları muhtemelen 44px altında bazı yerler

---

## 3. Modern Benchmark — Ne Olmalı

Premium enstrüman e-ticaretleri (Reverb, Thomann, Sweetwater) ve modern boutique (Bandcamp, MR-Online):

| Özellik | Tapadum şu an | Modern beklenti |
|---|---|---|
| Hero | Promo banner | Cinematik video / 4K görsel + USP |
| Ürün galerisi | Standart WC | Zoom + video + 360° + AR (premium ürünler) |
| Filtreler | Yok | Fiyat, marka, materyal, seviye, ülke (faceted) |
| Rating | Yok (kart) | Kart üzerinde ⭐ + count |
| Quick view | Yok | Modal + add-to-cart |
| Sticky CTA | Yok | Mobilde bottom-bar add-to-cart |
| Cart | Boş = ölü | Boş cart = öneri + recently viewed |
| Checkout | Standart WC | Tek sayfa + Apple Pay/PayPal Express |
| Search | Görünmez | AI-powered, autocomplete, "did you mean" |
| Personalization | Yok | "Based on your view" ribbon |
| Trust | 4 badge | Trustpilot widget + review video + ülke bayrak sayısı |

---

## 4. Yol Haritası — 4 Faz

### **FAZ 1 — Quick Wins (1 hafta)** ✅ Düşük risk, yüksek etki

Mevcut Elementor + LuwiPress endpoint'leriyle hemen yapılabilir, kod yazımı minimum.

| # | Görev | Tool | Süre | Etki |
|---|---|---|---|---|
| 1.1 | Anasayfa hero — Cinematik bir oud/handpan görseli + 1 net CTA ("Discover handpans") | `elementor/widget` + image upload | 1g | LCP & first impression ↑ |
| 1.2 | Cart sayfası — boş halde "Recently viewed" + "Bestsellers" widget'ı | `elementor/add-section` + WC shortcode | 0.5g | Bounce ↓ |
| 1.3 | Mobil sticky add-to-cart bar (CSS + LuwiPress global CSS) | `elementor/global-css` | 0.5g | Mobile CR ↑ 5-15% |
| 1.4 | Tüm kategori sayfalarına subkategori chip bar (WP_Query loop snippet) | `elementor/add-widget` HTML | 1g | Discovery ↑ |
| 1.5 | Trustpilot/Google Reviews widget anasayfaya | Embed kod | 0.5g | Trust ↑ |
| 1.6 | Footer'da newsletter signup (Mailchimp/FluentCRM) | `elementor/add-widget` | 0.5g | List build |
| 1.7 | Tüm sayfalarda "Trending now" sticky bar (yeni gelenler veya indirim) | Custom CSS + LuwiPress KG verisi | 0.5g | Discovery ↑ |

**Toplam:** ~5 gün, 0 satır core kod.

### **FAZ 2 — Discovery & Filtreleme (2-3 hafta)**

Kullanıcının "browse" deneyimini düzelt.

| # | Görev | Yaklaşım | Süre |
|---|---|---|---|
| 2.1 | Faceted filter bar — "Filter Plugins for WooCommerce" veya "FacetWP" | Plugin kurulumu + LuwiPress detector entegrasyon | 3g |
| 2.2 | Ürün kartlarında ⭐ rating + review count | Theme template override (`woocommerce/content-product.php`) | 1g |
| 2.3 | Quick view modal | YITH WooCommerce Quick View veya custom JS | 2g |
| 2.4 | Wishlist | YITH veya TI WooCommerce Wishlist | 1g |
| 2.5 | AI-powered search (autocomplete + "did you mean") — LuwiPress BM25 zaten var, ön yüze bağla | `customer-chat` BM25'i WC search'e bağla | 3g |
| 2.6 | Kategori hero banner sistemi — registry + Elementor | `theme-registry.json` benzeri category-banners.json + admin UI | 5g |
| 2.7 | Cross-sell: "Customers also viewed" — KG verisinden | LuwiPress KG endpoint → product page widget | 3g |

**Toplam:** ~18 gün, orta seviye plugin entegrasyonu.

### **FAZ 3 — Premium Görsel Yenileme (1 ay)**

Marka algısını "boutique premium"a çek.

| # | Görev | Yaklaşım | Süre |
|---|---|---|---|
| 3.1 | Yeni tema: `luwi-gold` migrasyonu (mevcut Elementor sayfalarını koruyarak) | Theme switch + Kit CSS migration | 5g |
| 3.2 | Tipografi yenileme — başlık serif (Cormorant/Playfair), gövde sans (Inter) | Kit CSS variables güncelleme | 1g |
| 3.3 | Renk paleti — sıcak premium (deep burgundy + gold accent + cream) | Tokens.css güncelleme | 1g |
| 3.4 | Ürün sayfası galeri yenileme — zoom + video + thumbnail rail | WooCommerce template override + JS lib | 5g |
| 3.5 | "Our Luthiers" sayfası — atölye fotoğrafları + sanatçı hikayeleri | Yeni Elementor sayfası | 2g |
| 3.6 | "Tapadum Customs" landing — özel sipariş formu + portfolyo | Yeni sayfa + WP Forms | 3g |
| 3.7 | Anasayfa video hero (autoplay muted loop, atölye/enstrüman) | Asset + Elementor video widget | 2g |
| 3.8 | Tüm placeholder SVG'leri gerçek görsellerle değiştir (toplu görsel optimizasyonu) | Bulk image upload + WebP conversion | 5g |

**Toplam:** ~24 gün, görsel asset üretimiyle paralel.

### **FAZ 4 — Conversion Optimization (2-4 hafta)**

Trafik aynı kalsa bile gelir 2x.

| # | Görev | Beklenen etki | Süre |
|---|---|---|---|
| 4.1 | Tek sayfa checkout (CheckoutWC veya Cartflows) | CR +20-40% | 3g |
| 4.2 | Apple Pay + Google Pay + PayPal Express | Mobile CR +15% | 1g |
| 4.3 | Cart abandonment email — FluentCRM + LuwiPress AI ile kişisel mesaj | Recovery 8-15% | 3g |
| 4.4 | Exit-intent popup (sadece desktop, ilk ziyaret) — %10 indirim email karşılığı | List +30% | 1g |
| 4.5 | Stok azlığı urgency ("Only 2 left in stock") | CR +5% | 1g |
| 4.6 | Customer chat widget'ı tam aktive et (LuwiPress BM25 + KG zaten hazır) | Pre-sale Q&A → CR ↑ | 2g |
| 4.7 | Dynamic pricing (FluentCRM segment + WC) | Repeat purchase ↑ | 3g |
| 4.8 | Schema markup audit — Product, FAQ, Review, BreadcrumbList | SEO + CTR ↑ | 2g |

**Toplam:** ~16 gün.

---

## 5. Risk & Bağımlılıklar

| Risk | Etki | Mitigation |
|---|---|---|
| WPML + tema değişikliği IT/FR/ES çevirilerini kırabilir | Yüksek | Faz 3 öncesi tam snapshot, staging'de tema deneme |
| Elementor sürüm uyumsuzluğu | Orta | Theme switch öncesi Elementor regenerate CSS |
| Görsel asset üretimi (atölye fotoğrafı, video) yavaşlatabilir | Yüksek | Faz 3 paralelinde fotoğrafçı brief'i hazırla |
| Plugin çoğalması performans öldürür (FacetWP + YITH x2 + ...) | Orta | Her plugin sonrası WP Performance Review çalıştır |
| Tek sayfa checkout WooCommerce eklentileriyle uyum sorunu | Orta | Staging'de tüm payment + shipping plugin'lerle test |

---

## 6. Ölçüm — KPI'lar

Modernizasyon başarısını izlemek için baseline alınması gereken metrikler (Faz 1 öncesi snapshot):

| Metrik | Tool | Hedef (3 ay) |
|---|---|---|
| Conversion rate | GA4 | +50% (baseline x 1.5) |
| Mobile CR | GA4 | +80% (baseline x 1.8) |
| Average session duration | GA4 | +40% |
| LCP | PageSpeed | < 2.5s mobile |
| Bounce rate (homepage) | GA4 | -25% |
| Add-to-cart rate (kategori sayfa) | GA4 | +60% |
| Cart abandonment | WC | -20% |
| Email list growth | Mailchimp/FluentCRM | +500/ay |
| Average order value | WC | +15% (cross-sell etkisi) |
| Trustpilot rating | Trustpilot | Minimum 4.5 ⭐ |

---

## 7. Önerilen Başlangıç

**Bugün başlanabilir (öncelik sırası):**

1. **Baseline snapshot** — `elementor/templates` ile tüm anasayfa + kategori + popüler ürün sayfalarını export et (rollback için)
2. **GA4 baseline rapor** — son 30 günün CR, bounce, AOV verilerini kayıt altına al
3. **Faz 1.1** — Anasayfa hero görseli (premium oud/handpan close-up + tek CTA)
4. **Faz 1.3** — Mobil sticky add-to-cart CSS'i (1 saatlik iş, anında etki)
5. **Faz 1.5** — Trustpilot widget (5 dakikalık embed, yüksek trust kazancı)

İlk hafta sonunda 7 quick win tamamlanmış olur, 2. haftadan itibaren Faz 2 (filtreler) başlatılır.

---

## 8. Kararlar — Müşteri Onayı Gerekenler

Yol haritasını ilerletmeden önce netleşmesi gereken stratejik kararlar:

1. **Tema:** Mevcut Elementor temasını mı geliştirelim, yoksa `luwi-gold`'a mı geçelim? (Faz 3'ün temeli)
2. **Marka tonalitesi:** "Boutique premium" mu, "Authentic ethnic" mi, "Modern global"? Renk + tipografi seçimi buna bağlı
3. **Bütçe:** Görsel asset (fotoğrafçı, video) için ayrılan bütçe nedir? (Faz 3 hızı buna bağlı)
4. **Plugin maliyet:** YITH/FacetWP gibi premium plugin'ler için yıllık ~€500 onay
5. **Hedef pazar:** EN ana pazar mı, IT/FR/ES eşit mi? (Hero copy + Trust signal'lar buna göre)

---

**Hazırlayan:** Claude (Opus 4.7) — LuwiPress geliştirme ortağı
**Sonraki adım:** Bu döküman üzerinde geri bildirim → Faz 1 görevleri için TodoWrite oluştur → uygulamaya başla.
