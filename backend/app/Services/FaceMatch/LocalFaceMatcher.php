<?php

namespace App\Services\FaceMatch;

use App\Services\FaceMatch\Contracts\FaceMatcher;
use Illuminate\Http\UploadedFile;

/**
 * 本地演示人脸比对驱动（基于 GD 的图像相似度计算）。
 *
 * 注意：平均哈希只能衡量图片整体相似度，不是真正的人脸识别，
 * 仅用于演示环境跑通"通过/疑似/失败"三态流程；
 * 生产环境请替换为云厂商人脸核身（活体检测 + 1:1 比对）服务。
 */
class LocalFaceMatcher implements FaceMatcher
{
    public function __construct(
        private readonly int $passThreshold = 80,
        private readonly int $suspectThreshold = 55,
    ) {
    }

    public function compare(UploadedFile $livePhoto, UploadedFile $idPhoto): array
    {
        $liveVector = $this->toVector($livePhoto);
        $idVector = $this->toVector($idPhoto);

        if ($liveVector === null || $idVector === null) {
            // 无法解析图片时，降级为"疑似"交人工处理，而不是直接放行
            return ['score' => 0, 'passed' => false, 'suspected' => true, 'raw' => 'unable_to_decode'];
        }

        $distance = $this->euclidean($liveVector, $idVector);
        $score = (int) round(max(0, min(100, 100 - $distance)));

        return [
            'score' => $score,
            'passed' => $score >= $this->passThreshold,
            'suspected' => $score < $this->passThreshold && $score >= $this->suspectThreshold,
            'raw' => "local-ahash,euclidean={$distance}",
        ];
    }

    /**
     * 缩放到 16x16 灰度并做均值归一化，返回 256 维向量。
     *
     * @return float[]|null
     */
    private function toVector(UploadedFile $file): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $contents = @file_get_contents($file->getRealPath());
        if ($contents === false) {
            return null;
        }

        $src = @imagecreatefromstring($contents);
        if ($src === false) {
            return null;
        }

        $size = 16;
        $thumb = imagecreatetruecolor($size, $size);
        imagecopyresampled(
            $thumb,
            $src,
            0,
            0,
            0,
            0,
            $size,
            $size,
            imagesx($src),
            imagesy($src)
        );

        $pixels = [];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $rgb = imagecolorat($thumb, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                // 亮度归一化到 0-1，弱化光照差异
                $pixels[] = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255.0;
            }
        }

        imagedestroy($src);
        imagedestroy($thumb);

        $mean = array_sum($pixels) / count($pixels);
        $variance = array_sum(array_map(fn ($p) => ($p - $mean) ** 2, $pixels)) / count($pixels);
        $std = sqrt(max($variance, 1e-8));

        return array_map(fn ($p) => ($p - $mean) / $std, $pixels);
    }

    /**
     * @param  float[]  $a
     * @param  float[]  $b
     */
    private function euclidean(array $a, array $b): float
    {
        $sum = 0.0;
        $count = min(count($a), count($b));
        for ($i = 0; $i < $count; $i++) {
            $sum += ($a[$i] - $b[$i]) ** 2;
        }

        // 归一化到 0-100 量纲
        return min(100.0, sqrt($sum / max($count, 1)) * 25);
    }
}
