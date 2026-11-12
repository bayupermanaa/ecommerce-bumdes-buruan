<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('notifications', function (Blueprint $table) { $table->string('event_key', 191)->nullable()->after('type'); $table->unique(['notifiable_type','notifiable_id','event_key'], 'notifications_recipient_event_unique'); }); }
    public function down(): void { Schema::table('notifications', function (Blueprint $table) { $table->dropUnique('notifications_recipient_event_unique'); $table->dropColumn('event_key'); }); }
};
