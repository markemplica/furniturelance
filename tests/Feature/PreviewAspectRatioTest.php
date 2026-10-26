<?php

namespace Tests\Feature;

use App\Models\FurnitureProduct;
use App\Services\RoomPreviewRasterService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PreviewAspectRatioTest extends TestCase
{
    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 180, 100, 50));
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        return $bytes;
    }

    public function test_composite_preserves_image_proportions_and_stays_within_room(): void
    {
        if (! extension_loaded('gd')) $this->markTestSkipped('GD required');
        Storage::fake('public');
        foreach ([[800,600,600,200], [600,800,100,700], [800,600,800,80]] as [$rw,$rh,$iw,$ih]) {
            Storage::disk('public')->put('item.png', $this->png($iw,$ih));
            $product = new FurnitureProduct(['image_path'=>'item.png', 'width_cm'=>100, 'height_cm'=>100]);
            $result = app(RoomPreviewRasterService::class)->composite($this->png($rw,$rh), $product, ['max_width_percent'=>90, 'x_percent'=>95, 'y_percent'=>10]);
            $this->assertNotNull($result);
            [$left,$top,$right,$bottom] = $result['bounds'];
            $width=$right-$left; $height=$bottom-$top;
            $this->assertEqualsWithDelta($width*$ih/$iw, $height, 1.1);
            $this->assertGreaterThanOrEqual(0, $left);
            $this->assertGreaterThanOrEqual(0, $top);
            $this->assertLessThanOrEqual($rw, $right);
            $this->assertLessThanOrEqual($rh, $bottom);
            $size=getimagesizefromstring($result['bytes']);
            $this->assertSame([$rw,$rh], [$size[0],$size[1]]);
            $product->width_cm=225; $product->height_cm=92;
            $other=app(RoomPreviewRasterService::class)->composite($this->png($rw,$rh), $product, ['max_width_percent'=>90, 'x_percent'=>95, 'y_percent'=>10]);
            $this->assertSame($result['bounds'], $other['bounds']);
        }
    }
}
