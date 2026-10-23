# Fixture classifier tanda tangan

Dataset kecil untuk HTTP fake tests dan evaluasi Groq opt-in. Label ada di `manifest.json`. Test HTTP fake membuktikan keputusan backend saja, meskipun memakai file gambar nyata. Evaluasi opt-in mengirim gambar ke Groq dan mencatat hasil sebenarnya.

| File | Asal dan hak penggunaan | Batas representasi |
| --- | --- | --- |
| `cursive.jpg` | [JohnHancockSignature.jpg, Wikimedia Commons](https://commons.wikimedia.org/wiki/File:JohnHancockSignature.jpg), public domain; reproduksi tanda tangan John Hancock pada Declaration of Independence, William Stone, 1776; crop/enhancement oleh Tim Packer. Original: https://upload.wikimedia.org/wikipedia/commons/7/71/JohnHancockSignature.jpg | Sampel tanda tangan historis asli yang direproduksi; bukan sampel pengguna saat ini. |
| `initials.png` | Crop 132×112 bagian awal dari `cursive.jpg`, hak penggunaan mengikuti sumber public domain. | Crop tanda awal/initial, bukan sampel paraf independen. |
| `transparent.png` | Turunan `cursive.jpg`; piksel terang diubah transparan dan tinta menjadi gelap. | Variasi dari penulis yang sama; preprocessing dapat menghilangkan tinta tipis. |
| `paper.png` | `cursive.jpg` diletakkan pada canvas kertas 480×190. | Simulasi margin scan; sumbernya sudah memiliki latar kertas. Bukan foto ponsel dengan pencahayaan/perspektif nyata. |
| `animal-photo.jpg` | [Closeup of a pet dog, Good Free Photos](https://www.goodfreephotos.com/animals/mammals/close-up-of-a-pet-dog.jpg.php), CC0/Public Domain sebagaimana dinyatakan pada halaman sumber. Original: https://www.goodfreephotos.com/albums/animals/mammals/close-up-of-a-pet-dog.jpg ; diperkecil menjadi lebar 480 px dan JPEG quality 85. | Satu foto hewan. |
| `meme.png` | Foto hewan di atas dengan caption buatan proyek. CC0/Public Domain untuk foto; perubahan fixture ini boleh digunakan untuk pengujian. | Berkorelasi dengan foto hewan. |
| `penguin.png` | Kartun hitam-putih geometris dibuat khusus sebagai fixture proyek. | Satu gaya kartun; bukan kumpulan kartun umum. |
| `logo.png` | Logo geometris dengan tulisan fiktif ACME COMPANY dibuat khusus sebagai fixture proyek. | Bukan logo perusahaan nyata. |
| `screenshot.png` | Tampilan dashboard/browser sintetis dibuat khusus sebagai fixture proyek. | Screenshot-like; bukan capture aplikasi/browser nyata. |

Fixture kartun/logo/dashboard/caption dibuat untuk tugas ini, tanpa gambar privat pengguna dan tanpa data akun nyata. Ukuran seluruh file mengikuti batas upload Phase A. Perubahan tersebut menggunakan GD yang sudah digunakan proyek; tidak ada dependensi tambahan.

Dataset ini bukan validation benchmark yang representatif. Sebelum produksi, tambahkan tanda tangan/initials independen yang diizinkan pemiliknya, tanda tangan pendek, tinta tipis, foto kertas dari ponsel, variasi latar, serta kartun/foto orang/objek/logo/screenshot nyata. Pisahkan dataset pengembangan dan evaluasi. Jangan menyebut keberhasilan dataset kecil ini sebagai 100% akurasi.

Untuk dataset tambahan, buat direktori berisi `manifest.json` berbentuk array dengan `id` netral, `file` berupa basename PNG/JPEG/WebP, dan `expected` bernilai `signature` atau `non_signature`, lalu set `GROQ_SIGNATURE_FIXTURES` ke direktori itu. Jangan memasukkan identitas pemilik tanda tangan pada id atau report. Menjalankan evaluasi berarti gambar dikirim ke Groq.
