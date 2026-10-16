# Dokumen Kita API

Dokumen Kita adalah aplikasi untuk menyusun dokumen, mengatur penerima sesuai urutan, dan meminta persetujuan dengan coretan tanda tangan. Owner dapat membuat draft atau mengimpor PDF/DOCX, mengubah hasil impor di editor, meninjau PDF preview, mengatur posisi tanda tangan, lalu menerbitkan dokumen. Penerima yang sedang mendapat giliran dapat membaca, menandatangani, atau meminta revisi. Setelah semua persetujuan selesai, sistem membentuk PDF final.

Repositori ini adalah **backend API**. Antarmuka pengguna berada di proyek terpisah, `dokumen-kita-web`. Coretan tanda tangan pada aplikasi ini bukan tanda tangan digital tersertifikasi; impor PDF hasil scan juga belum memiliki OCR.

## Arsitektur

```text
Browser / dokumen-kita-web (Vue)
    │  HTTP + cookie sesi Sanctum + CSRF
    ▼
Laravel API (/api/v1)
    ├── Controller, Form Request, Policy, Resource
    ├── Service: aturan dokumen, versi, undangan, tanda tangan
    ├── MySQL: akun, dokumen, status, sesi, cache, antrean, audit
    ├── Disk privat: sumber PDF/DOCX, preview, aset tanda tangan, PDF final
    └── Scheduler → job queue: konversi, preview, finalisasi, email
```

API memakai GUID untuk identitas publik. Owner dan penerima mendapat akses berbeda melalui policy. Perubahan draft menggunakan `editor_revision` agar autosave atau hasil konversi lama tidak menimpa edit terbaru. Berkas disimpan privat dan diambil melalui endpoint yang memeriksa izin. Email undangan dibuat ketika giliran penerima menjadi aktif.

Kode utama berada di `routes/api.php`, `app/Http/Controllers/Api/V1`, `app/Http/Requests`, `app/Http/Resources`, `app/Policies`, `app/Services`, dan `app/Jobs`. Struktur data ada di `app/Models` dan `database/migrations`. Batas proses dokumen ada di `config/documents.php`.

## Peta modul dan lokasi kode

Mulai dari `routes/api.php` untuk menemukan endpoint. Route menunjuk ke controller; validasi input biasanya ada di `app/Http/Requests`, aturan akses di `app/Policies`, aturan bisnis di `app/Services`, dan bentuk respons di `app/Http/Resources`. Model dan relasi database berada di `app/Models`; perubahan skema ada di `database/migrations`.

| Grup route di `routes/api.php` | Controller / request utama | Service dan lokasi terkait |
| --- | --- | --- |
| `auth/*`, `me` | `AuthController`, `GoogleAuthController`, `PasswordResetController`, `EmailVerificationController`, `MeController`; `app/Http/Requests/Auth/*` | `AuthService`, `GoogleAuthService`, `GoogleOAuthProvider`, `PasswordResetService`; `UserResource`, `AuthIdentity` |
| `documents/*`, `documents/{document}/imports` | `DocumentController`, `DocumentImportController`; `StoreDocumentRequest`, `ImportDocumentRequest`, `ListDocumentsRequest` | `DocumentService`, `SourceFileInspector`; `DocumentResource`, `Document`, `DocumentPolicy` |
| `versions/*` | `DocumentVersionController`, `VersionPreviewController`, `VersionConversionController`, `VersionSourceController`, `VersionFinalizationController`, `VersionDecorationController`, `VersionSignerController`, `VersionPublishController`, `DocumentPdfController`; request terkait di `app/Http/Requests/Document` | `VersionWorkflow`, `DocumentConversionService`, `SourceDocumentConverter`, `DocumentPipelineService`, `VersionDecorationService`, `PublishService`, `PdfDocumentRenderer`, `StoredPreviewSnapshot`; `DocumentVersionResource`, `DocumentVersionPolicy` |
| `assignments/*` | `AssignmentPlaceholderController`, `AssignmentRevisionController`, `AssignmentSignatureController`, `ReadConfirmationController`, `InvitationController`; request terkait di `app/Http/Requests/Document` | `SigningSetupService`, `RevisionService`, `SignatureService`, `ReadConfirmationService`, `InvitationService`; `SignerAssignmentPolicy`, `SignaturePlaceholderResource`, `SignerAssignmentResource`, `RevisionResultResource` |
| `signature-assets/*` | `SignatureAssetController`; `StoreSignatureAssetRequest` | `SignatureAssetService`; `SignatureAssetResource`, `SignatureAssetPolicy`, model `SignatureAsset` |
| `invitations/claim` | `InvitationController`; `ClaimInvitationRequest` | `InvitationService`; model `InvitationLink` dan `SignerAssignment` |

### Lanjutan alur dan referensi

- Model dan data: `app/Models`; schema dan indeks: `database/migrations`.
- Pekerjaan asynchronous: `app/Jobs` (`ConvertDocumentImport`, `GenerateDocumentPreview`, `FinalizeDocument`, `DeliverDocumentEmail`); alur antrean dan orkestrasi: `DocumentPipelineService`.
- Email, audit, dan notifikasi: `DocumentEmailService`, `AuditRecorder`, `app/Models/EmailNotification.php`, `app/Models/AuditEvent.php`, serta model notifikasi terkait.
- Pengaturan upload, disk, antrean dokumen, dan batas pemrosesan: `config/documents.php`; definisi jadwal: `routes/console.php`.
- Tes endpoint dan workflow: `tests/Feature`; factory dan data uji: `database/factories` dan `tests/Feature/*Fixtures.php`.
- Kontrak endpoint: `docs/openapi.yaml`. Untuk daftar route hasil registrasi Laravel: `php artisan route:list --path=api --no-interaction`.

## Teknologi dan library utama

| Komponen | Peran |
| --- | --- |
| PHP 8.3 dan Laravel 13 | Runtime, routing API, validasi, Eloquent, queue, scheduler, dan notifikasi. |
| MySQL | Database bawaan pada `.env.example`; menyimpan data aplikasi, sesi, cache, dan job queue. |
| Laravel Sanctum | Autentikasi frontend melalui cookie sesi dan CSRF. |
| Laravel Socialite | Login Google melalui OAuth, bila kredensial Google disediakan. |
| Dompdf | Merender blok editor menjadi PDF preview. |
| FPDI dan FPDF | Membaca PDF preview dan menempatkan gambar tanda tangan pada PDF final. |
| `smalot/pdfparser` | Fallback ekstraksi teks dan pembacaan gambar PDF. |
| `pdftotext` | Binary eksternal yang diutamakan untuk ekstraksi teks PDF; fallback PHP tersedia jika binary tidak ada. |
| PHP ZipArchive, DOM, dan GD | Membaca DOCX/XML serta memeriksa aset gambar tanda tangan. |
| Predis | Klien Redis yang tersedia bila deployment memilih Redis. Konfigurasi lokal bawaan memakai database untuk cache dan queue. |
| PHPUnit dan Laravel Pint | Pengujian dan format kode saat pengembangan. |

`package.json` di repositori backend menyediakan Vite/Tailwind untuk aset Laravel. Frontend aplikasi pengguna dan dependensinya berada di `dokumen-kita-web`; menjalankan API ini tidak memerlukan server Vite backend.

## Koneksi dan alamat API

Contoh konfigurasi lokal memakai backend `http://localhost:8000` dan frontend `http://localhost:5173`. Endpoint aplikasi berada di **`http://localhost:8000/api/v1`**. Frontend mengisi `VITE_API_BASE_URL=http://localhost:8000` tanpa tambahan `/api/v1`; service frontend menambahkan prefix tersebut. Frontend juga perlu `VITE_AUTH_MODE=api` agar menggunakan backend nyata.

| Variabel backend | Fungsi dan contoh lokal |
| --- | --- |
| `APP_URL` | Origin backend, misalnya `http://localhost:8000`; dipakai juga untuk link verifikasi dan callback OAuth. |
| `FRONTEND_URL` | Origin frontend, misalnya `http://localhost:5173`; tujuan redirect login Google, verifikasi email, dan link undangan. |
| `CORS_ALLOWED_ORIGINS` | Origin frontend yang boleh mengakses API dengan cookie. |
| `SANCTUM_STATEFUL_DOMAINS` | Host:port frontend dan backend yang memakai sesi Sanctum. |
| `DB_*` | Koneksi MySQL; database lokal pada contoh bernama `dokumen_kita_api`. |
| `DOCUMENT_DISK` | Disk privat untuk sumber, preview, tanda tangan, dan final; contoh lokal `local`. |
| `DOCUMENT_QUEUE_CONNECTION`, `DOCUMENT_QUEUE` | Koneksi dan nama queue pekerjaan dokumen; contoh `database` dan `documents`. |
| `MAIL_*` | Transport email. Nilai bawaan `MAIL_MAILER=log` menulis email ke log lokal, bukan mengirim ke inbox. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | Opsional untuk login Google. Redirect URI harus menunjuk ke `/api/v1/auth/google/callback` pada origin backend. |
| `PDFTOTEXT_BINARY` | Opsional jika lokasi executable `pdftotext` tidak ada di `PATH`. |

Untuk request dari browser, frontend mengambil cookie CSRF melalui `/sanctum/csrf-cookie` dan mengirim cookie sesi pada request berikutnya. Gunakan host yang konsisten pada kedua proyek, misalnya sama-sama `localhost`, agar pengaturan cookie tidak berbeda antara `localhost` dan `127.0.0.1`. Endpoint kesehatan Laravel tersedia di `/up`; daftar endpoint dapat dilihat dengan `php artisan route:list --path=api --no-interaction`.

## Menjalankan backend di lokal

Contoh perintah di bawah memakai PowerShell dan alamat dari `.env.example`. Siapkan PHP 8.3 beserta ekstensi yang diperlukan (terutama `pdo_mysql`, `zip`, `dom`/XML, dan `gd`), Composer, serta MySQL yang sedang berjalan. `pdftotext` dianjurkan untuk impor PDF digital. Node.js/npm hanya diperlukan jika ingin membangun aset Laravel di repositori backend.

1. Buka terminal di folder `dokumen-kita-api`, lalu pasang dependensi PHP dan salin konfigurasi:

   ```powershell
   composer install
   Copy-Item .env.example .env
   ```

2. Buat database MySQL bernama `dokumen_kita_api` (misalnya melalui Laragon/phpMyAdmin). Sesuaikan `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD` di `.env`. Pastikan `APP_URL`, `FRONTEND_URL`, CORS, dan domain Sanctum cocok dengan alamat yang akan dipakai. Jangan memasukkan kredensial nyata ke Git.

3. Buat app key dan jalankan migration:

   ```powershell
   php artisan key:generate --no-interaction
   php artisan migrate --no-interaction
   ```

4. Jalankan API pada terminal pertama:

   ```powershell
   php artisan serve --host=localhost --port=8000
   ```

5. Jalankan worker pada terminal kedua dan scheduler pada terminal ketiga. Keduanya diperlukan agar impor, preview, PDF final, dan email antrean diproses:

   ```powershell
   php artisan queue:work database --queue=documents,default --sleep=1 --tries=3 --timeout=75
   ```

   ```powershell
   php artisan schedule:work
   ```

6. Periksa `http://localhost:8000/up` dan, jika diperlukan, `php artisan route:list --path=api --no-interaction`.

`DB_QUEUE_RETRY_AFTER` harus lebih besar daripada timeout worker (contoh bawaan: 90 detik vs 75 detik). Pada server yang dipasang permanen, jalankan worker sebagai proses terkelola dan panggil `php artisan schedule:run` setiap menit melalui scheduler server. `composer run dev` bawaan tidak menjalankan scheduler pekerjaan dokumen.

### Menjalankan frontend bersama API

Jika repositori `dokumen-kita-web` ada di folder yang sejajar, siapkan Node.js sesuai `engines` pada `package.json` frontend dan buka terminal baru:

```powershell
Set-Location ..\dokumen-kita-web
Copy-Item .env.example .env.local
npm ci
```

Ubah `.env.local` frontend menjadi `VITE_AUTH_MODE=api` dan `VITE_API_BASE_URL=http://localhost:8000`, lalu jalankan `npm run dev`. Buka alamat yang ditampilkan Vite, biasanya `http://localhost:5173`. Pastikan backend tetap berjalan pada terminalnya. Integrasi UI untuk seluruh alur PDF editable dijabarkan pada PRD frontend di `docs/`.

## Email, Google, dan Redis

- Konfigurasi `MAIL_MAILER=log` cukup untuk melihat isi email saat pengembangan di `storage/logs`. Atur transport SMTP dan worker queue jika perlu menguji pengiriman email nyata; status diterima transport tidak menjamin email masuk inbox.
- Login email/password dapat dicoba tanpa Google OAuth. Untuk login Google, isi kredensial Google, daftarkan redirect URI backend yang tepat di penyedia OAuth, lalu cocokkan `GOOGLE_REDIRECT_URI` dengan URI tersebut.
- Redis bersifat opsional pada contoh lokal. Jika memilih Redis untuk cache/queue, jalankan server Redis dan sesuaikan `CACHE_STORE`, `QUEUE_CONNECTION`/`DOCUMENT_QUEUE_CONNECTION`, serta `REDIS_CLIENT` dan `REDIS_*`. Jangan mengasumsikan Redis aktif hanya karena paket Predis terpasang.

## Pengembangan dan pemeriksaan

```powershell
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

Gunakan tes terarah saat mengubah satu alur. Pengujian otomatis tidak menggantikan pemeriksaan PDF visual, MySQL untuk aksi bersamaan, atau email melalui SMTP/inbox uji. Spesifikasi OpenAPI tersedia pada `openapi.yaml`; handoff backend dan PRD frontend berada di `docs/`.
