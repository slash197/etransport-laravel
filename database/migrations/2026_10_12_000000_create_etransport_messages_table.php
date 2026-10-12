<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('account', 64);
            $table->string('cif', 16);
            $table->string('type', 3);
            $table->string('uit', 32)->nullable();
            $table->string('upload_id', 32)->nullable();
            $table->string('reference', 64)->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('status', 16);
            $table->longText('payload');
            $table->json('meta')->nullable();
            $table->text('errors')->nullable();
            $table->unsignedInteger('checks')->default(0);
            $table->dateTime('next_check_at')->nullable();
            $table->dateTime('last_check_at')->nullable();
            $table->dateTime('uploaded_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_check_at']);
            $table->index(['account', 'upload_id']);
            $table->index(['cif', 'uit']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        $table = config('etransport.outbox.table');

        return is_string($table) && $table !== '' ? $table : 'etransport_messages';
    }
};
