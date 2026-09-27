<?php

namespace App\Services;

use App\Models\UserFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserFileRetentionService
{
    public function archiveExpiredLocalFiles(int $limit = 100): int
    {
        $count = 0;
        UserFile::where('status', 'local')->whereNotNull('local_expires_at')->where('local_expires_at', '<=', now())->orderBy('id')->limit($limit)->get()->each(function (UserFile $file) use (&$count) {
            if ($this->archive($file)) $count++;
        });
        return $count;
    }

    public function deleteExpiredRemoteFiles(int $limit = 100): int
    {
        $count = 0;
        UserFile::where('status', 'remote')->whereNotNull('remote_expires_at')->where('remote_expires_at', '<=', now())->orderBy('id')->limit($limit)->get()->each(function (UserFile $file) use (&$count) {
            if ($file->remote_path) Storage::disk('farast_remote')->delete($file->remote_path);
            $file->delete();
            $count++;
        });
        return $count;
    }

    private function archive(UserFile $file): bool
    {
        if (! class_exists('ZipArchive')) return false;
        $source = Storage::disk($file->disk ?: 'private');
        if (! $source->exists($file->path)) return false;
        $contents = $source->get($file->path);
        $remoteName = 'archive/'.$file->user_id.'/'.Str::uuid().'-'.preg_replace('/[^A-Za-z0-9._-]+/u', '_', $file->original_name).'.zip';
        $remote = Storage::disk('farast_remote');
        if (! $remote->put($remoteName, $this->zipBytes($file->original_name, $contents))) return false;
        $file->update(['status' => 'remote', 'remote_path' => $remoteName, 'transferred_at' => now(), 'remote_expires_at' => now()->addDays(90)]);
        $source->delete($file->path);
        return true;
    }

    private function zipBytes(string $name, string $contents): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'farast-archive');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('archive_failed');
        $zip->addFromString($name, $contents);
        $zip->close();
        $bytes = file_get_contents($tmp) ?: '';
        @unlink($tmp);
        return $bytes;
    }
}
