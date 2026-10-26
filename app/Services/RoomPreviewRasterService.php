<?php

namespace App\Services;

use App\Models\FurnitureProduct;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RoomPreviewRasterService
{
    public function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagepng');
    }

    /** Normalize dimensions, orientation and metadata where GD is available. */
    public function normalize(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info) || ! isset($info[0], $info[1])) throw new RuntimeException('invalid_image');
        $width = (int) $info[0];
        $height = (int) $info[1];
        if (max($width, $height) < (int) config('roompreview.min_dimension')) throw new RuntimeException('image_too_small');
        if ($width * $height > (int) config('roompreview.max_pixels')) throw new RuntimeException('image_pixel_limit');
        if (! $this->available()) return ['bytes' => $bytes, 'mime' => $info['mime'] ?? 'image/jpeg', 'width' => $width, 'height' => $height, 'quality' => null, 'normalized' => false];

        $image = @imagecreatefromstring($bytes);
        if (! $image) throw new RuntimeException('invalid_image');
        $max = (int) config('roompreview.max_dimension', 1600);
        $scale = min(1, $max / max($width, $height));
        if ($scale < 1) {
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
            $width = $newWidth;
            $height = $newHeight;
        }
        $quality = $this->quality($image, $width, $height);
        ob_start();
        imagepng($image);
        $normalized = (string) ob_get_clean();
        imagedestroy($image);
        if (strlen($normalized) > (int) config('roompreview.max_upload_bytes')) throw new RuntimeException('normalized_image_too_large');
        return ['bytes' => $normalized, 'mime' => 'image/png', 'width' => $width, 'height' => $height, 'quality' => $quality, 'normalized' => true];
    }

    /** Deterministic compositing fallback using only trusted catalog image bytes and server geometry. */
    public function composite(string $roomBytes, FurnitureProduct $product, array $placement): ?array
    {
        if (! $this->available()) return null;
        $room = @imagecreatefromstring($roomBytes);
        $furnitureBytes = $this->catalogImageBytes($product);
        $item = $furnitureBytes ? @imagecreatefromstring($furnitureBytes) : false;
        if (! $room || ! $item) {
            if ($room) imagedestroy($room);
            if ($item) imagedestroy($item);
            return null;
        }
        $canvasWidth = imagesx($room);
        $canvasHeight = imagesy($room);
        $itemWidth = imagesx($item);
        $itemHeight = imagesy($item);
        $widthPercent = max(1.0, min(90.0, (float) ($placement['max_width_percent'] ?? 35)));
        // Scale both axes together using the actual catalog photograph/cutout.
        // Physical dimensions do not describe the projected shape of a multi-piece set.
        $desiredWidth = max(24, $canvasWidth * $widthPercent / 100);
        $scale = min($desiredWidth / $itemWidth, $canvasWidth * 0.9 / $itemWidth, $canvasHeight * 0.92 / $itemHeight);
        $targetWidth = max(1, (int) round($itemWidth * $scale));
        $targetHeight = max(1, (int) round($itemHeight * $scale));
        $centerX = (int) round($canvasWidth * max(0.05, min(0.95, (float) ($placement['x_percent'] ?? 50) / 100)));
        $bottomY = (int) round($canvasHeight * max(0.05, min(0.98, (float) ($placement['y_percent'] ?? 86) / 100)));
        $left = max(0, min($canvasWidth - $targetWidth, $centerX - (int) ($targetWidth / 2)));
        $top = max(0, min($canvasHeight - $targetHeight, $bottomY - $targetHeight));

        // A small translucent contact shadow grounds the item at its floor anchor.
        $shadow = imagecolorallocatealpha($room, 20, 16, 12, 88);
        imagefilledellipse($room, $left + (int) round($targetWidth / 2), min($canvasHeight - 1, $top + $targetHeight), (int) ($targetWidth * 0.82), max(3, (int) ($targetWidth * 0.09)), $shadow);
        imagealphablending($room, true);
        imagecopyresampled($room, $item, $left, $top, 0, 0, $targetWidth, $targetHeight, $itemWidth, $itemHeight);
        ob_start();
        imagepng($room);
        $bytes = (string) ob_get_clean();
        imagedestroy($item);
        imagedestroy($room);
        return ['bytes' => $bytes, 'mime' => 'image/png', 'bounds' => [$left, $top, $left + $targetWidth, $top + $targetHeight]];
    }

    /** Reject generated frames that materially alter pixels outside the intended item box. */
    public function pixelGuard(string $before, string $after, array $bounds): array
    {
        if (! $this->available()) return ['supported' => false, 'passed' => false, 'changed_ratio' => null];
        $a = @imagecreatefromstring($before);
        $b = @imagecreatefromstring($after);
        if (! $a || ! $b || imagesx($a) !== imagesx($b) || imagesy($a) !== imagesy($b)) {
            if ($a) imagedestroy($a);
            if ($b) imagedestroy($b);
            return ['supported' => true, 'passed' => false, 'changed_ratio' => 1.0];
        }
        [$x1, $y1, $x2, $y2] = array_map('intval', $bounds);
        $marginX = (int) ((imagesx($a)) * 0.025);
        $marginY = (int) ((imagesy($a)) * 0.025);
        $x1 -= $marginX; $x2 += $marginX; $y1 -= $marginY; $y2 += $marginY;
        $changed = 0; $sampled = 0;
        $step = max(1, (int) floor(max(imagesx($a), imagesy($a)) / 160));
        for ($y = 0; $y < imagesy($a); $y += $step) for ($x = 0; $x < imagesx($a); $x += $step) {
            if ($x >= $x1 && $x <= $x2 && $y >= $y1 && $y <= $y2) continue;
            $pa = imagecolorat($a, $x, $y); $pb = imagecolorat($b, $x, $y);
            $delta = abs((($pa >> 16) & 255) - (($pb >> 16) & 255))
                + abs((($pa >> 8) & 255) - (($pb >> 8) & 255))
                + abs(($pa & 255) - ($pb & 255));
            if ($delta > 90) $changed++;
            $sampled++;
        }
        imagedestroy($a); imagedestroy($b);
        $ratio = $sampled ? $changed / $sampled : 1.0;
        return ['supported' => true, 'passed' => $ratio <= (float) config('roompreview.pixel_difference_max', 0.05), 'changed_ratio' => round($ratio, 4)];
    }

    private function catalogImageBytes(FurnitureProduct $product): ?string
    {
        $path = (string) $product->image_path;
        if (str_starts_with($path, 'catalog:')) $path = public_path('images/catalog/'.substr($path, 8));
        elseif (str_starts_with($path, 'demo:')) $path = public_path('images/furniture/'.substr($path, 5));
        elseif (Storage::disk('public')->exists($path)) return Storage::disk('public')->get($path);
        else return null;
        return is_file($path) ? file_get_contents($path) : null;
    }

    private function quality($image, int $width, int $height): array
    {
        $brightnessTotal = 0.0; $sampleCount = 0; $grid = [];
        $stepX = max(1, (int) floor($width / 64)); $stepY = max(1, (int) floor($height / 64));
        for ($y = 0; $y < $height; $y += $stepY) {
            $row = [];
            for ($x = 0; $x < $width; $x += $stepX) {
                $rgb = imagecolorat($image, $x, $y);
                $gray = (int) round(0.299 * (($rgb >> 16) & 255) + 0.587 * (($rgb >> 8) & 255) + 0.114 * ($rgb & 255));
                $row[] = $gray; $brightnessTotal += $gray / 255; $sampleCount++;
            }
            $grid[] = $row;
        }
        $brightness = $sampleCount ? $brightnessTotal / $sampleCount : 0.0;
        $laplacians = [];
        for ($row = 1; $row < count($grid) - 1; $row++) {
            for ($column = 1; $column < count($grid[$row]) - 1; $column++) {
                $laplacians[] = $grid[$row - 1][$column] + $grid[$row + 1][$column]
                    + $grid[$row][$column - 1] + $grid[$row][$column + 1] - 4 * $grid[$row][$column];
            }
        }
        $lapMean = count($laplacians) ? array_sum($laplacians) / count($laplacians) : 0.0;
        $lapVariance = count($laplacians)
            ? array_sum(array_map(fn ($value) => ($value - $lapMean) ** 2, $laplacians)) / count($laplacians)
            : 0.0;
        return [
            'brightness' => round($brightness, 3),
            'blur_variance' => round($lapVariance, 2),
            'usable' => $brightness >= (float) config('roompreview.brightness_min')
                && $brightness <= (float) config('roompreview.brightness_max')
                && $lapVariance >= (float) config('roompreview.blur_variance_min'),
        ];
    }
}

