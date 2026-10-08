<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('ai.manual_chat_enabled', false);
    }

    public function down(): void
    {
        $this->migrator->delete('ai.manual_chat_enabled');
    }
};
