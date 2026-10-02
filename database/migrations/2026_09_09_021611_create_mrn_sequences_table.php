<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A single-row sequence used to allocate Medical Record Numbers under a
     * row lock, so concurrent patient registration cannot mint duplicate MRNs.
     */
    public function up(): void
    {
        Schema::create('mrn_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('last_value')->default(0);
        });

        DB::table('mrn_sequences')->insert(['id' => 1, 'last_value' => 0]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mrn_sequences');
    }
};
