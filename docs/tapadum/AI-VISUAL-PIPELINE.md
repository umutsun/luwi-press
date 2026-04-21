# Tapadum — AI Görsel Üretim Pipeline

**Tarih:** 2026-04-19
**Bağlam:** [TAPADUM-VISUAL-ASSETS-NEEDED.md](TAPADUM-VISUAL-ASSETS-NEEDED.md) — eksik görsellerin AI ile üretim stratejisi
**Hedef:** ~85+ eksik görsel için profesyonel çekim olmadan, **0-500 € bütçe** ile modern görünüm

---

## TL;DR — Hangi araç neyi çözer?

| Araç | Aylık ücret | En iyi olduğu iş | Tapadum'da kullanım |
|---|---|---|---|
| **Canva Pro** | ~12 € | Hero composition, banner mockup, brand kit, batch resize, BG remover, Magic Edit | Kategori banner'ları, promo block, social media |
| **Canva AI (Magic Studio)** | Pro'ya dahil | Text-to-image, Magic Eraser, BG generator, Magic Switch | Atölye/lifestyle moodboard, BG temizleme, çoklu boyut |
| **Midjourney** | ~10 € (Basic) | Cinematic photo realism, atmospheric scenes, hero shots | Hero görselleri, atölye atmosfer, sanatçı silüetleri |
| **DALL-E 3** (ChatGPT Plus / API) | OpenAI API | Hızlı iterasyon, in-painting, mevcut foto edit | UI ikonlar, küçük illüstrasyonlar, banner concept |
| **Adobe Firefly** | Adobe planı | Generative Fill, ticari lisans güvenli | Mevcut ürün fotoğraflarına BG ekleme, lifestyle context |
| **Photoroom / remove.bg** | 9-12 € | BG remove + AI replace | 131 ürünün BG temizleme + tutarlılık |
| **Runway ML / Sora / Veo 3** | 12-95 € | Text-to-video, image-to-video | Brand video, hero loop, ürün 360° |
| **Topaz Photo AI** | Tek seferlik 199 $ | Mevcut düşük çözünürlük görsellerini upscale | Eski ürün fotoğraflarını 4K'ya çıkar |
| **LuwiPress AI Engine** (mevcut) | Plan içi | Alt text, caption, SEO meta batch generation | 2511 medya için otomatik alt text |

**Toplam minimum stack:** Canva Pro + Midjourney + Photoroom = **~31 €/ay**, +1 kez Topaz upscaler

---

## 1. Strateji: Üç Katmanlı AI Üretim

### Katman 1: **Mevcut görselleri AI ile yükselt** (en hızlı, 0 risk)
- 131 ürün fotoğrafı → BG remove → tutarlı arkaplan → upscale
- Düşük çözünürlük eski görselleri Topaz ile 4K'ya çıkar
- LuwiPress AI ile alt text + caption batch üret

### Katman 2: **AI ile yeni görsel üret** (orta hız, kontrol gerek)
- Hero, kategori banner, promo block, atmospheric → Midjourney
- UI ikonlar, illüstrasyon → DALL-E 3 / Canva AI
- Trust badge, infographic → Canva templates + AI Magic Edit

### Katman 3: **AI compositing** (Canva'da derleme)
- Brand kit kur (renk, font, logo)
- Mid-journey'den çıkan hero + LuwiPress logosu + CTA → Canva'da birleştir
- Batch resize: aynı görseli desktop/mobile/social tüm boyutlara

---

## 2. Canva Pro — Detaylı Kullanım

### 2.1 Brand Kit (1. kurulum, sonsuza dek kullanılır)

```
Canva → Brand Hub → Brand Kit
├── Logos
│   ├── Tapadum primary logo (mevcut PNG)
│   └── Tapadum white version (footer için)
├── Colors
│   ├── Primary: #2C1810 (koyu ahşap)
│   ├── Accent: #D4A574 (sıcak gold)
│   ├── Cream: #F5EDE0
│   └── (Müşteri palette onayı sonrası finalize)
├── Fonts
│   ├── Heading: Cormorant Garamond / Playfair Display
│   └── Body: Inter / Open Sans
└── Photos
    └── Mevcut Tapadum görsellerinden seçili 20-30 favori
```

### 2.2 Tapadum için 3 ana Canva projesi

#### **Proje 1: Anasayfa Hero Set**
- Template: "Website Hero" (1920×1080)
- 8 farklı hero variant (rotating banner için)
- Magic Studio kullanımı:
  - **Magic Media (text-to-image):** "Close-up of master luthier's hands carving an oud, warm natural light, shallow depth of field, cinematic"
  - **Magic Edit:** Mevcut bir oud fotoğrafına atölye arkaplan ekle
  - **Magic Eraser:** Bozucu öğeleri sil
- Export: WebP + JPG fallback, 2 boyutta (desktop + mobile)

#### **Proje 2: Kategori Banner Pack**
- Template: "Banner" (1920×500)
- 11 ana + 15 alt kategori = 26 banner
- Workflow:
  1. Mid-journey'de category atmosphere üret (1024×512)
  2. Canva'ya import + brand colors overlay
  3. Boş metin alanı bırak (CSS overlay için)
  4. Batch export → otomatik file naming

#### **Proje 3: Trust Badge + Icon Set**
- Template: "Custom" (64×64 SVG)
- 8 trust ikon (free shipping, warranty, secure, etc.)
- Phosphor Icons import + brand renge boya
- Export: SVG sprite (tek dosya, tüm ikonlar)

### 2.3 Canva Magic Studio özellikleri (Tapadum için en yararlılar)

| Özellik | Tapadum kullanımı | Tahmini zaman tasarrufu |
|---|---|---|
| **Magic Media** (text-to-image) | Hero + banner + lifestyle scene üretimi | Fotoğrafçıdan 10-50× hızlı |
| **Magic Eraser** | Eski ürün fotoğraflarındaki bozucu öğeleri sil | Photoshop'tan 5× hızlı |
| **BG Remover** | 131 ürünün BG'sini temizle | Manual'den 50× hızlı |
| **Magic Edit** | Ürün fotoğrafına atölye/lifestyle context ekle | Yeni çekim gerek yok |
| **Magic Switch** | Bir hero'yu otomatik 8 boyuta dönüştür | Manuel resize'den 20× hızlı |
| **Magic Write** | Hero CTA copy üret (4 dilde) | Copywriter'dan hızlı |
| **Brand Voice** | Tüm copy'leri brand tone'a uydur | Tutarlılık güvencesi |

### 2.4 Canva → Tapadum WordPress workflow

**A. Manuel:** Canva → Export PNG/WebP → WP medya kütüphanesine upload → Elementor'da kullan

**B. API ile (önerilen, LuwiPress'le entegre):**
1. Canva Connect API (REST) ile export al
2. LuwiPress'e yeni endpoint: `POST /luwipress/v1/media/upload-from-url`
3. Canva URL → LuwiPress → WP medya + alt text otomatik (LuwiPress AI ile)
4. Otomatik attach: hero için anasayfa, banner için kategori sayfası

**C. Canva MCP ile (en otomatize):**
- Bu Claude session'ın `mcp__claude_ai_Canva__*` tool'ları zaten yüklü
- `generate-design` ile direkt brief'ten Canva tasarımı üret
- `export-design` ile WebP/PNG indir
- LuwiPress upload endpoint'iyle WP'ye gönder
- **Tek prompt ile uçtan uca:** "8 hero, 26 banner, 8 ikon üret + WP'ye yükle"

---

## 3. Midjourney — Detaylı Kullanım

### 3.1 Plan seçimi

| Plan | Aylık | Hızlı GPU | Tapadum için |
|---|---|---|---|
| Basic | 10 $ | 200 üretim | Yeterli — Faz 1 için |
| Standard | 30 $ | 15 saat | Faz 2-3 yoğun üretim |
| Pro | 60 $ | 30 saat | Sürekli iterasyon gerekirse |

**Öneri:** Basic ile başla, gerekirse upgrade.

### 3.2 Tapadum için prompt template'leri

#### **A. Hero görseli template**
```
close-up of master luthier hands carving an oud,
warm natural window light, shallow depth of field,
italian workshop background, wood shavings,
cinematic, editorial photography, fujifilm,
shot on 50mm lens, golden hour,
subtle bokeh, premium artisan aesthetic
--ar 16:9 --style raw --v 6
```

#### **B. Atölye / brand story template**
```
italian instrument workshop interior,
hanging ouds and saz on wood walls,
master luthier working at carved wood bench,
warm tungsten lighting, dust particles in air beam,
documentary photography style,
muted earth tones, sienna brown,
shot on canon 5d, 35mm lens, candid
--ar 3:2 --style raw --v 6
```

#### **C. Sanatçı / lifestyle template**
```
silhouette of musician playing handpan at sunset,
mountain landscape background, anatolia,
golden hour rim light, atmospheric haze,
emotional cinematic mood, terrence malick aesthetic,
shot on arri alexa, anamorphic lens,
muted color palette, deep shadows
--ar 21:9 --style raw --v 6
```

#### **D. Kategori banner template**
```
flat lay composition of [INSTRUMENT TYPE],
on dark walnut wood surface, dramatic side lighting,
selective focus, museum exhibition aesthetic,
deep shadows, warm gold accents,
overhead shot, professional product photography,
black background fade to wood texture
--ar 16:5 --style raw --v 6
```

**Tapadum kategorileri için substitution:**
- `darbuka and clay percussion`
- `ottoman oud with mother of pearl inlay`
- `kemenche and bow detail`
- `ney flutes arranged vertically`
- `handpan tongue drum top view`

#### **E. Promo / discount banner template**
```
elegant arabic oud case open showing instrument,
luxury gift presentation, satin lining,
premium retail product photography,
dramatic studio lighting, isolated dark background,
high-end luxury brand aesthetic,
inspired by Hermes, Bulgari product photography
--ar 16:5 --style raw --v 6
```

### 3.3 Midjourney workflow (üretim → seçim → polish)

1. **Üretim** — Her brief için 4 varyasyon, en iyi 1-2'sini upscale
2. **Vary (region)** — Beğenilen kompozisyonda detayları iyileştir
3. **Zoom Out** — Kompozisyonu genişlet (banner format için)
4. **Pan** — Yatay/dikey uzat
5. **Final upscale** — 4K çıktı
6. **Canva'ya import** — Brand overlay + CTA + logo
7. **WP'ye upload** — Alt text otomatik (LuwiPress AI)

**Kritik kalite kontrolü:**
- Eller, parmaklar düzgün mü? (AI ellerde hata yapar)
- Enstrüman anatomisi doğru mu? (Mid-journey oud'u uygunsuz çizebilir)
- Etnik temsil doğru mu? (Tapadum'un Türk/Arap/İran kimliğine sadık)
- Logo, marka, watermark var mı? (Olmamalı)

### 3.4 Lisans güvencesi

- **Midjourney Basic+:** Ticari kullanım hakkı dahil
- **Tapadum kullanabilir:** Anasayfa, banner, sosyal medya, reklam
- **Risk:** Stable Diffusion training data — bazen training image'a yakın çıkabilir, Reverse Image Search önerilir

---

## 4. DALL-E 3 + LuwiPress AI Engine

### 4.1 Mevcut altyapı

LuwiPress'te zaten 3 AI provider var:
- **Anthropic Claude** — text generation (alt text, caption, SEO)
- **OpenAI** — `gpt-4o-mini` text + DALL-E 3 image
- **Google** — Gemini text + Imagen 3 image (yeni)

**Class:** `LuwiPress_Image_Handler` — DALL-E entegrasyonu zaten var.

### 4.2 Tapadum için DALL-E 3 kullanımı

#### **A. Toplu UI ikon üretimi**
```php
// Pseudo workflow
$icons = [
    'free-shipping' => 'minimal line icon of delivery truck with europe map, single color, transparent background',
    'warranty' => 'minimal line icon of shield with checkmark and number 15, single color',
    'secure' => 'minimal line icon of padlock with shield, single color',
    // ... 8 ikon
];

foreach ($icons as $name => $prompt) {
    $url = LuwiPress_Image_Handler::generate($prompt, '1024x1024');
    $attachment_id = LuwiPress_Image_Handler::save_to_media($url, "icon-{$name}");
    // SVG'ye convert (Canva'da veya https://convertio.co)
}
```

#### **B. Blog post featured image üretimi**
- Mevcut blog post'lar için tutarlı görsel set
- LuwiPress AI: post title → DALL-E prompt → featured image otomatik
- Halihazırda yapılabilir, yeni endpoint gerekmiyor

#### **C. Kategori SEO text görselleri**
- Her kategori sayfasının SEO metni var (10 paragraf)
- Her paragrafın yanına AI inline image üret
- "Riq is a tambourine-style frame drum..." → DALL-E ile riq görseli

### 4.3 Maliyet (DALL-E 3 API)

| Boyut | Kalite | Fiyat (per image) |
|---|---|---|
| 1024×1024 | Standard | $0.040 |
| 1024×1024 | HD | $0.080 |
| 1792×1024 | HD | $0.120 |

**Tapadum 85+ görsel × $0.08 HD = ~$7** — neredeyse ücretsiz.

---

## 5. Photoroom / remove.bg — Ürün BG Standardizasyonu

131 ürünün arkaplanı tutarsız (bazı beyaz, bazı atölye, bazı stüdyo).

### Workflow:
1. **WP medya export** — 131 ana ürün görseli indir (LuwiPress endpoint ile)
2. **Photoroom batch** — BG remove → tek tip beyaz / cream / wood texture BG
3. **Canva'da brand consistency** — gerekirse hafif gölge, marka çerçeve
4. **WP'ye geri yükle** — orijinal görseli replace et (yedek alarak)

**Süre:** 131 ürün × 2 dk = ~4 saat manuel, veya **Photoroom API** ile 30 dk batch.
**Maliyet:** Photoroom Pro ($12/ay) tek ay yeterli.

---

## 6. Runway ML / Sora / Veo 3 — Video İçerik

### 6.1 Brand video (60-90s anasayfa hero loop)

**Prompt (Runway Gen-3 / Sora / Veo 3):**
```
camera slowly pans across italian instrument workshop,
ouds and percussion hanging on warm wood walls,
dust particles in golden window light,
master luthier visible in background carving wood,
cinematic, editorial documentary style,
slow motion, shallow depth of field
```

- **Çıktı:** 8-10s clip
- **Edit:** Canva Video veya CapCut'ta loop kur
- **Kullanım:** Anasayfa hero arkaplan video (autoplay muted loop)

### 6.2 Ürün 360° (top 10 ürün)

**Yaklaşım:** Mevcut ürün fotoğrafından **image-to-video** ile slow rotation
- Runway Gen-3 image-to-video
- Stable Video Diffusion (ücretsiz alternatif)

### 6.3 Maliyet
- Runway Standard: $12/ay (125 credits ≈ 60 saniye video)
- Sora (ChatGPT Plus dahil): $20/ay
- Veo 3 (Google): $20-40/ay

---

## 7. LuwiPress AI Engine — Otomatize Edilebilir İşler

Bunlar **zaten LuwiPress'te yapılabilir**, ek araç gerekmiyor:

### 7.1 Alt text batch generation
2511 medya öğesinin çoğunda alt text yok. LuwiPress AI ile:
```
GET /luwipress/v1/media/missing-alt → liste
POST /luwipress/v1/media/generate-alt-batch
  → her görsele Claude vision ile alt text üret
  → SEO + accessibility = çift kazanç
```

### 7.2 Caption + description batch
Ürün galerisinde her görselin caption'ı yok.
Claude → "Bu görselde Tapadum oud'unun mother-of-pearl detayı görülüyor..." → otomatik caption.

### 7.3 SEO image rename
`5-21.jpg` gibi anlamsız dosya adları → `professional-solo-clay-darbuka-n6.jpg`
- LuwiPress AI: ürün başlığı → SEO-friendly slug → media file rename

### 7.4 Metadata + EXIF temizleme
Toplu metadata standardize → tutarlı schema markup.

---

## 8. Önerilen 4 Aşamalı AI Üretim Planı

### **Aşama 1: Quick Win (3-5 gün, ~50 €)**

1. **Canva Pro abonelik** (12 €/ay)
2. **Brand Kit kurulumu** (renk, font, logo) — 1 saat
3. **Hero #1** — Mid-journey ile + Canva'da finalize → anasayfaya yükle
4. **8 trust ikon** — Phosphor Icons import + brand renge boya
5. **6 kategori card görseli** — Mevcut ürün fotolarından + Canva BG generate
6. **LuwiPress AI batch alt text** — 2511 medya → otomatik

**Sonuç:** Anasayfa modern görünüm, trust signal güçlendi, SEO + accessibility iyileşti.

### **Aşama 2: Kategori + Promo (1-2 hafta, ~50 €)**

1. **Mid-journey Basic** abonelik (10 €/ay)
2. **11 ana kategori banner** üret (Mid-journey + Canva)
3. **15 alt kategori banner** üret (öncelikli olanlar)
4. **8 promo block** (Customs, Academy, Discounts, Newsletter)
5. **About page atölye atmosfer** (4 görsel — placeholder yerine)
6. **Photoroom Pro** (1 ay) → 131 ürün BG standardize

**Sonuç:** Tüm kategori sayfaları premium görünüm, ürün gallery tutarlı.

### **Aşama 3: Brand Story + Lifestyle (2-3 hafta, ~100 €)**

1. **Mid-journey Standard** (30 €/ay) — yoğun üretim
2. **14 atölye/brand story görseli** (luthier, atelier, process)
3. **10 lifestyle / sanatçı görseli** (oud çalan müzisyen vb.)
4. **About page yeniden tasarım** (Canva ile mockup)
5. **Blog featured image set** (mevcut postlar için yeniden)

**Sonuç:** Brand storytelling tamamlanmış, premium algı oturmuş.

### **Aşama 4: Video + Premium Polish (3-4 hafta, ~150 €)**

1. **Runway veya Sora** abonelik (12-20 €/ay)
2. **Brand video** (60s anasayfa hero loop)
3. **5 ürün 360° rotation** (top sellers)
4. **3 atölye işleyiş video** (handpan tuning, oud carving)
5. **Topaz Photo AI** (199 € tek seferlik) → eski görselleri 4K upscale

**Sonuç:** Video içerik premium tier'a taşıdı, görsel kalite Reverb/Thomann seviyesinde.

---

## 9. Toplam Bütçe Karşılaştırma

| Yaklaşım | Süre | Maliyet | Kalite |
|---|---|---|---|
| **Profesyonel çekim** (önceki plan) | 6 hafta | 10,000-20,000 € | Premium, otantik |
| **AI tam pipeline** (bu plan) | 6-10 hafta | **~350-500 €** | Premium, tutarlı, tekrarlanabilir |
| **Hibrit** (AI + 1 atölye günü) | 4-6 hafta | ~2500-3500 € | En iyi |

**Tasarruf:** AI yaklaşımı **20-50× daha ucuz**, %80 kalite parite.

**Hibrit önerisi:** AI ile %90 yap, sadece **1 atölye günü** çekim yap (gerçek founder + ekip + showroom). Geri kalan AI ile.

---

## 10. Kritik Riskler & Mitigation

| Risk | Etki | Mitigation |
|---|---|---|
| AI görselde "AI look" hissi | Yüksek (premium algı zedelenir) | Mid-journey `--style raw` + post-process Canva, Photoshop |
| Eller, anatomi hataları | Yüksek (oud telleri yanlış sayı vb.) | Manuel inceleme + region vary + Photoroom touch-up |
| Lisans/telif sorunu | Düşük (Mid-journey Basic+ ticari OK) | Reverse image search ile training data benzerliği kontrol |
| Marka tutarlılığı kaybı | Orta | Brand Kit zorunlu kullanım, tek prompt template |
| AI üretim "ucuz" hissi | Orta | Hibrit yaklaşım — kritik 5-10 görsel gerçek çekim |
| Kategori ikonlarda yanlış enstrüman çizimi | Yüksek | Reference image upload (Mid-journey'de `--cref`) |
| Etnik/kültürel temsil hassasiyeti | Yüksek | Müşteri review zorunlu, Türk/Arap/İran kültürüne sadakat |

---

## 11. Şimdi Başlamak İçin — İlk Hafta Aksiyon Planı

### Gün 1 (bugün)
- ✅ Bu döküman onayı
- Canva Pro abonelik (12 €)
- Mid-journey Basic abonelik (10 €)
- Brand Kit kurulumu (renk, font, logo)
- Müşteriden 5-10 referans görsel iste (moodboard için)

### Gün 2
- Mid-journey'de hero #1 brief'i çalış (4 varyasyon)
- En iyi 1'i upscale + Canva'ya import
- Anasayfa hero olarak yükle
- Phosphor Icons → 8 trust ikon brand renge boya

### Gün 3
- Mid-journey'de 6 kategori card brief'i (Percussion, String, Bowed, Wind, Oud, Accessories)
- Canva'da finalize + WP yükle
- Anasayfa kategori bölümü güncelle

### Gün 4
- LuwiPress AI batch alt text job'ı çalıştır (2511 medya)
- Cart sayfası boş hali için "Recently viewed" widget ekle
- Mobile sticky add-to-cart CSS deploy

### Gün 5
- Promo block görselleri (Customs, Academy, Discounts) — Mid-journey + Canva
- Newsletter signup arkaplan
- Aşama 1 tamamlanır, müşteri review

**1 hafta sonu:** Anasayfa tamamen modernize, sıfır çekim, ~50 € maliyet.

---

## 12. Müşteri Onayı Bekleyenler

1. **Bütçe** — Aşama 1-4 toplam ~350-500 €/ay onay
2. **AI etik** — Marka olarak "AI generated" görsel kullanmak kabul mü?
3. **Hibrit yaklaşım** — Hibrit (AI + 1 atölye günü) mı, sadece AI mı?
4. **Tonalite** — Boutique premium / Modern global / Authentic ethnic — Mid-journey prompt'ları buna göre kalibre edilecek
5. **Brand kit** — Renk paleti, tipografi onayı (önceki dökümanda öneriler var)
6. **Bu Claude session'da Canva MCP** kullanarak doğrudan üretim mi, yoksa müşteri kendi yapsın mı?

---

## Sonraki Adım

Onayın sonrası **bu Claude session'da hemen başlatabilirim:**

1. Canva MCP tool'larıyla Brand Kit setup'ı dene
2. İlk hero brief'ini Mid-journey için hazırla
3. LuwiPress AI alt text batch'i tetikle
4. Aşama 1 tüm görselleri 1 günde üret (konsept seviyesinde, müşteri onayı için)

**Hazırlayan:** Claude (Opus 4.7) — LuwiPress geliştirme ortağı
