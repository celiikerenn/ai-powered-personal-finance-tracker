<?php

namespace App\Console\Commands;

use App\Services\AnomalyNotificationService;
use App\Services\FastApiService;
use Illuminate\Console\Command;

class TestAnomalyEmail extends Command
{
    protected $signature = 'fintrack:test-anomaly-email
                            {user_id : FastAPI user id (session user_id)}
                            {--email= : Recipient (defaults to user email from API if omitted)}
                            {--force : Send even when should_notify is false (preview / test)}
                            {--dry-run : Only show API status, do not send}';

    protected $description = 'Test spending anomaly detection and optional email send';

    public function handle(FastApiService $api, AnomalyNotificationService $notifier): int
    {
        $userId = (int) $this->argument('user_id');
        if ($userId < 1) {
            $this->error('user_id must be a positive integer.');

            return self::FAILURE;
        }

        $result = $notifier->fetchAnomalyStatus($userId);
        if ($result === null) {
            $this->error('Could not reach FastAPI or check failed. Is backend running on port 8001?');

            return self::FAILURE;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['month', (string) ($result['month'] ?? '')],
                ['current_month_total', (string) ($result['current_month_total'] ?? 0)],
                ['baseline_average (last 3 mo)', (string) ($result['baseline_average'] ?? 0)],
                ['increase_percent', (string) ($result['increase_percent'] ?? 0).'%'],
                ['has_anomalies (>50%)', ($result['has_anomalies'] ?? false) ? 'yes' : 'no'],
                ['should_notify', ($result['should_notify'] ?? false) ? 'yes' : 'no'],
                ['already_notified this month', ($result['already_notified'] ?? false) ? 'yes' : 'no'],
            ]
        );

        $mailer = config('mail.default');
        $this->line('Mail driver: <info>'.$mailer.'</info>');
        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER=log — no real inbox. Set smtp in .env (see laravel_app/EMAIL_SETUP.md).');
        } elseif ($mailer === 'smtp' && ! config('mail.mailers.smtp.username')) {
            $this->warn('MAIL_USERNAME is empty — fill .env before sending real mail.');
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — no email sent.');

            return self::SUCCESS;
        }

        $email = (string) ($this->option('email') ?? '');
        $name = 'User';
        if ($email === '') {
            try {
                foreach ($api->usersWithNotifications() as $u) {
                    if ((int) ($u['user_id'] ?? 0) === $userId) {
                        $email = (string) ($u['email'] ?? '');
                        $name = (string) ($u['name'] ?? 'User');
                        break;
                    }
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        if ($email === '') {
            $this->error('Pass --email=your@address.com (could not resolve from API).');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        if (! $force && empty($result['should_notify'])) {
            $this->warn('No email sent: threshold not met, already notified, or notifications off.');
            $this->line('Use --force to send a test email anyway (will not mark as notified).');

            return self::SUCCESS;
        }

        $sent = $force
            ? $notifier->sendAnomalyEmail($userId, $email, $name, $result, markNotified: false)
            : $notifier->notifyIfNeeded($userId, $email, $name);

        if ($sent) {
            $this->info("Email sent to {$email}".($force ? ' (forced test)' : '').'.');
        } else {
            $this->error('Send failed — check storage/logs/laravel.log');
        }

        return $sent ? self::SUCCESS : self::FAILURE;
    }
}
