@extends('legal.layout')
@section('title', 'Penghapusan Data')
@section('content')
<h1>Penghapusan Data Pengguna</h1>

@if($code)
  <div class="box">
    @if($req)
      <strong>Permintaan penghapusan selesai.</strong><br>
      Kode konfirmasi: <code>{{ $req->code }}</code><br>
      <span class="muted">Diproses {{ \Illuminate\Support\Carbon::parse($req->created_at)->translatedFormat('d F Y H:i') }}. Integrasi Meta dan data iklan yang tersimpan telah dihapus.</span>
    @else
      <strong>Kode konfirmasi tidak ditemukan.</strong> Periksa kembali kode Anda.
    @endif
  </div>
@endif

<p>Anda bisa menghapus data yang diperoleh Pesanin dari Facebook, Instagram, dan WhatsApp dengan salah satu cara berikut.</p>

<h2>Cara 1: Dari Facebook</h2>
<ol>
  <li>Buka <strong>Pengaturan & Privasi → Pengaturan</strong> di Facebook.</li>
  <li>Pilih <strong>Aplikasi dan Situs Web</strong>.</li>
  <li>Cari <strong>Pesanin</strong>, lalu klik <strong>Hapus</strong>.</li>
</ol>
<p>Facebook akan memberi tahu kami, dan semua data Meta Anda di Pesanin (token, daftar Halaman, data iklan) dihapus otomatis. Anda akan mendapat kode konfirmasi yang bisa dicek di halaman ini.</p>

<h2>Cara 2: Dari Pesanin</h2>
<ol>
  <li>Masuk ke Pesanin.</li>
  <li>Buka <strong>Chat</strong> atau <strong>Laporan → Iklan Meta</strong>, lalu klik <strong>Putuskan</strong>. Untuk WhatsApp, buka <strong>Pengaturan → Integrasi → WhatsApp</strong>.</li>
</ol>
<p>Token akses langsung dihapus dan izin aplikasi di akun Facebook Anda ikut dicabut.</p>

<h2>Cara 3: Hapus seluruh akun</h2>
<p>Untuk menghapus seluruh akun Pesanin beserta data pesanan dan pelanggan, kirim permintaan @if(config('app.contact_email')) ke <a href="mailto:{{ config('app.contact_email') }}">{{ config('app.contact_email') }}</a> @else melalui menu bantuan di aplikasi @endif dari email akun Anda. Kami memprosesnya paling lambat 30 hari.</p>
@endsection
