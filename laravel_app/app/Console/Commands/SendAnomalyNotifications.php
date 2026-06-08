<?php

namespace App\Console\Commands;

use App\Services\AnomalyNotificationService;
use App\Services\FastApiService;
use Illuminate\Console\Command;

class SendAnomalyNotifications extends Command
{
    protected $signature = 'fintrack:send-anomaly-notifications';

    protected $description = 'Email users about unusual category spending (monthly check)';

    public function handle(FastApiService $api, AnomalyNotificationService $notifier): int
    {
        try {
            $users = $api->usersWithNotifications();
        } catch (\Throwable $e) {
            $this->error('Could not reach FastAPI: '.$e->getMessage());

            return self::FAILURE;
        }

        $sent = 0;
        foreach ($users as $user) {
            $id = (int) ($user['user_id'] ?? 0);
            $email = (string) ($user['email'] ?? '');
            $name = (string) ($user['name'] ?? 'User');
            if ($id < 1 || $email === '') {
                continue;
            }
            $notifier->notifyIfNeeded($id, $email, $name);
            $sent++;
        }

        $this->info("Processed {$sent} user(s).");

        return self::SUCCESS;
    }
}
