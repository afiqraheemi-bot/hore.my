# hore.my - Master Project Context

## 1. Identiti dan visi

hore.my ialah ruang kerja perakaunan berasaskan AI untuk solopreneur dan peniaga mikro Malaysia. Pengguna pertama ialah enterprise atau milikan tunggal, seorang pemilik dan mata wang MYR.

Visinya ialah menjadikan pengurusan akaun semudah memuat naik bukti, menyemak cadangan dan mengesahkan satu tindakan. Pengguna tidak perlu belajar sistem akaun, mencari banyak menu, mengisi borang panjang atau melakukan key-in rutin.

hore.my bukan chatbot biasa, bukan platform chatting dan bukan sistem akaun tradisional yang hanya ditambah AI. Ia ialah task-driven accounting workspace.

## 2. Objektif

1. **Jimat** - mengurangkan kos bookkeeping rutin dan pembetulan rekod.
2. **Produktif** - sasaran urusan akaun mingguan di bawah 10 minit bagi penggunaan biasa.
3. **Tech-driven** - menggunakan AI, OCR dan automasi tanpa mengorbankan kawalan perakaunan.

## 3. Masalah pengguna

- Resit hilang atau direkod lewat.
- Transaksi peribadi dan perniagaan bercampur.
- Penyata bank tidak direkonsiliasi.
- Invois tidak selaras dengan rekod akaun.
- Pemilik tidak mengetahui kedudukan untung, tunai dan penghutang.
- Kerja pematuhan terkumpul sehingga hampir tarikh cukai atau semakan.
- Sistem tradisional terlalu teknikal dan memerlukan terlalu banyak input manual.

## 4. Prinsip produk yang dikunci

- Action-first, bukan conversation-first.
- Setiap interaksi mempunyai objektif, status, tindakan dan hasil terminal.
- Pengguna memberi bukti atau arahan; sistem menghasilkan cadangan berstruktur.
- Automatikkan perkara yang jelas dan tanya hanya tentang ketidakpastian material.
- AI mentafsir; enjin perakaunan mengawal dan mempost.
- Tiada satu sen boleh hilang, berganda atau berubah secara senyap.
- Semua angka boleh dijejaki kepada jurnal, keputusan dan bukti.
- Posted journal tidak boleh diedit atau dipadam.
- Pembetulan menggunakan reversal dan replacement.
- Semua tindakan material mempunyai audit trail.

## 5. UI/UX rasmi

Pengalaman mengikuti kesederhanaan dan pola interaksi ChatGPT hampir 99% tanpa menyalin logo, aset, kod atau identiti visualnya.

Komponen utama:

- Sidebar minimal dan boleh ditutup.
- Ruang kandungan berpusat.
- Satu composer utama.
- Drag-and-drop, paste imej dan lampiran fail.
- Respons progresif.
- Sejarah berdasarkan tugasan, bukan sejarah sembang bebas.
- Kad kewangan berstruktur.
- Light mode, dark mode, responsive dan WCAG 2.2 AA.

Setiap kad kewangan hendaklah menunjukkan amaun, tarikh, kategori, akaun, bukti, tahap keyakinan dan tindakan yang tersedia. Kesan tindakan material mesti diterangkan sebelum pengesahan.

Soalan pembukaan yang dicadangkan: **Apa yang kau mahu selesaikan hari ini?**

Tindakan pantas:

- Upload resit.
- Import penyata bank.
- Rekod pendapatan.
- Rekod perbelanjaan.
- Buat invois.
- Semak transaksi.
- Lihat laporan.

## 6. Input MVP

- Teks dan arahan ringkas.
- Imej.
- PDF.
- CSV.
- XLSX.

Suara, Telegram, WhatsApp ingestion dan open-ended chat tidak termasuk dalam MVP. Semua input, semakan, pengesahan dan rekod kewangan berlaku dalam hore.my.

## 7. Hasil MVP

1. Rekod pendapatan dan perbelanjaan automatik.
2. Pelanggan, sebut harga, invois dan bayaran.
3. MyInvois.
4. Import penyata bank dan bank reconciliation.
5. Untung rugi, kedudukan kewangan, aliran tunai, imbangan duga dan lejar.
6. Penghutang dan aging report.
7. Indeks bukti dan transaksi tanpa bukti.
8. Eksport PDF, XLSX dan CSV.
9. Compliance-ready pack.
10. Audit trail lengkap.

Compliance-ready bermaksud rekod tersusun, konsisten, boleh dijejaki dan boleh dieksport untuk semakan profesional. Ia bukan jaminan automatik bahawa audit, cukai atau penyerahan akan diterima.

## 8. Modul MVP

1. Identity dan Tenant.
2. Business Profile dan Onboarding.
3. Workspace dan Task.
4. Document Processing.
5. Transactions.
6. Banking dan Reconciliation.
7. Customers, Quotations dan Invoicing.
8. MyInvois.
9. Accounting Core.
10. Reporting dan Compliance Pack.
11. AI Orchestration.
12. Audit, Security dan Operations.

## 9. Di luar skop MVP

- Sdn. Bhd. dan multi-entiti.
- Multi-currency.
- Payroll.
- Inventori kompleks.
- Manufacturing.
- Operasi berbilang cawangan kompleks.
- Portal akauntan.
- Multi-user approval.
- Live bank feed.
- Aplikasi mobile native.
- Input suara.
- Telegram atau WhatsApp.
- Chat tanpa had.
- Nasihat cukai autonomi.
- Penghantaran cukai autonomi.
- Jaminan penerimaan audit atau cukai.

## 10. Integriti perakaunan

- Gunakan integer minor units atau NUMERIC/DECIMAL terkawal.
- Binary floating point dilarang untuk pengiraan wang.
- Setiap jurnal mempunyai sekurang-kurangnya dua baris.
- Jumlah debit sentiasa sama dengan jumlah kredit.
- Posting berlaku secara atomik.
- Jurnal, bukti, audit event dan outbox commit atau rollback bersama.
- Posted journal bersifat append-only.
- Direct update atau delete terhadap posted journal dilarang.
- Pembetulan menggunakan reversal dan replacement.
- Arahan material membawa idempotency key.
- Source fingerprint dan uniqueness constraint mencegah duplikasi.
- Baki authoritative datang daripada lejar.
- Projection boleh dibina semula.
- Allocation bayaran tidak melebihi baki invois.
- Tempoh ditutup tidak menerima posting biasa.
- Rekonsiliasi selesai mempunyai perbezaan RM0.00.

Proof of Accuracy mesti disiapkan sebelum AI penuh. Golden dataset hendaklah meliputi resit, penyata bank, transaksi, invois, jurnal, trial balance dan laporan.

## 11. Sempadan AI

AI boleh mentafsir arahan, mengklasifikasi dokumen, mengekstrak medan, mencadangkan transaksi atau akaun, memberikan confidence score dan menerangkan rasional.

AI tidak boleh:

- Menulis terus ke lejar.
- Mengubah posted journal.
- Mencipta bukti yang tidak wujud.
- Menyembunyikan ketidakpastian.
- Membuat keputusan cukai profesional secara autonomi.
- Memproses dokumen sama berulang kali tanpa sebab.

Output operasi AI mesti berstruktur, sah terhadap schema, mempunyai versi model/prompt, membawa confidence, melalui validasi deterministik, diuji melalui regression dataset dan mempunyai fallback.

## 12. Teknologi dan seni bina

### Frontend

- Nuxt 3.
- Vue 3.
- TypeScript.
- Tailwind CSS.
- Installable PWA.
- Responsive dari 320px.
- WCAG 2.2 AA.

### Backend

- Laravel modular monolith.
- Sempadan modul dan kontrak jelas.
- Stateless application instances.
- Queue berasingan mengikut risiko.

### Data dan infrastruktur

- PostgreSQL sebagai pangkalan data utama.
- Redis untuk queue, cache dan coordination.
- Object storage terenkripsi dan berversi.
- CDN, WAF, load balancer dan rate limiting.
- Centralized logs, metrics dan traces.
- Backup, point-in-time recovery dan restore drill.

### AI

- Provider abstraction.
- Structured output.
- Schema validation.
- Model routing.
- Evaluation registry.
- Cost telemetry.
- Context minimisation.

Modular monolith dipilih untuk mengekalkan transaksi jurnal dalam satu sempadan pangkalan data, mengurangkan risiko distributed transaction dan mempercepatkan pembangunan. Modul hanya dipisahkan apabila skala atau ownership memberikan sebab nyata.

Integrasi luaran menggunakan adapter. MyInvois, notifikasi dan pemprosesan dokumen menggunakan transactional outbox dan worker idempoten.

## 13. MyInvois

- Sandbox dan production terasing.
- Credential setiap environment tidak bercampur.
- Submission idempotency.
- UUID, timestamp, status dan error disimpan.
- Retry tidak menghasilkan submission berganda.
- Payload dan respons boleh diaudit.
- Status invois tempatan dan MyInvois direkonsiliasi.
- Schema dan peraturan membawa versi berkuat kuasa.

Dokumentasi rasmi LHDN terkini mesti disemak sebelum implementasi atau apabila spesifikasi berubah.

## 14. Skala, prestasi dan operasi

- Sasaran 3,000 sesi pengguna disahkan serentak melalui load test representatif.
- Horizontal scaling bagi application instances.
- OCR atau AI tidak boleh menyekat posting jurnal.
- Posting jurnal p95 di bawah satu saat bagi beban normal, tidak termasuk integrasi luar.
- Laporan standard p95 di bawah dua saat dalam had dataset.
- Availability 99.9% sebulan.
- RPO lima minit atau lebih baik.
- RTO 60 minit atau lebih baik.

## 15. Keselamatan dan privasi

- Tenant isolation pada aplikasi dan pangkalan data.
- Least privilege.
- Encryption in transit dan at rest.
- Secrets management.
- Rate limiting dan abuse protection.
- Malware scanning dan quarantine.
- Audit log append-only.
- Environment development, test, staging dan production terasing.
- Data yang dihantar kepada AI diminimumkan.
- Consent, tujuan, retention dan sharing diterangkan.
- Incident response dan restore drill diuji.
- Operator sokongan tidak boleh mengubah lejar terus.

## 16. Workflow pembangunan Codex

**Milestone -> Sprint -> Task -> Implement -> Test -> Review -> Commit -> Demo -> Release Gate**

Peraturan:

- Satu task mempunyai satu objektif utama.
- Kerja besar dipecahkan kepada micro-task.
- Codex membaca codebase sebelum mengubah kod.
- Targeted tests dijalankan selepas perubahan kecil.
- Full test suite dijalankan selepas work package, sebelum merge atau release.
- Diff disemak sebelum commit.
- Commit atomik.
- Tiada refactor di luar skop.
- Jangan overengineer.
- Keputusan seni bina tidak diubah secara senyap.
- ADR diperlukan untuk keputusan seni bina material.

## 17. Definition of Done

Task hanya selesai apabila:

- Acceptance criteria dipenuhi.
- Kod berada dalam modul yang betul.
- Tenant isolation dan authorization diuji.
- Pengiraan wang tepat dan deterministik.
- Audit event tersedia.
- Migration selamat.
- Targeted tests, lint dan static analysis lulus.
- UI mempunyai loading, empty, success dan error state.
- Dokumentasi dikemas kini jika perlu.
- Diff disemak.
- Commit kecil dan jelas.
- Tiada perubahan di luar skop.

## 18. Release blockers

Release tidak boleh dibuat jika:

- Trial balance tidak seimbang.
- Rekonsiliasi mempunyai perbezaan tidak dijelaskan.
- Terdapat risiko posting berganda.
- Tenant isolation gagal.
- Critical security finding belum diselesaikan.
- Migration belum diuji.
- Backup atau restore belum dibuktikan.
- AI regression melebihi threshold.
- MyInvois menghasilkan submission berganda.
- Load test gagal.
- Monitoring atau rollback tidak tersedia.

## 19. Roadmap

1. Product validation dan policy decisions.
2. Project foundation.
3. Proof of Accuracy.
4. Workspace UI/UX.
5. Document processing.
6. Transactions.
7. Bank reconciliation.
8. Invoicing dan payments.
9. MyInvois.
10. Reporting dan compliance pack.
11. AI orchestration.
12. Security dan production hardening.
13. Closed beta.
14. Controlled launch.
15. Scale hingga 3,000 sesi serentak.

Jangan membina AI penuh atau terlalu banyak UI sebelum Proof of Accuracy lulus.

## 20. Peranan

### Founder / Product Owner

Menentukan visi, prioriti, skop, model perniagaan, pengalaman pengguna, kelulusan reka bentuk dan keputusan release.

### CTO / Technical Partner

Menjaga seni bina, integriti kewangan, roadmap, sprint, risiko, implementation, quality gate dan release readiness.

### Codex

Implementation engine yang membaca codebase, menulis kod, menulis serta menjalankan ujian, membaiki defect, menyemak diff dan menyediakan commit kecil.

## 21. Arahan untuk semua chat

- Gunakan fail ini, Cadangan Projek Terperinci dan SRS sebagai sumber rujukan utama.
- Kekalkan keputusan yang dikunci.
- Bezakan keputusan rasmi, cadangan dan perkara belum diputuskan.
- Jangan kembangkan skop tanpa kelulusan Founder.
- Utamakan ketepatan kewangan, keselamatan, auditability dan UI/UX yang mudah.
- Cadangan hendaklah praktikal untuk Malaysia.
- Nyatakan trade-off bagi keputusan teknikal.
- Hasilkan task kecil dengan acceptance criteria jelas.
- Semak sumber rasmi terkini bagi MyInvois, regulatory, security atau dependency yang boleh berubah.
- Jangan mendakwa sesuatu siap tanpa bukti ujian.
- Tanya Founder hanya apabila keputusan mengubah skop, kos, risiko atau pengalaman pengguna.

## 22. Prinsip akhir

**Cepat pada perkara yang mudah dibetulkan. Perlahan dan teliti pada perkara yang melibatkan wang, data, keselamatan dan pematuhan.**

hore.my mesti terasa sangat mudah kepada pengguna, tetapi dibina di atas struktur perakaunan dan kejuruteraan yang sangat disiplin.
