<?php

namespace App\Services;

use RuntimeException;

class PcmWavConcatenator
{
    public function parse(string $wav): array
    {
        if (strlen($wav) < 44 || strlen($wav) > 12 * 1024 * 1024
            || substr($wav, 0, 4) !== 'RIFF' || substr($wav, 8, 4) !== 'WAVE') {
            throw new RuntimeException('document_tts_invalid_wav');
        }

        $riffSize = unpack('Vsize', substr($wav, 4, 4))['size'] ?? 0;
        if ($riffSize + 8 > strlen($wav)) {
            throw new RuntimeException('document_tts_truncated_wav');
        }

        $offset = 12;
        $format = null;
        $pcm = '';
        while ($offset + 8 <= strlen($wav)) {
            $chunkId = substr($wav, $offset, 4);
            $chunkSize = unpack('Vsize', substr($wav, $offset + 4, 4))['size'] ?? 0;
            $chunkStart = $offset + 8;
            if ($chunkSize > strlen($wav) - $chunkStart) {
                throw new RuntimeException('document_tts_invalid_wav_chunk');
            }
            if ($chunkId === 'fmt ') {
                if ($chunkSize < 16) {
                    throw new RuntimeException('document_tts_invalid_wav_format');
                }
                $values = unpack('vformat/vchannels/Vsample_rate/Vbyte_rate/vblock_align/vbits', substr($wav, $chunkStart, 16));
                $format = [
                    'format' => (int) $values['format'],
                    'channels' => (int) $values['channels'],
                    'sample_rate' => (int) $values['sample_rate'],
                    'byte_rate' => (int) $values['byte_rate'],
                    'block_align' => (int) $values['block_align'],
                    'bits' => (int) $values['bits'],
                ];
            } elseif ($chunkId === 'data') {
                $pcm .= substr($wav, $chunkStart, $chunkSize);
            }
            $offset = $chunkStart + $chunkSize + ($chunkSize % 2);
        }

        if (! $format || $format['format'] !== 1 || ! in_array($format['bits'], [16], true)
            || ! in_array($format['channels'], [1, 2], true) || $format['byte_rate'] < 1 || $pcm === '') {
            throw new RuntimeException('document_tts_unsupported_wav_format');
        }

        return ['format' => $format, 'pcm' => $pcm];
    }

    public function concatenate(array $segments): array
    {
        if ($segments === []) {
            throw new RuntimeException('document_tts_no_audio_segments');
        }

        $format = $segments[0]['format'];
        $pcm = '';
        $timeline = [];
        $cursor = 0;

        foreach ($segments as $index => $segment) {
            if (($segment['format'] ?? null) !== $format || ! is_string($segment['pcm'] ?? null)) {
                throw new RuntimeException('document_tts_format_mismatch');
            }
            $data = $segment['pcm'];
            $duration = (int) round((strlen($data) / $format['byte_rate']) * 1000);
            $timeline[] = [
                'sequence' => $index + 1,
                'start_ms' => $cursor,
                'end_ms' => $cursor + $duration,
            ];
            $cursor += $duration;
            $pcm .= $data;
        }

        $fmt = pack('v', $format['format'])
            .pack('v', $format['channels'])
            .pack('V', $format['sample_rate'])
            .pack('V', $format['byte_rate'])
            .pack('v', $format['block_align'])
            .pack('v', $format['bits']);
        $fmtChunk = 'fmt '.pack('V', 16).$fmt;
        $dataChunk = 'data'.pack('V', strlen($pcm)).$pcm;
        $body = 'WAVE'.$fmtChunk.$dataChunk;
        $wav = 'RIFF'.pack('V', strlen($body)).$body;

        return ['wav' => $wav, 'timeline' => $timeline, 'duration_ms' => $cursor, 'format' => $format];
    }
}
