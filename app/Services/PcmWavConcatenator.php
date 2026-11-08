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

    public function header(array $format, int $pcmBytes): string
    {
        if ($pcmBytes < 1 || $pcmBytes > 0xffffffff - 36) {
            throw new RuntimeException('document_tts_output_size_limit');
        }
        $fmt = pack('v', $format['format'])
            .pack('v', $format['channels'])
            .pack('V', $format['sample_rate'])
            .pack('V', $format['byte_rate'])
            .pack('v', $format['block_align'])
            .pack('v', $format['bits']);
        $fmtChunk = 'fmt '.pack('V', 16).$fmt;
        $dataHeader = 'data'.pack('V', $pcmBytes);
        $riffBodyLength = 4 + strlen($fmtChunk) + strlen($dataHeader) + $pcmBytes;

        return 'RIFF'.pack('V', $riffBodyLength).'WAVE'.$fmtChunk.$dataHeader;
    }
}
