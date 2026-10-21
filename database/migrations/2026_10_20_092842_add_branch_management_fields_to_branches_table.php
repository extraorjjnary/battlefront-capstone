<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('email')->nullable();
            $table->string('operating_hours')->nullable();
            $table->boolean('is_published')->default(false);
            $table->string('seed_key', 50)->nullable()->unique();
        });

        $seedKeys = [
            'Sagay City' => 'sagay-city',
            'Escalante City' => 'escalante-city',
            'San Carlos City' => 'san-carlos-city',
            'Guihulngan City' => 'guihulngan-city',
            'Bacolod City' => 'bacolod-city',
        ];

        DB::table('branches')->orderBy('id')->chunkById(100, function (Collection $branches) use ($seedKeys): void {
            foreach ($branches as $branch) {
                DB::table('branches')->where('id', $branch->id)->update([
                    'email' => config('battlefront.branch_emails.'.$branch->city),
                    'operating_hours' => config('battlefront.operating_hours', '8:00 AM–6:00 PM'),
                    'is_published' => true,
                    'seed_key' => $seedKeys[$branch->city] ?? null,
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Branch management contains administrator-owned data. Use a forward migration instead of dropping these fields.');
    }
};
