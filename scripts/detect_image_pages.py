"""Reports which pages of a PDF contain at least one embedded raster image
(a photo, logo, etc.) — the candidate set for ImageDescriptionService's
general "what does this picture show" pass, as opposed to
ChartVisionService's chart-data-only detection.

Usage: python detect_image_pages.py <pdf_path>
Prints one 1-based page number per line.
"""
import sys

import pymupdf

pdf_path = sys.argv[1]
doc = pymupdf.open(pdf_path)

for i, page in enumerate(doc):
    if len(page.get_images()) > 0:
        print(i + 1)
