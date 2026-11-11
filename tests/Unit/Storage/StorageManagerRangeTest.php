<?php

namespace Tests\Unit\Storage;

use App\Services\Storage\S3CompatibleAdapter;
use App\Services\Storage\StorageManager;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class StorageManagerRangeTest extends TestCase
{
    public function test_it_reports_size_and_streams_only_the_requested_file_range(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('reader/sample.pdf', '%PDF-0123456789-END');

        $manager = new StorageManager(new S3CompatibleAdapter());
        $this->assertSame(19, $manager->size('private', 'reader/sample.pdf'));

        $output = fopen('php://temp', 'w+b');
        $manager->streamRange('private', 'reader/sample.pdf', 5, 10, $output);
        rewind($output);

        $this->assertSame('0123456789', stream_get_contents($output));
        fclose($output);
    }

    public function test_it_rejects_invalid_ranges_instead_of_silently_returning_wrong_bytes(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('reader/sample.pdf', '%PDF-test');

        $manager = new StorageManager(new S3CompatibleAdapter());
        $output = fopen('php://temp', 'w+b');

        try {
            $manager->streamRange('private', 'reader/sample.pdf', -1, 4, $output);
            $this->fail('A negative range offset must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('محدوده دریافت فایل نامعتبر است.', $exception->getMessage());
        } finally {
            fclose($output);
        }
    }

    public function test_it_rejects_ranges_that_extend_beyond_the_available_file(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('reader/sample.pdf', '12345');

        $manager = new StorageManager(new S3CompatibleAdapter());
        $output = fopen('php://temp', 'w+b');

        try {
            $manager->streamRange('private', 'reader/sample.pdf', 4, 3, $output);
            $this->fail('A range extending past EOF must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('دریافت بخشی از سند ناقص بود.', $exception->getMessage());
        } finally {
            fclose($output);
        }
    }
}
