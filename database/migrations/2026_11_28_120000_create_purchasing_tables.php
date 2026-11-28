<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pembelian & Supplier: suppliers, purchase orders (PO) with items, and payments to suppliers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('phone', 30)->nullable();
            $t->string('email', 120)->nullable();
            $t->string('address', 300)->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->default(0); // 0 = cash, otherwise tempo
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'name']);
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->string('number', 30);
            $t->string('status', 15)->default('ordered'); // draft | ordered | received | cancelled
            $t->date('ordered_on');
            $t->date('due_on')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->unsignedBigInteger('subtotal')->default(0);
            $t->unsignedBigInteger('discount')->default(0);
            $t->unsignedBigInteger('shipping_cost')->default(0);
            $t->unsignedBigInteger('total')->default(0);
            $t->unsignedBigInteger('paid')->default(0);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'number']);
            $t->index(['user_id', 'status']);
        });
        Schema::create('purchase_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stock_master_id')->nullable()->constrained()->nullOnDelete(); // Shopee SKU (all shops)
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();      // web / social product
            $t->string('name', 255);
            $t->string('sku', 100)->nullable();
            $t->unsignedInteger('qty');
            $t->unsignedBigInteger('unit_cost');        // price per unit on the supplier invoice
            $t->unsignedBigInteger('landed_unit_cost')->default(0); // incl. its share of shipping minus discount (set on receive)
            $t->timestamps();
        });
        Schema::create('purchase_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('expense_id')->nullable()->constrained()->nullOnDelete(); // mirrored in Pengeluaran (cash out)
            $t->date('paid_on');
            $t->unsignedBigInteger('amount');
            $t->string('method', 20)->default('transfer');
            $t->string('note', 200)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
