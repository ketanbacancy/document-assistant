<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Describes what's actually pictured on pages that have an embedded photo
 * or illustration — distinct from ChartVisionService, which only cares
 * about numeric chart data. This is what makes a question like "what does
 * the GPO headquarters building look like?" answerable: that content isn't
 * a chart, so ChartVisionService's prompt would correctly (and uselessly)
 * report NO_CHART_DATA for it.
 *
 * @since Oct 26, 2026
 * @author Ketan Barevadiya <ketan.barevadiya@bacancy.com>
 * @copyright 2025 bacancy.com
 */
class ImageDescriptionService
{
    private const NO_IMAGE_SENTINEL = 'NO_IMAGE_CONTENT';

    private const PROMPT = <<<'TEXT'
Describe what this page's photo(s) or illustration(s) actually show, in 1-2
concise sentences — subject, setting, and anything visually notable (e.g.
a building's appearance, what people are doing). Do not describe charts,
tables, or plain text layout. If the page has no meaningful photo or
illustration (only text, or only a chart/table), respond with exactly:
NO_IMAGE_CONTENT
TEXT;

    private string $ollamaHost;

    private string $visionModel;

    public function __construct()
    {
        $this->ollamaHost = rtrim(config('services.ollama.host'), '/');
        $this->visionModel = config('services.ollama.vision_model', 'qwen2.5vl:3b');
    }

    /**
     * @param ?callable(int $current, int $total, int $pageNumber, string $status): void $onProgress
     *        called before and after each image page — status is one of
     *        'processing', 'found', 'none', 'skipped'
     * @return string[] one description per page with real image content,
     *                   each prefixed with its source page number
     */
    public function describeImages(string $absolutePath, ?callable $onProgress = null): array
    {
        $imagePages = $this->findImagePages($absolutePath);

        $total = count($imagePages);
        $descriptions = [];

        foreach ($imagePages as $i => $pageNumber) {
            $current = $i + 1;
            $this->reportProgress($onProgress, $current, $total, $pageNumber, 'processing');

            try {
                $renderedPath = $this->renderPage($absolutePath, $pageNumber);
            } catch (Throwable $e) {
                Log::warning("ImageDescriptionService: failed to render page {$pageNumber}: " . $e->getMessage());
                $this->reportProgress($onProgress, $current, $total, $pageNumber, 'skipped');

                continue;
            }

            try {
                $description = $this->describeImageSafely($renderedPath) ?? $this->describeImageSafely($renderedPath);

                if ($description !== null) {
                    $descriptions[] = "[Image description from page {$pageNumber}]\n{$description}";
                    $this->reportProgress($onProgress, $current, $total, $pageNumber, 'found');
                } else {
                    $this->reportProgress($onProgress, $current, $total, $pageNumber, 'none');
                }
            } finally {
                @unlink($renderedPath);
            }
        }

        return $descriptions;
    }

    /**
     * @return int[] 1-based page numbers with at least one embedded raster image
     */
    private function findImagePages(string $pdfPath): array
    {
        $process = new Process([
            'python', '-I', base_path('scripts/detect_image_pages.py'), $pdfPath,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Failed to detect image pages: ' . $process->getErrorOutput());
        }

        return array_map('intval', array_filter(explode("\n", trim($process->getOutput()))));
    }

    private function reportProgress(?callable $onProgress, int $current, int $total, int $pageNumber, string $status): void
    {
        if ($onProgress !== null) {
            $onProgress($current, $total, $pageNumber, $status);
        }
    }

    private function renderPage(string $pdfPath, int $pageNumber): string
    {
        $outputDir = storage_path('app/tmp-chart-pages');

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $outputPath = "{$outputDir}/image_page_{$pageNumber}_" . uniqid() . '.png';

        $process = new Process([
            'python', '-I', base_path('scripts/render_pdf_page.py'), $pdfPath, (string) $pageNumber, $outputPath, '110',
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful() || ! file_exists($outputPath)) {
            throw new \RuntimeException(
                "Failed to render PDF page {$pageNumber}: " . $process->getErrorOutput()
            );
        }

        return $outputPath;
    }

    /**
     * Wraps describeImage() so a single slow/failed page can't abort the
     * whole document's ingestion.
     */
    private function describeImageSafely(string $imagePath): ?string
    {
        try {
            return $this->describeImage($imagePath);
        } catch (Throwable $e) {
            Log::warning('ImageDescriptionService: failed to describe a page: ' . $e->getMessage());

            return null;
        }
    }

    private function describeImage(string $imagePath): ?string
    {
        $response = Http::timeout(480)->post("{$this->ollamaHost}/api/generate", [
            'model' => $this->visionModel,
            'prompt' => self::PROMPT,
            'images' => [base64_encode(file_get_contents($imagePath))],
            'stream' => false,
            'options' => ['temperature' => 0.2],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Ollama vision request failed (is Ollama running, and is the model pulled?): ' . $response->body()
            );
        }

        $text = trim((string) $response->json('response'));

        if ($text === '' || str_contains($text, self::NO_IMAGE_SENTINEL)) {
            return null;
        }

        return $text;
    }
}
