<?php

namespace Tests\Unit;

use App\Jobs\GenerateProductDocumentAudio;
use App\Jobs\ProcessProductDocument;
use Carbon\Carbon;
use Tests\TestCase;

class DocumentJobRetryPolicyTest extends TestCase
{
    public function test_document_processing_job_survives_daily_provider_quota_resets(): void
    {
        Carbon::setTestNow('2026-11-25 12:00:00');

        try {
            $processing = new ProcessProductDocument(10);
            $audio = new GenerateProductDocumentAudio(10);

            $this->assertSame('2026-11-28 12:00:00', $processing->retryUntil()->format('Y-m-d H:i:s'));
            $this->assertSame('2026-11-26 12:00:00', $audio->retryUntil()->format('Y-m-d H:i:s'));
            $this->assertSame([60, 300, 900, 1800, 3600], $processing->backoff);
            $this->assertSame([120, 600, 1800, 3600], $audio->backoff);
        } finally {
            Carbon::setTestNow();
        }
    }
}
