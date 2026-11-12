<?php

namespace App\Support;

/** First content for the public Tutorial / Blog pages and the in-app "Info Terbaru"; editable afterwards from the admin dashboard. */
class ContentDefaults
{
    public const TUTORIALS = [
        ['Video demo & tur singkat Pesanin', 'pemula', 'Kenalan dengan dashboard Pesanin: menu utama, pesanan, keuangan toko, dan pengaturan.', [
            'Masuk ke Pesanin dan buka Dashboard untuk melihat ringkasan omzet, laba, dan pesanan yang perlu diproses.',
            'Menu Marketplace berisi toko Shopee yang terhubung: ringkasan, pesanan & pengiriman, produk, keuangan, dan iklan.',
            'Menu Produk dan Pesanan Media Sosial untuk penjualan lewat link checkout dan landing page.',
            'Menu Chat untuk inbox WhatsApp dan Shopee, Laporan Bisnis untuk laba, pengeluaran, dan iklan.',
        ]],
        ['Cara menghubungkan toko Shopee', 'pemula', 'Tarik pesanan, produk, stok, chat, dan biaya iklan Shopee otomatis.', [
            'Buka Marketplace → Daftar Toko, lalu klik Hubungkan Toko Shopee.',
            'Login dengan akun utama (Main Account) Shopee dan setujui izin akses untuk Pesanin.',
            'Setelah kembali ke Pesanin, klik Sinkronkan. Pesanan 15 hari terakhir, produk, dan Shopee Ads akan ditarik.',
            'Selanjutnya pesanan baru dan perubahan status masuk otomatis setiap 30 detik.',
        ]],
        ['Cara mengisi HPP produk', 'lanjutan', 'Isi modal per produk, variasi, atau SKU agar laba per pesanan terhitung.', [
            'Buka Marketplace → Semua Produk.',
            'Klik Input HPP massal untuk mengisi semua SKU sekaligus, atau ubah per variasi di tabel.',
            'HPP berlaku untuk semua transaksi toko tersebut, termasuk pesanan sebelumnya.',
            'Produk tanpa HPP ditandai "HPP belum lengkap" di halaman Transaksi.',
        ]],
        ['Cara membaca transaksi & potongan Shopee', 'analitik', 'Lihat uang yang benar-benar masuk dari setiap pesanan.', [
            'Buka toko → Keuangan → Transaksi.',
            'Kolom Jumlah adalah harga produk, Biaya adalah total potongan Shopee, dan Pendapatan adalah uang yang cair.',
            'Klik panah di ujung baris untuk melihat rincian: biaya admin, layanan, pemrosesan Rp1.250, ongkir, AMS, dan pajak.',
            'Label Estimasi penghasilan berubah menjadi Sudah cair setelah Shopee melepas dana.',
            'Klik Ekspor Excel untuk mengunduh transaksi dengan kolom per jenis biaya.',
        ]],
        ['Cara membaca dashboard toko', 'analitik', 'Angka mana yang menjawab pertanyaan apa tentang performa tokomu.', [
            'Buka Marketplace → pilih toko → Ringkasan.',
            'Profit funnel menunjukkan alur omzet → HPP → potongan → iklan → biaya operasional → laba bersih.',
            'Bagian peringatan menampilkan HPP yang belum diisi dan pesanan yang masih estimasi.',
        ]],
        ['Cara membaca laporan laba rugi & arus kas', 'analitik', 'Pahami laporan penjualan, biaya, dan laba per periode.', [
            'Buka toko → Keuangan → Laba Rugi & Arus Kas, atau Laporan Bisnis → Laba Bersih untuk semua channel.',
            'Pilih rentang tanggal. Laba bersih = omzet − potongan − HPP − iklan − biaya operasional.',
            'Klik Cetak untuk menyimpan PDF.',
        ]],
        ['Cara input data pengeluaran', 'pemula', 'Catat biaya operasional supaya laba bersih terhitung lengkap.', [
            'Buka Laporan Bisnis → Pengeluaran, atau tab Pengeluaran di Keuangan toko.',
            'Klik Tambah pengeluaran, pilih kategori: Biaya Operasional, HPP & Pembelian Stok, atau Aset & Pembiayaan.',
            'Biaya Operasional mengurangi laba. Pembelian stok dan aset hanya tercatat di arus kas.',
            'Biaya Shopee Ads muncul otomatis di daftar, sudah termasuk PPN 11%.',
        ]],
        ['Cara memantau iklan & ROAS', 'analitik', 'Biaya iklan, penjualan dari iklan, dan laba setelah iklan.', [
            'Buka Marketplace → Iklan untuk Shopee Ads: biaya, penjualan, ROAS per kampanye dan per produk.',
            'Untuk Meta Ads, hubungkan akun iklan di Pengaturan → Integrasi.',
            'Biaya iklan lain (live, banner, influencer) dicatat di Laporan Bisnis → Biaya Iklan.',
        ]],
        ['Cara membuat link checkout & landing page', 'pemula', 'Untuk jualan di Instagram, TikTok, WhatsApp, dan iklan.', [
            'Buka Produk → Tambah Produk, isi harga, variasi, dan berat.',
            'Aktifkan metode pembayaran: transfer, e-payment, dan/atau COD.',
            'Salin link checkout atau buat landing page di menu Landing Page.',
            'Pasang pixel Meta/TikTok/Google di pengaturan produk untuk tracking iklan.',
        ]],
        ['Cara mengatur pengiriman & resi otomatis', 'lanjutan', 'Pesanan lunas langsung dipesankan kurir.', [
            'Hubungkan Biteship di Pengaturan → Integrasi dan isi alamat pickup.',
            'Aktifkan Resi otomatis di Pengaturan → Otomatisasi Pesanan.',
            'Untuk pesanan Shopee, atur pengiriman dan cetak label massal dari Pesanan & Pengiriman.',
        ]],
        ['Cara memakai inbox WhatsApp & broadcast', 'lanjutan', 'Balas semua chat dari satu tempat dan kirim broadcast.', [
            'Buka Pengaturan → Integrasi → WhatsApp, klik Masuk dengan Facebook dan pilih nomor WhatsApp Business.',
            'Chat WhatsApp dan Shopee masuk ke menu Chat. Beri label dan catatan untuk setiap pelanggan.',
            'Untuk broadcast, buat template di Broadcast → Template, tunggu disetujui Meta, lalu kirim ke segmen kontak.',
        ]],
        ['Cara mengajak anggota tim', 'lanjutan', 'Login terpisah tanpa membagikan akun pemilik.', [
            'Buka Pengaturan → Anggota Tim, klik Tambah.',
            'Pilih peran (Admin, CS, Gudang, Advertiser) dan buatkan login.',
            'Anggota hanya bisa membuka menu sesuai perannya.',
        ]],
    ];

    public const ANNOUNCEMENTS = [
        ['NEW', 'Pengeluaran & biaya operasional', 'Catat gaji, sewa, packing, pembelian stok, dan aset di Laporan Bisnis → Pengeluaran. Biaya operasional otomatis mengurangi laba bersih.'],
        ['NEW', 'Rincian potongan Shopee per pesanan', 'Biaya admin, layanan, pemrosesan pesanan Rp1.250, ongkir, AMS, dan pajak kini tampil per pesanan, lengkap dengan status Estimasi / Sudah cair dan ekspor Excel.'],
        ['UPDATE', 'Landing Page Builder', 'Susun halaman penjualan dengan formulir checkout tertanam, pantau sesi & referrer di Landing Page › Laporan.'],
        ['UPDATE', 'Ongkir otomatis & biaya COD', 'Pembeli memilih kecamatan, daftar kurir dan biaya COD dihitung otomatis dari alamat pickup kamu.'],
    ];
}
