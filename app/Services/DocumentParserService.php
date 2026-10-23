<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Mirrors Section 7.1 of the workshop (the "Loader" step) — but instead of one
 * hardcoded string, we branch on the real file extension the user uploaded.
 */
class DocumentParserService
{
    public function parse(string $absolutePath, string $extension): string
    {
        return match (strtolower($extension)) {
            'pdf' => $this->parsePdf($absolutePath),
            'docx' => $this->parseDocx($absolutePath),
            'txt', 'md' => $this->parsePlainText($absolutePath),
            default => throw new \InvalidArgumentException("Unsupported file type: .$extension"),
        };
    }

    private function parsePdf(string $path): string
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($path);

        return $pdf->getText();
    }

    private function parseDocx(string $path): string
    {
        $phpWord = IOFactory::load($path);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . "\n";
                } elseif (method_exists($element, 'getElements')) {
                    // Handles TextRun elements (paragraphs made of multiple runs)
                    foreach ($element->getElements() as $childElement) {
                        if (method_exists($childElement, 'getText')) {
                            $text .= $childElement->getText();
                        }
                    }
                    $text .= "\n";
                }
            }
        }

        return $text;
    }

    private function parsePlainText(string $path): string
    {
        return file_get_contents($path);
    }
}
