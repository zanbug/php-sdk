# Changelog — zanbug/php-sdk

## 2.0.4 — 2026-10-15

- Manual (`libs/`) auto-update **ext-zip olmayan serverdə** də işləyir: əvvəl `ZipArchive` yoxdursa yeniləmə hər gün "ext-zip is missing" ilə dayanırdı (166cargo #37). İndi sıra: ext-zip → `.zip`; yoxdursa `PharData` (ext-phar + zlib) → `.tar.gz`; o da yoxdursa `tar` əmri.
- Core ilə yanaşı qurulmuş adapterlər (`libs/zanbug-laravel` …) **həqiqətən yenilənir**: arxivdə `src/Zanbug.php` axtarıldığı üçün adapter qovluğu heç vaxt tapılmırdı və adapter köhnə versiyada qalırdı.

## 2.0.3 — 2026-10-15

- Auto-update **Composer 2-ni seçir**: serverdə həm apt-in Composer 1-i (`/usr/bin/composer`), həm Composer 2 (`/usr/local/bin/composer`) olanda əvvəl PATH-da ilk tapılan götürülürdü → Packagist Composer 1-ə xidmət etmir, "Could not find a matching version of package zanbug/php-sdk" ilə hər gün uğursuz olurdu. Composer 1-dən başqa heç nə yoxdursa yeniləmə işə salınmır, aydın mesaj verilir (`COMPOSER_BIN=`).
- Auto-update vəziyyəti paneldə görünür: gündəlik yoxlama `GET /api/ingest/latest`-ə SDK versiyası, quraşdırma növü, qovluğun yazıla bilməsi (`w`), OS istifadəçisi, Composer versiyası və son yeniləmənin nəticəsini (`last=ok|failed`, xəta mətni) göndərir — "açıqdır, amma niyə yenilənmir" sualına panel cavab verir. Heç bir sirr göndərilmir.
- `bin/zanbug update` nəticəsi temp qovluqda yadda saxlanılır (`.zanbug-update-result-*`), növbəti yoxlama onu bildirir.

## 2.0.2 — 2026-10-15

- `zanbug:update` / `bin/zanbug update` (və gündəlik auto-update) SDK **lokal path repository**-dən symlink ilə qoşulubsa (`vendor/zanbug/*` → `../../packages/...`) artıq `composer require` cəhd etmir — composer onu "path repo has higher repository priority" ilə rədd edirdi və komanda hər dəfə xəta verirdi. İndi aydın mesaj: yeniləmə path repo-nun özündə (git pull / paketi yenilə) edilməlidir; auto-update belə layihəni log-a qeyd edib keçir.
- `composer install --no-dev` ilə qurulmuş (production) layihədə yeniləmə `--update-no-dev` ilə gedir — əvvəl dev paketləri (phpunit, ignition …) də quraşdırılırdı.

## 2.0.1 — 2026-10-08

- Yavaş sorğunun yeri dəqiqdir: tətbiqin öz `DB::listen(fn => Zanbug::recordQuery(...))` closure-u (Laravel Events/Database dispatcher, Symfony event-dispatcher, Yii2 Component) çağıran sayılmır — sorğunu işlədən real kod (`file:line`) göndərilir (əvvəl hər sorğu `AppServiceProvider.php`-yə düşürdü).
- Sorğu ilə **çağırış zənciri** gedir: `trace` = ilk 5 tətbiq freymi (`file`, `line`, `fn`), vendor/SDK/adapter freymləri atılır — paneldə "Çağırış zənciri".
- Web sorğularında **route**: `route {name, action, uri}` (Laravel route adı + `Controller@method`; adsız route-da URI şablonu) — `LaravelRoute::current()`, adaptersiz manual quraşdırmada da işləyir.
- İlk yavaş sorğu autentifikasiyadan əvvəl (middleware/boot) düşəndə user boş keşlənib bütün sorğuya yayılırdı → flush anında user yenidən axtarılır.
- `Zanbug::capture($e)` dərhal ardınca `Log::error($e)` (istisna mesaj kimi, kontekstdə yox) eyni xətanı ikinci dəfə "Log" sətri kimi göndərirdi (Postlink #48-də hər 404 iki dəfə) → son 1 dəqiqədə capture olunmuş istisnanın string forması log kimi gəlsə atılır.
- CLI-də init iki dəfə olanda (manual `zanbug.php` + adapter) ikinci Client mövcud run-u götürür (`RunTracker::current()`) → cron/artisan sorğuları "web" kimi görünmür.

## 2.0.0 — 2026-10-07

- 2.0.0 — paket zanbug/* adına keçdi; namespace Zanbug\, env ZANBUG_*; köhnə env adları hələ fallback kimi oxunur
