<?php

namespace App\Console\Commands;

use App\Models\VoiceProviderAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class VoiceProviderAddCommand extends Command
{
    protected $signature = 'voice:provider:add
        {provider : google, azure or gladia}
        {--name= : Internal account name}
        {--model= : Provider model}
        {--region= : Provider region}
        {--project-id= : Google Cloud project ID}
        {--key= : Azure Speech resource key or Gladia API key}
        {--credential-file= : JSON credential file for Google}
        {--trial : Mark this account as trial}
        {--monthly-free : Mark this account as monthly free}
        {--quota= : Monthly or trial quota in audio seconds}
        {--credit= : Remaining trial credit in seconds}
        {--expires= : Trial/credit expiry in YYYY-MM-DD HH:MM:SS}
        {--priority=10 : Router priority (lower is preferred after quality/tier)}';

    protected $description = 'Add an encrypted Farast voice provider account.';

    public function handle(): int
    {
        if (!Schema::hasTable('voice_provider_accounts')) {
            $this->error('voice_provider_accounts table does not exist. Run: php artisan migrate');
            return self::FAILURE;
        }

        $provider = strtolower(trim((string) $this->argument('provider')));
        if (!in_array($provider, ['google', 'azure', 'gladia'], true)) {
            $this->error('Supported providers: google, azure, gladia');
            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Internal account name', ucfirst($provider) . ' Voice')));
        $region = trim((string) ($this->option('region') ?: $this->ask('Region', $provider === 'azure' ? 'eastus' : 'global')));
        $model = trim((string) ($this->option('model') ?: match ($provider) {
            'google' => 'chirp_3',
            'gladia' => 'solaria-1',
            default => 'speech',
        }));

        $credentials = [];
        if ($provider === 'azure') {
            $key = trim((string) ($this->option('key') ?: $this->secret('Azure Speech resource key')));
            if ($key === '') {
                $this->error('Azure Speech key is required.');
                return self::FAILURE;
            }
            $credentials = ['key' => $key, 'region' => $region];
        } elseif ($provider === 'gladia') {
            $key = trim((string) ($this->option('key') ?: $this->secret('Gladia API key')));
            if ($key === '') {
                $this->error('Gladia API key is required.');
                return self::FAILURE;
            }
            $credentials = ['api_key' => $key];
        } else {
            $projectId = trim((string) ($this->option('project-id') ?: $this->ask('Google Cloud project ID')));
            if ($projectId === '') {
                $this->error('Google Cloud project ID is required for a Google account.');
                return self::FAILURE;
            }
            $file = trim((string) ($this->option('credential-file') ?: ''));
            if ($file !== '') {
                if (!is_file($file) || !is_readable($file)) {
                    $this->error('Google credential file cannot be read: ' . $file);
                    return self::FAILURE;
                }
                $json = json_decode((string) file_get_contents($file), true);
                if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
                    $this->error('Google credential JSON is invalid or missing client_email/private_key.');
                    return self::FAILURE;
                }
                $credentials = array_merge($json, ['project_id' => $projectId]);
            } else {
                $credentials = ['project_id' => $projectId];
            }
        }

        $billing = $this->option('trial') ? 'trial' : ($this->option('monthly-free') ? 'monthly_free' : 'paid');
        $quota = $this->option('quota');
        $credit = $this->option('credit');
        $expires = $this->option('expires');

        $account = new VoiceProviderAccount();
        $account->provider = $provider;
        $account->name = $name;
        $account->model = $model;
        $account->credentials_array = $credentials;
        $account->capabilities = ['locales' => ['fa-IR', 'en-US', 'ar-SA'], 'streaming' => true];
        $account->billing_mode = $billing;
        $account->quota_limit_seconds = $quota !== null && $quota !== '' ? (int) $quota : null;
        $account->quota_used_seconds = 0;
        $account->credit_remaining = $credit !== null && $credit !== '' ? (float) $credit : null;
        $account->credit_expires_at = $expires !== null && $expires !== '' ? $expires : null;
        $account->quota_period_started_at = $billing === 'monthly_free' ? now() : null;
        $account->quota_period_ends_at = $billing === 'monthly_free' ? now()->addMonth() : null;
        $account->quality_score = match ($provider) {
            'google' => 98,
            'gladia' => 95,
            default => 94,
        };
        $account->reliability_score = 90;
        $account->priority = (int) $this->option('priority');
        $account->enabled = true;
        $account->healthy = true;
        $account->metadata = ['region' => $region, 'created_by' => 'voice:provider:add'];
        $account->save();

        $this->info('Voice provider account created.');
        $this->line('ID: ' . $account->id);
        $this->line('Provider: ' . $account->provider);
        $this->line('Model: ' . $account->model);
        $this->line('Billing mode: ' . $account->billing_mode);
        $this->line('Credentials are encrypted in the database and are not printed.');

        return self::SUCCESS;
    }
}
