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
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('logo_path')->nullable();
            $table->boolean('is_receiver')->default(true);
            $table->boolean('is_sender')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach ([
            ['بنك فلسطين', 'bank-of-palestine.webp', true],
            ['محفظة بالباي', 'palpay.webp', true],
            ['جوال باي', 'jawwal-pay.webp', true],
            ['البنك الإسلامي الفلسطيني', 'palestine-islamic-bank.webp', true],
            ['البنك الإسلامي العربي', 'arab-islamic-bank.webp', true],
            ['بنك القدس', 'quds-bank.webp', false],
        ] as [$name, $logo, $receiver]) {
            DB::table('banks')->insert(['name' => $name, 'logo_path' => '/images/banks/'.$logo, 'is_receiver' => $receiver, 'is_sender' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
