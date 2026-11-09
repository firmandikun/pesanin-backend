@extends('legal.layout')
@section('title', 'Kebijakan Privasi')
@section('content')
<h1>Kebijakan Privasi</h1>
<p class="muted">Terakhir diperbarui: {{ $updated }}</p>

<p>Pesanin ("kami") adalah aplikasi manajemen pesanan untuk penjual online. Kebijakan ini menjelaskan data apa yang kami kumpulkan, bagaimana data dipakai, dan pilihan yang Anda miliki.</p>

<h2>1. Data yang kami kumpulkan</h2>
<ul>
  <li><strong>Data akun</strong>: nama, email, dan kata sandi (disimpan dalam bentuk hash).</li>
  <li><strong>Data toko dan pesanan</strong>: produk, pesanan, data pembeli (nama, nomor telepon, alamat pengiriman) yang Anda masukkan atau yang disinkronkan dari marketplace yang Anda hubungkan.</li>
  <li><strong>Data integrasi</strong>: token akses yang Anda berikan saat menghubungkan Shopee, Meta (Facebook, Instagram, WhatsApp), layanan pengiriman, dan pembayaran.</li>
  <li><strong>Data dari Meta</strong>, hanya jika Anda menghubungkannya:
    <ul>
      <li>Daftar Halaman Facebook dan akun Instagram Bisnis yang Anda pilih;</li>
      <li>Percakapan dan pesan Messenger, Instagram Direct, dan WhatsApp Business antara bisnis Anda dan pelanggan;</li>
      <li>Data performa iklan (biaya, impresi, jangkauan, hasil) dari akun iklan yang Anda pilih.</li>
    </ul>
  </li>
</ul>

<h2>2. Cara kami menggunakan data</h2>
<ul>
  <li>Menampilkan dan membalas chat pelanggan dari satu kotak masuk.</li>
  <li>Mengelola pesanan, pengiriman, dan stok.</li>
  <li>Menghitung laporan biaya iklan dan ROAS dengan mencocokkan data iklan dengan pesanan Anda.</li>
  <li>Mengirim notifikasi pesanan dan pesan baru kepada Anda.</li>
</ul>
<p>Kami <strong>tidak menjual</strong> data Anda atau data pelanggan Anda, tidak memakainya untuk iklan pihak ketiga, dan tidak membagikannya kecuali ke layanan yang Anda hubungkan sendiri untuk menjalankan fitur tersebut.</p>

<h2>3. Penyimpanan dan keamanan</h2>
<p>Data disimpan di server kami. Token akses disimpan terenkripsi. Akses ke data dibatasi hanya untuk akun Anda. Pesan dan data iklan dari Meta disimpan atau di-cache seperlunya untuk menampilkan fitur, dan dihapus saat Anda memutus integrasi.</p>

<h2>4. Pilihan Anda</h2>
<ul>
  <li>Anda bisa memutus integrasi kapan saja dari menu <em>Pengaturan → Integrasi</em> atau dari menu Chat / Iklan Meta di Pesanin. Akses Pesanin di akun Facebook Anda juga ikut dicabut.</li>
  <li>Anda bisa menghapus aplikasi dari <em>Pengaturan Facebook → Aplikasi dan Situs Web</em>. Data Meta Anda akan dihapus otomatis.</li>
  <li>Anda bisa meminta penghapusan seluruh data. Lihat <a href="/data-deletion">Penghapusan Data</a>.</li>
</ul>

<h2>5. Perubahan kebijakan</h2>
<p>Jika kebijakan ini berubah, tanggal di atas akan diperbarui. Perubahan penting akan kami beritahukan di aplikasi.</p>

<h2>6. Kontak</h2>
<p>Pertanyaan tentang privasi dapat dikirim @if(config('app.contact_email')) ke <a href="mailto:{{ config('app.contact_email') }}">{{ config('app.contact_email') }}</a> @else melalui menu bantuan di aplikasi Pesanin @endif.</p>
@endsection
