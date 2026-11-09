@extends('legal.layout')
@section('title', 'Ketentuan Layanan')
@section('content')
<h1>Ketentuan Layanan</h1>
<p class="muted">Terakhir diperbarui: {{ $updated }}</p>

<p>Dengan mendaftar atau menggunakan Pesanin, Anda menyetujui ketentuan berikut.</p>

<h2>1. Layanan</h2>
<p>Pesanin menyediakan alat untuk mengelola pesanan, produk, pengiriman, chat pelanggan, dan laporan iklan. Sebagian fitur bergantung pada layanan pihak ketiga (seperti Shopee, Meta, dan kurir), sehingga ketersediaannya mengikuti layanan tersebut.</p>

<h2>2. Akun Anda</h2>
<ul>
  <li>Anda bertanggung jawab menjaga kerahasiaan akun dan semua aktivitas di dalamnya.</li>
  <li>Informasi yang Anda berikan harus benar dan terbaru.</li>
</ul>

<h2>3. Integrasi pihak ketiga</h2>
<p>Saat menghubungkan akun marketplace atau Meta, Anda memberi izin kepada Pesanin untuk mengakses data sesuai izin yang Anda setujui. Anda tetap wajib mematuhi ketentuan platform tersebut, termasuk aturan pesan Meta (misalnya batas waktu balasan 24 jam) dan kebijakan WhatsApp Business.</p>

<h2>4. Penggunaan yang dilarang</h2>
<ul>
  <li>Mengirim spam, pesan massal tanpa izin, atau konten yang melanggar hukum.</li>
  <li>Menjual barang terlarang atau melakukan penipuan.</li>
  <li>Mencoba mengakses data pengguna lain atau mengganggu sistem.</li>
</ul>

<h2>5. Paket dan pembayaran</h2>
<p>Fitur tertentu tersedia sesuai paket berlangganan. Harga dan kuota ditampilkan di aplikasi dan dapat berubah dengan pemberitahuan sebelumnya.</p>

<h2>6. Batasan tanggung jawab</h2>
<p>Pesanin disediakan "sebagaimana adanya". Kami berusaha menjaga layanan tetap berjalan, tetapi tidak bertanggung jawab atas kerugian akibat gangguan layanan pihak ketiga, kesalahan data dari platform lain, atau penggunaan di luar ketentuan ini.</p>

<h2>7. Penghentian</h2>
<p>Anda bisa berhenti menggunakan Pesanin kapan saja. Kami dapat menangguhkan akun yang melanggar ketentuan ini.</p>

<h2>8. Data dan privasi</h2>
<p>Penggunaan data diatur dalam <a href="/privacy">Kebijakan Privasi</a>.</p>
@endsection
