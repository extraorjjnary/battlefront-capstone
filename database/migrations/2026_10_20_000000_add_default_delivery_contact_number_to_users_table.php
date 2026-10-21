<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'default_delivery_contact_number')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('default_delivery_contact_number', 20)->nullable();
            });
        }
    }

    /**
     * Preserve customer profile data when rolling back application code.
     */
    public function down(): void {}
};
