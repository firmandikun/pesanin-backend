<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scan Out: the seller scans every parcel's resi before handing the batch to the courier. A session is one
 * handover (opened on the dashboard, scanned from a phone through the QR token); each resi can only ever be
 * scanned once per seller, so a parcel is never counted (or shipped) twice. The PDF of a session is the proof
 * for the expedition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_out_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('status', 10)->default('open'); // open | closed
            $table->string('scanned_by', 120)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('scan_out_parcels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_out_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marketplace_shop_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 100);           // the resi as scanned (normalised)
            $table->boolean('matched')->default(false);
            $table->string('shop_name', 160)->nullable();
            $table->string('courier', 120)->nullable();
            $table->string('order_sn', 60)->nullable();
            $table->string('customer_name', 160)->nullable();
            $table->string('items_summary', 500)->nullable();
            $table->string('scanned_by', 120)->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();
            $table->unique(['user_id', 'code']);
            $table->index(['scan_out_session_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_out_parcels');
        Schema::dropIfExists('scan_out_sessions');
    }
};
