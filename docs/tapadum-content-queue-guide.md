# Tapadum — Content Queue Playbook

**For:** Tapadum's content team + their Claude assistant
**Requires:** LuwiPress core **3.1.10+** · LuwiPress WebMCP companion **1.0.3+**
**Date:** 2026-04-21

Bu rehber iki yoldan Tapadum'a **toplu (bulk) blog yazısı kuyruğu** oluşturmayı anlatır:
1. **Admin UI** (WordPress → LuwiPress → Content Scheduler → Bulk Queue)
2. **WebMCP** (Claude'un doğrudan API çağrısıyla)

Her ikisi de aynı altyapıyı kullanır — aynı 50-topic limitine, aynı AI bütçe korumasına, aynı depth preset'lerine tabi.

---

## 1. Kalite seviyeleri — `depth` preset'i

Her content queue yazısı için üç kalite seviyesinden birini seçersiniz:

| Preset | Uzunluk | Stil | Ne zaman? |
|---|---|---|---|
| **standard** | 800-1500 kelime | Dengeli SEO makalesi | Hızlı günlük içerik, ürün odaklı yazılar |
| **deep** | 1500-3000 kelime | Araştırma çerçevesi, örnekler, alıntılar, karşı argümanlar, "Key takeaways", 3-5 soruluk FAQ | Konu derinlemesine araştırılması gerekenler (müzik teorisi, tarih, teknoloji) |
| **editorial** | 2000-3500+ kelime | Güçlü sesli deneme: anekdot açılış, tematik yay, kültürel/tarihi bağlam, kıvrak cümleler, akılda kalan kapanış | Marka değeri taşıyan "flagship" yazılar, editoryal hissi olan içerikler |

**Sistem prompt kuralları (hepsine uygulanır):**
- AI-boilerplate açılışları yasak ("Günümüzün hızlı tempolu dünyasında…", "Bu makalede şunu ele alacağız…")
- Somut örnek, isim, tarih, sayı zorunlu — "müzik insanları etkiler" gibi muğlak cümleler red
- İç linkler `[INTERNAL_LINK: anchor metin]` formatıyla yerleştirilir
- JSON çıktısı — markdown fence yok, dışında prose yok
- Deep ve editorial'de karşılaştırma tablosu / numaralı liste zorunlu

---

## 2. Path A — Admin UI (recommended, easiest for operators)

### 2.1 Where it lives

1. **WordPress admin** → **LuwiPress** → **Content Scheduler**
2. The page is a 4-step **wizard**: Topics → Style → Schedule → Review.
3. Use **Next** / **Back** to move between steps. Under Step 1 there is an "Advanced: per-topic overrides" section — use it when individual rows need to deviate from the batch defaults.

### 2.2 Filling in the steps

| Step | Field | Recommended for Tapadum |
|---|---|---|
| **1. Topics** | Topics textarea | One topic per line. Optional per-topic overrides (below) |
| **2. Style** | Content depth | `editorial` (cultural), `deep` (explainer), `standard` (short/product) |
| **2. Style** | Tone | Informative / Creative |
| **2. Style** | Words | 2500 |
| **2. Style** | Language | Turkish |
| **2. Style** | Post Type | Blog Post |
| **2. Style** | Generate featured image | ✓ |
| **3. Schedule** | After AI generates | **Save as draft for review** (recommended) or Auto-publish on schedule |
| **3. Schedule** | Start date | Tomorrow |
| **3. Schedule** | Publish time | 09:00 |
| **3. Schedule** | Cadence | 1 day (one post per day) |
| **3. Schedule** | AI stagger (min) | 10 (budget-friendly) |
| **4. Review** | Summary + estimated cost | Confirm → Queue all topics |

### 2.2a Draft-first workflow (new, recommended)

When Step 3 has **"Save as draft for review"** selected:
- As soon as AI generation finishes, the article lands as a **WP Draft** (the scheduled publish date is baked into `post_date`).
- A **"Review & publish"** button appears next to that queue row and sends you straight to the WP editor.
- Your flow is: generate → review → edit if needed → Publish.

The alternative, "Auto-publish on schedule", is the classic behavior: once AI finishes, the post goes live on the scheduled date with no manual step.

### 2.2b Per-topic override syntax (new, advanced)

If one or two rows in a batch need a different depth / words / tone, append `key=value` segments separated by pipes:

```
The music and note theories of Gurdjieff | depth=editorial | words=3000
How to store your darbuka | depth=standard | words=900 | image=0
History of the santur | keywords=ethnic instruments, turkish music | tone=creative
Al-Farabi's book on music and healing | depth=editorial | words=3500 | tone=academic
```

Supported keys:

| Key | Value | Note |
|---|---|---|
| `keywords` / `kw` | string | Legacy `Topic \| keyword` syntax still works |
| `depth` | `standard` / `deep` / `editorial` | |
| `words` / `word_count` | 300–5000 | |
| `tone` | `professional`, `casual`, `academic`, `creative`, `persuasive`, `informative` | |
| `lang` / `language` | language code (tr, en, it, fr…) | For multilingual batches |
| `image` / `img` | 0 / 1 (also yes/no, true/false) | Whether this row gets a featured image |
| `type` / `post_type` | `post` / `page` / `product` | |

**Rule:** If the first segment has no `=` it keeps the legacy keyword behavior. All subsequent segments must be `key=value`.

### 2.2c Failed retry (new)

When a row ends up in `Failed` status a **Retry** button shows up next to it — it clears the error, flips the row back to `pending`, and re-queues AI generation 30 seconds later. The old delete-and-re-add pattern is no longer necessary.

### 2.3 Örnek — Tapadum 12 başlık (editorial preset)

Aşağıdakini textarea'ya yapıştırın:

```
Gurdjieff'in müzik ve nota teorileri
Gezegenler ve notaların ilişkisi
İbn-i Farabi'nin müzik ve şifa kitabı
Oruç Rahmi Gübenç'in müzik ve şifa çalışmaları (TÜMATA)
Üçüncü Selim'in bulduğu makamlar
Âşık atışmaları
İstanbul'da sokak müziği kültürü
Claude Haiku, Sonnet, Opus ve müzik ilişkisi: bir marka değerinde müzik
Gezegenlerin çıkardığı sesler
Hu, Ohm ve Amen seslerinin gizemi
Budist kültüründe müziğin yeri
Hindu kültüründe müzik ayinleri: gün batımından gün doğumuna
```

**Tıkla:** "Queue All Topics" → 12 satır oluşur, her biri kendi AI çağrısı için staggered schedule ile işaretlenir.

### 2.4 İlerlemeyi izle

- Sayfanın üstündeki **Pending / Generating / Ready / Published** sayaçları 15 saniyede bir otomatik yenilenir (Generating olan varsa).
- Hemen işletmek için: **"Run N pending now"** butonu — wp-cron'u beklemeden ilk 10 taneyi hemen generate eder.
- Başarısız olanlar listede **"Failed"** olarak görünür, hata mesajıyla birlikte.

---

## 3. Yol B — WebMCP (Claude üzerinden)

Claude'unuz aşağıdaki araçları doğrudan çağırabilir. WebMCP 1.0.3 ile gelen **5 yeni content tool** vardır:

### 3.1 Araç kataloğu

| Tool | Amaç |
|---|---|
| `content_bulk_queue` | 50 taneye kadar başlığı tek çağrıda queue'ya al (en sık kullanılan) |
| `content_schedule_create` | Tek bir başlığı queue'ya al |
| `content_schedule_list` | Tüm planlanmış içeriklerin listesini al |
| `content_schedule_status` | Tek bir kayıt durumu (pending/generating/ready/published/failed + published_post_id) |
| `content_schedule_delete` | Henüz yayınlanmamış bir kaydı iptal et |
| `content_run_pending_now` | Bekleyen 10'a kadar kaydın AI üretimini hemen tetikle |

### 3.2 Claude ile bulk queue — örnek konuşma

**Kullanıcı:**
> Şu 12 başlığı günde 1 yayın olacak şekilde, editorial kalitede, yarından başlayarak Tapadum'a queue'ya ekle:
>
> [12 başlık listesi]

**Claude'un yapması gereken çağrı:**

```json
{
  "tool": "content_bulk_queue",
  "arguments": {
    "topics": [
      "Gurdjieff'in müzik ve nota teorileri",
      "Gezegenler ve notaların ilişkisi",
      "İbn-i Farabi'nin müzik ve şifa kitabı",
      "Oruç Rahmi Gübenç'in müzik ve şifa çalışmaları (TÜMATA)",
      "Üçüncü Selim'in bulduğu makamlar",
      "Âşık atışmaları",
      "İstanbul'da sokak müziği kültürü",
      "Claude Haiku, Sonnet, Opus ve müzik ilişkisi: bir marka değerinde müzik",
      "Gezegenlerin çıkardığı sesler",
      "Hu, Ohm ve Amen seslerinin gizemi",
      "Budist kültüründe müziğin yeri",
      "Hindu kültüründe müzik ayinleri: gün batımından gün doğumuna"
    ],
    "start_date": "2026-04-22",
    "start_time": "09:00",
    "interval_unit": "day",
    "interval_value": 1,
    "generate_offset": 10,
    "depth": "editorial",
    "tone": "informative",
    "word_count": 2500,
    "language": "tr",
    "post_type": "post",
    "generate_image": true
  }
}
```

**Dönen yanıt:**

```json
{
  "success": true,
  "queued": 12,
  "skipped": 0,
  "depth": "editorial",
  "items": [
    { "schedule_id": 34205, "topic": "Gurdjieff'in müzik ve nota teorileri", "publish_date": "2026-04-22 09:00:00", "generate_at": "2026-04-21 17:50:00" },
    { "schedule_id": 34206, "topic": "Gezegenler ve notaların ilişkisi",    "publish_date": "2026-04-23 09:00:00", "generate_at": "2026-04-21 18:00:00" },
    ...
  ]
}
```

### 3.3 Durum takibi

Her bir yazının durumunu tek tek:

```json
{ "tool": "content_schedule_status", "arguments": { "schedule_id": 34205 } }
```

Veya hepsini birden:

```json
{ "tool": "content_schedule_list", "arguments": {} }
```

### 3.4 Hızlı işletme

Wp-cron'u beklemeden ilk 10 pending kaydı hemen generate etmek için:

```json
{ "tool": "content_run_pending_now", "arguments": {} }
```

---

## 4. Claude için önerilen workflow (bootstrap'e eklenecek)

Aşağıdakini Tapadum Claude projenizin custom instructions'ına (veya bootstrap MD'sine) ekleyebilirsiniz:

```
Blog yazısı kuyruklama isteği geldiğinde:

1. Önce `content_schedule_list` ile mevcut kuyruğa bak — kaç pending/generating/ready var?
   Eğer pending sayısı 20'yi geçmişse kullanıcıya haber ver: "Şu an X bekleyen yazı var,
   yeni eklemek yerine Run pending now çalıştırmayı tercih eder misin?"

2. Kullanıcının başlıklarını + depth tercihini + programlamasını aldıktan sonra:
   - topics dizisini hazırla
   - start_date / interval / depth / tone / word_count netleştir
   - Kullanıcıya ÖZETİ göster ve ONAY iste (destructive olmasa da user-visible,
     sessiz queue'ya atmadan önce):
     "12 başlık, editorial kalitede, yarından itibaren günde 1, her biri ~2500 kelime.
      Tahmini AI maliyeti: [hesapla]. Queue'ya eklemek ister misin?"

3. Kullanıcı "evet" derse `content_bulk_queue` çağır.

4. Dönen schedule_id listesini not et; kullanıcı sonra "2. yazının durumu ne?" dediğinde
   `content_schedule_status` ile kontrol et.

5. İçerik üretildikten sonra kullanıcı yayın öncesi inceleme isterse:
   - published_post_id alanı ready/published status'te dolar
   - /wp-admin/post.php?post=<id>&action=edit linkini ver

Asla:
- content_bulk_queue'yu 50'den fazla topicle çağırma
- content_schedule_delete'i kullanıcı onayı almadan kullanma (destructive hint true)
- Daily AI budget dolmuşsa direkt tekrar dene — budget-aware defer zaten var (scheduler kendisi saat sonra yeniden dener)
```

---

## 5. Bütçe + hata yönetimi

### Otomatik budget-aware defer

Eğer bir AI generation tetiklendiğinde günlük bütçe doluysa:
- Kayıt **pending** kalır
- Cron otomatik olarak **1 saat sonra** yeniden dener
- Bütçe sıfırlandığında (her gece 00:00) veya siz cap'i yükselttiğinizde kuyruk kaldığı yerden devam eder

Bu sayede büyük batch'leri (50 editorial yazı) güvenle kuyruğa atabilirsiniz — bütçeyi patlatmaz, sadece zamana yayar.

### Başarısız olanları yeniden tetikleme

Eğer bir kayıt `failed` olduysa:

**Admin UI'dan:**
- Liste satırındaki çöp kutusu ile sil
- Content Scheduler'a yeni başlık olarak tekrar ekle

**Claude üzerinden:**
```
content_schedule_delete(schedule_id: 34205)
content_schedule_create({topic: "...", depth: "editorial", ...})
```

> **Not:** Şu anda direkt "retry" tool'u yok. Silip yeniden ekleme pattern'i mevcut sürümde standart yol. Retry tool'u ilerideki sürümde eklenecek ise önce kuyruk büyüdüğünde düşünülecek.

---

## 6. Depth preset'i pratik seçim rehberi

Tapadum için depth tercih karar matrisi:

| İçerik türü | Preset | Örnek başlık |
|---|---|---|
| Ürün rehberi, bakım ipucu | `standard` | "Darbukanızı nasıl saklarsınız" |
| Müzik tarihi, kültür, teori | `deep` | "Osmanlı müziğinde makam sistemi" |
| Flagship içerik, marka sesi | `editorial` | "Gurdjieff'in müzik teorileri", "Gezegenlerin sesi" |
| Kategori açılış yazısı (SEO hub) | `deep` | "Türkiye'nin etnik müzik aletleri: bir rehber" |

Editorial preset'i AI'yı en çok zorlayan ve en pahalı olandır — rastgele kullanmayın, flagship içerik için saklayın.

---

## 7. Doğrulama checklist'i

Kurulumdan sonra:

- [ ] **Admin UI** — Content Scheduler sayfasında "Bulk Queue" kartı görünüyor
- [ ] Textarea'ya 1-2 test başlığı yapıştırın, **editorial** seçin, queue edin
- [ ] "Run N pending now" ile hemen tetikleyin
- [ ] **Usage & Logs** sayfasında AI çağrısının geldiğini görün
- [ ] ~30 saniye sonra kayıt **Ready** olmalı
- [ ] **published_post_id** meta'sı dolmalı, `/wp-admin/post.php?post=<id>&action=edit` ile açılmalı
- [ ] Üretilen yazıyı okuyun: başlangıç cümlesi AI-boilerplate mi, yoksa spesifik ve ilgi çekici mi? Eğer boilerplate ise `luwipress_content_system_prompt` option'ına kendi brand voice'unuzu ekleyin.

### WebMCP tarafı (opsiyonel)

- [ ] Claude Desktop'tan `content_schedule_list` çağırın — boş veya mevcut kayıtlar dönmeli
- [ ] `content_bulk_queue` ile 2 test başlığı gönderin, `queued: 2` yanıtı alın
- [ ] `content_schedule_status` ile schedule_id'lerden birinin durumunu çekin
- [ ] Yazı bittiğinde `published_post_id` alanı dolu olmalı

---

## 8. İlgili dosyalar

- `LUWIPRESS-FEATURES.md` — genel feature overview
- `luwipress-webmcp/docs/FEATURES.md` — WebMCP tool kataloğu
- `docs/tapadum-session-bootstrap.md` — Claude bootstrap (bu dosyadaki workflow notlarını oraya entegre edin)
- `docs/tapadum-claude-test-guide.md` — smoke test + task library
- `docs/tapadum-handoff-3.1.4.md` — önceki release handoff

---

## 9. Destek

- **Plugin vendor:** Luwi Developments LLC — hello@luwi.dev
- **Önce deneyin:** standard depth ile 2 test başlığı → beğendiyseniz editorial'e geçin
- **Sorun yaşarsanız:** *WP Admin → LuwiPress → Usage & Logs* sayfasında hata detayı var
