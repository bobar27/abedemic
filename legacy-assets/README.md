# Aset Legacy Repositori Original

Folder ini berisi file dari repo asli Abedemic yang ikut masuk workspace saat kedua repo
di-merge. File-file di sini **sengaja disimpan, tidak dihapus**, karena diperlukan untuk
menyusun pull request ke repo original.

## Kenapa dipindahkan, bukan dihapus?

`git merge` dari repo original menyertakan aset versi lama yang sekarang sudah tidak
dipakai aplikasi. Semuanya adalah duplikat dari aset bersih yang sudah ada di `img/`.
Kalau dihapus, riwayat PR jadi sulit dilacak; karena itu semua file dipindahkan ke sini
supaya tetap ikut ter-commit tanpa mengganggu folder utama.

## Isi

### Prototipe statis (sudah digantikan versi `.php`)

| File | Digantikan oleh |
|---|---|
| `index.html` | `index.php` |
| `login.html` | `login.php` |
| `register.html` | `register.php` |

Isinya masih HTML statis lama dengan path gambar yang tidak lagi ada
(mis. `img/rimuru mascot.jpeg`), jadi tidak bisa dijalankan lagi.

### Gambar duplikat (versi lama)

Sudah ada padanannya yang bersih di `img/`:

| File legacy | Dipakai aplikasi sebagai |
|---|---|
| `ask_our_ai-removebg-preview.png`, `ask our ai.jpeg` | `img/ask-our-ai.png` |
| `sumarry_pic-removebg-preview.png`, `sumarry pic.jpeg` | `img/summary-pic.png` |
| `quiz_pic-removebg-preview.png`, `quiz pic.jpeg` | `img/quiz-pic.png` |
| `game_pic-removebg-preview.png`, `game pic.jpeg` | `img/game-pic.png` |
| `rimuru mascot.jpeg` | `img/rimuru-mascot.jpeg` |
| `crocodile mascot.jpeg` | `img/crocodile-mascot.jpeg` |
| `dino mascot.jpeg` | `img/dino-mascot.jpeg` |
| `elf mascot.jpeg` | `img/elf-mascot.jpeg` |
| `folder pic.jpeg` | `img/folder-pic.jpeg` |

### Background

`background.jpg` dan `bg.jpg` tidak dipakai. Yang dipakai aplikasi adalah `img/bg.jpg`
(`styles.css` baris `background: var(--night) url('img/bg.jpg')`).

## Catatan

Folder `legacy-assets/` tidak boleh dihapus selama PR masih disusun.