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
        $this->changeLabelType(true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $lengthFunction = DB::getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
        if (DB::table('orders')->whereRaw("{$lengthFunction}(delivery_assumption_label) > 255")->exists()) {
            throw new RuntimeException('Cannot shorten retained delivery assumption labels. Use a forward migration.');
        }

        $this->changeLabelType(false);
    }

    private function changeLabelType(bool $expanded): void
    {
        /** @var list<literal-string> $triggers Application-defined SQL retained from SQLite's schema. */
        $triggers = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'orders')->pluck('sql')->all()
            : [];

        Schema::table('orders', function (Blueprint $table) use ($expanded): void {
            if ($expanded) {
                $table->text('delivery_assumption_label')->nullable()->change();
            } else {
                $table->string('delivery_assumption_label')->nullable()->change();
            }
        });

        /** SQLite column changes rebuild the table and discard its application constraint triggers. */
        foreach ($triggers as $sql) {
            DB::unprepared($sql);
        }
    }
};
