<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * dompdf cannot draw glyphs outside the Basic Multilingual Plane, so emojis in
 * descriptions would print as boxes. This renders each emoji as a small inline
 * image (Twemoji PNG), cached on disk and embedded as a data URI.
 */
class EmojiPdf
{
    // Flags (two regional indicators) first, then pictographs with modifiers/ZWJ sequences.
    private const PATTERN = '/([\x{1F1E6}-\x{1F1FF}]{2}|\p{Extended_Pictographic}(?:\x{FE0F}|\x{20E3}|\p{Emoji_Modifier}|\x{200D}\p{Extended_Pictographic})*)/u';

    private const CDN = 'https://cdn.jsdelivr.net/gh/jdecked/twemoji@15.1.0/assets/72x72/';

    /** @var array<string, string|null> */
    private array $memo = [];

    public function __construct(private readonly string $cacheDir = '')
    {
    }

    /** Escapes the text for HTML and replaces emoji runs with inline images. */
    public function html(?string $text): string
    {
        $escaped = e((string) $text);

        return preg_replace_callback(self::PATTERN, function (array $m) {
            $dataUri = $this->dataUri($m[1]);
            if (! $dataUri) {
                return '';
            }

            return '<img class="emoji" src="' . $dataUri . '" alt="">';
        }, $escaped) ?? $escaped;
    }

    private function dataUri(string $emoji): ?string
    {
        $name = $this->twemojiName($emoji);
        if (isset($this->memo[$name])) {
            return $this->memo[$name];
        }

        $dir = $this->cacheDir ?: storage_path('app/emoji');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . $name . '.png';

        if (! is_file($file)) {
            try {
                $response = Http::timeout(4)->get(self::CDN . $name . '.png');
                if ($response->successful() && str_starts_with($response->body(), "\x89PNG")) {
                    file_put_contents($file, $response->body());
                } else {
                    return $this->memo[$name] = null;
                }
            } catch (\Throwable $e) {
                Log::info('emoji image unavailable: ' . $name . ' (' . $e->getMessage() . ')');

                return $this->memo[$name] = null;
            }
        }

        return $this->memo[$name] = 'data:image/png;base64,' . base64_encode((string) file_get_contents($file));
    }

    /** Twemoji file naming: lowercase code points joined by "-", FE0F dropped unless a ZWJ sequence. */
    private function twemojiName(string $emoji): string
    {
        $points = [];
        foreach (mb_str_split($emoji) as $char) {
            $points[] = mb_ord($char);
        }
        $hasZwj = in_array(0x200D, $points, true);
        if (! $hasZwj) {
            $points = array_values(array_filter($points, fn ($p) => $p !== 0xFE0F));
        }

        return implode('-', array_map(fn ($p) => strtolower(dechex($p)), $points));
    }
}
