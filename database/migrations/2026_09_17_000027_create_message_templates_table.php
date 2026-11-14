<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saved wordings for messages to subscriptions, one list for the whole
     * company, starting with one ready-made wording for each kind.
     */
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('kind');
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();

        DB::table('message_templates')->insert([
            [
                'name' => 'قراءة الأسبوع',
                'kind' => 'weekly_reading',
                'body' => 'عزيزي {الاسم}، قراءة عدادك لأسبوع {تاريخ_القراءة}: السابقة {القراءة_السابقة} والحالية {القراءة_الحالية}، الاستهلاك {الاستهلاك} كيلو، المطلوب {قيمة_القراءة} ₪. رصيدك الحالي {الرصيد} ₪.',
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'تذكير بالدفع',
                'kind' => 'balance_reminder',
                'body' => 'عزيزي {الاسم}، نذكّرك بأن عليك رصيدًا مستحقًا بقيمة {الرصيد} ₪ على الاشتراك رقم {رقم_الاشتراك}. يرجى المبادرة بالدفع. شكرًا لك.',
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'إعلان عام',
                'kind' => 'custom',
                'body' => 'عزيزي {الاسم}، ',
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
