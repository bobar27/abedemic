# Plan: Game "Run & Jump" + Rombak Quiz (Quiziz-style) + Spell Card + Bersihkan Aset

## Konteks yang sudah diverifikasi

- Semua halaman sudah bisa render tanpa error PHP, login + session jalan, MySQL `abedemic` aktif.
- Konvensi kode: **tab** untuk indent, komentar Bahasa Indonesia, `partials/header.php` + `partials/footer.php` jadi kerangka tiap halaman, script inline per halaman kecuali `app.js` yang dipakai bersama.
- Aset statis sudah di-cache-bust lewat `asset_url()` (`config.php`), jadi perubahan CSS/JS langsung terlihat.
- **Aset game sudah ada dan sudah transparan** (alpha = 0 di sudut):
  - `img/gambar_koin.png` (500x500) — koin
  - `img/gambar_perisai.png` (500x500) — kartu IMMUNITY
  - `img/gambar_angka_2.png` (500x500) — kartu SECOND CHANCE
- Semua file "asing" di root **tidak direferensikan kode mana pun**. Aplikasi hanya memakai `img/*`:
  `ask-our-ai.png`, `summary-pic.png`, `quiz-pic.png`, `game-pic.png`, `rimuru-mascot.jpeg`,
  `crocodile-mascot.jpeg`, `dino-mascot.jpeg`, `elf-mascot.jpeg`, `folder-pic.jpeg`, `bg.jpg`.
- Sidebar sekarang urutannya: **Profile → Riwayat → Menu → Setting → Logout**.

## Keputusan yang sudah dikunci

- **Jumlah soal kuis**: bisa pilih **10 atau 20** saat generate (default 10). Gemini diminta menghasilkan sejumlah itu.
- **Mekanik spell card** (sudah dikonfirmasi user):
  - **IMMUNITY** — di-*arm* sebelum menjawab. Kalau jawab **benar**, kartu **tidak** terpakai (kembali utuh). Kalau jawab **salah**, soal jadi **VOID** (tidak masuk skor, tidak masuk results) dan kartu **terpakai**.
  - **SECOND CHANCE** — di-*arm*, kartu langsung **terpakai**, soal yang sedang aktif **di-skip ke urutan paling akhir** untuk dijawab ulang nanti.
  - Kedua kartu single-use, disimpan per akun.

---

## Tahap 1 — Aset liar dipindah (bukan dihapus)

Buat folder `legacy-assets/original-repo/` lalu `git mv` semua file berikut ke sana. **Tidak ada yang dihapus**, sesuai permintaan.

| Dipindah | Alasan |
|---|---|
| `index.html`, `login.html`, `register.html` | Prototipe statis lama, sudah digantikan `.php` |
| `ask our ai.jpeg`, `ask_our_ai-removebg-preview.png` | Duplikat `img/ask-our-ai.png` |
| `game_pic-removebg-preview.png` | Duplikat `img/game-pic.png` |
| `quiz_pic-removebg-preview.png` | Duplikat `img/quiz-pic.png` |
| `sumarry_pic-removebg-preview.png` | Duplikat `img/summary-pic.png` |
| `crocodile mascot.jpeg`, `dino mascot.jpeg`, `elf mascot.jpeg`, `folder pic.jpeg`, `game pic.jpeg`, `quiz pic.jpeg`, `rimuru mascot.jpeg`, `sumarry pic.jpeg` | Versi lama Bernama Spasi, sudah ada versi bersih di `img/` |
| `background.jpg`, `bg.jpg` (root) | Tidak dipakai; yang dipakai `img/bg.jpg` |

Tambah `legacy-assets/README.md` singkat: asal file (repo original), alasan dipindah, dan bahwa file ini sengaja disimpan untuk keperluan PR.

> Catatan: `.kilo/worktrees/*` milik sesi lain — **jangan disentuh**.

## Tahap 2 — Database: dompet, kartu, dan attempt kuis

Tambah ke `database.sql` **dan** migrasi idempotent di `history.php` (pola `CREATE TABLE IF NOT EXISTS` seperti `chat_conversations`, supaya DB lama ikut ter-upgrade tanpa perlu import ulang).

```sql
-- Saldo koin + rekor game per user
CREATE TABLE `user_wallet` (
  `user_id`       INT UNSIGNED NOT NULL,
  `coins`         INT UNSIGNED NOT NULL DEFAULT 0,
  `best_distance` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_runs`    INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `user_wallet_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

-- Kartu yang DIMILIKI (belum dipakai) per user
CREATE TABLE `user_cards` (
  `user_id`  INT UNSIGNED NOT NULL,
  `card_key` VARCHAR(30) NOT NULL,          -- 'immunity' | 'second_chance'
  `owned`    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`, `card_key`),
  CONSTRAINT `user_cards_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

-- Riwayat setiap run game
CREATE TABLE `game_runs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `distance`   INT UNSIGNED NOT NULL,   -- meter
  `coins_taken`   INT UNSIGNED NOT NULL,  -- koin dipickup di run ini
  `coins_earned`  INT UNSIGNED NOT NULL,  -- koin yang benar-benar masuk saldo
  `played_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY (`user_id`),
  CONSTRAINT `game_runs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);

-- Satu pengerjaan kuis (untuk layar Results + skor di Riwayat)
CREATE TABLE `quiz_attempts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quiz_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `answers`    JSON NOT NULL,   -- [{q, chosen, correct, voided, cardUsed}]
  `score`      INT UNSIGNED NOT NULL,
  `total`      INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), KEY (`user_id`), KEY (`quiz_id`),
  CONSTRAINT `quiz_attempts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);
```

Fungsi baru di `history.php` (nama disesuaikan dengan implementasi):

| rencana | Implementasi | Catatan |
|---|---|
| `get_wallet()` | `get_wallet()` | sama |
| `add_coins()` + `save_game_run()` | `add_run_rewards()` | digabung jadi satu transaksi DB supaya saldo & rekor tidak bisa ter-update sebagian |
| `get_game_runs()` | `get_game_runs()` | sama |
| `get_cards()` | `get_cards()` | sama |
| `buy_card()` | `buy_card()` | sama + `SELECT ... FOR UPDATE` supaya tidak dobelbeli |
| `use_card()` | `consume_card()` | nama lain, isi sama |
| `refund_card()` | (tidak ada) | **sengaja tidak dibuat.** Tidak ada jalur yang butuh refund: IMMUNITY baru dikurangi kalau jawabannya salah, SECOND CHANCE langsung hangus. Menambahkannya cuma jadi kode mati. |
| `save_quiz_attempt()` | `save_quiz_attempt()` | sama |
| `get_attempts()` | `get_attempt_scores()` + `get_last_attempt()` | dipecah 2: satu untuk badge skor di Riwayat, satu untuk attempt terakhir |

## Tahap 3 — Halaman Games (`games.php` + `game.js`)

Ganti placeholder "Games segera hadir" dengan game sungguhan. Pakai **`<canvas>`** supaya authentic ala Chrome dino; gambar koin dipakai lewat `ctx.drawImage()`.

**Tiga layar** (mengikuti mockup yang kamu kirim):

1. **Start** — judul "Run & Jump", maskot + teks "Achieve and collect certain goals/objective to trade it for spell cards!", tombol Mulai, saldo koin, rekor jarak.
2. **Main** — HUD atas: jarak (meter) + jumlah koin run ini.
3. **Results** — "Your Score / Distance Travelled / Coins Collected / Rewards Gain", tombol Main Lagi + tombol ke Shop.

**Mekanika `game.js`:**
- Canvas 960x300 (skala responsif), `ctx.imageSmoothingEnabled = false` supaya tetap pixel-art.
- Runnable digambar pakai `fillRect` di grid piksel (gaya dino Chrome) — aset karakter tidak tersedia, jadi digambar vektor agar tidak perlu aset tambahan. Koin memakai `img/gambar_koin.png`.
- Gravitasi + lompat (Space / ↑ / klik / tap), double-jump tidak ada.
- Obstacle acak: kaktus (3 varian tinggi) dan burung (2 varian tinggi) — burung baru muncul setelah jarak tertentu.
- Koin spawn acak di jalur dengan jarak antar-koin acak, terambil saat overlap.
- Kecepatan naik perlahan; makin jauh makin cepat dan makin jarang.
- Tabrak = game over → `POST api/game.php` untuk simpan run.
- `Escape` atau klik "Menu" saat main = keluar.

**Matematika(run):** `Coins Collected` = koin yang dipickup.
`Rewards Gain` = `Coins Collected` + bonus **(rekor baru / ≥200m → +20)** — supaya dua angka di layar Results punya arti dan tidak kembar.

**Shop (modal di `games.php`):** dua kartu (pakai `gambar_perisai.png` & `gambar_angka_2.png`) menampilkan icon, nama, deskripsi, harga (**IMMUNITY 50**, **SECOND CHANCE 40**), jumlah dimiliki, tombol Beli. Tombol nonaktif + pesan "Koin kurang" kalau saldo kurang.

**Saldo koin di sidebar** — tambah badge koin kecil di bawah tombol Riwayat supaya saldo terlihat di semua halaman (konfirmasi "tiap akun beda").

## Tahap 4 — Quiz rombak total (Quiziz-style)

`quiz.php` ditulis ulang, `api/quiz.php` diubah.

**Halaman soal (setelah generate):**
- **Header progres**: `Soal 3 / 10` + bar progres + `Benar: 2 · Salah: 1`. Counter **tidak** berubah warna tiap jawab (biar tidak memberi petunjuk).
- **Tidak ada warna benar/salah langsung.** Klik pilihan → jawaban langsung dicatat → **langsung** pindah ke soal berikutnya (gaya Quiziz). Tombol "Next Question" dihapus; tidak ada lagi explanation di tengah.
- **Kartu spell menempel di sisi kanan** (`position: sticky`, kolom terpisah): icon kartu + **angka jumlah kartu** di atas gambar. Klik = arm untuk soal aktif; sekali arm, kartu nonaktif sampai jawaban masuk. Status: "Active" (hijau) / "Ready" (netral).
- **Soal voided**: kalau IMMUNITY aktif dan jawaban salah → skor tidak bertambah, soal ditandai **VOID** di results. Kalau SECOND CHANCE aktif → soal di-skip, masuk antrean paling akhir, kartu hangus.
- **Navigasi**: maju otomatis setelah menjawab. Tombol "Quit" kembali ke upload.

**Layar Results (akhir quiz):**
- Skor besar: `Benar 8 / 10` (+ jumlah voided kalau ada).
- Daftar per soal: nomor, soal, jawaban kamu, jawaban benar, dan **explanation lengkap** untuk masing-masing (kenapa benar / kenapa salah).
- Tombol "Coba Lagi" + "Buat Kuis dari Materi Lain".

- **Riwayat**: `Riwayat Kuis` di modal Riwayat dan di halaman quiz menampilkan skor terakhir (`8/10`) dari `quiz_attempts`.

- **Perubahan `api/quiz.php`:** jumlah soal jadi parameter (`10`/`20`) yang masuk ke system prompt; `normalize_quiz_questions()` tetap dipakai sebagai filter.

## Tahap 5 — Styling

- `styles.css`: blok baru `/* ========== Game (Run & Jump) ========== */` dan `/* ========== Quiz Quiziz-style ========== */` — HUD, start/results card, shop card + badge jumlah kartu, progress bar soal, kolom kartu sticky, list results.
- `app.css`: warna/animasi tambahan (mis. badge koin di sidebar, kartu "active" glow).
- Semua selector baru pakai prefix `.game-` / `.quiziz-`. **Jangan sentuh** rule chat/summary yang sudah beres.
- Semua aset baru pakai `asset_url()`.

## Tahap 6 — Verifikasi

1. `php -l` semua file.
2. Smoke test HTTP semua halaman (login + render) → 0 warning/notice/fatal.
3. **Chrome headless** (`--dump-dom` + probe DOM) untuk:
   - Quiz: header `Soal 1/10` → jawab → `Soal 2/10` tanpa warna; setelah 10 → Results berisi explanation; IMMUNITY di-arm + jawaban salah → skor tidak naik & soal void; SECOND CHANCE → soal balik muncul di akhir.
   - Game: canvas render, lompat saat Space, koin bertambah, collision → game over → `game_runs` bertambah 1 di DB dan `coins` naik sesuai `Rewards Gain`.
   - Cookie session antar akun (user 1 vs user 2) → saldo koin & kartu benar-benar terpisah.
4. Screenshot untuk konfirmasi visual ketiga halaman.

## Urutan pengerjaan

Aset → DB/fungsi → Games (+Shop) → Quiz → Riwayat/attempt → styling → verifikasi.

## Yang belum diputuskan (nilai sementara, gampang diubah)

- Harga kartu: **IMMUNITY 50**, **SECOND CHANCE 40** (simpan sebagai konstanta di satu tempat).
- Bonus jarak: **200m → +20 koin**. Kalau maunya lebih mudah atau lebih berat, tinggal ubah satu angka.
- Koin per run: **1 koin per pickup**, tanpa rate limit.
