<?php

use App\Operations\Upgrade\Expand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new #[Expand] class extends Migration
{
    public function up(): void
    {
        // The made-to-order order a conversion opened (M-03-T02, SRS-COM-13): the interest's historical reference.
        Schema::table('interests', function (Blueprint $table) {
            $table->foreignId('converted_order_id')->nullable()->after('converted_customer_id')->constrained('orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('interests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_order_id');
        });
    }
};
