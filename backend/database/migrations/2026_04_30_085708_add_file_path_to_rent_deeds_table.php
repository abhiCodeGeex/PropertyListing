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
        Schema::table('rent_deeds', function (Blueprint $table) {
            $table->string('file_path')->nullable()->after('agreement_date');
            
            if (Schema::hasColumn('rent_deeds', 'size')) {
                $table->dropColumn('size');
            }
            if (Schema::hasColumn('rent_deeds', 'usage')) {
                $table->dropColumn('usage');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rent_deeds', function (Blueprint $table) {
            $table->dropColumn('file_path');
            $table->string('size')->nullable()->after('property_id');
            $table->string('usage')->nullable()->after('size');
        });
    }
};
