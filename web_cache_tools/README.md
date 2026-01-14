# Cache Probe - Tester LSCache

Automatyczne testowanie cache na Twojej stronie WordPress z LSCache.

## 🚀 Szybki start

```bash
# Podstawowy test (20 stron, desktop + mobile, ~2 min)
php cache-probe.php --site=https://sklep.plantis.app --limit=20

# Sprawdź co już jest w cache (szybkie, ~1 min)
php cache-probe.php --site=https://sklep.plantis.app --limit=20 --passes=1

# Duża próbka 100 stron (~8 min)
php cache-probe.php --site=https://sklep.plantis.app --limit=100 --delay-ms=300
```

## 📊 Co robi skrypt?

1. Pobiera losowe strony z sitemap
2. Testuje desktop + mobile osobno
3. **Pass 1:** Rozgrzewa cache (MISS ~3s)
4. **Pass 2:** Sprawdza czy działa (HIT ~0.2s)
5. Zapisuje raport HTML + logi

## ⏱️ Ile trwa?

- **20 stron** (2 passy) = ~2 minuty
- **20 stron** (1 pass) = ~1 minuta
- **100 stron** (2 passy) = ~8 minut

Czas zależy od `--delay-ms` (domyślnie 500ms między requestami).

## ⚠️ WAŻNE - Bezpieczeństwo serwera

**Delay chroni Twój serwer przed przeciążeniem!**

- `--delay-ms=500` (domyślne) = **bezpieczne**, nie obciąża serwera
- `--delay-ms=200` = szybsze, ale **większe obciążenie**
- `--delay-ms=100` = **ryzykowne**, może spowolnić stronę dla użytkowników
- `--concurrency=1` (domyślne) = **bezpieczne**, jeden request na raz
- `--concurrency=2+` = **ostrożnie**, wiele równoległych requestów

❌ **NIE UŻYWAJ** `--delay-ms=50` ani `--concurrency=5+` - możesz przegrzać serwer!

## 📁 Pliki wyjściowe

- `cache-probe-2026-01-13_23-16-33.html` - raport wizualny
- `cache-probe-2026-01-13_23-16-33.log` - szczegółowe logi

## ⚙️ Parametry

- `--site` (wymagane) - adres strony
- `--limit=20` - ile stron testować
- `--passes=1` - tylko sprawdzenie (bez rozgrzewania)
- `--passes=2` - rozgrzanie + weryfikacja (domyślne)
- `--delay-ms=500` - opóźnienie między requestami (domyślne)
- `--concurrency=1` - ile requestów równolegle (domyślne)
- `--verbose` - więcej logów w konsoli
- `--trace` - pełne nagłówki HTTP w logu
