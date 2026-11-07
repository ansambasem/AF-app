<?php

use Carbon\CarbonImmutable;
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
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_devices')->default(1);
        });
        Schema::create('user_login_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'session_hash']);
            $table->index(['user_id', 'expires_at']);
        });
        if (config('session.driver') === 'database' && config('session.connection') === null && Schema::hasTable(config('session.table', 'sessions'))) {
            $table = config('session.table', 'sessions');
            DB::table($table)->join('users', 'users.id', '=', $table.'.user_id')
                ->where('last_activity', '>', now()->subMinutes(config('session.lifetime'))->timestamp)
                ->select([$table.'.id as session_id', $table.'.user_id', $table.'.last_activity'])->orderBy($table.'.id')
                ->chunk(200, function (Collection $sessions): void {
                    foreach ($sessions as $session) {
                        $seen = CarbonImmutable::createFromTimestamp($session->last_activity);
                        DB::table('user_login_devices')->insert([
                            'user_id' => $session->user_id, 'session_hash' => hash('sha256', $session->session_id),
                            'created_at' => $seen, 'updated_at' => $seen,
                            'expires_at' => $seen->addMinutes(config('session.lifetime')),
                        ]);
                    }
                });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_login_devices');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('max_devices');
        });
    }
};
