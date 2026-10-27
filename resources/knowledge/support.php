<?php

// Audited application facts only. Source paths are for maintainers, never model context.
return [
    [
        'id' => 'registration',
        'concise_answer' => [
            'id' => 'Daftar dengan nama, email, kata sandi, dan konfirmasinya. Setelah itu, buka tautan verifikasi yang dikirim ke email kamu.',
            'en' => 'Register with your name, email, password, and password confirmation. Then open the verification link sent to your email.',
        ],
        'email_answer' => [
            'id' => 'Iya, setelah mendaftar kamu akan menerima email verifikasi akun.',
            'en' => 'Yes, you will receive an account verification email after registering.',
        ],
        'missing_email_answer' => [
            'id' => 'Coba cek folder spam. Kalau belum ada, login lalu minta pengiriman ulang email verifikasi.',
            'en' => 'Check your spam folder. If it is still missing, log in and request another verification email.',
        ],
        'module' => 'authentication',
        'sources' => ['app/Services/AuthService.php', 'app/Http/Requests/Auth/RegisterRequest.php', 'tests/Feature/AuthApiTest.php'],
        'aliases' => [
            'registrasi akun', 'mendaftar akun', 'buat akun', 'daftar akun', 'register account', 'create account', 'sign up',
            'daftar akun baru', 'mendaftar akun baru', 'membuat akun baru', 'belum punya akun', 'register a new account', 'create a new account',
        ],
        'question' => ['id' => 'Bagaimana cara mendaftar akun?', 'en' => 'How do I create an account?'],
        'answer' => [
            'id' => 'Daftar menggunakan nama, email yang belum digunakan, kata sandi, dan konfirmasi kata sandi. Registrasi membuat akun dan sesi login, lalu menyiapkan email verifikasi. Buka tautan verifikasi yang dikirim ke email Anda.',
            'en' => 'Register with your name, an unused email address, a password, and password confirmation. Registration creates your account and login session and queues a verification email. Open the verification link sent to your email address.',
        ],
    ],
    [
        'id' => 'login',
        'concise_answer' => [
            'id' => 'Masuk dengan email dan kata sandi akun kamu, atau gunakan login Google.',
            'en' => 'Log in with your account email and password, or use Google sign-in.',
        ],
        'module' => 'authentication',
        'sources' => ['app/Services/AuthService.php', 'routes/api.php', 'tests/Feature/AuthApiTest.php', 'tests/Feature/GoogleAuthTest.php'],
        'aliases' => [
            'login', 'log in', 'masuk akun', 'sign in', 'login google', 'masuk google', 'google sign in',
            'masuk ke akun', 'masuk pakai google', 'login pakai google', 'login dengan google',
            'tidak bisa login', 'login email salah', 'login kata sandi salah', 'log into my account', 'sign in with google',
            'logout', 'keluar akun', 'mengakhiri sesi', 'log out of my account',
            'can not log in', 'can not login',
        ],
        'question' => ['id' => 'Bagaimana cara login?', 'en' => 'How do I log in?'],
        'answer' => [
            'id' => 'Login menggunakan email dan kata sandi akun Anda. Dokumen-Kita juga menyediakan alur login Google. Jika email atau kata sandi salah, login ditolak; percobaan login dibatasi. Gunakan logout untuk mengakhiri sesi.',
            'en' => 'Log in with your account email address and password. Dokumen-Kita also provides a Google sign-in flow. Incorrect email or password credentials are rejected, and login attempts are rate limited. Use logout to end your session.',
        ],
    ],
    [
        'id' => 'email_verification',
        'concise_answer' => [
            'id' => 'Buka tautan di email verifikasi; kalau perlu, minta pengiriman ulang setelah login. Email harus terverifikasi sebelum kamu mengklaim undangan atau menandatangani dokumen.',
            'en' => 'Open the link in your verification email; you can request another after logging in. Verify your email before claiming an invitation or signing a document.',
        ],
        'module' => 'authentication',
        'sources' => ['app/Http/Controllers/Api/V1/EmailVerificationController.php', 'app/Services/VersionWorkflow.php', 'tests/Feature/AuthApiTest.php'],
        'aliases' => [
            'verifikasi email', 'kirim ulang verifikasi email', 'verify email', 'email verification', 'resend verification email',
            'email verifikasi tidak masuk', 'email verifikasi belum masuk', 'email verifikasi tidak sampai',
            'kirim ulang email verifikasi', 'verifikasi email akun', 'verify my email address',
            'verification email has not arrived', 'resend my verification email',
        ],
        'question' => ['id' => 'Bagaimana cara verifikasi email?', 'en' => 'How do I verify my email?'],
        'answer' => [
            'id' => 'Buka tautan pada email verifikasi Dokumen-Kita. Anda dapat meminta pengiriman ulang email verifikasi setelah login; permintaan dibatasi. Email akun harus terverifikasi untuk mengklaim undangan dan menandatangani dokumen.',
            'en' => 'Open the link in your Dokumen-Kita verification email. After logging in, you can request another verification email; requests are rate limited. Your account email must be verified to claim an invitation and sign a document.',
        ],
    ],
    [
        'id' => 'password_reset',
        'concise_answer' => [
            'id' => 'Gunakan lupa kata sandi, verifikasi OTP dari email, lalu masukkan kata sandi baru beserta konfirmasinya. Alur ini tidak tersedia untuk akun khusus Google yang belum memiliki kata sandi.',
            'en' => 'Use forgot password, verify the emailed OTP, then enter and confirm your new password. This flow is unavailable for Google-only accounts without a password.',
        ],
        'module' => 'authentication',
        'sources' => ['app/Services/PasswordResetService.php', 'tests/Feature/PasswordResetOtpTest.php'],
        'aliases' => [
            'lupa kata sandi', 'lupa password', 'reset password', 'forgot password', 'reset kata sandi',
            'lupa sandi akun', 'password lupa', 'reset password pakai otp', 'atur ulang kata sandi',
            'forgot my password', 'reset my forgotten password', 'forgotten password',
            'mengatur ulang kata sandi', 'verifikasi otp reset password',
        ],
        'question' => ['id' => 'Bagaimana jika saya lupa kata sandi?', 'en' => 'How do I reset a forgotten password?'],
        'answer' => [
            'id' => 'Untuk akun yang memiliki kata sandi, gunakan alur lupa kata sandi dengan email akun, verifikasi kode OTP yang dikirim melalui email, lalu masukkan kata sandi baru beserta konfirmasinya. Akun khusus Google tanpa kata sandi tidak menggunakan alur ini. OTP dan percobaan verifikasi dibatasi; gunakan kode yang masih berlaku.',
            'en' => 'For accounts with a password, use the forgot-password flow with your account email, verify the OTP sent by email, and enter a new password with confirmation. Google-only accounts without a password do not use this flow. OTP validity and verification attempts are limited; use an unexpired code.',
        ],
    ],
    [
        'id' => 'dashboard',
        'concise_answer' => [
            'id' => 'Cari judul di daftar dokumen kamu, lalu gunakan filter status, kategori, atau visibilitas sesuai kebutuhan.',
            'en' => 'Search by title in your document list, then filter by status, category, or visibility as needed.',
        ],
        'module' => 'dashboard',
        'sources' => ['app/Services/DocumentService.php', 'app/Http/Requests/Document/ListDocumentsRequest.php', 'tests/Feature/DocumentVisibilityTest.php'],
        'aliases' => [
            'dashboard', 'dasbor', 'cari dokumen', 'filter dokumen', 'search documents', 'filter documents',
            'mencari dokumen berdasarkan judul', 'mencari dokumen saya', 'mencari dokumen gue', 'memfilter dokumen',
            'filter dokumen berdasarkan status', 'mencari dokumen di dashboard', 'find my documents',
            'search for a document by title', 'filter my documents by status',
            'filter dokumen tanpa kategori', 'filter dokumen berdasarkan kategori', 'filter dokumen tersembunyi',
        ],
        'question' => ['id' => 'Bagaimana cara mencari dan memfilter dokumen?', 'en' => 'How do I search and filter documents?'],
        'answer' => [
            'id' => 'Daftar dokumen milik Anda mendukung pencarian judul dan filter status, kategori, tanpa kategori, serta dokumen aktif atau disembunyikan. Daftar memakai pagination. Asisten ini hanya memberi panduan umum dan tidak dapat memeriksa jumlah atau status dokumen akun Anda.',
            'en' => 'Your owned-document list supports title search and filters for status, category, uncategorized documents, and active or hidden documents. Results are paginated. This assistant provides general guidance and cannot inspect your account document counts or statuses.',
        ],
    ],
    [
        'id' => 'document_categories',
        'concise_answer' => [
            'id' => 'Kamu bisa membuat dan mengubah kategori, lalu memasang atau melepasnya dari dokumen milikmu. Sebelum menghapus kategori yang masih dipakai, pindahkan atau lepaskan kategorinya dari dokumen terlebih dahulu.',
            'en' => 'Create or edit categories and assign or remove them from your own documents. Before deleting a category in use, remove or change its document assignments.',
        ],
        'module' => 'categories',
        'sources' => ['app/Services/DocumentCategoryService.php', 'app/Http/Controllers/Api/V1/DocumentCategoryController.php', 'tests/Feature/DocumentCategoryApiTest.php'],
        'aliases' => [
            'kategori dokumen', 'document category', 'document categories', 'buat kategori', 'hapus kategori',
            'create category', 'delete category', 'ubah kategori', 'assign category',
            'mengelola kategori dokumen', 'membuat kategori baru', 'menghapus kategori yang masih dipakai',
            'kategori tidak bisa dihapus', 'lepas kategori dokumen', 'create a new document category',
            'category is still in use', 'remove a category from my document',
            'menetapkan kategori dokumen', 'melepas kategori dokumen', 'mengubah kategori beberapa dokumen sekaligus',
        ],
        'question' => ['id' => 'Bagaimana cara mengelola kategori dokumen?', 'en' => 'How do I manage document categories?'],
        'answer' => [
            'id' => 'Kategori dikelola oleh pemilik akun dengan nama, warna yang didukung, dan deskripsi opsional. Anda dapat membuat, mengubah, dan menetapkan atau melepas kategori pada dokumen milik Anda, termasuk beberapa dokumen sekaligus. Kategori yang masih dipakai dokumen tidak dapat dihapus; lepaskan atau pindahkan kategorinya terlebih dahulu.',
            'en' => 'Account owners manage categories with a name, a supported color, and an optional description. You can create or edit categories and assign or remove a category on your own documents, including multiple documents at once. A category still used by documents cannot be deleted; remove or change those category assignments first.',
        ],
    ],
    [
        'id' => 'create_document',
        'concise_answer' => [
            'id' => 'Buat dokumen dengan judul, lalu isi draft lewat editor atau impor PDF/DOCX. Sebelum publish, siapkan preview terbaru, penerima, urutan, dan posisi tanda tangannya.',
            'en' => 'Create a document with a title, then edit the draft or import a PDF/DOCX. Before publishing, prepare the latest preview, recipients, signing order, and signature positions.',
        ],
        'module' => 'documents',
        'sources' => ['app/Services/DocumentService.php', 'tests/Feature/DocumentApiTest.php'],
        'aliases' => [
            'buat dokumen', 'membuat dokumen', 'bikin dokumen', 'dokumen baru', 'create document', 'new document', 'create draft',
            'membuat dokumen baru', 'membuat draft baru', 'mulai dokumen baru', 'create a new document', 'start a new draft',
        ],
        'question' => ['id' => 'Bagaimana cara membuat dokumen?', 'en' => 'How do I create a document?'],
        'answer' => [
            'id' => 'Buat dokumen dengan judul untuk menghasilkan draft versi pertama. Isi draft dapat diedit atau berasal dari impor PDF/DOCX. Hanya pemilik yang dapat mengubah draft terkini. Siapkan isi, preview terbaru, penerima, urutan, dan posisi tanda tangan sebelum menerbitkan dokumen.',
            'en' => 'Create a document with a title to generate its first draft version. Draft content can be edited or imported from PDF/DOCX. Only the owner can edit the current draft. Prepare the content, current preview, recipients, signing order, and signature positions before publishing.',
        ],
    ],
    [
        'id' => 'document_visibility',
        'concise_answer' => [
            'id' => 'Sembunyikan dokumen milikmu lewat pengaturan visibilitas, lalu gunakan filter dokumen tersembunyi untuk menemukannya kembali. Ini tidak menghapus dokumen atau membatalkan alur tanda tangan.',
            'en' => 'Hide your document through visibility settings and use the hidden-document filter to find it again. Hiding does not delete the document or cancel signing.',
        ],
        'module' => 'documents',
        'sources' => ['app/Services/DocumentService.php', 'tests/Feature/DocumentVisibilityTest.php'],
        'aliases' => [
            'sembunyikan dokumen', 'dokumen tersembunyi', 'hide document', 'hidden documents', 'restore hidden document',
            'tampilkan dokumen tersembunyi', 'menyembunyikan dokumen', 'sembunyiin dokumen',
            'balikin dokumen tersembunyi', 'menampilkan kembali dokumen tersembunyi', 'dokumen tidak terlihat di daftar aktif',
            'hide a document from my active list', 'restore a hidden document',
        ],
        'question' => ['id' => 'Bagaimana cara menyembunyikan dokumen?', 'en' => 'How do I hide a document?'],
        'answer' => [
            'id' => 'Pemilik dapat menyembunyikan dokumen dari daftar aktif dan menampilkannya kembali melalui pengaturan visibilitas. Gunakan filter dokumen tersembunyi untuk melihatnya. Menyembunyikan dokumen tidak menghapusnya atau membatalkan alur tanda tangan.',
            'en' => 'The owner can hide a document from the active list and restore it through visibility settings. Use the hidden-document filter to find it. Hiding a document does not delete it or cancel its signing workflow.',
        ],
    ],
    [
        'id' => 'import_document',
        'concise_answer' => [
            'id' => 'Impor PDF atau DOCX ke draft terkini dan konfirmasi penggantian jika draft sudah berisi konten. Setelah konversi selesai, periksa isi dan tata letak, lalu buat preview terbaru dan tinjau posisi tanda tangan sebelum publish.',
            'en' => 'Import a PDF or DOCX into the current draft and confirm replacement if it already has content. After conversion, review the content and layout, generate a fresh preview, and check signature positions before publishing.',
        ],
        'module' => 'imports',
        'sources' => ['app/Services/DocumentService.php', 'app/Services/SourceFileInspector.php', 'tests/Feature/ImportConversionTest.php'],
        'aliases' => [
            'impor dokumen', 'import document', 'impor pdf', 'impor docx', 'import pdf', 'import docx',
            'upload pdf', 'upload docx', 'unggah pdf', 'unggah docx', 'pdf docx', 'upload word', 'unggah word', 'impor word', 'import word',
            'mengimpor pdf', 'mengimpor docx', 'unggah file word', 'impor berkas docx', 'impor word ke draft',
            'hasil impor berubah tata letak', 'import a word file into my draft', 'upload a docx file', 'import layout changed',
            'unggah file word ke draft', 'unggah dokumen pdf', 'unggah dokumen docx',
        ],
        'question' => ['id' => 'Bagaimana cara impor PDF atau DOCX?', 'en' => 'How do I import a PDF or DOCX?'],
        'answer' => [
            'id' => 'Impor PDF atau DOCX ke draft dokumen terkini. Berkas diperiksa dan disimpan privat, lalu masuk antrean konversi ke isi editor. Jika draft sudah berisi konten, penggantian perlu dikonfirmasi. Tunggu konversi, tinjau isi hasil impor, buat preview dari editor terbaru, dan periksa kembali posisi tanda tangan sebelum publish. Hasil konversi perlu ditinjau karena tata letak dapat berubah.',
            'en' => 'Import a PDF or DOCX into the current document draft. The file is checked, stored privately, and queued for conversion into editor content. Replacing existing draft content requires confirmation. Wait for conversion, review the imported content, generate a preview from the latest editor content, and review signature positions before publishing. Review the conversion because layout may change.',
        ],
    ],
    [
        'id' => 'editor_autosave',
        'concise_answer' => [
            'id' => 'Perubahan disimpan di draft terkini; muat ulang jika ada konflik revisi dari sesi lain. Setelah mengubah isi, buat preview baru dan tinjau kembali posisi tanda tangan.',
            'en' => 'Changes are saved to the current draft; reload if another session caused a revision conflict. After changing content, generate a new preview and review signature positions.',
        ],
        'module' => 'editor',
        'sources' => ['app/Services/DocumentService.php', 'app/Http/Requests/Document/UpdateVersionRequest.php', 'tests/Feature/DocumentApiTest.php'],
        'aliases' => [
            'autosave', 'editor', 'simpan draft', 'save draft', 'edit dokumen', 'edit document', 'editor revision conflict', 'konflik simpan',
            'mengubah isi dokumen', 'mengubah draft', 'simpan perubahan draft', 'draft berubah dari sesi lain',
            'draft tidak bisa disimpan karena konflik revisi', 'edit my document', 'save changes to my draft', 'draft changed in another session',
        ],
        'question' => ['id' => 'Bagaimana penyimpanan draft di editor?', 'en' => 'How does saving an editor draft work?'],
        'answer' => [
            'id' => 'Perubahan editor disimpan pada draft terkini dengan pemeriksaan revisi. Jika draft berubah dari sesi lain, muat ulang sebelum menyimpan kembali. Perubahan isi membuat preview lama tidak berlaku dan posisi tanda tangan perlu ditinjau ulang. Buat preview terbaru setelah selesai mengedit; versi yang sudah diterbitkan tidak dapat diedit langsung.',
            'en' => 'Editor changes are saved to the current draft with revision checks. If another session changed the draft, reload before saving again. Content changes invalidate the previous preview and require signature positions to be reviewed. Generate a new preview after editing; a published version cannot be edited directly.',
        ],
    ],
    [
        'id' => 'publish_invitation',
        'concise_answer' => [
            'id' => 'Siapkan draft, preview terbaru, penerima berurutan tanpa duplikasi email, dan posisi tanda tangan, lalu publish. Undangan disiapkan lewat email sesuai giliran penerima.',
            'en' => 'Prepare the draft, current preview, ordered recipients without duplicate emails, and signature positions, then publish. Invitation emails are queued according to each recipient\'s turn.',
        ],
        'email_answer' => [
            'id' => 'Iya, kamu akan menerima email undangan saat giliran tanda tanganmu aktif.',
            'en' => 'Yes, you will receive an invitation email when your signing turn becomes active.',
        ],
        'missing_email_answer' => [
            'id' => 'Coba cek folder spam. Kalau masih belum ada dan giliranmu sudah aktif, hubungi pemilik dokumen untuk memeriksa atau mengirim ulang undangan.',
            'en' => 'Check your spam folder. If it is still missing and your turn is active, ask the document owner to check or resend the invitation.',
        ],
        'module' => 'invitations',
        'sources' => ['app/Services/PublishService.php', 'app/Services/InvitationService.php', 'tests/Feature/PublishApiTest.php', 'tests/Feature/InvitationApiTest.php'],
        'aliases' => [
            'kirim undangan', 'mengirim undangan', 'send invitation', 'publish document', 'terbitkan dokumen', 'publish dokumen',
            'kirim ulang undangan', 'resend invitation', 'kirim undangan tanda tangan', 'kirim ulang undangan tanda tangan',
            'menerbitkan dokumen', 'undang penerima tanda tangan', 'send a signing invitation', 'resend a signing invitation',
        ],
        'question' => ['id' => 'Bagaimana cara mengirim undangan tanda tangan?', 'en' => 'How do I send signing invitations?'],
        'answer' => [
            'id' => 'Pemilik menyiapkan draft terkini, preview yang valid, daftar email penerima berurutan tanpa duplikasi, serta posisi tanda tangan untuk setiap penerima. Setelah publish, undangan aktif disiapkan melalui email sesuai giliran. Pengiriman ulang tersedia untuk penerima yang gilirannya aktif; tautan undangan sebelumnya dicabut.',
            'en' => 'The owner prepares the current draft, a valid preview, an ordered list of recipient emails without duplicates, and signature positions for every recipient. Publishing queues active invitations by email according to signing order. Resending is available for the recipient whose turn is active; the previous invitation link is revoked.',
        ],
    ],
    [
        'id' => 'claim_invitation',
        'concise_answer' => [
            'id' => 'Login dengan akun terverifikasi yang emailnya sesuai penerima, lalu buka tautan undangan yang masih berlaku saat giliranmu aktif. Jika tautannya kedaluwarsa atau dicabut, minta pemilik mengirim ulang saat giliranmu aktif.',
            'en' => 'Log in with the verified recipient email and open a valid invitation link when your turn is active. If the link has expired or been revoked, ask the owner to resend it while your turn is active.',
        ],
        'module' => 'invitations',
        'sources' => ['app/Services/InvitationService.php', 'tests/Feature/InvitationApiTest.php'],
        'aliases' => [
            'terima undangan', 'menerima undangan', 'buka undangan', 'claim invitation', 'accept invitation', 'open invitation',
            'undangan kedaluwarsa', 'expired invitation', 'terima undangan tanda tangan', 'buka link undangan',
            'tautan undangan kedaluwarsa', 'link undangan sudah expired', 'klaim undangan tanda tangan',
            'accept a signing invitation', 'my invitation link has expired',
        ],
        'question' => ['id' => 'Bagaimana cara menerima undangan tanda tangan?', 'en' => 'How do I claim a signing invitation?'],
        'answer' => [
            'id' => 'Login dengan akun yang emailnya sama dengan email penerima undangan dan sudah terverifikasi, lalu buka tautan undangan yang masih berlaku. Undangan hanya aktif pada giliran penerima. Tautan yang kedaluwarsa atau dicabut tidak dapat digunakan; pemilik dapat mengirim ulang undangan ketika giliran Anda aktif.',
            'en' => 'Log in with a verified account whose email matches the invitation recipient and open an unexpired invitation link. An invitation is active only on that recipient’s turn. Expired or revoked links cannot be used; the owner can resend an invitation when your turn is active.',
        ],
    ],
    [
        'id' => 'signing_order',
        'concise_answer' => [
            'id' => 'Penerima menandatangani sesuai urutan yang ditetapkan pemilik; hanya penerima yang siap pada giliran aktif yang bisa menandatangani. Setelah selesai, penerima berikutnya diaktifkan dan undangannya disiapkan.',
            'en' => 'Recipients sign in the order set by the owner; only the ready recipient on the active turn can sign. Once they finish, the next recipient is activated and their invitation is queued.',
        ],
        'module' => 'signing',
        'sources' => ['app/Services/SigningSetupService.php', 'app/Services/SignatureService.php', 'tests/Feature/SigningSetupApiTest.php', 'tests/Feature/SignatureApiTest.php'],
        'aliases' => [
            'urutan signer', 'urutan penandatangan', 'urutan tanda tangan', 'giliran tanda tangan',
            'signing order', 'signer order', 'waiting signer', 'menunggu giliran',
            'mengatur urutan penandatangan', 'atur urutan tanda tangan', 'nunggu giliran tanda tangan',
            'giliran tanda tangan belum aktif', 'set the signing order', 'waiting for my signing turn',
        ],
        'question' => ['id' => 'Bagaimana urutan penandatangan bekerja?', 'en' => 'How does signing order work?'],
        'answer' => [
            'id' => 'Pemilik menetapkan urutan penerima pada draft dari 1 sampai jumlah penerima tanpa email duplikat. Hanya penerima berstatus siap pada giliran aktif yang dapat menandatangani. Setelah ia menandatangani, penerima berikutnya diaktifkan dan undangannya disiapkan. Pemilik juga dapat menjadi salah satu penandatangan.',
            'en' => 'The owner sets the draft recipient order from 1 through the recipient count without duplicate emails. Only the ready recipient on the active turn can sign. After that recipient signs, the next recipient is activated and an invitation is queued. The owner may also be a signer.',
        ],
    ],
    [
        'id' => 'sign_document',
        'concise_answer' => [
            'id' => 'Login dengan email penerima yang terverifikasi dan terima undangan aktif. Saat giliranmu siap, baca dan konfirmasikan preview terbaru, lalu pilih aset tanda tangan milikmu dan kirim tanda tangan.',
            'en' => 'Log in with the verified recipient email and claim an active invitation. When your turn is ready, read and confirm the current preview, then choose your signature asset and submit your signature.',
        ],
        'module' => 'signing',
        'sources' => ['app/Services/SignatureService.php', 'app/Services/ReadConfirmationService.php', 'tests/Feature/SignatureApiTest.php', 'tests/Feature/SignatureAuthorizationTest.php'],
        'aliases' => [
            'menandatangani dokumen', 'tandatangani dokumen', 'tanda tangan dokumen', 'sign dokumen',
            'sign document', 'sign a document', 'sign documents', 'signing document',
            'tanda tangan di dokumen', 'konfirmasi baca sebelum tanda tangan', 'confirm reading before signing', 'sign my document',
        ],
        'question' => ['id' => 'Bagaimana cara menandatangani dokumen?', 'en' => 'How do I sign a document?'],
        'answer' => [
            'id' => 'Login dengan email penerima yang sudah terverifikasi dan terima undangan yang masih aktif. Saat giliran Anda siap, baca preview dokumen dan konfirmasikan telah membaca snapshot tersebut. Pilih aset tanda tangan milik Anda lalu kirim tanda tangan. Jika dokumen berubah atau giliran belum aktif, muat ulang atau tunggu giliran sebelum mencoba kembali.',
            'en' => 'Log in with the verified recipient email and claim an active invitation. When your turn is ready, read the document preview and confirm that you have read that snapshot. Choose your own signature asset and submit your signature. If the document changed or your turn is not active, reload or wait for your turn before trying again.',
        ],
    ],
    [
        'id' => 'signature_assets',
        'concise_answer' => [
            'id' => 'Gambar atau unggah tanda tangan dalam format PNG, JPG/JPEG, atau WebP; gambar kosong atau bukan tanda tangan akan ditolak. Aset bersifat privat dan tidak bisa dihapus setelah dipakai menandatangani.',
            'en' => 'Draw or upload a signature as PNG, JPG/JPEG, or WebP; blank or non-signature images are rejected. Assets are private and cannot be deleted after being used to sign.',
        ],
        'module' => 'signature_assets',
        'sources' => ['app/Services/SignatureAssetService.php', 'app/Http/Requests/Document/StoreSignatureAssetRequest.php', 'tests/Feature/SignatureAssetApiTest.php'],
        'aliases' => [
            'aset tanda tangan', 'signature asset', 'signature assets', 'my signs', 'tanda tangan saya', 'unggah tanda tangan',
            'upload signature', 'draw signature', 'gambar tanda tangan', 'hapus aset tanda tangan', 'delete signature asset',
            'menghapus tanda tangan tersimpan', 'unggah gambar tanda tangan', 'aset tanda tangan sudah dipakai tidak bisa dihapus',
            'delete a saved signature', 'upload my signature image', 'signature image is blank',
        ],
        'question' => ['id' => 'Bagaimana cara mengelola aset tanda tangan saya?', 'en' => 'How do I manage my signature assets?'],
        'answer' => [
            'id' => 'Aset tanda tangan milik Anda dapat berasal dari gambar yang digambar atau diunggah dalam format PNG, JPG/JPEG, atau WebP. Gambar diperiksa dan dinormalisasi; gambar kosong atau bukan tanda tangan ditolak. Aset disimpan privat dan hanya pemilik yang dapat mengelolanya. Aset yang sudah dipakai menandatangani tidak dapat dihapus.',
            'en' => 'Your signature assets can come from drawn or uploaded PNG, JPG/JPEG, or WebP images. Images are checked and normalized; blank or non-signature images are rejected. Assets are private and only their owner can manage them. An asset already used to sign cannot be deleted.',
        ],
    ],
    [
        'id' => 'signing_history',
        'concise_answer' => [
            'id' => 'Buka riwayat pribadi untuk melihat aktivitas tanda tangan dan permintaan revisi akunmu. Kamu bisa mencari judul dokumen, memfilter tanggal, dan membuka detail yang tersedia.',
            'en' => 'Open your personal history to see your signing and revision-request activity. Search by document title, filter by date, and open available details.',
        ],
        'module' => 'history',
        'sources' => ['app/Http/Controllers/Api/V1/SigningHistoryController.php', 'app/Http/Requests/SigningHistoryRequest.php', 'tests/Feature/SigningHistoryApiTest.php'],
        'aliases' => [
            'riwayat tanda tangan', 'riwayat penandatanganan', 'signing history', 'signs histories', 'signature history',
            'melihat riwayat tanda tangan', 'history tanda tangan', 'riwayat permintaan revisi',
            'mencari riwayat tanda tangan', 'filter riwayat tanda tangan berdasarkan tanggal',
            'view my signing history', 'find my revision request history',
            'melihat riwayat penandatanganan', 'melihat riwayat permintaan revisi',
        ],
        'question' => ['id' => 'Bagaimana cara melihat riwayat tanda tangan?', 'en' => 'How do I view signing history?'],
        'answer' => [
            'id' => 'Riwayat pribadi menampilkan aktivitas tanda tangan dan permintaan revisi yang dilakukan akun Anda. Anda dapat mencari judul dokumen, memfilter rentang tanggal, dan membuka detail riwayat yang tersedia. Riwayat milik akun lain tidak dapat diakses. Riwayat lama mungkin tidak memiliki snapshot lengkap.',
            'en' => 'Personal history lists signing and revision-request activities performed by your account. You can search document titles, filter by a date range, and open available history details. Other accounts history is inaccessible. Older history entries may lack a complete snapshot.',
        ],
    ],
    [
        'id' => 'request_revision',
        'concise_answer' => [
            'id' => 'Saat giliranmu aktif dan belum menandatangani, ajukan revisi beserta alasannya. Undangan versi lama dicabut dan pemilik mendapat draft baru yang perlu preview, peninjauan posisi tanda tangan, serta persetujuan baru.',
            'en' => 'Request a revision with a reason while your turn is active and before signing. Old invitations are revoked and the owner receives a new draft requiring a fresh preview, signature-position review, and new approvals.',
        ],
        'module' => 'revisions',
        'sources' => ['app/Services/RevisionService.php', 'tests/Feature/RevisionWorkflowTest.php'],
        'aliases' => [
            'minta revisi', 'permintaan revisi', 'revisi dokumen', 'request revision', 'document revision', 'revise document',
            'meminta revisi dokumen', 'minta revisi dokumen', 'minta perbaikan dokumen', 'ajukan revisi dokumen',
            'request a revision to this document', 'ask for a document revision',
        ],
        'question' => ['id' => 'Bagaimana cara meminta revisi dokumen?', 'en' => 'How do I request a document revision?'],
        'answer' => [
            'id' => 'Penerima pada giliran aktif yang belum menandatangani dapat meminta revisi dengan alasan. Versi lama ditandai meminta revisi dan undangannya dicabut. Sistem membuat draft versi baru untuk pemilik; posisi tanda tangan harus ditinjau ulang dan preview serta persetujuan baru diperlukan sebelum alur tanda tangan dilanjutkan.',
            'en' => 'The active recipient who has not signed can request a revision with a reason. The old version is marked revision requested and its invitations are revoked. A new draft version is created for the owner; signature positions require review, and a fresh preview and approvals are needed before signing resumes.',
        ],
    ],
    [
        'id' => 'reject_signing',
        'concise_answer' => [
            'id' => 'Saat giliranmu aktif dan belum menandatangani, kamu bisa menolak dengan alasan; versi dokumen menjadi ditolak dan undangannya dicabut. Penolakan ini tidak mencabut tanda tangan yang sudah dikirim.',
            'en' => 'While your turn is active and before signing, you can reject with a reason; the version becomes rejected and its invitations are revoked. This does not revoke an already submitted signature.',
        ],
        'module' => 'rejections',
        'sources' => ['app/Services/AssignmentRejectionService.php', 'routes/api.php', 'tests/Feature/SignerRejectionTest.php'],
        'aliases' => [
            'tolak tanda tangan', 'menolak dokumen', 'tolak dokumen', 'reject signing', 'reject document', 'cancel signing',
            'batal tanda tangan', 'pembatalan tanda tangan', 'batalkan tanda tangan', 'menolak penandatanganan',
            'tolak permintaan tanda tangan', 'menolak sebelum tanda tangan', 'reject a signing request', 'decline to sign a document',
        ],
        'question' => ['id' => 'Bagaimana cara menolak penandatanganan?', 'en' => 'How do I reject signing?'],
        'answer' => [
            'id' => 'Penerima pada giliran aktif yang belum menandatangani dapat menolak dengan alasan. Versi dokumen menjadi ditolak, undangan dicabut, dan penerima yang masih menunggu dibatalkan. Ini adalah penolakan sebelum tanda tangan; panduan ini tidak menyediakan cara mencabut atau menghapus tanda tangan yang sudah dikirim.',
            'en' => 'The active recipient who has not signed can reject with a reason. The document version becomes rejected, invitations are revoked, and waiting recipients are cancelled. This is rejection before signing; this guidance does not provide a way to revoke or remove an already submitted signature.',
        ],
    ],
    [
        'id' => 'final_pdf',
        'concise_answer' => [
            'id' => 'PDF final tersedia setelah penandatangan terakhir selesai dan finalisasi berhasil, menggunakan snapshot preview yang disetujui beserta tanda tangan. Kalau masih finalisasi, tunggu pemrosesan selesai; akses PDF tetap memerlukan izin.',
            'en' => 'The final PDF becomes available after the last signer finishes and finalization succeeds, using the approved preview snapshot and signatures. If it is still finalizing, wait for processing; PDF access still requires permission.',
        ],
        'module' => 'final_pdf',
        'sources' => ['app/Services/DocumentPipelineService.php', 'app/Http/Controllers/Api/V1/DocumentPdfController.php', 'tests/Feature/DocumentPipelineApiTest.php'],
        'aliases' => [
            'pdf final', 'final pdf', 'unduh pdf', 'download pdf', 'finalizing', 'finalisasi dokumen', 'download signed document',
            'unduh dokumen selesai', 'mengunduh pdf final', 'pdf final belum siap', 'dokumen masih finalisasi',
            'download the final pdf', 'final pdf is not ready', 'download my signed document',
        ],
        'question' => ['id' => 'Kapan PDF final tersedia?', 'en' => 'When is the final PDF available?'],
        'answer' => [
            'id' => 'Setelah penandatangan terakhir selesai, versi masuk tahap finalisasi. PDF final dibuat dari snapshot preview yang disetujui beserta tanda tangan, lalu tersedia ketika versi berstatus selesai. Akses PDF tetap memerlukan izin. Jika masih finalisasi atau PDF belum siap, tunggu pemrosesan selesai; asisten tidak dapat memeriksa atau mempercepat proses dokumen Anda.',
            'en' => 'After the last signer finishes, the version enters finalization. The final PDF is generated from the approved preview snapshot with signatures and becomes available when the version is completed. PDF access still requires authorization. If finalization is ongoing or the PDF is not ready, wait for processing; this assistant cannot inspect or speed up your document processing.',
        ],
    ],
    [
        'id' => 'profile',
        'concise_answer' => [
            'id' => 'Kamu bisa mengubah nama dan foto profil. Perubahan email atau kata sandi hanya tersedia untuk akun email/kata sandi tanpa identitas Google, dan email baru perlu diverifikasi.',
            'en' => 'You can update your name and profile photo. Email and password changes require an email/password account without a Google identity, and a new email must be verified.',
        ],
        'module' => 'profile',
        'sources' => ['app/Services/ProfileService.php', 'app/Http/Requests/UploadProfilePhotoRequest.php', 'tests/Feature/ProfileApiTest.php'],
        'aliases' => [
            'profil', 'profile', 'ubah nama', 'change name', 'foto profil', 'profile photo', 'ubah email', 'change email',
            'ubah kata sandi', 'change password', 'mengubah foto profil', 'unggah foto profil', 'menghapus foto profil',
            'email baru perlu verifikasi', 'akun google tidak bisa mengubah password', 'mengubah password',
            'change my profile picture', 'update my email address', 'delete my profile photo',
        ],
        'question' => ['id' => 'Bagaimana cara mengubah profil?', 'en' => 'How do I update my profile?'],
        'answer' => [
            'id' => 'Anda dapat mengubah nama serta mengunggah atau menghapus foto profil. Foto mendukung JPG/JPEG, PNG, atau WebP hingga 8 MB, dengan batas unggahan server tetap berlaku. Perubahan email memerlukan verifikasi ke email baru. Perubahan email dan kata sandi hanya tersedia bagi akun email/kata sandi tanpa identitas Google; akun Google tidak dapat menggunakan kedua perubahan tersebut.',
            'en' => 'You can update your name and upload or remove your profile photo. Photos support JPG/JPEG, PNG, or WebP up to 8 MB, subject to server upload limits. Changing email requires verification at the new email address. Email and password changes are available only to email/password accounts without a Google identity; Google-linked accounts cannot use those changes.',
        ],
    ],
];
