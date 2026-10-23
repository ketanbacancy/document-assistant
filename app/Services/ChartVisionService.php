<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Recovers chart/figure data that plain text extraction can't see — a bar
 * chart's values exist only as drawn shapes in the PDF, not as text. For
 * each page whose text contains a "FIGURE" caption, this renders that page
 * to an image (via scripts/render_pdf_page.py — nothing in PHP on this
 * machine can rasterize a PDF page) and asks a local vision model
 * (Ollama, same zero-quota approach as EmbeddingService) to transcribe
 * what the chart shows. The result is folded into the document's text
 * before chunking, so chart data becomes retrievable like any other
 * paragraph.
 */
class ChartVisionService
{
    private const FIGURE_MARKER = 'FIGURE';

    private const NO_CHART_SENTINEL = 'NO_CHART_DATA';

    private const PROMPT = <<<'TEXT'
Report chart data from this page's figures only, as a terse list.
For each figure: title, then each data point as "label: value". No prose,
no explanations, no markdown headers. If a chart shows many similar points
(like a line chart), report only the first, last, min, and max points.
If there is no chart with numeric data, respond with exactly: NO_CHART_DATA
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
     *        called before and after each figure page — status is one of
     *        'processing', 'found', 'none', 'skipped'
     * @return string[] one description per page with chart data, each
     *                   prefixed with its source page number
     */
    public function describeCharts(string $absolutePath, ?callable $onProgress = null): array
    {
        $allPages = (new PdfParser())->parseFile($absolutePath)->getPages();

        $figurePages = [];
        foreach ($allPages as $index => $page) {
            if (str_contains($page->getText(), self::FIGURE_MARKER)) {
                $figurePages[] = $index + 1;
            }
        }

        $total = count($figurePages);
        $descriptions = [];

        foreach ($figurePages as $i => $pageNumber) {
            $current = $i + 1;
            $this->reportProgress($onProgress, $current, $total, $pageNumber, 'processing');

            try {
                $imagePath = $this->renderPage($absolutePath, $pageNumber);
            } catch (Throwable $e) {
                // One page failing to render shouldn't lose every other
                // page's chart data for the whole document — skip it.
                Log::warning("ChartVisionService: failed to render page {$pageNumber}: " . $e->getMessage());
                $this->reportProgress($onProgress, $current, $total, $pageNumber, 'skipped');

                continue;
            }

            try {
                // Low temperature, one retry: an empty response is sampling
                // variance, not the model saying "no chart here" (that's the
                // NO_CHART_DATA sentinel) — without this, one unlucky draw
                // silently drops that page's chart data for the whole run.
                $description = $this->describeChartSafely($imagePath) ?? $this->describeChartSafely($imagePath);

                if ($description !== null) {
                    $descriptions[] = "[Chart/figure data from page {$pageNumber}]\n{$description}";
                    $this->reportProgress($onProgress, $current, $total, $pageNumber, 'found');
                } else {
                    $this->reportProgress($onProgress, $current, $total, $pageNumber, 'none');
                }
            } finally {
                @unlink($imagePath);
            }
        }

        return $descriptions;
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

        $outputPath = "{$outputDir}/page_{$pageNumber}_" . uniqid() . '.png';

        // Lower DPI than the 150 we verified readability at — roughly halves
        // inference time on this CPU-only setup, at a small, tested cost to
        // precision (still reads charts correctly, just with coarser
        // approximation of exact values).
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
     * Wraps describeImage() so a single slow/failed page (CPU inference time
     * varies a lot) can't abort the whole document's ingestion — it just
     * means that one page's chart data is skipped, logged, not fatal.
     */
    private function describeChartSafely(string $imagePath): ?string
    {
        try {
            return $this->describeImage($imagePath);
        } catch (Throwable $e) {
            Log::warning('ChartVisionService: failed to describe a page: ' . $e->getMessage());

            return null;
        }
    }

    private function describeImage(string $imagePath): ?string
    {
        // Generous timeout: this is a 3.8B vision model running on CPU, and
        // generation time varies a lot page to page (dense multi-panel
        // figures take much longer than a single simple chart).
        $response = Http::timeout(480)->post("{$this->ollamaHost}/api/generate", [
            'model' => $this->visionModel,
            'prompt' => self::PROMPT,
            'images' => [base64_encode(file_get_contents($imagePath))],
            'stream' => false,
            'options' => ['temperature' => 0.1],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Ollama vision request failed (is Ollama running, and is the model pulled?): ' . $response->body()
            );
        }

        $text = trim((string) $response->json('response'));

        if ($text === '' || str_contains($text, self::NO_CHART_SENTINEL)) {
            return null;
        }

        return $text;
    }
}
