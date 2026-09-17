<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('image_url', 'image_path');
        });

        DB::table('products')
            ->whereNotNull('image_path')
            ->orderBy('id')
            ->eachById(function (object $product): void {
                $path = parse_url((string) $product->image_path, PHP_URL_PATH);

                DB::table('products')
                    ->where('id', $product->id)
                    ->update([
                        'image_path' => is_string($path) && str_starts_with($path, '/images/demo-products/')
                            ? ltrim($path, '/')
                            : null,
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('image_path', 'image_url');
        });
    }
};
