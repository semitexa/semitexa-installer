<?php

declare(strict_types=1);

namespace Semitexa\Installer\Tests\Unit\Scaffold;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The image's ImageMagick policy denies every coder but the raster formats
 * media handles. ImageMagick reads the BMP most tools write as BMP3 (and the
 * oldest as BMP2) — coders of their own, so allowing BMP alone refused them:
 * the release preflight on 2026-10-29 failed media's
 * a_bmp_of_any_header_version_is_image_bmp, and an uploaded BMP3 would have
 * been refused in production the same way.
 */
final class ImageMagickPolicyTest extends TestCase
{
    #[Test]
    public function every_raster_format_media_handles_has_its_coders_allowed(): void
    {
        $dockerfile = @file_get_contents(dirname(__DIR__, 3) . '/scaffold' . '/Dockerfile');
        self::assertIsString($dockerfile, 'the scaffold Dockerfile cannot be read');

        // Every coder rule, in order: ImageMagick applies the last one that
        // matches, so a second read|write rule appended after these would open
        // every coder while a check for "one allow-list exists" still passed.
        // Read by attribute, whatever their order or quotes (CodeRabbit on
        // installer#134): a rule written as <policy pattern="*" domain='coder'>
        // counts as much as one in the usual order.
        preg_match_all('/<policy\b[^>]*>/', $dockerfile, $elements);
        $rules = [];
        foreach ($elements[0] as $element) {
            // Whole names only: data-pattern is not pattern.
            preg_match_all('/(?<=\s)([\w:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $element, $pairs, PREG_SET_ORDER);
            $attributes = [];
            foreach ($pairs as $pair) {
                $attributes[$pair[1]] = $pair[2] !== '' ? $pair[2] : ($pair[3] ?? '');
            }
            if (($attributes['domain'] ?? null) === 'coder') {
                $rules[] = [$attributes['rights'] ?? null, $attributes['pattern'] ?? null];
            }
        }

        self::assertSame([
            ['none', '*'],
            ['read|write', '{JPEG,JPG,PNG,WEBP,GIF,HEIC,HEIF,AVIF,BMP,BMP2,BMP3}'],
        ], $rules);
    }
}
