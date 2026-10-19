<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['products', 'suppliers', 'administration_roles'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedInteger('edit_version')->default(1);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['products', 'suppliers', 'administration_roles'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('edit_version');
            });
        }
    }
};
