<?php

use App\Actions\Product\OptimizeProductImage;
use Illuminate\Http\UploadedFile;

test('product sources become size-limited 1024 pixel WebP images', function (string $extension) {
    $source = UploadedFile::fake()->image('product.'.$extension, 320, 240);

    $result = app(OptimizeProductImage::class)->execute($source->getPathname());

    $details = getimagesizefromstring($result['bytes']);
    expect([$details[0], $details[1], $details['mime']])->toBe([1024, 1024, 'image/webp']);
    expect(strlen($result['bytes']))->toBeLessThanOrEqual(819200);
})->with(['png', 'jpg', 'webp']);

test('non-square images crop the center without stretching the outer edges', function (int $width, int $height) {
    $source = UploadedFile::fake()->image('product.png', $width, $height);
    $canvas = imagecreatetruecolor($width, $height);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 0, 0));
    $left = intdiv($width - 800, 2);
    $top = intdiv($height - 800, 2);
    imagefilledrectangle($canvas, $left, $top, $left + 799, $top + 799, imagecolorallocate($canvas, 0, 255, 0));
    imagefilledrectangle($canvas, $left + 200, $top + 200, $left + 599, $top + 599, imagecolorallocate($canvas, 0, 0, 255));
    imagepng($canvas, $source->getPathname());
    unset($canvas);

    $result = app(OptimizeProductImage::class)->execute($source->getPathname());

    $output = imagecreatefromstring($result['bytes']);
    foreach ([[10, 512], [512, 10], [1010, 512], [512, 1010]] as [$x, $y]) {
        $color = imagecolorsforindex($output, imagecolorat($output, $x, $y));
        expect($color['green'])->toBeGreaterThan(240);
        expect($color['red'])->toBeLessThan(15);
    }
    foreach ([[260, 512], [512, 260], [760, 512], [512, 760]] as [$x, $y]) {
        expect(imagecolorsforindex($output, imagecolorat($output, $x, $y))['blue'])->toBeGreaterThan(240);
    }
})->with(['landscape' => [1600, 800], 'portrait' => [800, 1600]]);

test('transparent product backgrounds remain transparent', function () {
    $source = UploadedFile::fake()->image('transparent.png', 64, 64);
    $canvas = imagecreatetruecolor(64, 64);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    imagefilledrectangle($canvas, 16, 16, 47, 47, imagecolorallocatealpha($canvas, 220, 20, 20, 0));
    imagepng($canvas, $source->getPathname());

    $output = imagecreatefromstring(app(OptimizeProductImage::class)->execute($source->getPathname())['bytes']);

    expect(imagecolorsforindex($output, imagecolorat($output, 10, 10))['alpha'])->toBe(127);
    expect(imagecolorsforindex($output, imagecolorat($output, 512, 512))['alpha'])->toBe(0);
});

test('unsupported or corrupt product images cannot be optimized', function (string $contents) {
    $source = UploadedFile::fake()->createWithContent('image.png', $contents);

    expect(fn () => app(OptimizeProductImage::class)->execute($source->getPathname()))
        ->toThrow(RuntimeException::class, 'PNG, JPEG, or WebP');
})->with(['corrupt' => 'not an image', 'svg' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>']);

test('excessive source dimensions are rejected before decoding image pixels', function () {
    $header = 'IHDR'.pack('NNCCCCC', 10000, 10000, 8, 2, 0, 0, 0);
    $source = UploadedFile::fake()->createWithContent('large.png', "\x89PNG\r\n\x1a\n".pack('N', 13).$header.pack('N', crc32($header)));

    expect(fn () => app(OptimizeProductImage::class)->execute($source->getPathname()))
        ->toThrow(RuntimeException::class, '40 million pixels');
});
